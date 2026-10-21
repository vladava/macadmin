<?php
declare(strict_types=1);

// Datum a kod commitu; doplni git archive (export-subst v .gitattributes), tedy i tarball z GitHubu pro macadmin-update.
const APP_VERSION = '$Format:%cs %h$';
const APP_AUTHOR = 'Vladimír Vais';

function cfg(?string $key = null)
{
    static $c = null;
    if ($c === null) {
        $file = __DIR__ . '/config.php';
        if (!is_file($file)) {
            http_response_code(500);
            exit('Chybi config.php (zkopirujte config.sample.php a upravte).');
        }
        $c = require $file;
    }
    return $key === null ? $c : ($c[$key] ?? null);
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(cfg('db_dsn'), cfg('db_user'), cfg('db_pass'), [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}

/** Prevede libovolny zapis MAC na AA:BB:CC:DD:EE:FF, nebo vrati null. Pismeno O bere jako nulu (casty preklep). */
function normalize_mac(string $s): ?string
{
    $hex = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', str_ireplace('O', '0', $s)));
    if (strlen($hex) !== 12) {
        return null;
    }
    return implode(':', str_split($hex, 2));
}

/** Rok nastupu a pismeno tridy ze skolniho e-mailu zaka (2026.g.prijmeni@stu.gfp.cz -> 2026.g), jinak ''. */
function trida_z_emailu(string $email): string
{
    return preg_match('/^(\d{4})\.([a-z])\./i', trim($email), $m) ? $m[1] . '.' . strtolower($m[2]) : '';
}

/** Nahodna (soukroma) MAC: druhy znak 2, 6, A nebo E. */
function is_random_mac(string $mac): bool
{
    $hex = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', $mac));
    return strlen($hex) >= 2 && in_array($hex[1], ['2', '6', 'A', 'E'], true);
}

function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/* ---------- Session, prihlaseni, CSRF ---------- */

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name('macadmin');
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Strict',
        'secure'   => !empty($_SERVER['HTTPS']),
    ]);
    session_start();
    $t = (int)cfg('session_timeout');
    if ($t <= 0) {
        $t = 3600; // chybi nebo nesmyslna hodnota v config.php
    }
    if (isset($_SESSION['last']) && time() - $_SESSION['last'] > $t) {
        $_SESSION = [];
        session_regenerate_id(true);
    }
    $_SESSION['last'] = time();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
}

function is_logged_in(): bool
{
    return !empty($_SESSION['admin']);
}

function try_login(string $user, string $pass): bool
{
    $ok = hash_equals((string)cfg('admin_user'), $user)
        && password_verify($pass, (string)cfg('admin_pass_hash'));
    if ($ok) {
        session_regenerate_id(true);
        $_SESSION['admin'] = true;
        return true;
    }
    sleep(2); // zpomaleni hadani hesla
    return false;
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . h($_SESSION['csrf']) . '">';
}

function check_csrf(): void
{
    if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('Neplatny formular (CSRF). Obnovte stranku a zkuste to znovu.');
    }
}

