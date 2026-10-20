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
        if ($post && isset($_POST['delete_all'])) {
            // vsechna zarizeni odpovidajici filtru (i pres vic stranek); bez filtru nic
            $f = device_filter($_POST);
            if (!$f['active']) {
                flash('Bez filtru nejde smazat všechno najednou.', 'err');
                redirect('?p=zarizeni');
            }
            $st = db()->prepare('SELECT r.username FROM radcheck r LEFT JOIN mac_info i ON i.mac = r.username WHERE '
                . implode(' AND ', $f['where']));
            $st->execute($f['args']);
            flash('Smazáno zařízení: ' . delete_devices($st->fetchAll(PDO::FETCH_COLUMN)) . '. Vrátit je jde na stránce Smazaná.');
            redirect('?p=zarizeni');
        }
        if ($post && isset($_POST['delete'])) {
            // presny zapis z databaze, aby sly smazat i nestandardni a neplatne zaznamy
            $macs = array_values(array_filter(array_map('strval', (array)($_POST['mac'] ?? [])), fn($m) => $m !== ''));
            $n = delete_devices($macs);
            flash("Smazáno zařízení: $n. Vrátit je jde na stránce Smazaná.");
            if (($_POST['zpet'] ?? '') === 'udrzba') {
                redirect('?p=udrzba');
            }
            redirect('?p=zarizeni&' . http_build_query([
                'q' => $_POST['q'] ?? '', 't' => $_POST['t'] ?? '', 'l' => $_POST['l'] ?? '', 'r' => $_POST['r'] ?? '', 'n' => $_POST['n'] ?? '', 'm' => $_POST['m'] ?? '',
            ]));
        }
        $f = device_filter($_GET);
        ['q' => $q, 't' => $t, 'l' => $l, 'r' => $rnd, 'n' => $nd, 'm' => $m, 'where' => $where, 'args' => $args] = $f;
        $s = max(1, (int)($_GET['s'] ?? 1));
        $perPage = 500;
        $from = 'FROM radcheck r LEFT JOIN mac_info i ON i.mac = r.username WHERE ' . implode(' AND ', $where);
        $st = db()->prepare("SELECT COUNT(*) $from");
        $st->execute($args);
        $found = (int)$st->fetchColumn();
        $pages = max(1, (int)ceil($found / $perPage));
        $s = min($s, $pages);
        $st = db()->prepare("SELECT r.username AS mac, i.jmeno, i.trida, i.poznamka, i.vytvoreno,
                  (SELECT MAX(p.authdate) FROM radpostauth p WHERE p.username = r.username AND p.reply = 'Access-Accept') AS naposledy
                $from
                ORDER BY i.trida, i.jmeno, r.username LIMIT $perPage OFFSET " . (($s - 1) * $perPage));
        $st->execute($args);
        $rows = $st->fetchAll();
        $tridy = db()->query("SELECT trida, COUNT(*) n FROM mac_info WHERE trida <> '' GROUP BY trida ORDER BY trida")->fetchAll();
        $st = db()->prepare('SELECT COUNT(*) FROM radcheck WHERE attribute = ?');
        $st->execute([cfg('radcheck_attribute')]);
        $total = (int)$st->fetchColumn();
        $since = db()->query('SELECT MIN(authdate) FROM radpostauth')->fetchColumn();
        $keep = ['p' => 'zarizeni', 'q' => $q, 't' => $t, 'l' => $l, 'r' => $rnd ? '1' : '', 'n' => $nd ?: '', 'm' => $m ?: ''];
        $pismena = db()->query("SELECT LOWER(RIGHT(trida, 1)) AS l, COUNT(*) AS n FROM mac_info
                                WHERE trida LIKE '____._' GROUP BY 1 ORDER BY 1")->fetchAll();

        page_header('Zařízení', 'zarizeni');
        echo '<form class="row" method="get"><input type="hidden" name="p" value="zarizeni">'
            . '<label>Hledat (MAC, jméno, poznámka)<input name="q" value="' . h($q) . '" size="30"></label>'
            . '<label>Třída<select name="t"><option value="">všechny</option>';
        foreach ($tridy as $tr) {
            echo '<option' . ($tr['trida'] === $t ? ' selected' : '') . ' value="' . h($tr['trida']) . '">'
                . h($tr['trida']) . ' (' . (int)$tr['n'] . ')</option>';
        }
        echo '</select></label><label>Písmeno třídy<select name="l"><option value="">všechna</option>';
        foreach ($pismena as $pm) {
            echo '<option' . ($pm['l'] === $l ? ' selected' : '') . ' value="' . h($pm['l']) . '">'
                . h($pm['l']) . ' (' . (int)$pm['n'] . ')</option>';
        }
        echo '</select></label>'
            . '<label>Nepřihlášeno aspoň (dní)<input name="n" type="number" min="1" max="3650" value="' . ($nd ?: '') . '" size="5"></label>'
            . '<label>Zařízení na e-mail aspoň<input name="m" type="number" min="2" max="50" value="' . ($m ?: '') . '" size="4"></label>'
            . '<label><span><input type="checkbox" name="r" value="1"' . ($rnd ? ' checked' : '') . '> jen náhodné</span></label>'
            . '<button>Filtrovat</button></form>';
        if ($nd > 0 && ($since === null || strtotime((string)$since) > time() - $nd * 86400)) {
            echo '<div class="flash err">Historie přihlášení je k dispozici teprve od '
                . h($since === null ? 'dneška (zatím žádná)' : substr((string)$since, 0, 10))
                . ', takže filtr „nepřihlášeno ' . $nd . ' dní“ zatím není spolehlivý – zahrnuje i zařízení, která se jen dosud nepřipojila.</div>';
        }
        echo '<p class="mut">Nalezeno ' . $found . ' z ' . $total . ' povolených zařízení.';
        if ($pages > 1) {
            $link = fn($n) => '?' . http_build_query($keep + ['s' => $n]);
            echo ' Stránka ' . $s . ' z ' . $pages . ':';
            for ($i = 1; $i <= $pages; $i++) {
                echo ' ' . ($i === $s ? '<strong>' . $i . '</strong>' : '<a href="' . h($link($i)) . '">' . $i . '</a>');
            }
        }
        echo '</p>';
        echo '<form method="post">' . csrf_field()
            . '<input type="hidden" name="q" value="' . h($q) . '"><input type="hidden" name="t" value="' . h($t) . '"><input type="hidden" name="l" value="' . h($l) . '">'
            . '<input type="hidden" name="r" value="' . ($rnd ? '1' : '') . '"><input type="hidden" name="n" value="' . ($nd ?: '') . '"><input type="hidden" name="m" value="' . ($m ?: '') . '">'
            . '<div class="scroll"><table><tr><th><input type="checkbox" onclick="for(const c of document.querySelectorAll(\'.sel\'))c.checked=this.checked"></th>'
            . '<th>MAC</th><th>Jméno</th><th>Třída</th><th>Poznámka</th><th>Přidáno</th><th>Naposledy</th><th></th></tr>';
        foreach ($rows as $r) {
            echo '<tr><td><input type="checkbox" class="sel" name="mac[]" value="' . h($r['mac']) . '"></td>'
                . '<td class="mono">' . h($r['mac']) . (is_random_mac($r['mac']) ? ' <span class="tag">náhodná</span>' : '') . '</td>'
                . '<td>' . h($r['jmeno']) . '</td><td>' . h($r['trida']) . '</td><td>' . h($r['poznamka']) . '</td>'
                . '<td class="mut">' . h($r['vytvoreno'] ? substr($r['vytvoreno'], 0, 10) : '') . '</td>'
                . '<td class="mut">' . h($r['naposledy'] ? substr($r['naposledy'], 0, 16) : '–') . '</td>'
                . '<td><a href="?p=pridat&mac=' . urlencode($r['mac']) . '">upravit</a></td></tr>';
        }
        echo '</table></div><p class="row"><button class="danger" name="delete" value="1" onclick="return confirm(\'Opravdu smazat vybraná zařízení?\')">Smazat vybrané</button>';
        if ($f['active'] && $found > 0) {
            echo '<button class="danger" name="delete_all" value="1" onclick="return confirm(\'Opravdu smazat všech ' . $found . ' nalezených zařízení?\')">'
                . 'Smazat všech ' . $found . ' nalezených</button>';
        }
        echo '</p></form>';
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
            $zpet = (string)($_POST['zpet'] ?? '');
            redirect(in_array($zpet, ['zamitnuta', 'udrzba'], true) ? '?p=' . $zpet : '?p=pridat');
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
                . '<input type="hidden" name="mac[]" value="' . h($edit) . '"><input type="hidden" name="zpet" value="' . h($zpet) . '">'
                . '<button class="danger" name="delete" value="1">Smazat zařízení</button></form>';
            $st = db()->prepare('SELECT reply, authdate FROM radpostauth WHERE username = ? ORDER BY authdate DESC LIMIT 20');
            $st->execute([$edit]);
            $hist = $st->fetchAll();
            echo '<div class="card"><h2>Posledních 20 pokusů o připojení</h2>';
            if (!$hist) {
                echo '<p class="mut">Za posledních ' . (int)cfg('postauth_keep_days') . ' dní se zařízení nepokusilo připojit.</p>';
            } else {
                echo '<table><tr><th>Čas</th><th>Výsledek</th></tr>';
                foreach ($hist as $hr) {
                    $ok = $hr['reply'] === 'Access-Accept';
                    echo '<tr><td class="mono">' . h(substr((string)$hr['authdate'], 0, 19)) . '</td><td>'
                        . ($ok ? 'povoleno' : '<span class="tag">' . h($hr['reply'] === 'Access-Reject' ? 'zamítnuto' : $hr['reply']) . '</span>') . '</td></tr>';
                }
                echo '</table>';
            }
            echo '</div>';
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
            . 'Adresy označené „náhodná“ jsou soukromé adresy telefonů – žák musí pro síť GFP soukromou adresu vypnout, jinak se adresa časem změní. '
            . '<a href="?p=napoveda#zamitnuta">Co znamená stav „už povoleno“?</a></p>';
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
            $rows = export_rows();
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="mac-gfp-' . date('Y-m-d') . '.csv"');
            write_csv(fopen('php://output', 'w'), $rows);
            exit;
        }
        if ($post && (isset($_POST['import']) || isset($_POST['import_url']))) {
            try {
                if (isset($_POST['import_url'])) {
                    $s = import_forms_url((string)cfg('forms_url'));
                    state_set('import', array_merge(state_get('import'),
                        ['last_ok' => date('Y-m-d H:i:s'), 'added' => $s['added'], 'filled' => $s['filled']]));
                } else {
                    $rows = [];
                    $tmp = $_FILES['soubor']['tmp_name'] ?? '';
                    $paste = trim((string)($_POST['vlozit'] ?? ''));
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
                }
                $c = $s['cols'];
                $nm = fn($i) => $i === null ? '–' : ($c['header'] ? '„' . mb_strimwidth($c['names'][$i] ?? '', 0, 40, '…') . '“' : 'sloupec ' . ($i + 1));
                flash("Import: nových $s[added], u existujících doplněno $s[filled], beze změny $s[skipped].");
                if ($s['new']) {
                    flash('Přidáno: ' . implode(', ', array_slice($s['new'], 0, 60)) . (count($s['new']) > 60 ? ' …' : ''));
                }
                flash('Použité sloupce – MAC: ' . implode(', ', array_map($nm, $c['macs'])) . '; jméno: ' . $nm($c['jmeno'])
                    . '; třída: ' . $nm($c['trida']) . '; poznámka (e-mail): ' . $nm($c['email']));
                if ($s['ignored']) {
                    flash('Přeskočeno – dříve smazaná zařízení (' . count($s['ignored']) . '): ' . implode(', ', array_slice($s['ignored'], 0, 40))
                        . (count($s['ignored']) > 40 ? ' …' : '') . '. Znovu je povolíte ručně přes Přidat.');
                }
                if ($s['bad']) {
                    flash('Neplatná MAC (řádek: hodnota): ' . implode(' | ', array_slice($s['bad'], 0, 40)), 'err');
                }
                if ($s['random']) {
                    flash('Nově přidané náhodné (soukromé) adresy – žák by měl soukromou adresu pro GFP vypnout: '
                        . implode(', ', array_slice($s['random'], 0, 40)), 'err');
                }
            } catch (RuntimeException $e) {
                flash($e->getMessage(), 'err');
            }
            redirect('?p=import');
        }
        page_header('Import / export', 'import');
        echo '<div class="card"><h2>Import</h2>'
            . '<p class="mut">Nahrajte celý soubor Excelu z MS Forms (.xlsx) nebo CSV, případně vložte sloupce zkopírované z Excelu. '
            . 'Sloupec s MAC adresou se najde sám, jméno, třída a e-mail podle záhlaví. '
            . '<strong>Přidají se jen nové MAC adresy</strong>, u existujících se nic nepřepíše, jen se doplní prázdné jméno nebo třída. '
            . 'Zařízení, která jste smazali, se znovu nepřidají. Klidně tedy nahrávejte pokaždé celý soubor.</p>'
            . '<form method="post" enctype="multipart/form-data">' . csrf_field()
            . '<div class="row"><label>Soubor (.xlsx, .csv)<input type="file" name="soubor" accept=".xlsx,.csv,text/csv"></label></div>'
            . '<div class="row"><label style="flex:1">nebo vložte z Excelu (i se záhlavím)'
            . '<textarea name="vlozit" rows="6" style="width:100%" placeholder="MAC adresa&#9;Jméno a příjmení&#9;Třída"></textarea></label></div>'
            . '<button name="import" value="1">Importovat</button></form></div>';
        if ((string)cfg('forms_url') !== '') {
            echo '<div class="card"><h2>Import z OneDrive</h2>'
                . '<p class="mut">Načte aktuální Excel s odpověďmi z MS Forms podle odkazu v <code>config.php</code>. '
                . 'Automaticky to dělá i <code>import-forms.php</code> z cronu.</p>'
                . '<form method="post">' . csrf_field() . '<button name="import_url" value="1">Načíst z OneDrive</button></form></div>';
        }
        echo '<div class="card"><h2>Export</h2><form method="post">' . csrf_field()
            . '<button name="export" value="1" class="sec">Stáhnout CSV</button></form></div>';
        break;

    /* ---------- Smazana zarizeni ---------- */
    case 'smazana':
        if ($post) {
            $macs = array_values(array_filter(array_map('strval', (array)($_POST['mac'] ?? [])), fn($x) => $x !== ''));
            if (isset($_POST['restore_batch'])) {
                $st = db()->prepare('SELECT mac FROM mac_smazane WHERE smazano = ?');
                $st->execute([(string)$_POST['restore_batch']]);
                flash('Obnoveno zařízení: ' . restore_devices($st->fetchAll(PDO::FETCH_COLUMN)));
            } elseif (isset($_POST['restore'])) {
                flash('Obnoveno zařízení: ' . restore_devices($macs));
            } elseif (isset($_POST['forget'])) {
                flash('Zapomenuto zařízení: ' . forget_deleted($macs) . '. Při dalším importu z Forms se znovu přidají.');
            }
            redirect('?p=smazana');
        }
        $q = trim((string)($_GET['q'] ?? ''));
        $where = '1';
        $args = [];
        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $where = '(mac LIKE ? OR jmeno LIKE ? OR poznamka LIKE ? OR trida LIKE ?)';
            $args = [$like, $like, $like, $like];
        }
        $st = db()->prepare("SELECT COUNT(*) FROM mac_smazane WHERE $where");
        $st->execute($args);
        $found = (int)$st->fetchColumn();
        $st = db()->prepare("SELECT mac, jmeno, trida, poznamka, smazano FROM mac_smazane WHERE $where ORDER BY smazano DESC, trida, jmeno LIMIT 500");
        $st->execute($args);
        $rows = $st->fetchAll();
        $batches = db()->query('SELECT smazano, COUNT(*) AS n FROM mac_smazane GROUP BY smazano ORDER BY smazano DESC LIMIT 5')->fetchAll();
        page_header('Smazaná zařízení', 'smazana');
        echo '<p class="mut">Smazaná zařízení si aplikace pamatuje, aby je import z Forms znovu nepřidal. Tady je můžete obnovit (vrátí se i jméno, třída a poznámka), '
            . 'nebo zapomenout – pak je další import z Forms přidá znovu, pokud jsou pořád v Excelu.</p>';
        if ($batches) {
            echo '<div class="card"><h2>Poslední mazání</h2><table><tr><th>Smazáno</th><th>Zařízení</th><th></th></tr>';
            foreach ($batches as $b) {
                echo '<tr><td class="mono">' . h((string)$b['smazano']) . '</td><td>' . (int)$b['n'] . '</td><td>'
                    . '<form method="post" onsubmit="return confirm(\'Obnovit ' . (int)$b['n'] . ' zařízení smazaných ' . h((string)$b['smazano']) . '?\')">'
                    . csrf_field() . '<button class="sec" name="restore_batch" value="' . h((string)$b['smazano']) . '">Obnovit vše z tohoto mazání</button></form></td></tr>';
            }
            echo '</table></div>';
        }
        echo '<form class="row" method="get"><input type="hidden" name="p" value="smazana">'
            . '<label>Hledat (MAC, jméno, třída, poznámka)<input name="q" value="' . h($q) . '" size="30"></label><button>Hledat</button></form>';
        echo '<p class="mut">Nalezeno ' . $found . ($found > 500 ? ' (zobrazeno 500 nejnovějších)' : '') . '.</p>';
        echo '<form method="post">' . csrf_field()
            . '<div class="scroll"><table><tr><th><input type="checkbox" onclick="for(const c of document.querySelectorAll(\'.seldel\'))c.checked=this.checked"></th>'
            . '<th>MAC</th><th>Jméno</th><th>Třída</th><th>Poznámka</th><th>Smazáno</th></tr>';
        foreach ($rows as $r) {
            echo '<tr><td><input type="checkbox" class="seldel" name="mac[]" value="' . h($r['mac']) . '"></td>'
                . '<td class="mono">' . h($r['mac']) . (is_random_mac($r['mac']) ? ' <span class="tag">náhodná</span>' : '') . '</td>'
                . '<td>' . h($r['jmeno']) . '</td><td>' . h($r['trida']) . '</td><td>' . h($r['poznamka']) . '</td>'
                . '<td class="mut">' . h(substr((string)$r['smazano'], 0, 16)) . '</td></tr>';
        }
        echo '</table></div><p class="row"><button name="restore" value="1">Obnovit vybrané</button>'
            . '<button class="sec" name="forget" value="1" onclick="return confirm(\'Zapomenutá zařízení se při dalším importu z Forms znovu přidají. Pokračovat?\')">Zapomenout vybrané</button></p></form>';
        break;

    /* ---------- Udrzba ---------- */
    case 'udrzba':
        if ($post && isset($_POST['prune'])) {
            $n = prune_postauth((int)cfg('postauth_keep_days'));
            flash("Smazáno starých záznamů přihlášení: $n");
            redirect('?p=udrzba');
        }
        if ($post && isset($_POST['delete'])) {
            $macs = array_values(array_filter(array_map('strval', (array)($_POST['mac'] ?? [])), fn($m) => $m !== ''));
            flash('Smazáno zařízení: ' . delete_devices($macs));
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
        $imp = state_get('import');
        $zal = state_get('zaloha');
        echo '<div class="card"><h2>Automatické úlohy</h2><table>';
        echo '<tr><th>Import z OneDrive</th><td>';
        if ((string)cfg('forms_url') === '') {
            echo '<span class="mut">není nastavený (<code>forms_url</code> v config.php)</span>';
        } else {
            if (isset($imp['since'])) {
                echo '<div class="flash err">Nefunguje od ' . h($imp['since']) . ' (pokusů: ' . (int)($imp['count'] ?? 0) . '). Chyba: ' . h($imp['error'] ?? '') . '</div>';
            }
            echo isset($imp['last_ok'])
                ? 'naposledy úspěšně ' . h($imp['last_ok']) . ' (přidáno ' . (int)($imp['added'] ?? 0) . ', doplněno ' . (int)($imp['filled'] ?? 0) . ')'
                : 'zatím neproběhl';
            if (isset($imp['last_run'])) {
                echo '<br><span class="mut">poslední spuštění cronem ' . h($imp['last_run']) . '</span>';
            }
        }
        echo '</td></tr><tr><th>Noční záloha seznamu</th><td>';
        echo $zal
            ? h($zal['cas']) . ' – ' . (int)$zal['pocet'] . ' zařízení, <code>' . h($zal['soubor']) . '</code>'
            : 'zatím neproběhla';
        echo '<br><span class="mut">každou noc do <code>/var/lib/macadmin/zalohy</code> (30 dní); obnova: soubor stáhnout přes SFTP a nahrát v Import / export</span></td></tr></table></div>';
        echo '<div class="card"><h2>Promazání historie přihlášení</h2><p class="mut">Smaže záznamy z <code>radpostauth</code> starší než '
            . (int)cfg('postauth_keep_days') . ' dní. Automaticky to dělá i <code>udrzba.php</code> z cronu.</p>'
            . '<form method="post">' . csrf_field() . '<button name="prune" value="1" class="sec">Promazat nyní</button></form></div>';
        echo '<div class="card"><h2>Nestandardní zápis MAC (' . count($bad) . ')</h2>'
            . '<p class="mut">FreeRADIUS hledá adresu ve tvaru <code>AA:BB:CC:DD:EE:FF</code>. Jinak zapsané adresy nemusí projít ověřením.</p>';
        $fixable = array_filter($bad, fn($b) => $b[1] !== null);
        $invalid = array_filter($bad, fn($b) => $b[1] === null);
        if ($fixable) {
            echo '<h2>Lze opravit automaticky (' . count($fixable) . ')</h2>'
                . '<div class="scroll"><table><tr><th>V databázi</th><th>Opraveno na</th></tr>';
            foreach (array_slice($fixable, 0, 200) as [$o, $n]) {
                echo '<tr><td class="mono">[' . h($o) . ']</td><td class="mono">' . h($n) . '</td></tr>';
            }
            echo '</table></div><form method="post" style="margin-top:8px">' . csrf_field()
                . '<button name="normalize" value="1">Opravit zápis</button></form>';
        }
        if ($invalid) {
            echo '<h2>Neplatné – nejsou to MAC adresy (' . count($invalid) . ')</h2>'
                . '<p class="mut">Nemají 12 šestnáctkových znaků, FreeRADIUS je nikdy nepovolí. Opravte je ručně, nebo je smažte.</p>'
                . '<form method="post" onsubmit="return confirm(\'Opravdu smazat vybrané záznamy?\')">' . csrf_field()
                . '<div class="scroll"><table><tr><th><input type="checkbox" onclick="for(const c of document.querySelectorAll(\'.selbad\'))c.checked=this.checked"></th>'
                . '<th>V databázi</th><th></th></tr>';
            foreach ($invalid as [$o]) {
                echo '<tr><td><input type="checkbox" class="selbad" name="mac[]" value="' . h($o) . '"></td>'
                    . '<td class="mono">[' . h($o) . ']</td>'
                    . '<td><a href="?p=pridat&zpet=udrzba&mac=' . urlencode($o) . '">opravit…</a></td></tr>';
            }
            echo '</table></div><p><button class="danger" name="delete" value="1">Smazat vybrané</button></p></form>';
        }
        echo '</div>';
        break;

    /* ---------- Napoveda ---------- */
    case 'napoveda':
        $keepDays = (int)cfg('postauth_keep_days');
        $rejDays = (int)cfg('rejected_days');
        $forms = (string)cfg('forms_url') !== '' ? 'nastavený' : 'nenastavený (import jen ručně)';
        $alert = trim((string)cfg('alert_email')) !== '' ? h((string)cfg('alert_email')) : 'nenastaveno';
        page_header('Nápověda', 'napoveda');
        echo <<<HTML
<div class="card help">
<p><strong>Obsah:</strong>
<a href="#princip">Jak to funguje</a> ·
<a href="#zarizeni">Zařízení</a> ·
<a href="#zamitnuta">Zamítnutá</a> ·
<a href="#smazana">Smazaná</a> ·
<a href="#nahodne">Náhodné adresy</a> ·
<a href="#import">Import</a> ·
<a href="#udrzba">Údržba</a> ·
<a href="#problemy">Když něco nefunguje</a> ·
<a href="#certifikat">Certifikát a heslo v Chrome</a> ·
<a href="#server">Server</a></p>
</div>

<div class="card help" id="princip">
<h2>Jak to funguje</h2>
<p>Žákovská wifi GFP (UniFi, UDM) se při každém připojení zařízení zeptá RADIUS serveru (FreeRADIUS na tomto serveru), jestli MAC adresu pustit.
FreeRADIUS se podívá do tabulky povolených adres (<code>radcheck</code>): je-li tam, odpoví „povoleno“, jinak „zamítnuto“. Každý pokus zapíše do historie přihlášení (<code>radpostauth</code>).</p>
<p>Tahle aplikace jen upravuje seznam povolených adres a ukazuje historii. Jméno, třída a poznámka k adrese jsou zvlášť a FreeRADIUS je nepotřebuje.
Změna v seznamu platí hned pro další připojení – nic se nerestartuje.</p>
<p>V UDM: Nastavení → Sítě → Radius servery → <em>Freeradius Linux</em> (IP tohoto serveru, port 1812). Účtovací servery nejsou potřeba.</p>
</div>

<div class="card help" id="zarizeni">
<h2>Zařízení</h2>
<ul>
<li><strong>Hledat</strong> – podle části MAC (v libovolném zápisu, např. <code>b7:32</code> nebo <code>0b9a05</code>), jména nebo poznámky (e-mailu).</li>
<li><strong>Třída</strong> – rok nástupu a písmeno, např. <code>2026.g</code>. Import ji bere ze školního e-mailu žáka (<code>2026.g.prijmeni@stu.gfp.cz</code>). <strong>Písmeno třídy</strong> vybere všechny ročníky s daným písmenem.</li>
<li><strong>Naposledy</strong> – poslední úspěšné připojení k wifi. Historie se uchovává {$keepDays} dní, „–“ znamená, že se zařízení za tu dobu nepřipojilo.</li>
<li><strong>Nepřihlášeno aspoň (dní)</strong> – zařízení, která se tak dlouho nepřipojila. Hodí se na úklid: vyfiltrovat, zaškrtnout vše v hlavičce tabulky, <em>Smazat vybrané</em>. Dokud historie nepokrývá zvolený počet dní, ukáže se červené upozornění, že výsledek není spolehlivý.</li>
<li><strong>Zařízení na e-mail aspoň</strong> – žáci s více zařízeními (např. 3 a víc): kdo si několikrát vyměnil telefon, nebo sdílí účet. Stará zařízení poznáte podle sloupce <em>Naposledy</em>.</li>
<li><strong>Smazaná zařízení si aplikace pamatuje</strong> a import z Forms je znovu nepřidá, i když jsou pořád v Excelu. Vrátit je jde na stránce <a href="#smazana">Smazaná</a>.</li>
<li><strong>upravit</strong> – úprava údajů; dole je i posledních 20 pokusů o připojení (povoleno / zamítnuto), hodí se, když žák tvrdí, že mu wifi nejde.</li>
<li>Zaškrtnutí všech v hlavičce platí jen pro aktuální stránku (max. 500 zařízení). Když je zadaný nějaký filtr, tlačítko <strong>Smazat všech N nalezených</strong> smaže všechna nalezená zařízení i přes víc stránek.</li>
<li><strong>Ročník po maturitě</strong> – vyberte třídu (např. <code>2022.a</code>), <em>Filtrovat</em> a <em>Smazat všech N nalezených</em>.</li>
</ul>
</div>

<div class="card help" id="zamitnuta">
<h2>Zamítnutá zařízení</h2>
<p>Zařízení, která se za posledních {$rejDays} dní (lze změnit) zkusila připojit a server je odmítl. Odkazem <em>povolit…</em> zařízení rovnou přidáte.
Žák, kterému wifi nejde, tu typicky najde svou adresu – porovnejte ji s tou, kterou zadal do formuláře.</p>
<p><strong>Stav „už povoleno“</strong> znamená: zařízení <em>je</em> v seznamu povolených, a přesto bylo odmítnuto. Aplikace adresu převede na standardní zápis
<code>AA:BB:CC:DD:EE:FF</code>, ale FreeRADIUS hledá přesně to, co poslalo UDM – na velikosti písmen nezáleží, na oddělovačích ano
(<code>d80b9a05b732</code> nebo <code>D8-0B-…</code> v databázi nenajde).</p>
<ul>
<li><strong>Pár takových řádků je normální</strong> – historie ukazuje i pokusy z doby, než jste zařízení povolili. Poslední pokus (sloupec <em>Naposledy</em>) je pak starší než chvíle povolení a nové už nepřibývají.</li>
<li><strong>Hodně řádků „už povoleno“ s čerstvými časy</strong> a na Údržbě stojící „Povoleno za 24 h“ znamená, že UDM posílá MAC v jiném zápisu, než je v databázi – nepřipojí se nikdo. Podívejte se do sloupce MAC, v jakém zápisu adresy chodí, a v UDM dočasně vraťte původní RADIUS server.</li>
</ul>
</div>

<div class="card help" id="smazana">
<h2>Smazaná zařízení</h2>
<p>Každé smazání se zapamatuje i se jménem, třídou a poznámkou. Na stránce <a href="?p=smazana">Smazaná</a> je vidět posledních pět mazání –
<strong>Obnovit vše z tohoto mazání</strong> vrátí celé hromadné smazání najednou (třeba když se nepovedl filtr). Jednotlivá zařízení obnovíte zaškrtnutím a <em>Obnovit vybrané</em>.</p>
<p><em>Zapomenout vybrané</em> záznam o smazání odstraní – další import z Forms pak zařízení znovu přidá, pokud je pořád v Excelu. Hodí se, když jste smazali omylem a žák mezitím nic nemění.</p>
</div>

<div class="card help" id="nahodne">
<h2>Náhodné (soukromé) adresy</h2>
<p>Štítek <span class="tag">náhodná</span> znamená, že adresa není výrobní adresa zařízení, ale soukromá adresa, kterou si vygeneroval telefon nebo počítač
(druhý znak adresy je 2, 6, A nebo E). Pro danou síť ji iPhone i Android obvykle drží stále stejnou, takže zařízení normálně funguje –
<strong>hromadně je nemažte</strong>. Adresa se ale změní po resetu nastavení sítě, po „zapomenutí“ sítě nebo když je soukromá adresa nastavená jako střídavá;
pak se žák nepřipojí a stará adresa zůstane v seznamu zbytečně.</p>
<p>Žák má proto pro síť GFP soukromou adresu vypnout (iPhone: Nastavení → Wi-Fi → ⓘ u sítě → Soukromá adresa Wi-Fi → Vypnuto; Android: Wi-Fi → síť → Ochrana soukromí → MAC adresa zařízení / telefonu).
Zbytečné adresy uklidíte filtrem <em>Nepřihlášeno aspoň … dní</em> (případně s <em>jen náhodné</em>).</p>
<p>Adresa <code>02:00:00:00:00:00</code> je zástupná hodnota, kterou Android ukazuje aplikacím místo skutečné adresy – žádné zařízení ji na wifi nepoužívá, smažte ji.</p>
</div>

<div class="card help" id="import">
<h2>Import a export</h2>
<ul>
<li><strong>Excel z MS Forms</strong> – nahrajte vždy celý soubor. Sloupce s MAC (první i druhé zařízení) se najdou podle obsahu, jméno a e-mail podle záhlaví, třída se odvodí z e-mailu.
Přidají se jen nové adresy, u existujících se doplní jen prázdné údaje, nic se nepřepisuje. Písmeno O v adrese se bere jako nula.</li>
<li><strong>Import z OneDrive</strong> (odkaz je {$forms}) – Excel s odpověďmi sdílený jako „Kdokoli s odkazem“, odkaz je v <code>config.php</code> (<code>forms_url</code>).
Server ho načítá sám každých 10 minut; tlačítkem <em>Načíst z OneDrive</em> hned.</li>
<li><strong>Upozornění e-mailem</strong> na {$alert} – když automatický import selže (OneDrive nedostupný, zrušené sdílení…): hned při první chybě, pak nejvýš jednou za 6 hodin a jednou po obnovení.</li>
<li><strong>Výsledek importu</strong>: <em>Přidáno</em> – nové adresy; <em>Přeskočeno</em> – dříve smazaná zařízení; <em>Neplatná MAC</em> – odpovědi, které nejsou adresa (překlepy, IP adresa, text) – nic se z nich nepřidá; <em>Nově přidané náhodné</em> – viz <a href="#nahodne">náhodné adresy</a>.</li>
<li><strong>CSV nebo vložený text</strong> – sloupce se záhlavím, nebo bez záhlaví v pořadí MAC; jméno; třída; poznámka.</li>
</ul>
</div>

<div class="card help" id="udrzba">
<h2>Údržba</h2>
<ul>
<li><strong>Povoleno / Zamítnuto za 24 h</strong> – rychlá kontrola, že wifi ověřuje. Ve výuce „Povoleno“ roste se stovkami.</li>
<li><strong>Automatické úlohy</strong> – kdy naposledy proběhl import z OneDrive a jestli teď nehlásí chybu, a kdy proběhla noční záloha.</li>
<li><strong>Noční záloha</strong> – každou noc se seznam povolených zařízení uloží do <code>/var/lib/macadmin/zalohy/mac-RRRR-MM-DD.csv</code> (30 dní zpět).
Obnova: soubor stáhnout přes SFTP a nahrát v <em>Import / export</em> (přidají se chybějící zařízení i s údaji; dříve smazaná se přeskočí).</li>
<li><strong>Promazání historie</strong> – záznamy starší než {$keepDays} dní maže každou noc cron (<code>udrzba.php</code>), tlačítkem hned.</li>
<li><strong>Nestandardní zápis MAC</strong> – adresy zapsané jinak než <code>AA:BB:CC:DD:EE:FF</code>; <em>Opravit zápis</em> je sjednotí (duplicity sloučí).
<strong>Neplatné</strong> nejsou MAC adresy vůbec – opravte je ručně, nebo zaškrtněte a smažte.</li>
</ul>
</div>

<div class="card help" id="problemy">
<h2>Když něco nefunguje</h2>
<ul>
<li><strong>Nepřipojí se nikdo</strong> – Údržba: roste „Povoleno za 24 h“? Na serveru <code>systemctl status radiusd</code> a <code>tail -f /var/log/radius/radius.log</code>.
Rychlý návrat: v UDM změnit IP RADIUS serveru zpět na starý server. Podrobný výpis: <code>systemctl stop radiusd; radiusd -X</code> (Ctrl+C a <code>systemctl start radiusd</code>).</li>
<li><strong>Nepřipojí se jeden žák</strong> – je-li v seznamu, otevřete <em>upravit</em> a podívejte se na poslední pokusy o připojení. Jinak ho najděte v <a href="?p=zamitnuta">Zamítnutých</a>. Adresa tam je, ale v seznamu ne → zadal do formuláře jinou (často náhodnou) adresu nebo se překlepl; povolte ji odkazem <em>povolit…</em>.
Adresa tam není → zařízení se k síti GFP vůbec nepřipojuje (špatná síť, heslo wifi).</li>
<li><strong>Přišel e-mail, že import selhal</strong> – nejčastěji zrušené sdílení souboru na OneDrive nebo změněný odkaz; zkontrolujte <code>forms_url</code> a zkuste <em>Načíst z OneDrive</em>, chyba se ukáže.</li>
<li><strong>Log hlásí BlastRADIUS / require_message_authenticator</strong> – jen doporučení; v <code>/etc/raddb/clients.conf</code> doplnit k danému klientovi <code>require_message_authenticator = yes</code> a restartovat radiusd.</li>
</ul>
</div>

<div class="card help" id="certifikat">
<h2>Certifikát a uložení hesla v Chrome</h2>
<p>Chrome nenabízí uložení hesla na stránkách s neplatným certifikátem (u adresy „Nezabezpečeno“). Aplikace má vlastní certifikát pro adresu
<code>192.168.0.1</code>; aby ho počítač bral jako platný, je potřeba ho jednou přidat mezi důvěryhodné.</p>
<p><strong>Na serveru</strong> (jen pokud certifikát ještě není – <code>ls /etc/pki/tls/certs/macadmin.crt</code>):</p>
<pre>openssl req -x509 -newkey rsa:2048 -nodes -days 3650 \
  -subj "/CN=192.168.0.1" -addext "subjectAltName=IP:192.168.0.1" \
  -keyout /etc/pki/tls/private/macadmin.key -out /etc/pki/tls/certs/macadmin.crt
chmod 600 /etc/pki/tls/private/macadmin.key
restorecon /etc/pki/tls/private/macadmin.key /etc/pki/tls/certs/macadmin.crt
cat &gt; /etc/httpd/conf.d/00-default-ssl.conf &lt;&lt;'EOF'
&lt;VirtualHost _default_:443&gt;
    SSLEngine on
    SSLCertificateFile /etc/pki/tls/certs/macadmin.crt
    SSLCertificateKeyFile /etc/pki/tls/private/macadmin.key
&lt;/VirtualHost&gt;
EOF
apachectl configtest &amp;&amp; systemctl reload httpd</pre>
<p><strong>Na počítači (Windows):</strong></p>
<ol>
<li>Přes SFTP (Bitvise) stáhnout <code>/etc/pki/tls/certs/macadmin.crt</code>.</li>
<li>Dvakrát kliknout → <em>Nainstalovat certifikát</em> → <em>Aktuální uživatel</em> → <em>Všechny certifikáty umístit do následujícího úložiště</em> →
<em>Důvěryhodné kořenové certifikační autority</em> → Dokončit a potvrdit.</li>
<li>Zavřít všechna okna Chrome a znovu otevřít <code>https://192.168.0.1/macadmin/</code> – u adresy je zámek a po přihlášení Chrome nabídne uložení hesla.</li>
</ol>
<p>Certifikát platí 10 let. Na jiném počítači stačí zopakovat krok na počítači. Bez certifikátu jde heslo uložit ručně v <code>chrome://password-manager/passwords</code> → <em>Přidat</em>.</p>
</div>

<div class="card help" id="server">
<h2>Server</h2>
<table>
<tr><th>Aktualizace aplikace z GitHubu</th><td><code>macadmin-update</code> (jako root; záloha v <code>/root/macadmin-zalohy</code>)</td></tr>
<tr><th>Nastavení</th><td><code>/var/www/macadmin/config.php</code> – přístup do DB, jméno a hash hesla správce, <code>forms_url</code>, <code>alert_email</code></td></tr>
<tr><th>Nové heslo správce</th><td><code>php -r 'echo password_hash("nove-heslo", PASSWORD_DEFAULT), PHP_EOL;'</code> a výsledek do <code>admin_pass_hash</code> (v jednoduchých uvozovkách)</td></tr>
<tr><th>Cron</th><td><code>/etc/cron.d/macadmin</code> (promazání historie a záloha ve 2:15), <code>/etc/cron.d/macadmin-import</code> (import každých 10 min)</td></tr>
<tr><th>Zálohy seznamu</th><td><code>/var/lib/macadmin/zalohy/</code> (CSV, každou noc, 30 dní)</td></tr>
<tr><th>Logy</th><td><code>/var/log/macadmin-import.log</code>, <code>/var/log/macadmin-udrzba.log</code>, <code>/var/log/radius/radius.log</code></td></tr>
<tr><th>FreeRADIUS</th><td><code>/etc/raddb/clients.conf</code> (UDM/AP a sdílené heslo), <code>/etc/raddb/mods-available/sql</code> (databáze); kontrola <code>radiusd -XC</code></td></tr>
<tr><th>Test ověření</th><td><code>radtest AA:BB:CC:DD:EE:FF AA:BB:CC:DD:EE:FF 127.0.0.1 0 testing123</code> → Access-Accept / Access-Reject</td></tr>
<tr><th>Zkušební e-mail</th><td><code>sudo -u apache php /var/www/macadmin/import-forms.php --test-mail</code></td></tr>
</table>
</div>
HTML;
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
