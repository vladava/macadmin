# macadmin – správa MAC adres pro žákovskou wifi GFP

Náhrada za daloRADIUS. Jednoduchá PHP 8 aplikace (bez frameworku) nad databází FreeRADIUS (MariaDB).

## Kontext
- Wifi síť GFP (UniFi, UDM Pro Max) ověřuje žáky podle MAC adresy přes FreeRADIUS (RADIUS MAC auth, profil „Freeradius Linux“ v UDM).
- FreeRADIUS čte povolené MAC z tabulky `radcheck` (username = MAC ve tvaru `AA:BB:CC:DD:EE:FF`, `Auth-Type := Accept`).
- Historie přihlášení je v `radpostauth` (FreeRADIUS ji zapisuje sám), na starém serveru měla 19 mil. řádků → promazávání po 90 dnech.
- Jméno, třída a poznámka k MAC jsou ve vlastní tabulce `mac_info` (FreeRADIUS ji nečte).
- Žáci registrují MAC přes MS Forms; správce nahrává celý export z Forms (.xlsx) – import přidá jen nové MAC a nic nepřepisuje.
- Cílový server: AlmaLinux 9, FreeRADIUS 3.0.27, Apache + PHP 8, SELinux zapnutý. Starý server: CentOS 7, FreeRADIUS 3.0.13 (k vypnutí).
- Jediný uživatel rozhraní je správce (jeden účet v `config.php`, heslo jako hash).

## Struktura
- `index.php` – všechny stránky (`?p=zarizeni|pridat|zamitnuta|import|udrzba|login|logout`)
- `lib.php` – DB, přihlášení, CSRF, normalizace MAC, čtení XLSX (ZipArchive + SimpleXML, bez knihoven), import, vzhled
- `udrzba.php` – CLI skript pro cron (promazání `radpostauth`)
- `setup.sql` – tabulka `mac_info`, indexy, DB uživatel `macadmin`
- `macadmin.conf` – Apache (přístup jen z 192.168.0.0/24)
- `README.md` – postup přesunu RADIUS ze starého serveru a instalace

## Zásady
- UI je česky s diakritikou; výstupy skriptů v konzoli a komentáře v kódu bez diakritiky.
- Všechny SQL dotazy přes PDO prepared statements, všechny POST formuláře s CSRF tokenem, výstup přes `h()`.
- MAC vždy normalizovat přes `normalize_mac()`; náhodné (soukromé) MAC poznat přes `is_random_mac()`.
- `config.php` se necommituje (je v .gitignore), vzor je `config.sample.php`.

## Test lokálně
MariaDB se schématem FreeRADIUS + `setup.sql`, pak `php -S 127.0.0.1:8099` v adresáři projektu.

## Git
- Změny po otestování commitovat a pushovat rovnou do `main` (bez vedlejší větve a PR).
