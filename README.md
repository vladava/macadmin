# Přesun RADIUS na AlmaLinux 9 + správa MAC adres (macadmin)

Náhrada za daloRADIUS: jednoduché webové rozhraní pro povolené MAC adresy žákovské wifi GFP.

Obsah balíčku:

| Soubor | K čemu |
|---|---|
| `index.php`, `lib.php` | webové rozhraní |
| `config.sample.php` | vzor konfigurace → zkopírovat jako `config.php` |
| `setup.sql` | tabulka `mac_info`, indexy, databázový uživatel `macadmin` |
| `udrzba.php` | denní promazání historie přihlášení (cron) |
| `macadmin.conf` | konfigurace Apache |

Funkce rozhraní: seznam zařízení s hledáním a filtrem podle třídy, přidání / úprava / smazání (MAC lze zadat v libovolném zápisu), zamítnutá zařízení s odkazem „povolit…“ a označením náhodných adres, import celého Excelu z MS Forms (.xlsx), CSV nebo vloženého textu (přidá jen nové MAC, nic nepřepisuje), export CSV, smazání celé třídy, údržba (statistiky, promazání `radpostauth`, oprava nestandardního zápisu MAC).

---

## 1. Zjistit na STARÉM serveru (192.168.0.100)

```
mysql -u radius -p radius -e "SHOW TABLES; SELECT attribute, op, value, COUNT(*) FROM radcheck GROUP BY 1,2,3; SELECT COUNT(*) FROM nas;"
```

- Hodnoty `attribute / op / value` musí odpovídat nastavení `radcheck_*` v `config.php` (výchozí `Auth-Type := Accept`).
- Pokud je tabulka `nas` prázdná, AP se berou z `clients.conf`.

Export dat (jen potřebné tabulky, bez 19 mil. řádků historie):

```
mysqldump -u radius -p --no-create-info --complete-insert radius radcheck radreply radgroupcheck radgroupreply radusergroup nas > radius-data.sql
```

Jména z daloRADIUS (pokud tabulka `userinfo` existuje) jako CSV pro pozdější import – jen k MAC, které jsou v `radcheck` (v `userinfo` zůstávají i smazaná zařízení a import by je znovu povolil):

```
mysql -u radius -p radius -B -N -e "SELECT u.username, TRIM(CONCAT(IFNULL(u.firstname,''),' ',IFNULL(u.lastname,''))), IFNULL(u.department,''), REPLACE(IFNULL(u.notes,''),';',',') FROM userinfo u JOIN radcheck r ON r.username = u.username AND r.attribute = 'Auth-Type'" | sed 's/\t/;/g' > jmena.csv
```

Zkopírujte si také `/etc/raddb/clients.conf` (obsahuje sdílené heslo pro AP).

Oba soubory a `clients.conf` přeneste na nový server (např. přes Bitvise SFTP).

## 2. NOVÝ server – instalace

```
dnf install -y freeradius freeradius-mysql freeradius-utils mariadb-server httpd mod_ssl php php-mysqlnd php-mbstring php-xml php-pecl-zip
systemctl enable --now mariadb
mysql_secure_installation
```

Databáze s čerstvým schématem FreeRADIUS 3.0.27 a import dat:

```
mysql -u root -p -e "CREATE DATABASE radius CHARACTER SET utf8mb4; CREATE USER 'radius'@'localhost' IDENTIFIED BY 'HESLO_RADIUS'; GRANT ALL ON radius.* TO 'radius'@'localhost'; FLUSH PRIVILEGES;"
mysql -u root -p radius < /etc/raddb/mods-config/sql/main/mysql/schema.sql
mysql -u root -p radius < radius-data.sql
```

## 3. NOVÝ server – FreeRADIUS

Zapnout modul SQL:

```
ln -s /etc/raddb/mods-available/sql /etc/raddb/mods-enabled/sql
```

V `/etc/raddb/mods-available/sql` nastavit:

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

