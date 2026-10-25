<?php
// Denni udrzba - spousti cron, ne webovy server:
// ulozeni souhrnu statistik, promazani historie prihlaseni (radpostauth) a zaloha seznamu povolenych zarizeni (CSV, 30 dni).
//   15 2 * * * apache php /var/www/macadmin/udrzba.php >> /var/log/macadmin-udrzba.log 2>&1
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
require __DIR__ . '/lib.php';

const BACKUP_DIR = '/var/lib/macadmin/zalohy';

// souhrny statistik ulozit drive, nez se historie promaze
try {
    stats_refresh();
} catch (Throwable $e) {
    echo date('Y-m-d H:i:s') . ' statistiky selhaly: ' . $e->getMessage() . "\n";
}

$days = (int)cfg('postauth_keep_days');
$n = prune_postauth($days);
echo date('Y-m-d H:i:s') . " radpostauth: smazano $n zaznamu starsich nez $days dni\n";

db()->exec('DELETE FROM import_historie WHERE cas < NOW() - INTERVAL 1 YEAR');

try {
    [$file, $count] = backup_devices(BACKUP_DIR);
    echo date('Y-m-d H:i:s') . " zaloha: $count zarizeni do $file\n";
} catch (Throwable $e) {
    echo date('Y-m-d H:i:s') . ' zaloha selhala: ' . $e->getMessage() . "\n";
    exit(1);
}
