# macadmin – správa MAC adres pro FreeRADIUS

Jednoduché webové rozhraní (PHP 8, bez frameworku) pro wifi, která ověřuje zařízení podle MAC adresy přes FreeRADIUS
(RADIUS MAC autentizace, např. UniFi). Náhrada za daloRADIUS pro školy a podobné sítě, kde se zařízení registrují
přes formulář (MS Forms) a správce potřebuje jen seznam povolených adres.

**Funkce**

- seznam povolených zařízení: hledání (i podle části MAC v libovolném zápisu), filtry podle třídy, náhodných adres,
  zařízení dlouho nepřipojených nebo počtu zařízení na e-mail, třídění podle sloupců, sloupec „naposledy připojeno“
- přidání / úprava / smazání, u zařízení historie posledních pokusů o připojení
- zamítnutá zařízení se statistikou po dnech a odkazem „povolit…“
- import Excelu z MS Forms (.xlsx), CSV nebo vloženého textu – najde sloupce s MAC sám, přidá jen nové adresy, nic nepřepisuje;
  rozpozná i divně zapsané adresy (malá písmena, `-` `.` mezery, vynechané nuly, O místo 0, I/l místo 1, adresu uprostřed věty)
- automatický import z Excelu na OneDrive každých 10 minut a upozornění e-mailem, když selže
- smazaná zařízení si pamatuje (import je znovu nepřidá) a umí je obnovit; hromadné mazání lze omezit jen na zařízení žáků
- noční promazání historie přihlášení a záloha seznamu, stav úloh a historie importů na stránce Údržba
- nápověda pro správce přímo v aplikaci

**Požadavky:** Linux (testováno na AlmaLinux 9 se SELinuxem), FreeRADIUS 3 s modulem `sql` nad MariaDB/MySQL, Apache, PHP 8 s rozšířeními `pdo_mysql`, `mbstring`, `xml`, `zip`, `curl`.

| Soubor | K čemu |
|---|---|
| `index.php`, `lib.php` | webové rozhraní |
| `config.sample.php` | vzor konfigurace → zkopírovat jako `config.php` (necommituje se) |
| `setup.sql` | vlastní tabulky aplikace, indexy, databázový uživatel `macadmin` |
| `udrzba.php` | denní údržba z cronu: promazání historie přihlášení, záloha seznamu |
| `import-forms.php` | automatický import z OneDrive z cronu |
| `macadmin.conf` | konfigurace Apache |
| `update.sh` | aktualizace na serveru z GitHubu |

---

## 1. FreeRADIUS a databáze

```
dnf install -y freeradius freeradius-mysql freeradius-utils mariadb-server
systemctl enable --now mariadb
mysql_secure_installation

mysql -u root -p -e "CREATE DATABASE radius CHARACTER SET utf8mb4;
  CREATE USER 'radius'@'localhost' IDENTIFIED BY 'HESLO_RADIUS';
  GRANT ALL ON radius.* TO 'radius'@'localhost';"
mysql -u root -p radius < /etc/raddb/mods-config/sql/main/mysql/schema.sql
```

Modul SQL: `ln -s ../mods-available/sql /etc/raddb/mods-enabled/sql` a v `/etc/raddb/mods-available/sql` nastavit

```
dialect = "mysql"
driver = "rlm_sql_${dialect}"
server = "localhost"
port = 3306
login = "radius"
password = "HESLO_RADIUS"
radius_db = "radius"
read_clients = yes
```

a ve stejném souboru **zakomentovat celý blok `tls { ... }`** uvnitř `mysql { }` (jinak modul hledá certifikáty a FreeRADIUS nenastartuje).

Klienti (wifi řadič / AP) v `/etc/raddb/clients.conf` – každý blok s jiným jménem, silné sdílené heslo (stejné jako ve wifi řadiči):

```
client wifi {
	ipaddr = 192.168.0.0/16
	secret = SILNE_SDILENE_HESLO
	require_message_authenticator = yes
}
```

Výchozí `sites-enabled/default` už `-sql` v sekcích authorize a post-auth obsahuje. Povolená MAC se zapisuje do `radcheck`
jako `username = AA:BB:CC:DD:EE:FF`, `Auth-Type := Accept` (lze změnit v `config.php`).

Certifikát pro modul EAP (bez něj FreeRADIUS nenastartuje, i když se EAP pro MAC nepoužívá):