a ve stejném souboru **zakomentovat celý blok `tls { ... }`** uvnitř sekce `mysql { }` – jinak modul hledá neexistující certifikáty a FreeRADIUS nenastartuje.

Klienti (AP): přeneste z `clients.conf` starého serveru bloky pro `192.168.0.0/16` a `172.17.0.0/16` se stejným `secret`. Každý blok musí mít **jiné jméno** (na starém serveru oba měly `private-network-1`):

```
client skolni-sit {
	ipaddr = 192.168.0.0/16
	secret = STEJNE_HESLO_JAKO_NA_STAREM
}
client wifi-site {
	ipaddr = 172.17.0.0/16
	secret = STEJNE_HESLO_JAKO_NA_STAREM
}
```

Výchozí `sites-enabled/default` už `-sql` v sekcích authorize a post-auth obsahuje, není potřeba nic měnit.
Pro kontrolu porovnejte se starým serverem: `diff` starého a nového `sites-enabled/default`.

Kontrola konfigurace, firewall, spuštění:

```
radiusd -XC
firewall-cmd --permanent --add-service=radius
firewall-cmd --reload
systemctl enable --now radiusd
```

Test jednou povolenou MAC (z tabulky radcheck):

```
radtest D8:0B:9A:05:B7:32 D8:0B:9A:05:B7:32 127.0.0.1 0 testing123
```

Očekávaný výsledek: `Received Access-Accept`. Pro neznámou MAC: `Access-Reject`.

## 4. NOVÝ server – webové rozhraní

```
mkdir -p /var/www/macadmin
cp index.php lib.php config.sample.php udrzba.php /var/www/macadmin/
cp /var/www/macadmin/config.sample.php /var/www/macadmin/config.php
cp macadmin.conf /etc/httpd/conf.d/
```

Heslo do databáze pro rozhraní – v `setup.sql` nahraďte `ZMENTE_HESLO` a spusťte:

```
mysql -u root -p radius < setup.sql
```

Hash hesla správce:

```
php -r 'echo password_hash("vase-heslo", PASSWORD_DEFAULT), PHP_EOL;'
```

Vyplňte `config.php` (heslo k DB a hash), práva a SELinux:

```
chown root:apache /var/www/macadmin/config.php
chmod 640 /var/www/macadmin/config.php
setsebool -P httpd_can_network_connect_db 1
restorecon -R /var/www/macadmin
firewall-cmd --permanent --add-service=http --add-service=https
firewall-cmd --reload
systemctl enable --now httpd
```

Rozhraní: `https://IP-NOVEHO-SERVERU/macadmin/` (přístup jen z 192.168.0.0/24, viz `macadmin.conf`; HTTP se přesměruje na HTTPS).

Registrace z MS Forms: v rozhraní **Import / export** nahrajte pokaždé celý stažený Excel z Forms. Sloupce MAC, jméno, třída a e-mail se najdou podle záhlaví, přidají se jen nové adresy a vypíšou se neplatné a náhodné MAC.

Denní údržba – soubor `/etc/cron.d/macadmin`:

```
15 2 * * * apache php /var/www/macadmin/udrzba.php >> /var/log/macadmin-udrzba.log 2>&1
```

a vytvořit log: `touch /var/log/macadmin-udrzba.log && chown apache /var/log/macadmin-udrzba.log`

## 5. Přepnutí

1. V UDM: Nastavení → Profily → RADIUS → „Freeradius Linux“ → změnit IP na nový server.
2. Ověřit, že se žáci připojují (v rozhraní Údržba: „Povoleno za 24 h“ roste).
3. Návrat: v profilu vrátit IP `192.168.0.100`.
4. Po pár dnech bez potíží starý server vypnout.

Po instalaci rozhraní spusťte v Údržbě **Opravit zápis** – sjednotí nestandardně zapsané MAC (na starém serveru jich bylo 41). Teprve potom importujte `jmena.csv`, jinak se jména k nestandardně zapsaným MAC nespárují a vzniknou duplicitní záznamy.
