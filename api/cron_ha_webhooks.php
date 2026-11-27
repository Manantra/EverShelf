<?php
/** Drain the private HA outbox. Run as the web user, never via HTTP. */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}
define('CRON_MODE', true);
require_once __DIR__ . '/lib/env.php';
// The async switch is an administrator-only .env setting. Avoid bootstrapping
// inactive fresh installations every ten seconds.
$health = in_array('--health', $argv, true);
$async = (loadEnv()['HA_WEBHOOK_ASYNC'] ?? 'false') === 'true';
if (!$async && ($health || !is_dir(dirname(__DIR__) . '/data/ha_webhook_outbox'))) {
    if ($health) echo json_encode(['enabled' => false, 'ok' => true, 'state' => 'disabled',
        'last_run' => null, 'pending' => null, 'oldest_age' => null]) . PHP_EOL;
    exit(0);
}
require_once __DIR__ . '/index.php';
if ($health) {
    $status = evershelfHaWorkerStatus();
    echo json_encode($status) . PHP_EOL;
    exit($status['ok'] ? 0 : 1);
}
exit(evershelfHaOutboxDrain() < 0 ? 1 : 0);
