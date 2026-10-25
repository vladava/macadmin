# macadmin – správa MAC adres pro FreeRADIUS

Náhrada za daloRADIUS. Jednoduchá PHP 8 aplikace (bez frameworku) nad databází FreeRADIUS (MariaDB). Repozitář je veřejný
a obecný – nic specifického pro konkrétní školu (název, domény, e-maily, IP) nepatří do kódu ani dokumentace, ale do `config.php`.

## Kontext
- Wifi (např. UniFi) ověřuje zařízení podle MAC adresy přes FreeRADIUS (RADIUS MAC auth).
- FreeRADIUS čte povolené MAC z tabulky `radcheck` (username = MAC ve tvaru `AA:BB:CC:DD:EE:FF`, `Auth-Type := Accept`). `setup.sql` přidává unikátní index `(username, attribute)`, aby v ní nevznikaly duplicity.
- Historie přihlášení je v `radpostauth` (FreeRADIUS ji zapisuje sám) → promazávání po `postauth_keep_days` (90).
- Jméno, třída a poznámka (e-mail) k MAC jsou ve vlastní tabulce `mac_info` (FreeRADIUS ji nečte).
- Třídu umí import odvodit z e-mailu žáka podle `trida_regex` (např. `2026.g` z `2026.g.prijmeni@…`); třídy ve tvaru rok.písmeno jde filtrovat i podle písmene.
- Zařízení se registrují přes MS Forms; import (ruční nahrání Excelu nebo automaticky z OneDrive přes `forms_url`, plněný tokem Power Automate) přidá jen nové MAC a nic nepřepisuje.
- Typické nasazení: AlmaLinux 9, FreeRADIUS 3.0.27, Apache + PHP 8 (php-fpm), SELinux zapnutý.
- Jediný uživatel rozhraní je správce (jeden účet v `config.php`, heslo jako hash).

## Struktura
- `index.php` – všechny stránky (`?p=prehled|zarizeni|pridat|zamitnuta|smazana|import|udrzba|napoveda|login|logout`; `prehled` = statistiky, úvodní stránka po přihlášení); nápověda pro správce je stránka `napoveda` – při změně chování ji aktualizovat
- `lib.php` – DB, přihlášení, CSRF, normalizace MAC, čtení XLSX (ZipArchive + SimpleXML, bez knihoven), import, vzhled
- `udrzba.php` – CLI skript pro cron (promazání `radpostauth`, noční záloha seznamu do `/var/lib/macadmin/zalohy`)
- `import-forms.php` – CLI skript pro cron (import Excelu z OneDrive podle `forms_url`, upozornění na `alert_email`)
- `setup.sql` – tabulky `mac_info`, `mac_smazane` (smazané MAC i s údaji – import je znovu nepřidá, stránka Smazaná je obnoví), `macadmin_stav` (stav importu a zálohy pro Údržbu), `import_historie` (importy, které přidaly zařízení) a `statistika` (souhrny uzavřených dnů/týdnů/měsíců – přežijí promazání `radpostauth`; ukládá je `stats_series()` a noční `udrzba.php` před promazáním), indexy, DB uživatel `macadmin`
- `config.sample.php` – všechny volby s popisem; nová volba vždy sem (Údržba ukáže, které v `config.php` chybí) a kód musí mít rozumnou výchozí hodnotu
- `macadmin.conf` – Apache (přístup jen ze správcovské sítě)
- `update.sh` – aktualizace na serveru z GitHubu (`/usr/local/sbin/macadmin-update`): záloha, nové soubory, Apache, setup.sql, cron; seznam souborů aplikace je v `APP_FILES`
- `backup.sh` – kompletní záloha serveru do jednoho `.tgz` (`/usr/local/sbin/macadmin-zaloha`; instaluje a aktualizuje ho `update.sh`): soubory aplikace a konfigurace, dump DB `radius` a její uživatelé, `OBNOVA.txt`; při přidání souboru/konfigurace na server doplnit do `PATHS`
- `README.md` – instalace, nastavení, Forms/Power Automate, přechod z daloRADIUS

## Zásady
- UI je česky s diakritikou; výstupy skriptů v konzoli a komentáře v kódu bez diakritiky.
- Všechny SQL dotazy přes PDO prepared statements, všechny POST formuláře s CSRF tokenem, výstup přes `h()`.
- MAC vždy normalizovat přes `normalize_mac()` (zvládá různé zápisy, zaměnitelné znaky i MAC uprostřed textu; z běžného textu MAC neposkládá); náhodné (soukromé) MAC poznat přes `is_random_mac()`.
- Hromadné mazání (Smazat vybrané / všech nalezených) maže jen zařízení žáků (`poznamka` končí `student_email_domain`; prázdné = bez omezení), ostatní jen vypíše; jednotlivé zařízení z „upravit“ jde smazat vždy. Noční údržba zařízení nemaže, jen historii přihlášení.
- `config.php` se necommituje (je v .gitignore), vzor je `config.sample.php`.
- Verze aplikace (`APP_VERSION` v `lib.php`, zobrazuje se v patičce) se doplní sama přes `export-subst` v `.gitattributes` při stažení tarballu z GitHubu – ručně ji neměnit.

## Test lokálně
MariaDB se schématem FreeRADIUS + `setup.sql`, pak `php -S 127.0.0.1:8099` v adresáři projektu.

## Git
- Změny po otestování commitovat a pushovat rovnou do `main` (bez vedlejší větve a PR).