function flash(string $msg, string $type = 'ok'): void
{
    $_SESSION['flash'][] = [$type, $msg];
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

/* ---------- Prace s MAC adresami ---------- */

/** Vrati zapis MAC presne tak, jak je ulozen v radcheck (muze byt nestandardni), nebo null. */
function find_mac(string $mac): ?string
{
    $st = db()->prepare('SELECT username FROM radcheck WHERE username = ? AND attribute = ? LIMIT 1');
    $st->execute([$mac, cfg('radcheck_attribute')]);
    $u = $st->fetchColumn();
    return $u === false ? null : (string)$u;
}

function mac_exists(string $mac): bool
{
    return find_mac($mac) !== null;
}

/**
 * Prejmenuje zaznam $old (presny zapis vcetne velikosti pismen) na $new v radcheck i mac_info.
 * Pokud $new uz v radcheck je, stary zaznam smaze a udaje z mac_info slouci (doplni prazdna pole).
 * Vraci true, kdyz doslo ke slouceni. Volat uvnitr transakce.
 */
function rename_mac(string $old, string $new): bool
{
    $pdo = db();
    $st = $pdo->prepare('SELECT 1 FROM radcheck WHERE BINARY username = ? LIMIT 1');
    $st->execute([$new]);
    $merged = (bool)$st->fetchColumn();
    if ($merged) {
        $pdo->prepare('DELETE FROM radcheck WHERE BINARY username = ?')->execute([$old]);
    } else {
        $pdo->prepare('UPDATE radcheck SET username = ? WHERE BINARY username = ?')->execute([$new, $old]);
    }
    $st = $pdo->prepare('SELECT jmeno, trida, poznamka, vytvoreno FROM mac_info WHERE BINARY mac = ?');
    $st->execute([$old]);
    if ($info = $st->fetch()) {
        $pdo->prepare('DELETE FROM mac_info WHERE BINARY mac = ?')->execute([$old]);
        $pdo->prepare(
            "INSERT INTO mac_info (mac, jmeno, trida, poznamka, vytvoreno) VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
               jmeno = IF(jmeno = '', VALUES(jmeno), jmeno),
               trida = IF(trida = '', VALUES(trida), trida),
               poznamka = IF(poznamka = '', VALUES(poznamka), poznamka)"
        )->execute([$new, $info['jmeno'], $info['trida'], $info['poznamka'], $info['vytvoreno']]);
    }
    return $merged;
}

/** Sjednoti zapis vsech MAC v radcheck na AA:BB:CC:DD:EE:FF. Vraci [opraveno, slouceno duplicit]. */
function fix_mac_format(): array
{
    $pdo = db();
    $st = $pdo->prepare('SELECT username FROM radcheck WHERE attribute = ?');
    $st->execute([cfg('radcheck_attribute')]);
    $fixed = $merged = 0;
    foreach (array_unique($st->fetchAll(PDO::FETCH_COLUMN)) as $u) {
        $n = normalize_mac((string)$u);
        if ($n === null || $n === $u) {
            continue;
        }
        $pdo->beginTransaction();
        try {
            rename_mac((string)$u, $n) ? $merged++ : $fixed++;
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
    return [$fixed, $merged];
}

/**
 * Prida nebo aktualizuje zarizeni. $oldMac je puvodni zapis v radcheck (i nestandardni).
 * Vraci 'added' | 'updated'.
 */
function save_device(string $mac, string $jmeno, string $trida, string $poznamka, ?string $oldMac = null): string
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $result = 'updated';
        if ($oldMac !== null && $oldMac !== $mac) {
            // zmena MAC nebo oprava zapisu: prejmenovat zaznam
            rename_mac($oldMac, $mac);
        }
        if (!mac_exists($mac)) {
            $pdo->prepare('INSERT INTO radcheck (username, attribute, op, value) VALUES (?, ?, ?, ?)')
                ->execute([$mac, cfg('radcheck_attribute'), cfg('radcheck_op'), cfg('radcheck_value')]);
            $result = 'added';
        }
        $pdo->prepare(
            'INSERT INTO mac_info (mac, jmeno, trida, poznamka) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE jmeno = VALUES(jmeno), trida = VALUES(trida), poznamka = VALUES(poznamka)'
        )->execute([$mac, $jmeno, $trida, $poznamka]);
        $pdo->prepare('DELETE FROM mac_smazane WHERE mac = ?')->execute([$mac]);
        $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Smaze zarizeni a zapamatuje si je i s udaji (mac_smazane), aby je import ze souboru Forms znovu nepridal
 * a aby se dala obnovit. Jedno volani = jedna davka se stejnym casem smazani.
 */
function delete_devices(array $macs): int
{
    if (!$macs) {
        return 0;
    }
    $pdo = db();
    $in = implode(',', array_fill(0, count($macs), '?'));
    $pdo->beginTransaction();
    try {
        $now = $pdo->query('SELECT NOW()')->fetchColumn();
        $info = [];
        $st = $pdo->prepare("SELECT mac, jmeno, trida, poznamka FROM mac_info WHERE mac IN ($in)");
        $st->execute($macs);
        foreach ($st as $r) {
            $info[normalize_mac((string)$r['mac']) ?? $r['mac']] = $r;
        }
        $st = $pdo->prepare("DELETE FROM radcheck WHERE username IN ($in)");
        $st->execute($macs);
        $n = $st->rowCount();
        $pdo->prepare("DELETE FROM mac_info WHERE mac IN ($in)")->execute($macs);
        $mark = $pdo->prepare(
            'INSERT INTO mac_smazane (mac, smazano, jmeno, trida, poznamka) VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE smazano = VALUES(smazano), jmeno = VALUES(jmeno), trida = VALUES(trida), poznamka = VALUES(poznamka)'
        );
        foreach ($macs as $m) {
            if (($norm = normalize_mac((string)$m)) !== null) {
                $i = $info[$norm] ?? ['jmeno' => '', 'trida' => '', 'poznamka' => ''];
                $mark->execute([$norm, $now, $i['jmeno'], $i['trida'], $i['poznamka']]);
            }
        }
        $pdo->commit();
        return $n;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** Obnovi smazana zarizeni i s ulozenymi udaji. Vraci pocet obnovenych. */
function restore_devices(array $macs): int
{
    $st = db()->prepare('SELECT mac, jmeno, trida, poznamka FROM mac_smazane WHERE mac = ?');
    $n = 0;
    foreach ($macs as $m) {
        $st->execute([(string)$m]);
        if ($r = $st->fetch()) {
            save_device($r['mac'], $r['jmeno'], $r['trida'], $r['poznamka']); // smaze i zaznam v mac_smazane
            $n++;
        }
    }
    return $n;
}

/** Zapomene smazana zarizeni - pristi import z Forms je zase prida. */
function forget_deleted(array $macs): int
{
    if (!$macs) {
        return 0;
    }
    $st = db()->prepare('DELETE FROM mac_smazane WHERE mac IN (' . implode(',', array_fill(0, count($macs), '?')) . ')');
    $st->execute(array_values($macs));
    return $st->rowCount();
}

/** Stav automaticke ulohy (import, zaloha) z tabulky macadmin_stav. */
function state_get(string $key): array
{
    $st = db()->prepare('SELECT hodnota FROM macadmin_stav WHERE klic = ?');
    $st->execute([$key]);
    $v = $st->fetchColumn();
    return $v === false ? [] : (json_decode((string)$v, true) ?: []);
}

function state_set(string $key, array $value): void
{
    db()->prepare('INSERT INTO macadmin_stav (klic, hodnota) VALUES (?, ?) ON DUPLICATE KEY UPDATE hodnota = VALUES(hodnota)')
        ->execute([$key, json_encode($value, JSON_UNESCAPED_UNICODE)]);
}

/** Zapise do historie import, ktery pridal aspon jedno zarizeni. */
function log_import(string $zdroj, array $s): void
{
    if ($s['added'] > 0) {
        db()->prepare('INSERT INTO import_historie (zdroj, pridano, doplneno, zarizeni) VALUES (?, ?, ?, ?)')
            ->execute([$zdroj, $s['added'], $s['filled'], implode("\n", $s['new'])]);
    }
}

/** Povolena zarizeni s udaji - pro export a zalohu. */
function export_rows(): array
{
    $st = db()->prepare(
        'SELECT r.username AS mac, i.jmeno, i.trida, i.poznamka FROM radcheck r
         LEFT JOIN mac_info i ON i.mac = r.username WHERE r.attribute = ? ORDER BY i.trida, i.jmeno'
    );
    $st->execute([cfg('radcheck_attribute')]);
    return $st->fetchAll();
}

/** Zapise CSV (format exportu, lze ho zase naimportovat) do otevreneho souboru. */
function write_csv($out, array $rows): void
{
    fwrite($out, "\xEF\xBB\xBF"); // BOM pro Excel
    fputcsv($out, ['mac', 'jmeno', 'trida', 'poznamka'], ';', '"', '\\');
    foreach ($rows as $r) {
        fputcsv($out, [$r['mac'], $r['jmeno'], $r['trida'], $r['poznamka']], ';', '"', '\\');
    }
}

/** Nocni zaloha seznamu do $dir (mac-RRRR-MM-DD.csv), starsi nez $keepDays smaze. Vraci [soubor, pocet]. */
function backup_devices(string $dir, int $keepDays = 30): array
{
    if (!is_dir($dir) && !mkdir($dir, 0750, true)) {
        throw new RuntimeException("Nelze vytvorit adresar $dir");
    }
    $rows = export_rows();
    $file = $dir . '/mac-' . date('Y-m-d') . '.csv';
    $out = fopen($file, 'w');
    if ($out === false) {
        throw new RuntimeException("Nelze zapsat $file");
    }
    write_csv($out, $rows);
    fclose($out);
    foreach (glob($dir . '/mac-*.csv') ?: [] as $old) {
        if (filemtime($old) < time() - $keepDays * 86400) {
            unlink($old);
        }
    }
    state_set('zaloha', ['cas' => date('Y-m-d H:i:s'), 'soubor' => $file, 'pocet' => count($rows)]);
    return [$file, count($rows)];
}

/** Smaze stare zaznamy z radpostauth po davkach. Vraci pocet smazanych radku. */
function prune_postauth(int $days, int $batch = 20000): int
{
    $total = 0;
    $st = db()->prepare('DELETE FROM radpostauth WHERE authdate < NOW() - INTERVAL ? DAY LIMIT ' . (int)$batch);
    do {
        $st->execute([$days]);
        $n = $st->rowCount();
        $total += $n;
    } while ($n === $batch);
    return $total;
}

/**
 * Filtr seznamu zarizeni z parametru (GET nebo POST): q, t, l, r, n, m.
 * Vraci hodnoty filtru, podminky WHERE a jejich parametry; 'active' = je zadany aspon jeden filtr.
 */
function device_filter(array $src): array
{
    $q = trim((string)($src['q'] ?? ''));
    $t = trim((string)($src['t'] ?? ''));
    $l = (string)($src['l'] ?? '');
    $l = preg_match('/^[a-z]$/', $l) ? $l : ''; // pismeno tridy za rokem nastupu (2026.g)
    $rnd = !empty($src['r']);
    $nd = max(0, min((int)($src['n'] ?? 0), 3650)); // neprihlaseno alespon nd dni
    $m = max(0, min((int)($src['m'] ?? 0), 50)); // aspon m zarizeni na jeden e-mail (poznamku)
    $where = ['r.attribute = ?'];
    $args = [cfg('radcheck_attribute')];
    if ($q !== '') {
        $like = '%' . addcslashes($q, '%_\\') . '%';
        $cond = ['r.username LIKE ?', 'i.jmeno LIKE ?', 'i.poznamka LIKE ?'];
        $args = array_merge($args, [$like, $like, $like]);
        // cast MAC v jinem zapisu (napr. 0b9a05b7) hledat i bez oddelovacu - jen kdyz dotaz vypada jako MAC
        $hex = preg_replace('/[^0-9A-Fa-f]/', '', $q);
        if (strlen($hex) >= 4 && preg_match('/^[0-9A-Fa-f:.\s-]+$/', $q)) {
            $cond[] = "REPLACE(REPLACE(REPLACE(r.username, ':', ''), '-', ''), '.', '') LIKE ?";
            $args[] = '%' . $hex . '%';
        }
        $where[] = '(' . implode(' OR ', $cond) . ')';
    }
    if ($t !== '') {
        $where[] = 'i.trida = ?';
        $args[] = $t;
    }
    if ($l !== '') {
        $where[] = "i.trida LIKE ?";
        $args[] = '____.' . $l;
    }
    if ($rnd) {
        // stejne pravidlo jako is_random_mac(): druhy hexa znak 2, 6, A nebo E
        $where[] = "UPPER(SUBSTRING(REPLACE(REPLACE(REPLACE(r.username, ':', ''), '-', ''), '.', ''), 2, 1)) IN ('2', '6', 'A', 'E')";
    }
    if ($nd > 0) {
        $where[] = "NOT EXISTS (SELECT 1 FROM radpostauth p WHERE p.username = r.username
                     AND p.reply = 'Access-Accept' AND p.authdate > NOW() - INTERVAL ? DAY)";
        $args[] = $nd;
    }
    if ($m > 1) {
        $where[] = "i.poznamka IN (SELECT poznamka FROM mac_info WHERE poznamka <> '' GROUP BY poznamka HAVING COUNT(*) >= ?)";
        $args[] = $m;
    }
    return [
        'q' => $q, 't' => $t, 'l' => $l, 'r' => $rnd, 'n' => $nd, 'm' => $m, 'where' => $where, 'args' => $args,
        'active' => $q !== '' || $t !== '' || $l !== '' || $rnd || $nd > 0 || $m > 1,
    ];
}

/* ---------- Import (Excel z MS Forms, CSV, vlozeny text) ---------- */

/**
 * Precte list s daty ze souboru XLSX jako pole radku (bez knihoven, jen ZipArchive).
 * Bere list s odpovedmi Forms (definedName ...ResponseSheet), jinak prvni viditelny neprazdny list.
 */
function read_xlsx(string $path): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('Chybí PHP rozšíření zip (dnf install php-pecl-zip).');
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('Soubor nejde otevřít jako XLSX.');
    }
    $shared = [];
    if (($xml = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
        $sx = simplexml_load_string($xml);
        foreach ($sx->si as $si) {
            // text muze byt primo v <t> nebo rozdeleny do <r><t>
            $t = isset($si->t) ? (string)$si->t : '';
            foreach ($si->r as $r) {
                $t .= (string)$r->t;
            }
            $shared[] = $t;
        }
    }
    // listy v poradi sesitu; cesty podle workbook.xml.rels
    $sheets = [];
    $wb = $zip->getFromName('xl/workbook.xml');
    $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($wb !== false && $rels !== false) {
        $targets = [];
        foreach (simplexml_load_string($rels)->Relationship as $rel) {
            $target = ltrim((string)$rel['Target'], '/');
            $targets[(string)$rel['Id']] = str_starts_with($target, 'xl/') ? $target : 'xl/' . $target;
        }
        $wbx = simplexml_load_string($wb);
        $wbx->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $response = null;
        foreach ($wbx->xpath('//m:definedNames/m:definedName') ?: [] as $dn) {
            if (str_ends_with((string)$dn['name'], 'ResponseSheet')) {
                $response = trim((string)$dn, '"\'');
            }
        }
        foreach ($wbx->xpath('//m:sheets/m:sheet') ?: [] as $sh) {
            $rid = (string)$sh->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
            if (!isset($targets[$rid])) {
                continue;
            }
            $entry = ['path' => $targets[$rid], 'hidden' => (string)$sh['state'] !== '' && (string)$sh['state'] !== 'visible'];
            if ($response !== null && (string)$sh['name'] === $response) {
                array_unshift($sheets, $entry);
            } else {
                $sheets[] = $entry;
            }
        }
    }
    if (!$sheets) {
        $sheets[] = ['path' => 'xl/worksheets/sheet1.xml', 'hidden' => false];
    }
    $rows = [];
    foreach ($sheets as $sheet) {
        if ($sheet['hidden'] || ($xml = $zip->getFromName($sheet['path'])) === false) {
            continue;
        }
        $rows = xlsx_sheet_rows($xml, $shared);
        if ($rows) {
            break;
        }
    }
    $zip->close();
    return $rows;
}

/** Radky jednoho listu XLSX. */
function xlsx_sheet_rows(string $xml, array $shared): array
{
    $rows = [];
    foreach (simplexml_load_string($xml)->sheetData->row as $row) {
        $cells = [];
        foreach ($row->c as $c) {
            $col = 0;
            foreach (str_split(preg_replace('/\d/', '', (string)$c['r'])) as $ch) {
                $col = $col * 26 + (ord($ch) - 64);
            }
            $type = (string)$c['t'];
            if ($type === 's') {
                $val = $shared[(int)$c->v] ?? '';
            } elseif ($type === 'inlineStr') {
                $val = (string)$c->is->t;
            } else {
                $val = (string)$c->v;
            }
            $cells[$col - 1] = $val;
        }
        if ($cells) {
            $max = max(array_keys($cells));
            $rows[] = array_map(fn($i) => trim($cells[$i] ?? ''), range(0, $max));
        }
    }
    return $rows;
}

/** Text (CSV soubor nebo vlozeno z Excelu) na pole radku. */
function parse_text_table(string $raw): array
{
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
    if (!mb_check_encoding($raw, 'UTF-8')) {
        // CSV z ceskeho Excelu; mbstring Windows-1250 nezna, proto iconv
        $conv = @iconv('CP1250', 'UTF-8', $raw);
        if ($conv === false) {
            throw new RuntimeException('Soubor není v kódování UTF-8 ani Windows-1250.');
        }
        $raw = $conv;
    }
    $lines = array_values(array_filter(preg_split('/\r\n|\r|\n/', $raw), fn($l) => trim($l) !== ''));
    if (!$lines) {
        return [];
    }
    $first = $lines[0];
    $sep = "\t";
    if (!str_contains($first, "\t")) {
        $sep = substr_count($first, ';') >= substr_count($first, ',') ? ';' : ',';
    }
    return array_map(fn($l) => array_map('trim', str_getcsv($l, $sep, '"', '\\')), $lines);
}

/**
 * Najde sloupce: MAC podle obsahu (i vic sloupcu - Forms ma prvni a druhe zarizeni), ostatni podle hlavicky.
 * Vraci ['macs'=>[i,...], 'jmeno'=>i|null, 'trida'=>i|null, 'email'=>i|null, 'header'=>bool, 'names'=>[...]]
 */
function detect_columns(array $rows): array
{
    $width = max(array_map('count', $rows ?: [[]]));
    $macs = [];
    $best = null;
    $bestScore = 0;
    for ($i = 0; $i < $width; $i++) {
        $n = $ok = 0;
        foreach (array_slice($rows, 0, 500) as $r) {
            if (($r[$i] ?? '') === '') {
                continue;
            }
            $n++;
            if (normalize_mac($r[$i]) !== null) {
                $ok++;
            }
        }
        $score = $n ? $ok / $n : 0;
        if ($ok > 0 && $score >= 0.5) {
            $macs[] = $i;
        }
        if ($ok > 0 && $score > $bestScore) {
            $best = $i;
            $bestScore = $score;
        }
    }
    if ($best === null) {
        throw new RuntimeException('V datech jsem nenašel žádný sloupec s MAC adresami.');
    }
    if (!$macs) {
        $macs = [$best];
    }
    $first = $macs[0];
    $header = normalize_mac($rows[0][$first] ?? '') === null;
    $res = ['macs' => $macs, 'jmeno' => null, 'trida' => null, 'email' => null, 'header' => $header, 'names' => $rows[0]];
    if ($header) {
        foreach ($rows[0] as $i => $h) {
            $hl = mb_strtolower($h);
            if (in_array($i, $macs, true)) {
                continue;
            }
            if ($res['trida'] === null && preg_match('/tř[ií]d|trid|class/u', $hl)) {
                $res['trida'] = $i;
            } elseif ($res['email'] === null && (str_contains($hl, 'mail') || preg_match('/^pozn[aá]mka$|^note$/u', $hl))) {
                $res['email'] = $i; // e-mail z Forms, nebo sloupec poznamka z exportu
            } elseif (preg_match('/jm[eé]no|příjmen|prijmen/u', $hl)) {
                $res['jmeno'] = $i; // otazka "Jmeno a prijmeni" ma prednost
            } elseif ($res['jmeno'] === null && preg_match('/^(name|název)$/u', $hl)) {
                $res['jmeno'] = $i; // sloupec Name z Forms (prihlaseny uzivatel)
            }
        }
    } else {
        // bez hlavicky: format mac;jmeno;trida;poznamka
        $res['macs'] = [$first];
        $res['jmeno'] = $first + 1 < $width ? $first + 1 : null;
        $res['trida'] = $first + 2 < $width ? $first + 2 : null;
        $res['email'] = $first + 3 < $width ? $first + 3 : null;
    }
    return $res;
}

/**
 * Import bez prepisovani: nove MAC prida, u existujicich jen doplni prazdne udaje.
 * Drive smazana zarizeni (mac_smazane) znovu nepridava. Vraci statistiku.
 */
function import_rows(array $rows): array
{
    $c = detect_columns($rows);
    $stat = ['added' => 0, 'filled' => 0, 'skipped' => 0, 'new' => [], 'ignored' => [], 'bad' => [], 'random' => [], 'cols' => $c];
    $pdo = db();
    $deleted = $pdo->prepare('SELECT 1 FROM mac_smazane WHERE mac = ?');
    $seen = [];
    foreach ($rows as $idx => $r) {
        if ($idx === 0 && $c['header']) {
            continue;
        }
        $jmeno = $c['jmeno'] !== null ? mb_substr($r[$c['jmeno']] ?? '', 0, 100) : '';
        $trida = $c['trida'] !== null ? mb_substr($r[$c['trida']] ?? '', 0, 20) : '';
        $pozn  = $c['email'] !== null ? mb_substr($r[$c['email']] ?? '', 0, 255) : '';
        if ($trida === '') {
            $trida = trida_z_emailu($pozn); // Forms tridu nema, je v e-mailu zaka
        }
        $badInRow = [];
        foreach ($c['macs'] as $col) {
            $rawMac = $r[$col] ?? '';
            if ($rawMac === '') {
                continue;
            }
            $mac = normalize_mac($rawMac);
            if ($mac === null) {
                // stejny preklep i v pomocnem sloupci (napr. Dalor) hlasit jen jednou
                $key = strtoupper(preg_replace('/[^0-9A-Za-z]/', '', $rawMac));
                if (!isset($badInRow[$key])) {
                    $badInRow[$key] = true;
                    $stat['bad'][] = ($idx + 1) . ': ' . mb_substr($rawMac, 0, 40);
                }
                continue;
            }
            if (isset($seen[$mac])) {
                continue; // stejna MAC vicekrat v souboru
            }
            $seen[$mac] = true;
            $label = $mac . ($jmeno !== '' ? " ($jmeno)" : '');
            if (!mac_exists($mac)) {
                $deleted->execute([$mac]);
                if ($deleted->fetchColumn()) {
                    $stat['ignored'][] = $label;
                    continue;
                }
                save_device($mac, $jmeno, $trida, $pozn);
                $stat['added']++;
                $stat['new'][] = $label;
                if (is_random_mac($mac)) {
                    $stat['random'][] = $label;
                }
                continue;
            }
            // existujici: doplnit jen prazdna pole
            $pdo->prepare("INSERT IGNORE INTO mac_info (mac) VALUES (?)")->execute([$mac]);
            $st = $pdo->prepare(
                "UPDATE mac_info SET
                   jmeno = IF(jmeno = '', ?, jmeno),
                   trida = IF(trida = '', ?, trida),
                   poznamka = IF(poznamka = '', ?, poznamka)
                 WHERE mac = ?"
            );
            $st->execute([$jmeno, $trida, $pozn, $mac]);
            $st->rowCount() > 0 ? $stat['filled']++ : $stat['skipped']++;
        }
    }
    return $stat;
}

/** Stahne XLSX ze sdileneho odkazu OneDrive / SharePoint ("Kdokoli s odkazem") do docasneho souboru. */
function download_xlsx(string $url): string
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('Chybí PHP rozšíření curl.');
    }
    if (parse_url($url, PHP_URL_SCHEME) !== 'https') {
        throw new RuntimeException('Odkaz na soubor musí začínat https://');
    }
    $host = strtolower((string)parse_url($url, PHP_URL_HOST));
    if (str_ends_with($host, 'sharepoint.com') && !preg_match('/[?&]download=1/', $url)) {
        $url .= (str_contains($url, '?') ? '&' : '?') . 'download=1';
    } elseif ($host === '1drv.ms' || str_ends_with($host, 'onedrive.live.com')) {
        // osobni OneDrive: sdileny odkaz pres API shares
        $url = 'https://api.onedrive.com/v1.0/shares/u!' . rtrim(strtr(base64_encode($url), '+/', '-_'), '=') . '/root/content';
    }
    $tmp = tempnam(sys_get_temp_dir(), 'macadmin');
    $fh = fopen($tmp, 'wb');
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FILE           => $fh,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 10,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_COOKIEFILE     => '', // SharePoint posila cookie mezi presmerovanimi
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_FAILONERROR    => true,
        CURLOPT_MAXFILESIZE    => 50 * 1024 * 1024,
        CURLOPT_USERAGENT      => 'macadmin',
    ]);
    $ok = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    fclose($fh);
    if (!$ok) {
        unlink($tmp);
        throw new RuntimeException('Stažení souboru z OneDrive selhalo: ' . $err);
    }
    if (file_get_contents($tmp, false, null, 0, 2) !== 'PK') {
        unlink($tmp);
        throw new RuntimeException('Odkaz nevrací soubor Excel – zkontrolujte, že je soubor sdílený pro „Kdokoli s odkazem“.');
    }
    return $tmp;
}

