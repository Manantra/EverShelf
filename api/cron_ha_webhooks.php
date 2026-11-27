<?php
/** Drain the private HA outbox. Run as the web user, never via HTTP. */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}
// An inactive installation should not bootstrap the app every scheduler tick.
if (!is_dir(dirname(__DIR__) . '/data/ha_webhook_outbox')) exit(0);
define('CRON_MODE', true);
require_once __DIR__ . '/index.php';
exit(evershelfHaOutboxDrain() < 0 ? 1 : 0);
