#!/bin/bash
# Aktualizace macadmin z GitHubu (vetev main) - spoustet jako root na serveru.
#
#   instalace (jednou):
#     curl -fsSL https://raw.githubusercontent.com/vladava/macadmin/main/update.sh -o /usr/local/sbin/macadmin-update
#     chmod 700 /usr/local/sbin/macadmin-update
#   pouziti:
#     macadmin-update           # nainstaluje novou verzi, pokud nejaka je
#     macadmin-update --force   # nainstaluje znovu i stejnou verzi
#
# Soukromy repozitar: GitHub token (jen cteni obsahu) ulozit do /root/.macadmin-token
# config.php se nikdy neprepisuje. Pred kazdou aktualizaci se web zazalohuje do /root/macadmin-zalohy.
set -euo pipefail

REPO=vladava/macadmin
WEB=/var/www/macadmin
APACHE_CONF=/etc/httpd/conf.d/macadmin.conf
STATE=/var/lib/macadmin-update
BACKUPS=/root/macadmin-zalohy
APP_FILES=(index.php lib.php udrzba.php import-forms.php config.sample.php)

FORCE=0
[[ "${1:-}" == "--force" ]] && FORCE=1
[[ $EUID -eq 0 ]] || { echo "Spustte jako root."; exit 1; }
[[ -f "$WEB/config.php" ]] || { echo "Chybi $WEB/config.php - nejdriv udelejte prvni instalaci podle README."; exit 1; }
mkdir -p "$STATE"

AUTH=()
if [[ -s /root/.macadmin-token ]]; then
    AUTH=(-H "Authorization: Bearer $(tr -d '[:space:]' < /root/.macadmin-token)")
fi

# --- verze ---
SHA=$(curl -fsS "${AUTH[@]}" -H "Accept: application/vnd.github.sha" \
    "https://api.github.com/repos/$REPO/commits/main") \
    || { echo "Nepodarilo se zjistit verzi na GitHubu (bez internetu, nebo soukromy repozitar bez tokenu?)."; exit 1; }
OLD=$(cat "$STATE/version" 2>/dev/null || true)
if [[ "$SHA" == "$OLD" && $FORCE -eq 0 ]]; then
    echo "Nainstalovana verze ${SHA:0:7} je aktualni."
    exit 0
fi
echo "Aktualizace ${OLD:0:7} -> ${SHA:0:7}"

# seznam zmen od posledni aktualizace
if [[ -n "$OLD" && "$OLD" != "$SHA" ]]; then
    curl -fsS "${AUTH[@]}" "https://api.github.com/repos/$REPO/compare/$OLD...$SHA" 2>/dev/null \
        | php -r '$d = json_decode(stream_get_contents(STDIN), true);
                  foreach ($d["commits"] ?? [] as $c) echo "  - ", strtok($c["commit"]["message"], "\n"), "\n";' \
        || true
fi

# --- stazeni a kontrola ---
TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT
curl -fsSL "${AUTH[@]}" "https://api.github.com/repos/$REPO/tarball/$SHA" | tar xz -C "$TMP" --strip-components=1
for f in "${APP_FILES[@]}"; do
    [[ -f "$TMP/$f" ]] || { echo "V nove verzi chybi $f, aktualizace zrusena."; exit 1; }
    php -l "$TMP/$f" > /dev/null || { echo "Chyba syntaxe v $f, aktualizace zrusena."; exit 1; }
done

