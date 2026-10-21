<?php
// Automaticky import registraci z Excelu MS Forms na OneDrive (forms_url v config.php) - spousti cron.
//   */10 * * * * apache php /var/www/macadmin/import-forms.php >> /var/log/macadmin-import.log 2>&1
// Do logu pise jen pri zmene nebo chybe. Pri chybe posle e-mail na alert_email:
// hned pri prvni chybe, pri trvajici chybe nejvys jednou za ALERT_REPEAT, a jednou po obnoveni.
// Stav (posledni uspech, trvajici chyba) se uklada do tabulky macadmin_stav a ukazuje v Udrzbe.
//   php import-forms.php --test-mail   # vyzkouseni odesilani e-mailu
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
require __DIR__ . '/lib.php';

const ALERT_REPEAT = 6 * 3600;

function now(): string
{
    return date('Y-m-d H:i:s');
}

if (in_array('--test-mail', $argv, true)) {
    if (trim((string)cfg('alert_email')) === '') {
        echo "alert_email neni v config.php nastaveno\n";
        exit(1);
    }
    $ok = send_alert('macadmin: zkušební e-mail', 'Odesílání upozornění z macadmin funguje.');
    echo $ok ? "E-mail predan postfixu, zkontrolujte schranku (i spam).\n" : "Odeslani selhalo (bezi postfix?).\n";
    exit($ok ? 0 : 1);
}

$url = (string)cfg('forms_url');
if ($url === '') {
    fwrite(STDERR, "forms_url neni v config.php nastaveno\n");
    exit(1);
}
// stav v tabulce macadmin_stav (zobrazuje se v Udrzbe): since/count/mailed/error pri chybe, last_ok/added/filled
try {
    $st = state_get('import');
} catch (Throwable $e) {
    $st = []; // databaze nebezi - import stejne selze a upozorneni se posle
}
$st['last_run'] = now();
try {
    $s = import_forms_url($url);
} catch (Throwable $e) {
    $msg = $e->getMessage();
    echo now() . ' chyba: ' . $msg . "\n";
    $st['error'] = $msg;
    $st['since'] ??= now();
    $st['count'] = ($st['count'] ?? 0) + 1;
    if (!isset($st['mailed']) || time() - $st['mailed'] >= ALERT_REPEAT) {
        $body = "Automatický import registrací z OneDrive se nepovedl.\n\n"
            . "Chyba: $msg\n"
            . "Nefunguje od: $st[since] (neúspěšných pokusů: $st[count])\n\n"
            . "Import se dál zkouší každých 10 minut. Dokud chyba trvá, připomínka přijde nejvýš jednou za "
            . (ALERT_REPEAT / 3600) . " hodin; až import zase projde, přijde jeden e-mail.\n"
            . "Log: /var/log/macadmin-import.log";
        if (send_alert('macadmin: import z OneDrive selhal', $body)) {
            $st['mailed'] = time();
            echo now() . " upozorneni odeslano na " . cfg('alert_email') . "\n";
        }
    }
    try {
        state_set('import', $st);
    } catch (Throwable $e) {
    }
    exit(1);
}
if (isset($st['mailed'])) {
    send_alert('macadmin: import z OneDrive znovu funguje',
        "Import registrací z OneDrive zase funguje (nefungoval od $st[since], neúspěšných pokusů: " . ($st['count'] ?? 0) . ').');
    echo now() . " import znovu funguje\n";
}
unset($st['error'], $st['since'], $st['count'], $st['mailed']);
state_set('import', array_merge($st, ['last_ok' => now(), 'added' => $s['added'], 'filled' => $s['filled']]));
log_import('OneDrive – automaticky', $s);
if ($s['added'] || $s['filled']) {
    echo now() . " import: pridano $s[added], doplneno $s[filled]"
        . ($s['new'] ? ': ' . implode(', ', $s['new']) : '') . "\n";
}