/** Stahne Excel z odkazu forms_url a naimportuje ho. */
function import_forms_url(string $url): array
{
    $tmp = download_xlsx($url);
    try {
        return import_rows(read_xlsx($tmp));
    } finally {
        unlink($tmp);
    }
}

/** Posle upozorneni e-mailem na alert_email (pres mistni MTA). Vraci true, kdyz ho MTA prijal. */
function send_alert(string $subject, string $body): bool
{
    $to = trim((string)cfg('alert_email'));
    if ($to === '') {
        return false;
    }
    $host = gethostname() ?: 'localhost';
    $headers = [
        'From'                      => 'macadmin <macadmin@' . $host . '>',
        'MIME-Version'              => '1.0',
        'Content-Type'              => 'text/plain; charset=UTF-8',
        'Content-Transfer-Encoding' => '8bit',
    ];
    return mail($to, mb_encode_mimeheader($subject, 'UTF-8'), $body . "\n\n-- \nmacadmin na serveru $host\n", $headers);
}

/* ---------- Vzhled ---------- */

function page_header(string $title, string $active = ''): void
{
    $menu = [
        'zarizeni'  => 'Zařízení',
        'pridat'    => 'Přidat',
        'zamitnuta' => 'Zamítnutá',
        'import'    => 'Import / export',
        'smazana'   => 'Smazaná',
        'udrzba'    => 'Údržba',
        'napoveda'  => 'Nápověda',
    ];
    echo '<!doctype html><html lang="cs"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . h($title) . ' – MAC wifi GFP</title><style>' . css() . '</style></head><body>';
    if (is_logged_in()) {
        echo '<nav><strong>Wifi GFP – MAC adresy</strong>';
        foreach ($menu as $k => $v) {
            echo '<a href="?p=' . $k . '"' . ($k === $active ? ' class="on"' : '') . '>' . $v . '</a>';
        }
        echo '<form method="post" action="?p=logout" class="right">' . csrf_field()
            . '<button class="link">Odhlásit</button></form></nav>';
    }
    echo '<main><h1>' . h($title) . '</h1>';
    foreach ($_SESSION['flash'] ?? [] as [$type, $msg]) {
        echo '<div class="flash ' . h($type) . '">' . h($msg) . '</div>';
    }
    unset($_SESSION['flash']);
}

