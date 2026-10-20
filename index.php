<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

start_session();
ob_start(); // pri chybe DB zahodit rozpracovanou stranku
$p = $_GET['p'] ?? 'zarizeni';
$post = $_SERVER['REQUEST_METHOD'] === 'POST';

/* ---------- Prihlaseni ---------- */
if (!is_logged_in()) {
    if ($post && $p === 'login') {
        check_csrf();
        if (try_login((string)($_POST['user'] ?? ''), (string)($_POST['pass'] ?? ''))) {
            redirect('?p=zarizeni');
        }
        flash('Špatné jméno nebo heslo.', 'err');
        redirect('?p=login');
    }
    page_header('Přihlášení');
    echo '<form method="post" action="?p=login" class="card" style="max-width:340px">' . csrf_field()
        . '<div class="row"><label>Jméno<input name="user" autocomplete="username" required autofocus></label></div>'
        . '<div class="row"><label>Heslo<input name="pass" type="password" autocomplete="current-password" required></label></div>'
        . '<button>Přihlásit</button></form>';
    page_footer();
    exit;
}

if ($post) {
    check_csrf();
}

try {
    switch ($p) {

    /* ---------- Odhlaseni ---------- */
    case 'logout':
        if (!$post) {
            redirect('?p=zarizeni');
        }
        $_SESSION = [];
        session_destroy();
        redirect('?p=login');

    /* ---------- Seznam zarizeni ---------- */
    case 'zarizeni':
        if ($post && isset($_POST['delete'])) {
            // presny zapis z databaze, aby sly smazat i nestandardni a neplatne zaznamy
            $macs = array_values(array_filter(array_map('strval', (array)($_POST['mac'] ?? [])), fn($m) => $m !== ''));
            $n = delete_devices($macs);
            flash("Smazáno zařízení: $n");
            redirect('?p=zarizeni&' . http_build_query(['q' => $_POST['q'] ?? '', 't' => $_POST['t'] ?? '']));
        }
        $q = trim((string)($_GET['q'] ?? ''));
        $t = trim((string)($_GET['t'] ?? ''));
        $s = max(1, (int)($_GET['s'] ?? 1));
        $perPage = 500;
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
        $from = 'FROM radcheck r LEFT JOIN mac_info i ON i.mac = r.username WHERE ' . implode(' AND ', $where);
        $st = db()->prepare("SELECT COUNT(*) $from");
        $st->execute($args);
        $found = (int)$st->fetchColumn();
        $pages = max(1, (int)ceil($found / $perPage));
        $s = min($s, $pages);
        $st = db()->prepare("SELECT r.username AS mac, i.jmeno, i.trida, i.poznamka, i.vytvoreno $from
                ORDER BY i.trida, i.jmeno, r.username LIMIT $perPage OFFSET " . (($s - 1) * $perPage));
        $st->execute($args);
        $rows = $st->fetchAll();
        $tridy = db()->query("SELECT trida, COUNT(*) n FROM mac_info WHERE trida <> '' GROUP BY trida ORDER BY trida")->fetchAll();
        $st = db()->prepare('SELECT COUNT(*) FROM radcheck WHERE attribute = ?');
        $st->execute([cfg('radcheck_attribute')]);
        $total = (int)$st->fetchColumn();

        page_header('Zařízení', 'zarizeni');
        echo '<form class="row" method="get"><input type="hidden" name="p" value="zarizeni">'
            . '<label>Hledat (MAC, jméno, poznámka)<input name="q" value="' . h($q) . '" size="30"></label>'
            . '<label>Třída<select name="t"><option value="">všechny</option>';
        foreach ($tridy as $tr) {
            echo '<option' . ($tr['trida'] === $t ? ' selected' : '') . ' value="' . h($tr['trida']) . '">'
                . h($tr['trida']) . ' (' . (int)$tr['n'] . ')</option>';
        }
        echo '</select></label><button>Filtrovat</button></form>';
        echo '<p class="mut">Nalezeno ' . $found . ' z ' . $total . ' povolených zařízení.';
        if ($pages > 1) {
            $link = fn($n) => '?' . http_build_query(['p' => 'zarizeni', 'q' => $q, 't' => $t, 's' => $n]);
            echo ' Stránka ' . $s . ' z ' . $pages . ':';
            for ($i = 1; $i <= $pages; $i++) {
                echo ' ' . ($i === $s ? '<strong>' . $i . '</strong>' : '<a href="' . h($link($i)) . '">' . $i . '</a>');
            }
        }
        echo '</p>';
        echo '<form method="post" onsubmit="return confirm(\'Opravdu smazat vybraná zařízení?\')">' . csrf_field()
            . '<input type="hidden" name="q" value="' . h($q) . '"><input type="hidden" name="t" value="' . h($t) . '">'
            . '<div class="scroll"><table><tr><th><input type="checkbox" onclick="for(const c of document.querySelectorAll(\'.sel\'))c.checked=this.checked"></th>'
            . '<th>MAC</th><th>Jméno</th><th>Třída</th><th>Poznámka</th><th>Přidáno</th><th></th></tr>';
        foreach ($rows as $r) {
            echo '<tr><td><input type="checkbox" class="sel" name="mac[]" value="' . h($r['mac']) . '"></td>'
                . '<td class="mono">' . h($r['mac']) . (is_random_mac($r['mac']) ? ' <span class="tag">náhodná</span>' : '') . '</td>'
                . '<td>' . h($r['jmeno']) . '</td><td>' . h($r['trida']) . '</td><td>' . h($r['poznamka']) . '</td>'
                . '<td class="mut">' . h($r['vytvoreno'] ? substr($r['vytvoreno'], 0, 10) : '') . '</td>'
                . '<td><a href="?p=pridat&mac=' . urlencode($r['mac']) . '">upravit</a></td></tr>';
        }
        echo '</table></div><p><button class="danger" name="delete" value="1">Smazat vybrané</button></p></form>';
        break;

    /* ---------- Pridat / upravit ---------- */
    case 'pridat':
        if ($post) {
            $mac = normalize_mac((string)($_POST['mac'] ?? ''));
            // puvodni zapis v databazi (muze byt nestandardni nebo neplatny)
            $old = (string)($_POST['old'] ?? '');
            $old = $old !== '' ? $old : null;
            if ($mac === null) {
                flash('Neplatná MAC adresa.', 'err');
                redirect('?p=pridat' . ($old !== null ? '&mac=' . urlencode($old) : ''));
            }
            if ($old === null && mac_exists($mac)) {
                flash("Adresa $mac už v seznamu je, zde ji můžete upravit.", 'err');
                redirect('?p=pridat&mac=' . urlencode($mac));
            }
            if ($old !== null && normalize_mac($old) !== $mac && mac_exists($mac)) {
                flash("Adresa $mac už v seznamu je.", 'err');
                redirect('?p=pridat&mac=' . urlencode($old));
            }
            $res = save_device(
                $mac,
                mb_substr(trim((string)($_POST['jmeno'] ?? '')), 0, 100),
                mb_substr(trim((string)($_POST['trida'] ?? '')), 0, 20),
                mb_substr(trim((string)($_POST['poznamka'] ?? '')), 0, 255),
                $old
            );
            flash($res === 'added' ? "Přidáno: $mac" : "Uloženo: $mac");
            if (is_random_mac($mac)) {
                flash("Pozor: $mac je náhodná (soukromá) adresa, telefon ji může časem změnit.", 'err');
            }
            redirect(isset($_POST['zpet']) && $_POST['zpet'] === 'zamitnuta' ? '?p=zamitnuta' : '?p=pridat');
        }
        $arg = trim((string)($_GET['mac'] ?? ''));
        $norm = $arg !== '' ? normalize_mac($arg) : null;
        // presny zapis v databazi; odkaz ze seznamu nese i nestandardni zapis
        $edit = $arg !== '' ? (find_mac($arg) ?? ($norm !== null ? find_mac($norm) : null)) : null;
        $d = ['mac' => $norm ?? '', 'jmeno' => '', 'trida' => '', 'poznamka' => ''];
        $isEdit = $edit !== null;
        if ($isEdit) {
            $d['mac'] = normalize_mac($edit) ?? $edit;
            $st = db()->prepare('SELECT jmeno, trida, poznamka FROM mac_info WHERE mac = ?');
            $st->execute([$edit]);
            $d = array_merge($d, $st->fetch() ?: []);
        }
        $zpet = (string)($_GET['zpet'] ?? '');
        page_header($isEdit ? 'Upravit zařízení' : 'Přidat zařízení', 'pridat');
        echo '<form method="post" class="card">' . csrf_field()
            . ($isEdit ? '<input type="hidden" name="old" value="' . h($edit) . '">' : '')
            . ($isEdit && normalize_mac($edit) !== $edit
                ? '<p class="flash err">V databázi je zapsáno nestandardně: <code>[' . h($edit) . ']</code>. Uložením se zápis opraví.</p>' : '')
            . '<input type="hidden" name="zpet" value="' . h($zpet) . '">'
            . '<div class="row"><label>MAC adresa (libovolný zápis)<input name="mac" class="mono" required value="' . h($d['mac']) . '" placeholder="AA:BB:CC:DD:EE:FF" size="22"></label>'
            . '<label>Jméno<input name="jmeno" value="' . h($d['jmeno']) . '" size="26" maxlength="100"></label>'
            . '<label>Třída<input name="trida" value="' . h($d['trida']) . '" size="8" maxlength="20" list="tridy"></label>'
            . '<label>Poznámka<input name="poznamka" value="' . h($d['poznamka']) . '" size="30" maxlength="255"></label></div>'
            . '<button>' . ($isEdit ? 'Uložit' : 'Přidat') . '</button></form>';
        if ($isEdit) {
            echo '<form method="post" action="?p=zarizeni" onsubmit="return confirm(\'Opravdu smazat toto zařízení?\')">' . csrf_field()
                . '<input type="hidden" name="mac[]" value="' . h($edit) . '">'
                . '<button class="danger" name="delete" value="1">Smazat zařízení</button></form>';
        }
        echo '<datalist id="tridy">';
        foreach (db()->query("SELECT DISTINCT trida FROM mac_info WHERE trida <> '' ORDER BY trida") as $r) {
            echo '<option value="' . h($r['trida']) . '">';
        }
        echo '</datalist>';
        break;

    /* ---------- Zamitnuta zarizeni ---------- */
    case 'zamitnuta':
        $days = (int)($_GET['d'] ?? cfg('rejected_days'));
        $days = max(1, min($days, 90));
        $st = db()->prepare(
            "SELECT p.username AS mac, COUNT(*) AS pokusu, MIN(p.authdate) AS prvni, MAX(p.authdate) AS naposledy
             FROM radpostauth p
             WHERE p.reply = 'Access-Reject' AND p.authdate > NOW() - INTERVAL ? DAY
             GROUP BY p.username ORDER BY naposledy DESC LIMIT 500"
        );
        $st->execute([$days]);
        $rows = $st->fetchAll();
        page_header('Zamítnutá zařízení', 'zamitnuta');
        echo '<form class="row" method="get"><input type="hidden" name="p" value="zamitnuta">'
            . '<label>Za posledních dní<input name="d" type="number" min="1" max="90" value="' . $days . '" size="4"></label><button>Zobrazit</button></form>';
        echo '<p class="mut">Zařízení, která se zkoušela připojit k síti GFP a nejsou povolená. '
            . 'Adresy označené „náhodná“ jsou soukromé adresy telefonů – žák musí pro síť GFP soukromou adresu vypnout, jinak se adresa časem změní.</p>';
        echo '<div class="scroll"><table><tr><th>MAC</th><th>Pokusů</th><th>První</th><th>Naposledy</th><th>Stav</th><th></th></tr>';
        foreach ($rows as $r) {
            $norm = normalize_mac($r['mac']) ?? $r['mac'];
            $known = mac_exists($norm);
            echo '<tr><td class="mono">' . h($r['mac'])
                . (is_random_mac($r['mac']) ? ' <span class="tag">náhodná</span>' : '') . '</td>'
                . '<td>' . (int)$r['pokusu'] . '</td><td class="mut">' . h($r['prvni']) . '</td><td>' . h($r['naposledy']) . '</td>'
                . '<td>' . ($known ? 'už povoleno' : '') . '</td>'
                . '<td>' . ($known ? '' : '<a href="?p=pridat&zpet=zamitnuta&mac=' . urlencode($norm) . '">povolit…</a>') . '</td></tr>';
        }
        echo '</table></div>';
        break;

    /* ---------- Import / export ---------- */
    case 'import':
        if ($post && isset($_POST['export'])) {
            $st = db()->prepare(
                'SELECT r.username AS mac, i.jmeno, i.trida, i.poznamka FROM radcheck r
                 LEFT JOIN mac_info i ON i.mac = r.username WHERE r.attribute = ? ORDER BY i.trida, i.jmeno'
            );
            $st->execute([cfg('radcheck_attribute')]);
            $rows = $st->fetchAll();
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="mac-gfp-' . date('Y-m-d') . '.csv"');
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM pro Excel
            fputcsv($out, ['mac', 'jmeno', 'trida', 'poznamka'], ';', '"', '\\');
            foreach ($rows as $r) {
                fputcsv($out, [$r['mac'], $r['jmeno'], $r['trida'], $r['poznamka']], ';', '"', '\\');
            }
            exit;
        }
        if ($post && isset($_POST['import'])) {
            $rows = [];
            $tmp = $_FILES['soubor']['tmp_name'] ?? '';
            $paste = trim((string)($_POST['vlozit'] ?? ''));
            try {
                if ($tmp !== '' && is_uploaded_file($tmp)) {
                    $isXlsx = str_ends_with(strtolower($_FILES['soubor']['name']), '.xlsx')
                        || file_get_contents($tmp, false, null, 0, 2) === 'PK';
                    $rows = $isXlsx ? read_xlsx($tmp) : parse_text_table(file_get_contents($tmp));
                } elseif ($paste !== '') {
                    $rows = parse_text_table($paste);
                }
                if (!$rows) {
                    flash('Nahrajte soubor nebo vložte data.', 'err');
                    redirect('?p=import');
                }
                $s = import_rows($rows);
                $c = $s['cols'];
                $nm = fn($i) => $i === null ? '–' : ($c['header'] ? '„' . ($c['names'][$i] ?? '') . '“' : 'sloupec ' . ($i + 1));
                flash("Import: nových $s[added], u existujících doplněno $s[filled], beze změny $s[skipped].");
                flash('Použité sloupce – MAC: ' . $nm($c['mac']) . ', jméno: ' . $nm($c['jmeno'])
                    . ', třída: ' . $nm($c['trida']) . ', poznámka (e-mail): ' . $nm($c['email']));
                if ($s['bad']) {
                    flash('Neplatná MAC (řádek: hodnota): ' . implode(' | ', array_slice($s['bad'], 0, 40)), 'err');
                }
                if ($s['random']) {
                    flash('Náhodné (soukromé) adresy – žák by měl soukromou adresu pro GFP vypnout: '
                        . implode(', ', array_slice($s['random'], 0, 40)), 'err');
                }
            } catch (RuntimeException $e) {
                flash($e->getMessage(), 'err');
            }
            redirect('?p=import');
        }
        if ($post && isset($_POST['del_trida'])) {
            $tr = trim((string)$_POST['trida']);
            $st = db()->prepare('SELECT mac FROM mac_info WHERE trida = ?');
            $st->execute([$tr]);
            $n = delete_devices($st->fetchAll(PDO::FETCH_COLUMN));
            flash("Smazáno zařízení třídy $tr: $n");
            redirect('?p=import');
        }
        page_header('Import / export', 'import');
        echo '<div class="card"><h2>Import</h2>'
            . '<p class="mut">Nahrajte celý soubor Excelu z MS Forms (.xlsx) nebo CSV, případně vložte sloupce zkopírované z Excelu. '
            . 'Sloupec s MAC adresou se najde sám, jméno, třída a e-mail podle záhlaví. '
            . '<strong>Přidají se jen nové MAC adresy</strong>, u existujících se nic nepřepíše, jen se doplní prázdné jméno nebo třída. '
            . 'Klidně tedy nahrávejte pokaždé celý soubor.</p>'
            . '<form method="post" enctype="multipart/form-data">' . csrf_field()
            . '<div class="row"><label>Soubor (.xlsx, .csv)<input type="file" name="soubor" accept=".xlsx,.csv,text/csv"></label></div>'
            . '<div class="row"><label style="flex:1">nebo vložte z Excelu (i se záhlavím)'
            . '<textarea name="vlozit" rows="6" style="width:100%" placeholder="MAC adresa&#9;Jméno a příjmení&#9;Třída"></textarea></label></div>'
            . '<button name="import" value="1">Importovat</button></form></div>';
        echo '<div class="card"><h2>Export</h2><form method="post">' . csrf_field()
            . '<button name="export" value="1" class="sec">Stáhnout CSV</button></form></div>';
        echo '<div class="card"><h2>Smazat celou třídu</h2><p class="mut">Např. po maturitě.</p>'
            . '<form method="post" class="row" onsubmit="return confirm(\'Opravdu smazat všechna zařízení této třídy?\')">' . csrf_field()
            . '<select name="trida" required><option value="">vyberte</option>';
        foreach (db()->query("SELECT trida, COUNT(*) n FROM mac_info WHERE trida <> '' GROUP BY trida ORDER BY trida") as $r) {
            echo '<option value="' . h($r['trida']) . '">' . h($r['trida']) . ' (' . (int)$r['n'] . ')</option>';
        }
        echo '</select><button class="danger" name="del_trida" value="1">Smazat</button></form></div>';
        break;

    /* ---------- Udrzba ---------- */
    case 'udrzba':
        if ($post && isset($_POST['prune'])) {
            $n = prune_postauth((int)cfg('postauth_keep_days'));
            flash("Smazáno starých záznamů přihlášení: $n");
            redirect('?p=udrzba');
        }
        if ($post && isset($_POST['normalize'])) {
            [$fixed, $dupl] = fix_mac_format();
            flash("Opraveno zápisů: $fixed, sloučeno duplicit: $dupl");
            redirect('?p=udrzba');
        }
        $pdo = db();
        $st = $pdo->prepare('SELECT username FROM radcheck WHERE attribute = ?');
        $st->execute([cfg('radcheck_attribute')]);
        $macs = $st->fetchAll(PDO::FETCH_COLUMN);
        $stats = [
            'Povolených zařízení'            => count($macs),
            'Záznamů přihlášení celkem'      => $pdo->query('SELECT COUNT(*) FROM radpostauth')->fetchColumn(),
            'Nejstarší záznam přihlášení'    => $pdo->query('SELECT MIN(authdate) FROM radpostauth')->fetchColumn(),
            'Povoleno za 24 h'               => $pdo->query("SELECT COUNT(*) FROM radpostauth WHERE reply='Access-Accept' AND authdate > NOW() - INTERVAL 1 DAY")->fetchColumn(),
            'Zamítnuto za 24 h'              => $pdo->query("SELECT COUNT(*) FROM radpostauth WHERE reply='Access-Reject' AND authdate > NOW() - INTERVAL 1 DAY")->fetchColumn(),
        ];
        $bad = [];
        foreach (array_unique($macs) as $u) {
            $n = normalize_mac((string)$u);
            if ($n !== $u) {
                $bad[] = [(string)$u, $n];
            }
        }
        page_header('Údržba', 'udrzba');
        echo '<div class="card"><table>';
        foreach ($stats as $k => $v) {
            echo '<tr><th>' . h($k) . '</th><td>' . h((string)$v) . '</td></tr>';
        }
        echo '</table></div>';
        echo '<div class="card"><h2>Promazání historie přihlášení</h2><p class="mut">Smaže záznamy z <code>radpostauth</code> starší než '
            . (int)cfg('postauth_keep_days') . ' dní. Automaticky to dělá i <code>udrzba.php</code> z cronu.</p>'
            . '<form method="post">' . csrf_field() . '<button name="prune" value="1" class="sec">Promazat nyní</button></form></div>';
        echo '<div class="card"><h2>Nestandardní zápis MAC (' . count($bad) . ')</h2>'
            . '<p class="mut">FreeRADIUS hledá adresu ve tvaru <code>AA:BB:CC:DD:EE:FF</code>. Jinak zapsané adresy nemusí projít ověřením.</p>';
        if ($bad) {
            echo '<div class="scroll"><table><tr><th>V databázi</th><th>Opraveno na</th></tr>';
            foreach (array_slice($bad, 0, 200) as [$o, $n]) {
                echo '<tr><td class="mono">[' . h($o) . ']</td><td class="mono">' . ($n !== null ? h($n) : 'neplatná – <a href="?p=pridat&mac=' . urlencode($o) . '">opravit nebo smazat</a>') . '</td></tr>';
            }
            echo '</table></div><form method="post" style="margin-top:8px">' . csrf_field()
                . '<button name="normalize" value="1">Opravit zápis</button></form>';
        }
        echo '</div>';
        break;

    default:
        redirect('?p=zarizeni');
    }
} catch (PDOException $e) {
    error_log('macadmin: ' . $e->getMessage());
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    page_header('Chyba');
    echo '<div class="flash err">Chyba databáze: ' . h($e->getMessage()) . '</div>';
}

page_footer();
