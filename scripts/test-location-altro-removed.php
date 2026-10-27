#!/usr/bin/env php
<?php
/**
 * Guard test — the "Altro" location is gone (1.16.19).
 *
 * "Altro" was a generic bucket: whatever did not fit a real place ended up there, so
 * stock hid instead of living where it belongs. The location is removed from the app;
 * rows still pointing at it are migrated to the first location (dispensa) exactly
 * once, and the client says so once per device with the number and the destination.
 *
 * The category `altro` is a different thing and must stay (it is the fallback the
 * classifier returns for an unknown name) — this test guards both sides.
 *
 * Run: php scripts/test-location-altro-removed.php
 */

define('CRON_MODE', true);
require_once __DIR__ . '/../api/bootstrap.php';
require_once __DIR__ . '/../api/database.php';

$root = dirname(__DIR__);
$app  = (string)file_get_contents($root . '/assets/js/app.js');
$html = (string)file_get_contents($root . '/index.html');
$api  = (string)file_get_contents($root . '/api/index.php');
$fail = 0;
$checks = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $fail, $checks;
    $checks++;
    if ($ok) {
        echo "ok    {$label}\n";
    } else {
        $fail++;
        echo "FAIL  {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

// ── 1. The migration really moves the stock, once ───────────────────────────
$tmpPath = tempnam(sys_get_temp_dir(), 'evs_altro_');
@unlink($tmpPath);
$dbPath = $tmpPath . '.sqlite';
$db = new PDO('sqlite:' . $dbPath);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
initializeDB($db);

$db->exec("INSERT INTO products (name, unit) VALUES ('Caffè test', 'pz')");
$productId = (int)$db->lastInsertId();
$db->exec("INSERT INTO inventory (product_id, location, quantity) VALUES ({$productId}, 'altro', 2)");
$db->exec("INSERT INTO inventory (product_id, location, quantity) VALUES ({$productId}, 'frigo', 1)");

migrateDB($db);

$rows = $db->query("SELECT location, quantity FROM inventory ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
check('the "altro" row moved to dispensa',
    ($rows[0]['location'] ?? '') === 'dispensa' && (float)($rows[0]['quantity'] ?? 0) === 2.0,
    json_encode($rows));
check('another location is untouched', ($rows[1]['location'] ?? '') === 'frigo');
check('nothing is left in "altro"',
    (int)$db->query("SELECT COUNT(*) FROM inventory WHERE location = 'altro'")->fetchColumn() === 0);

$flag = json_decode((string)$db->query("SELECT value FROM app_settings WHERE key = 'migration_location_altro_v1'")->fetchColumn(), true);
check('the migration records how much it moved', is_array($flag) && (int)($flag['moved'] ?? 0) === 1, json_encode($flag));
check('the record names the destination', ($flag['to'] ?? '') === 'dispensa');

// Idempotency: a second boot must not re-count or re-move anything.
$db->exec("INSERT INTO inventory (product_id, location, quantity) VALUES ({$productId}, 'altro', 5)");
migrateDB($db);
$flag2 = json_decode((string)$db->query("SELECT value FROM app_settings WHERE key = 'migration_location_altro_v1'")->fetchColumn(), true);
check('the migration is one-shot (the recorded count does not change)',
    (int)($flag2['moved'] ?? 0) === (int)($flag['moved'] ?? -1));
$db = null;
@unlink($dbPath);

// ── 2. The location is gone from the client ────────────────────────────────
check('"altro" is no longer a built-in location',
    !preg_match("/'altro':\\s*\\{\\s*icon/s", explode('let LOCATIONS', $app)[0]));
check('the runtime location map has no "altro"', !preg_match("/let LOCATIONS = \\{[^}]*'altro'/s", $app));
check('the add-location form no longer reserves "Altro"',
    !str_contains($app, "'dispensa', 'frigo', 'freezer', 'altro'"));
check('the inventory page has no hardcoded Altro tab', !str_contains($html, "filterLocation('altro')"));
check('an old "altro" row is read as dispensa (legacy alias)',
    str_contains($app, "const LEGACY_LOCATION_ALIASES = { altro: 'dispensa' }")
    && str_contains($app, 'normalizeLocationKey(item.location)'));

// ── 3. The category `altro` is a different thing and stays ─────────────────
check('the fallback category still exists',
    str_contains($app, "'cereali': '🌾', 'igiene': '🧴', 'pulizia': '🧹', 'altro': '📦'"));
check('the shopping section still groups the fallback category', str_contains($app, "key: 'altro'"));

// ── 4. The notice reaches the client ───────────────────────────────────────
check('get_settings exposes the migration notice',
    str_contains($api, "'location_altro_migration'") && str_contains($api, "'migration_location_altro_v1'"));
check('the client shows it once per device',
    str_contains($app, 'function _maybeShowLocationAltroNotice(')
    && str_contains($app, "localStorage.getItem('evershelf_location_altro_notice')")
    && str_contains($app, "showToast(t('location_altro.removed', { n: info.moved, to: toLabel }), 'warning', 9000)"));
check('the notice is shown after the app is visible',
    str_contains($app, '_maybeShowLocationAltroNotice(_serverSettings);'));

// ── 4b. A stale client cache cannot print a key that no longer exists ──────
// The migration rewrites the rows, but a phone holding an old payload (or a browser
// cache from before 1.16.19) would still carry "altro": every label lookup normalises
// the legacy key, so the UI never shows a raw "altro" or an empty icon.
preg_match_all('/LOCATIONS\[([^\]]+)\]/', $app, $m);
// `locKey` is `normalizeLocationKey(currentLocation)` at the top of _syncLocationFilterUi():
// the filter UI (dropdown + cabinet pill) normalises once and reads that value.
$raw = array_filter($m[1] ?? [], fn($k) => !str_starts_with(ltrim($k), 'normalizeLocationKey(')
    && !in_array(ltrim($k), ['cur', 'hidden.value', '$key', 'key', 'name', 'locKey'], true));
check('every LOCATIONS lookup normalises legacy keys', $raw === [], implode(', ', $raw));
check('the add/use forms no longer offer the removed location',
    !str_contains((string)file_get_contents($root . '/index.html'), "locations.altro")
    && !str_contains($app, "selectLocation(this, 'altro')"));

// ── 5. i18n: the notice exists in six languages, the old label is gone ─────
foreach (['it', 'en', 'de', 'fr', 'es', 'zh'] as $loc) {
    $doc = json_decode((string)file_get_contents($root . "/translations/{$loc}.json"), true);
    $msg = $doc['location_altro']['removed'] ?? '';
    check("translations/{$loc}.json has location_altro.removed with {n} and {to}",
        is_string($msg) && str_contains($msg, '{n}') && str_contains($msg, '{to}'));
    check("translations/{$loc}.json no longer offers locations.altro", !isset($doc['locations']['altro']));
    $hint = $doc['settings']['custom_locations']['builtin_hint'] ?? '';
    check("translations/{$loc}.json built-in hint no longer names Altro",
        is_string($hint) && stripos($hint, 'altro') === false);
}

echo "\n{$checks} checks, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
