<?php
// Denni udrzba databaze RADIUS - spousti cron, ne webovy server.
//   15 2 * * * apache php /var/www/macadmin/udrzba.php >> /var/log/macadmin-udrzba.log 2>&1
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
require __DIR__ . '/lib.php';

$days = (int)cfg('postauth_keep_days');
$n = prune_postauth($days);
echo date('Y-m-d H:i:s') . " radpostauth: smazano $n zaznamu starsich nez $days dni\n";