# --- zaloha (ponechat poslednich 5) ---
BK="$BACKUPS/$(date +%Y%m%d-%H%M%S)"
mkdir -p "$BK"
chmod 700 "$BACKUPS"
cp -a "$WEB/." "$BK/"
cp -a "$APACHE_CONF" "$BK/" 2>/dev/null || true
ls -1dt "$BACKUPS"/*/ | tail -n +6 | xargs -r rm -rf
echo "Zaloha: $BK"

# --- soubory aplikace ---
for f in "${APP_FILES[@]}"; do
    install -m 644 -o root -g root "$TMP/$f" "$WEB/$f"
done
restorecon -R "$WEB"
echo "Web: soubory aktualizovany"

# --- konfigurace Apache ---
# Prepise se sama jen tehdy, kdyz ji nikdo rucne neupravil (shoduje se s minulou verzi z GitHubu).
if ! cmp -s "$TMP/macadmin.conf" "$APACHE_CONF"; then
    REPLACE=0
    if [[ -f "$STATE/macadmin.conf" ]] && cmp -s "$STATE/macadmin.conf" "$APACHE_CONF"; then
        REPLACE=1
    else
        echo "Konfigurace Apache se lisi od verze na GitHubu (mozna rucni uprava):"
        diff -u "$APACHE_CONF" "$TMP/macadmin.conf" || true
        read -r -p "Prepsat $APACHE_CONF verzi z GitHubu? [a/N] " ans
        [[ "$ans" == [aAyY] ]] && REPLACE=1
    fi
    if [[ $REPLACE -eq 1 ]]; then
        cp -a "$APACHE_CONF" "$TMP/macadmin.conf.puvodni"
        install -m 644 -o root -g root "$TMP/macadmin.conf" "$APACHE_CONF"
        restorecon "$APACHE_CONF"
        if apachectl configtest > /dev/null 2>&1; then
            systemctl reload httpd
            echo "Apache: nova konfigurace nactena"
        else
            cp -a "$TMP/macadmin.conf.puvodni" "$APACHE_CONF"
            echo "Apache: nova konfigurace ma chybu, ponechana puvodni"
            apachectl configtest || true
        fi
    fi
fi

# --- databaze (jen pri zmene setup.sql; prikazy jdou spustit opakovane) ---
if ! cmp -s "$TMP/setup.sql" "$STATE/setup.sql"; then
    echo "Zmena struktury databaze - zadejte heslo uzivatele root do MariaDB:"
    # uzivatel macadmin uz existuje, heslo v CREATE USER IF NOT EXISTS se nepouzije
    if sed 's/ZMENTE_HESLO/nepouzije-se/' "$TMP/setup.sql" | mysql -u root -p radius; then
        cp "$TMP/setup.sql" "$STATE/setup.sql"
        echo "Databaze: aktualizovana"
    else
        echo "Databaze: chyba, spustte znovu macadmin-update --force"
    fi
fi

# --- cron ---
if [[ ! -f /etc/cron.d/macadmin ]]; then
    echo '15 2 * * * apache php /var/www/macadmin/udrzba.php >> /var/log/macadmin-udrzba.log 2>&1' > /etc/cron.d/macadmin
    echo "Cron: pridano denni promazani historie"
fi
FORMS=$(php -r '$c = require "/var/www/macadmin/config.php"; echo $c["forms_url"] ?? "";')
if [[ -n "$FORMS" && ! -f /etc/cron.d/macadmin-import ]]; then
    echo '*/10 * * * * apache php /var/www/macadmin/import-forms.php >> /var/log/macadmin-import.log 2>&1' > /etc/cron.d/macadmin-import
    echo "Cron: pridan import z OneDrive kazdych 10 minut"
fi
install -d -o apache -g apache -m 750 /var/lib/macadmin   # stav importu (upozorneni e-mailem)
for log in /var/log/macadmin-udrzba.log /var/log/macadmin-import.log; do
    [[ -f "$log" ]] || touch "$log"
    chown apache "$log"
done

# --- hotovo ---
echo "$SHA" > "$STATE/version"
cp "$TMP/macadmin.conf" "$STATE/macadmin.conf"
echo "Hotovo, nainstalovana verze ${SHA:0:7}."

# novou verzi tohoto skriptu nahradit az nakonec (mv = novy soubor, bezici skript necha dobehnout)
SELF=$(readlink -f "$0")
if [[ -f "$TMP/update.sh" ]] && ! cmp -s "$TMP/update.sh" "$SELF"; then
    install -m 700 "$TMP/update.sh" "$SELF.new" && mv -f "$SELF.new" "$SELF"
    echo "Skript macadmin-update aktualizovan."
fi
