<?php
// Automaticky import registraci z Excelu MS Forms na OneDrive (forms_url v config.php) - spousti cron.
//   */10 * * * * apache php /var/www/macadmin/import-forms.php >> /var/log/macadmin-import.log 2>&1
// Do logu pise jen pri zmene nebo chybe.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
require __DIR__ . '/lib.php';

$url = (string)cfg('forms_url');
if ($url === '') {
    fwrite(STDERR, "forms_url neni v config.php nastaveno\n");
    exit(1);
}
try {
    $s = import_forms_url($url);
} catch (Throwable $e) {
    echo date('Y-m-d H:i:s') . ' chyba: ' . $e->getMessage() . "\n";
    exit(1);
}
if ($s['added'] || $s['filled']) {
    echo date('Y-m-d H:i:s') . " import: pridano $s[added], doplneno $s[filled]"
        . ($s['new'] ? ': ' . implode(', ', $s['new']) : '') . "\n";
}
