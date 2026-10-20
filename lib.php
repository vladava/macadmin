<?php
declare(strict_types=1);

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

/** Prevede libovolny zapis MAC na AA:BB:CC:DD:EE:FF, nebo vrati null. */
function normalize_mac(string $s): ?string
{
    $hex = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', $s));
    if (strlen($hex) !== 12) {
        return null;
    }
    return implode(':', str_split($hex, 2));
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

function mac_exists(string $mac): bool
{
    $st = db()->prepare('SELECT 1 FROM radcheck WHERE username = ? AND attribute = ? LIMIT 1');
    $st->execute([$mac, cfg('radcheck_attribute')]);
    return (bool)$st->fetchColumn();
}

/** Prida nebo aktualizuje zarizeni. Vraci 'added' | 'updated'. */
function save_device(string $mac, string $jmeno, string $trida, string $poznamka, ?string $oldMac = null): string
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $result = 'updated';
        if ($oldMac !== null && $oldMac !== $mac) {
            // zmena MAC: prejmenovat zaznam
            $pdo->prepare('UPDATE radcheck SET username = ? WHERE username = ?')->execute([$mac, $oldMac]);
            $pdo->prepare('DELETE FROM mac_info WHERE mac = ?')->execute([$oldMac]);
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
        $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function delete_devices(array $macs): int
{
    if (!$macs) {
        return 0;
    }
    $pdo = db();
    $in = implode(',', array_fill(0, count($macs), '?'));
    $pdo->beginTransaction();
    $st = $pdo->prepare("DELETE FROM radcheck WHERE username IN ($in)");
    $st->execute($macs);
    $n = $st->rowCount();
    $pdo->prepare("DELETE FROM mac_info WHERE mac IN ($in)")->execute($macs);
    $pdo->commit();
    return $n;
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

/* ---------- Import (Excel z MS Forms, CSV, vlozeny text) ---------- */

/** Precte prvni list souboru XLSX jako pole radku (bez knihoven, jen ZipArchive). */
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
    // prvni list podle workbook.xml.rels (Forms ho jmenuje ruzne)
    $sheetPath = 'xl/worksheets/sheet1.xml';
    $wb = $zip->getFromName('xl/workbook.xml');
    $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($wb !== false && $rels !== false) {
        $wbx = simplexml_load_string($wb);
        $wbx->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $first = $wbx->xpath('//m:sheets/m:sheet')[0] ?? null;
        if ($first !== null) {
            $rid = (string)$first->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
            foreach (simplexml_load_string($rels)->Relationship as $rel) {
                if ((string)$rel['Id'] === $rid) {
                    $target = ltrim((string)$rel['Target'], '/');
                    $sheetPath = str_starts_with($target, 'xl/') ? $target : 'xl/' . $target;
                }
            }
        }
    }
    $xml = $zip->getFromName($sheetPath);
    $zip->close();
    if ($xml === false) {
        throw new RuntimeException('V souboru XLSX chybí list s daty.');
    }
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
        $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1250'); // CSV z ceskeho Excelu
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
    return array_map(fn($l) => array_map('trim', str_getcsv($l, $sep)), $lines);
}

/**
 * Najde sloupce: MAC podle obsahu, ostatni podle hlavicky.
 * Vraci ['mac'=>i, 'jmeno'=>i|null, 'trida'=>i|null, 'email'=>i|null, 'header'=>bool, 'names'=>[...]]
 */
function detect_columns(array $rows): array
{
    $width = max(array_map('count', $rows ?: [[]]));
    $best = null;
    $bestScore = 0;
    for ($i = 0; $i < $width; $i++) {
        $n = $ok = 0;
        foreach (array_slice($rows, 0, 300) as $r) {
            if (($r[$i] ?? '') === '') {
                continue;
            }
            $n++;
            if (normalize_mac($r[$i]) !== null) {
                $ok++;
            }
        }
        $score = $n ? $ok / $n : 0;
        if ($ok > 0 && $score > $bestScore) {
            $best = $i;
            $bestScore = $score;
        }
    }
    if ($best === null) {
        throw new RuntimeException('V datech jsem nenašel žádný sloupec s MAC adresami.');
    }
    $header = normalize_mac($rows[0][$best] ?? '') === null;
    $res = ['mac' => $best, 'jmeno' => null, 'trida' => null, 'email' => null, 'header' => $header, 'names' => $rows[0]];
    if ($header) {
        foreach ($rows[0] as $i => $h) {
            $hl = mb_strtolower($h);
            if ($i === $best) {
                continue;
            }
            if ($res['trida'] === null && preg_match('/tř[ií]d|trid|class/u', $hl)) {
                $res['trida'] = $i;
            } elseif ($res['email'] === null && str_contains($hl, 'mail')) {
                $res['email'] = $i;
            } elseif (preg_match('/jm[eé]no|příjmen|prijmen/u', $hl)) {
                $res['jmeno'] = $i; // otazka "Jmeno a prijmeni" ma prednost
            } elseif ($res['jmeno'] === null && preg_match('/^(name|název)$/u', $hl)) {
                $res['jmeno'] = $i; // sloupec Name z Forms (prihlaseny uzivatel)
            }
        }
    } else {
        // bez hlavicky: format mac;jmeno;trida;poznamka
        $res['jmeno'] = $best + 1 < $width ? $best + 1 : null;
        $res['trida'] = $best + 2 < $width ? $best + 2 : null;
        $res['email'] = $best + 3 < $width ? $best + 3 : null;
    }
    return $res;
}

/**
 * Import bez prepisovani: nove MAC prida, u existujicich jen doplni prazdne udaje.
 * Vraci statistiku.
 */
function import_rows(array $rows): array
{
    $c = detect_columns($rows);
    $stat = ['added' => 0, 'filled' => 0, 'skipped' => 0, 'bad' => [], 'random' => [], 'cols' => $c];
    $pdo = db();
    $seen = [];
    foreach ($rows as $idx => $r) {
        if ($idx === 0 && $c['header']) {
            continue;
        }
        $rawMac = $r[$c['mac']] ?? '';
        if ($rawMac === '') {
            continue;
        }
        $mac = normalize_mac($rawMac);
        if ($mac === null) {
            $stat['bad'][] = ($idx + 1) . ': ' . mb_substr($rawMac, 0, 40);
            continue;
        }
        if (isset($seen[$mac])) {
            continue; // stejna MAC vicekrat v souboru
        }
        $seen[$mac] = true;
        $jmeno = $c['jmeno'] !== null ? mb_substr($r[$c['jmeno']] ?? '', 0, 100) : '';
        $trida = $c['trida'] !== null ? mb_substr($r[$c['trida']] ?? '', 0, 20) : '';
        $pozn  = $c['email'] !== null ? mb_substr($r[$c['email']] ?? '', 0, 255) : '';
        if (is_random_mac($mac)) {
            $stat['random'][] = $mac . ($jmeno !== '' ? " ($jmeno)" : '');
        }
        if (!mac_exists($mac)) {
            save_device($mac, $jmeno, $trida, $pozn);
            $stat['added']++;
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
    return $stat;
}

/* ---------- Vzhled ---------- */

function page_header(string $title, string $active = ''): void
{
    $menu = [
        'zarizeni'  => 'Zařízení',
        'pridat'    => 'Přidat',
        'zamitnuta' => 'Zamítnutá',
        'import'    => 'Import / export',
        'udrzba'    => 'Údržba',
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

function page_footer(): void
{
    echo '</main></body></html>';
}

function css(): string
{
    return <<<CSS
:root{--bg:#f6f7f9;--fg:#1d2330;--mut:#667085;--card:#fff;--line:#e3e6ea;--acc:#1f5fbf;--ok:#e7f6ec;--okf:#17643a;--err:#fdecec;--errf:#9b1c1c;--warn:#fff6e0}
@media (prefers-color-scheme:dark){:root{--bg:#14171c;--fg:#e6e8eb;--mut:#9aa3af;--card:#1c2027;--line:#2c323b;--acc:#6aa2ff;--ok:#16301f;--okf:#8fd9a8;--err:#3a1a1a;--errf:#f2a3a3;--warn:#3a3016}}
*{box-sizing:border-box}body{margin:0;font:15px/1.45 system-ui,Segoe UI,Arial,sans-serif;background:var(--bg);color:var(--fg)}
nav{display:flex;flex-wrap:wrap;gap:4px 16px;align-items:center;padding:10px 16px;background:var(--card);border-bottom:1px solid var(--line)}
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
CSS;
}