/** Verze pro zobrazeni: "2026-10-21 (abc1234)", v pracovni kopii z gitu "vývojová". */
function app_version(): string
{
    if (str_starts_with(APP_VERSION, '$')) {
        return 'vývojová';
    }
    [$date, $hash] = array_pad(explode(' ', APP_VERSION, 2), 2, '');
    return $date . ($hash !== '' ? " ($hash)" : '');
}

function page_footer(): void
{
    echo '</main><footer>macadmin – správa MAC adres pro wifi GFP · verze ' . h(app_version())
        . ' · autor ' . h(APP_AUTHOR) . '</footer></body></html>';
}

function css(): string
{
    return <<<CSS
:root{--bg:#f6f7f9;--fg:#1d2330;--mut:#667085;--card:#fff;--line:#e3e6ea;--acc:#1f5fbf;--ok:#e7f6ec;--okf:#17643a;--err:#fdecec;--errf:#9b1c1c;--warn:#fff6e0}
@media (prefers-color-scheme:dark){:root{--bg:#14171c;--fg:#e6e8eb;--mut:#9aa3af;--card:#1c2027;--line:#2c323b;--acc:#6aa2ff;--ok:#16301f;--okf:#8fd9a8;--err:#3a1a1a;--errf:#f2a3a3;--warn:#3a3016}}
*{box-sizing:border-box}body{margin:0;font:15px/1.45 system-ui,Segoe UI,Arial,sans-serif;background:var(--bg);color:var(--fg)}
nav{display:flex;flex-wrap:wrap;gap:4px 16px;align-items:center;padding:10px 16px;background:var(--card);border-bottom:1px solid var(--line)}
a{color:var(--acc)}.nowrap{white-space:nowrap}
nav a{color:var(--fg);text-decoration:none;padding:4px 2px}nav a.on{color:var(--acc);border-bottom:2px solid var(--acc)}
nav .right{margin-left:auto}main{max-width:1100px;margin:0 auto;padding:16px}
h1{font-size:22px;margin:8px 0 16px}h2{font-size:17px;margin:24px 0 8px}
table{width:100%;border-collapse:collapse;background:var(--card);border:1px solid var(--line)}
th,td{text-align:left;padding:6px 8px;border-bottom:1px solid var(--line);vertical-align:top}th{font-weight:600;color:var(--mut);font-size:13px}
tr:hover td{background:rgba(127,127,127,.06)}code,.mono{font-family:ui-monospace,Consolas,monospace}
input,select,textarea{font:inherit;padding:6px 8px;border:1px solid var(--line);border-radius:6px;background:var(--card);color:var(--fg)}
button{font:inherit;padding:6px 12px;border:1px solid var(--acc);background:var(--acc);color:#fff;border-radius:6px;cursor:pointer}
button.sec{background:transparent;color:var(--acc)}button.danger{background:#b42318;border-color:#b42318}
button.link{background:none;border:0;color:var(--acc);padding:0}
.flash{padding:8px 12px;border-radius:6px;margin:0 0 12px}.flash.ok{background:var(--ok);color:var(--okf)}.flash.err{background:var(--err);color:var(--errf)}
.row{display:flex;flex-wrap:wrap;gap:8px;align-items:end;margin:0 0 12px}.row label{display:flex;flex-direction:column;gap:2px;font-size:13px;color:var(--mut)}
.card{background:var(--card);border:1px solid var(--line);border-radius:8px;padding:12px 16px;margin:0 0 16px}
.tag{display:inline-block;font-size:12px;padding:1px 6px;border-radius:4px;background:var(--warn)}.mut{color:var(--mut)}
.scroll{overflow-x:auto}
th a.sort{color:inherit;text-decoration:none}th a.sort:hover{color:var(--acc)}
.tiles{display:flex;flex-wrap:wrap;gap:12px;margin:0 0 16px}.tile{background:var(--card);border:1px solid var(--line);border-radius:8px;padding:10px 14px;min-width:150px}
.tile b{display:block;font-size:24px;font-weight:600}.tile span{color:var(--mut);font-size:13px}.tile.warn{background:var(--warn)}
.bars{display:flex;align-items:flex-end;gap:2px;height:160px;padding-top:20px;border-bottom:1px solid var(--line)}
.bar{flex:1;min-width:3px;height:100%;display:flex;flex-direction:column;justify-content:flex-end;align-items:center;position:relative}
.bar span{display:block;width:100%;max-width:32px;min-height:1px;background:var(--acc);border-radius:4px 4px 0 0}
.bar b{font-size:12px;font-weight:600;margin-bottom:2px}.bar:hover span{opacity:.75}
.bar:hover::after{content:attr(data-tip);position:absolute;bottom:100%;left:50%;transform:translateX(-50%);white-space:nowrap;background:var(--fg);color:var(--bg);font-size:12px;padding:3px 8px;border-radius:4px;z-index:1;pointer-events:none}
.bars-axis{display:flex;gap:2px;margin:2px 0 8px}.bars-axis small{flex:1;min-width:3px;text-align:center;color:var(--mut);font-size:11px;white-space:nowrap;overflow:visible}
footer{max-width:1100px;margin:8px auto 24px;padding:0 16px;color:var(--mut);font-size:12px}
details.help-login{max-width:820px;font-size:14px}details.help-login summary{cursor:pointer;color:var(--acc)}
details.help-login pre{background:var(--card);border:1px solid var(--line);border-radius:6px;padding:8px;overflow-x:auto;font-size:12px}
.help p,.help li{max-width:80ch}.help li{margin:0 0 6px}.help h2{margin-top:4px}.help th{white-space:nowrap}
.help pre{background:var(--bg);border:1px solid var(--line);border-radius:6px;padding:8px;overflow-x:auto;font-size:13px}
CSS;
}