```
cd /etc/raddb/certs && ./bootstrap
chown root:radiusd /etc/raddb/certs/* && chmod 640 /etc/raddb/certs/* && chmod 750 /etc/raddb/certs/bootstrap
restorecon -R /etc/raddb/certs
```

Kontrola a spuštění (má-li server veřejnou IP, povolte RADIUS ve firewallu jen z vnitřních sítí přes `--add-rich-rule`):

```
radiusd -XC
firewall-cmd --permanent --add-service=radius && firewall-cmd --reload
systemctl enable --now radiusd
radtest AA:BB:CC:DD:EE:FF AA:BB:CC:DD:EE:FF 127.0.0.1 0 testing123   # Access-Accept, je-li MAC povolená
```

Databázi stačí poslouchat jen místně: do `/etc/my.cnf.d/zz-local.cnf` dát `[mysqld]` a `bind-address = 127.0.0.1`.

## 2. Webové rozhraní

```
dnf install -y httpd mod_ssl php php-mysqlnd php-mbstring php-xml php-pecl-zip
mkdir -p /var/www/macadmin
cp index.php lib.php config.sample.php udrzba.php import-forms.php /var/www/macadmin/
cp /var/www/macadmin/config.sample.php /var/www/macadmin/config.php
cp macadmin.conf /etc/httpd/conf.d/        # upravte Require ip na svou správcovskou síť
sed 's/ZMENTE_HESLO/heslo-macadmin/' setup.sql | mysql -u root -p radius
```

Vyplňte `config.php` (viz níže), pak práva, SELinux a služby:

```
chown root:apache /var/www/macadmin/config.php && chmod 640 /var/www/macadmin/config.php
install -d -o apache -g apache -m 750 /var/lib/macadmin
restorecon -R /var/www/macadmin
echo 'date.timezone = Europe/Prague' > /etc/php.d/99-macadmin.ini
firewall-cmd --permanent --add-service=http --add-service=https && firewall-cmd --reload
systemctl enable --now php-fpm httpd
```

Rozhraní: `https://IP-SERVERU/macadmin/` (HTTP se přesměruje na HTTPS). Jak nastavit vlastní certifikát, aby prohlížeč nehlásil
„Nezabezpečeno“ a nabízel uložení hesla, je v aplikaci v Nápovědě.

### Nastavení (`config.php`)

| Volba | Význam |
|---|---|
| `app_name`, `wifi_name` | název aplikace v menu a název wifi sítě v textech |
| `db_dsn`, `db_user`, `db_pass` | připojení k databázi FreeRADIUS (uživatel `macadmin` ze `setup.sql`) |
| `admin_user`, `admin_pass_hash` | účet správce; hash: `php -r 'echo password_hash("heslo", PASSWORD_DEFAULT), PHP_EOL;'` |
| `radcheck_attribute`, `radcheck_op`, `radcheck_value` | jak se v `radcheck` zapisuje povolená MAC (výchozí `Auth-Type := Accept`) |
| `student_email_domain` | doména e-mailu žáků (např. `@zaci.skola.cz`); hromadné mazání pak smaže jen jejich zařízení, prázdné = bez omezení |
| `trida_regex` | jak z e-mailu žáka odvodit třídu, např. `/^(\d{4}\.[a-z])\./i` pro `2026.g.prijmeni@…` → `2026.g`; prázdné = neodvozovat |
| `forms_url` | sdílený odkaz na Excel s registracemi na OneDrive (pro automatický import) |
| `alert_email`, `alert_from` | komu a od koho poslat e-mail, když automatický import selže |
| `postauth_keep_days`, `rejected_days`, `session_timeout` | historie přihlášení (dní), výchozí období zamítnutých, odhlášení po neaktivitě (s) |

Chybějící volby (třeba po aktualizaci) ukáže stránka Údržba.

## 3. Cron

```
echo '15 2 * * * apache php /var/www/macadmin/udrzba.php >> /var/log/macadmin-udrzba.log 2>&1' > /etc/cron.d/macadmin
touch /var/log/macadmin-udrzba.log && chown apache /var/log/macadmin-udrzba.log
```

Noční údržba maže jen historii přihlášení starší než `postauth_keep_days` (povolená zařízení zůstávají) a ukládá zálohu
seznamu do `/var/lib/macadmin/zalohy` (CSV, 30 dní; obnova nahráním v Import / export). Cron pro import z OneDrive viz níže
(oba doplní i `macadmin-update`).

## 4. Registrace z MS Forms

