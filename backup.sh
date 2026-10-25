#!/bin/bash
# Kompletni zaloha serveru macadmin do jednoho .tgz - spoustet jako root:
# aplikace vc. config.php, stav a nocni zalohy aplikace, pomocne skripty a cron, konfigurace FreeRADIUS,
# Apache a TLS certifikat, nastaveni MariaDB a PHP, dump databaze radius a jeji uzivatele.
#
#   instalace (jednou; pak ji aktualizuje macadmin-update):
#     curl -fsSL https://raw.githubusercontent.com/vladava/macadmin/main/backup.sh -o /usr/local/sbin/macadmin-zaloha
#     chmod 700 /usr/local/sbin/macadmin-zaloha
#   pouziti:
#     macadmin-zaloha                    # do /root/macadmin-zalohy-serveru, ponecha 10 poslednich
#     macadmin-zaloha -o /mnt/nas/zalohy # jinam (napr. pripojeny NAS)
#     macadmin-zaloha --bez-historie     # bez historie prihlaseni (radpostauth) - mnohem mensi
#     macadmin-zaloha --ponechat 5       # kolik zaloh v cilovem adresari nechat
#
# Archiv obsahuje hesla a klice - je citelny jen pro roota. Postup obnovy je v archivu v souboru OBNOVA.txt.
set -euo pipefail

OUT=/root/macadmin-zalohy-serveru
KEEP=10
DB=radius
NOHIST=0
while [[ $# -gt 0 ]]; do
    case "$1" in
        -o) OUT=${2:?chybi adresar za -o}; shift 2 ;;
        --bez-historie) NOHIST=1; shift ;;
        --ponechat) KEEP=${2:?chybi pocet za --ponechat}; shift 2 ;;
        -h|--help) sed -n '2,16p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *) echo "Neznamy parametr: $1 (napoveda: macadmin-zaloha --help)"; exit 1 ;;
    esac
done
[[ $EUID -eq 0 ]] || { echo "Spustte jako root."; exit 1; }
[[ "$KEEP" =~ ^[0-9]+$ && "$KEEP" -ge 1 ]] || { echo "--ponechat musi byt kladne cislo."; exit 1; }

umask 077
STAMP=$(date +%Y%m%d-%H%M%S)
NAME="macadmin-server-$(hostname -s)-$STAMP.tgz"
TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT
mkdir -p "$OUT" "$TMP/db"

# --- databaze ---
# root se do MariaDB obvykle prihlasi pres unix_socket bez hesla; kdyz ne, zeptat se
if ! mysql -u root -e 'SELECT 1' > /dev/null 2>&1; then
    read -r -s -p "Heslo uzivatele root do MariaDB: " PW; echo
    export MYSQL_PWD="$PW"
    mysql -u root -e 'SELECT 1' > /dev/null || { echo "Prihlaseni do MariaDB selhalo."; exit 1; }
fi
IGN=()
[[ $NOHIST -eq 1 ]] && IGN=(--ignore-table="$DB.radpostauth")
echo "Dump databaze $DB..."
mysqldump -u root --single-transaction --routines --triggers --events "${IGN[@]}" --databases "$DB" > "$TMP/db/$DB.sql"
if [[ $NOHIST -eq 1 ]]; then
    # bez historie: tabulka radpostauth prazdna, ale se strukturou (ve stejnem souboru, at se pri obnove nezapomene)
    mysqldump -u root --no-data "$DB" radpostauth >> "$TMP/db/$DB.sql"
fi
# uzivatele databaze a jejich prava (heslo jen jako hash)
mysql -u root -N -e "SELECT CONCAT('SHOW CREATE USER ''', user, '''@''', host, '''; SHOW GRANTS FOR ''', user, '''@''', host, ''';')
                     FROM mysql.user WHERE user IN ('radius', 'macadmin')" \
    | mysql -u root -N | sed 's/$/;/' > "$TMP/db/uzivatele.sql"

# --- soubory (jen ty, ktere existuji) ---
PATHS=(
    /var/www/macadmin
    /var/lib/macadmin
    /var/lib/macadmin-update
    /etc/raddb
    /etc/httpd/conf.d/macadmin.conf
    /etc/httpd/conf.d/00-default-ssl.conf
    /etc/pki/tls/certs/macadmin.crt
    /etc/pki/tls/private/macadmin.key
    /etc/cron.d/macadmin
    /etc/cron.d/macadmin-import
    /etc/my.cnf.d/zz-local.cnf
    /etc/php.d/99-macadmin.ini
    /usr/local/sbin/macadmin-update
    /usr/local/sbin/macadmin-zaloha
)
FILES=()
for p in "${PATHS[@]}"; do
    [[ -e "$p" ]] && FILES+=("${p#/}")
done

cat > "$TMP/OBNOVA.txt" <<EOF
Zaloha serveru macadmin ($(hostname -f 2>/dev/null || hostname)), vytvorena $(date '+%Y-%m-%d %H:%M:%S')

Obsah:
  db/$DB.sql       dump databaze $DB (vc. CREATE DATABASE)$( [[ $NOHIST -eq 1 ]] && echo " - BEZ historie prihlaseni (radpostauth prazdna)")
  db/uzivatele.sql    uzivatele radius a macadmin s pravy (heslo jako hash)
  ostatni             soubory na svych mistech od korene /:
$(printf '                      /%s\n' "${FILES[@]}")

Obnova na novem serveru (AlmaLinux 9):
  1. Nainstalovat balicky podle README (freeradius freeradius-mysql freeradius-utils mariadb-server
     httpd mod_ssl php php-mysqlnd php-mbstring php-xml php-pecl-zip postfix) a spustit mariadb.
  2. Rozbalit soubory na sva mista:
       tar xzf $NAME -C / --exclude=db --exclude=OBNOVA.txt
       restorecon -R /var/www/macadmin /var/lib/macadmin /etc/raddb /etc/httpd /etc/pki/tls
  3. Databaze a uzivatele:
       tar xzf $NAME -C /root db
       mysql < /root/db/$DB.sql
       mysql < /root/db/uzivatele.sql      # kdyz uzivatel uz existuje, prislusny radek skonci chybou - nevadi
  4. Prava a sluzby:
       chown root:apache /var/www/macadmin/config.php; chmod 640 /var/www/macadmin/config.php
       chown -R apache:apache /var/lib/macadmin
       setsebool -P httpd_can_network_connect 1
       firewall-cmd --permanent --add-service=radius --add-service=http --add-service=https; firewall-cmd --reload
       systemctl enable --now radiusd php-fpm httpd postfix crond
  5. Kontrola: radiusd -XC; radtest MAC MAC 127.0.0.1 0 testing123; https://IP-SERVERU/macadmin/
EOF

# --- archiv ---
echo "Archiv..."
tar czf "$OUT/$NAME" -C / "${FILES[@]}" -C "$TMP" db OBNOVA.txt || [[ $? -eq 1 ]] # 1 = soubor se behem cteni zmenil (log apod.)
chmod 600 "$OUT/$NAME"

# ponechat poslednich KEEP zaloh
ls -1t "$OUT"/macadmin-server-*.tgz 2> /dev/null | tail -n +$((KEEP + 1)) | xargs -r rm -f

echo "Hotovo: $OUT/$NAME ($(du -h "$OUT/$NAME" | cut -f1))"
echo "Zkopirujte ji i mimo server (NAS, PC) - pri poruse disku by zaloha zmizela se serverem."