Nejjednodušší je v **Import / export** nahrát celý stažený Excel z Forms – kdykoli znovu, přidají se jen nové adresy.

**Automaticky (do 10 minut):** Excel propojený s Forms (tlačítko „Otevřít v Excelu“) dostává nové odpovědi až ve chvíli,
kdy ho někdo otevře, proto odpovědi zapisuje tok v Power Automate do obyčejného Excelu:

1. V OneDrive nový Excel, v 1. řádku `Čas | E-mail | Jméno | MAC 1 | MAC 2`, označit a *Vložit → Tabulka* (s hlavičkou).
2. Power Automate → *Automatizovaný tok cloudu*: *Forms – Když se odešle nová odpověď* → *Forms – Získat podrobnosti o odpovědi*
   → *Office 365 Users – Získat profil uživatele (V2)* (uživatel = e-mail respondenta) → *Excel Online (Business) – Přidá řádek do tabulky*
   (Čas = datum odeslání, E-mail, Jméno = zobrazované jméno, MAC 1 / MAC 2 = odpovědi s MAC).
3. Excel sdílet „Kdokoli s odkazem – může zobrazit“, odkaz do `forms_url`.
4. Povolit webovému serveru přístup ven (tlačítko *Načíst z OneDrive*) a cron:

```
setsebool -P httpd_can_network_connect 1
echo '*/10 * * * * apache php /var/www/macadmin/import-forms.php >> /var/log/macadmin-import.log 2>&1' > /etc/cron.d/macadmin-import
touch /var/log/macadmin-import.log && chown apache /var/log/macadmin-import.log
```

Upozornění e-mailem při selhání importu: vyplnit `alert_email`, nainstalovat a spustit postfix
(`dnf install -y postfix && systemctl enable --now postfix`) a vyzkoušet
`sudo -u apache php /var/www/macadmin/import-forms.php --test-mail`. Když e-mail nedorazí, podívejte se do `journalctl -u postfix`
– pošta může zprávy přímo ze serveru odmítat, pak je potřeba v postfixu nastavit `relayhost`.

Podrobnosti (co může tok rozbít, jak ho otestovat) jsou v aplikaci v Nápovědě.

## 5. Aktualizace

Jednou nainstalovat skript:

```
curl -fsSL https://raw.githubusercontent.com/vladava/macadmin/main/update.sh -o /usr/local/sbin/macadmin-update
chmod 700 /usr/local/sbin/macadmin-update
```

Pak při každé aktualizaci jako root `macadmin-update`. Skript zjistí poslední verzi na GitHubu a vypíše změny, zkontroluje
syntaxi PHP, zazálohuje web do `/root/macadmin-zalohy`, nahraje nové soubory (`config.php` nikdy nepřepisuje), konfiguraci
Apache aktualizuje jen neupravenou, při změně `setup.sql` upraví databázi (zeptá se na heslo root do MariaDB), doplní cron
a aktualizuje sám sebe. `--force` nainstaluje znovu i stejnou verzi. Z vlastního forku: `MACADMIN_REPO=uzivatel/repozitar macadmin-update`.

## 6. Přechod z daloRADIUS (volitelné)

Na starém serveru zjistit, jak jsou MAC zapsané a co je v konfiguraci změněné:

```
mysql -u root -p radius -e "SELECT attribute, op, value, COUNT(*) FROM radcheck GROUP BY 1,2,3; SELECT COUNT(*) FROM nas;"
rpm -Va 'freeradius*' | grep raddb        # konfiguracni soubory zmenene oproti balicku
```

Export jen potřebných tabulek (bez historie) a jejich import do nové databáze po načtení `schema.sql`:

```
mysqldump -u root -p --no-create-info --complete-insert radius radcheck radreply radgroupcheck radgroupreply radusergroup nas > radius-data.sql
mysql -u root -p radius < radius-data.sql
```

Konfiguraci FreeRADIUS nekopírujte celou – na novém serveru nechte výchozí a přeneste jen změněné hodnoty (klienti
z `clients.conf`, přístup k DB). Po instalaci rozhraní spusťte v Údržbě **Opravit zápis** (sjednotí nestandardně zapsané MAC)
a `setup.sql` odstraní duplicitní záznamy. Pak ve wifi řadiči přepněte IP RADIUS serveru; návrat je jen vrácení původní IP.

## Licence

[MIT](LICENSE) – kdokoli může aplikaci zdarma používat, upravovat i šířit, jen musí zachovat informaci o autorovi a licenci. Bez záruky.
