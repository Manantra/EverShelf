#!/usr/bin/env php
<?php
/**
 * Regression tests: "non lo compro" must survive the 5-minute cron.
 *
 * The report (from an external shopping client, spesaPoli): after a `shopping_remove`
 * with `purchased: false` the cron put 6 products back on the list within 5 minutes
 * (Avocado, Taralli, Macedonia, Mandorle, Snack, Potato Wedges). Cause: the purchase
 * blocklist alone is not enough —
 *   * `smartItemNeedsRestockOverride()` lets a DEPLETED product through the block, and
 *   * `internalShoppingAutoAddCritical()` cleared the block when it re-added the row.
 *
 * A dismiss is now a dated decision (`app_settings.shopping_dismissed`, name → YYYY-MM-DD,
 * `SHOPPING_DISMISS_DAYS` default 14, `dismiss_days` per call) that the cron respects and
 * that only a purchase lifts — the same pattern the out-of-season dismiss already used.
 *
 * Run: php scripts/test-shopping-dismiss.php
 */
define('CRON_MODE', true);
require_once __DIR__ . '/../api/bootstrap.php';
require_once __DIR__ . '/../api/index.php';

$fail = 0;

function assert_true(bool $cond, string $msg): void
{
    global $fail;
    if (!$cond) {
        echo "FAIL: {$msg}\n";
        $fail++;
    } else {
        echo "OK: {$msg}\n";
    }
}

function assert_same($expected, $actual, string $msg): void
{
    global $fail;
    if ($expected !== $actual) {
        echo "FAIL: {$msg} (got " . var_export($actual, true) . ", expected " . var_export($expected, true) . ")\n";
        $fail++;
    } else {
        echo "OK: {$msg}\n";
    }
}

$dbFile = sys_get_temp_dir() . '/evershelf_test_dismiss.sqlite';
@unlink($dbFile);
register_shutdown_function(static function () use ($dbFile): void { @unlink($dbFile); });

$db = new PDO('sqlite:' . $dbFile);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->exec("CREATE TABLE products (id INTEGER PRIMARY KEY, name TEXT, brand TEXT DEFAULT '', category TEXT DEFAULT '',
    unit TEXT DEFAULT 'pz', default_quantity REAL DEFAULT 1, package_unit TEXT DEFAULT '', shopping_name TEXT DEFAULT '')");
$db->exec("CREATE TABLE inventory (id INTEGER PRIMARY KEY AUTOINCREMENT, product_id INTEGER, quantity REAL,
    location TEXT DEFAULT 'dispensa', expiry_date TEXT, opened_at TEXT)");
$db->exec("CREATE TABLE transactions (id INTEGER PRIMARY KEY AUTOINCREMENT, product_id INTEGER, type TEXT,
    quantity REAL, location TEXT DEFAULT 'dispensa', notes TEXT DEFAULT '', undone INTEGER DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
$db->exec("CREATE TABLE shopping_list (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, raw_name TEXT DEFAULT '',
    specification TEXT DEFAULT '')");
$db->exec("CREATE TABLE app_settings (key TEXT PRIMARY KEY, value TEXT NOT NULL DEFAULT '',
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
$db->exec("INSERT INTO products (id, name, unit, default_quantity, shopping_name) VALUES
    (1, 'Avocado Hass', 'pz', 1, 'Avocado'),
    (2, 'Tarallini',    'g', 250, 'Taralli'),
    (3, 'Macedonia di Verdure', 'g', 250, 'Verdure')");

// ── The store itself ───────────────────────────────────────────────────────
assert_true(!shoppingIsDismissed($db, 'Avocado'), 'nothing is dismissed at the start');

shoppingMarkDismissed($db, 'Avocado', 'Avocado Hass', 14);
assert_true(shoppingIsDismissed($db, 'Avocado'), 'the row name is dismissed');
assert_true(shoppingIsDismissed($db, 'Avocado Hass'), 'the product name is dismissed too');
$map = shoppingDismissLoad($db);
assert_same(date('Y-m-d', strtotime('+14 days')), $map['avocado'] ?? null, 'the expiry is 14 days ahead');
assert_true(shoppingDismissDays() >= 1, 'the env default is a positive number of days');

// A clamped / custom window.
shoppingMarkDismissed($db, 'Taralli', 'Tarallini', 999);
$map = shoppingDismissLoad($db);
assert_same(date('Y-m-d', strtotime('+365 days')), $map['taralli'] ?? null, 'an absurd dismiss_days is clamped to a year');

// An expired entry is ignored (and dropped on the next save).
$raw = json_decode((string)$db->query("SELECT value FROM app_settings WHERE key = 'shopping_dismissed'")->fetchColumn(), true);
$raw['avocado'] = date('Y-m-d', strtotime('-1 day'));
$db->prepare("UPDATE app_settings SET value = ? WHERE key = 'shopping_dismissed'")->execute([json_encode($raw)]);
assert_true(!shoppingIsDismissed($db, 'Avocado'), 'a dismiss that has expired no longer hides anything');

// Bought it → the dismiss is lifted for the whole family.
shoppingMarkDismissed($db, 'Avocado', 'Avocado Hass', 14);
shoppingClearDismissedForProduct($db, 1);

// ── The cron: a dismissed product is NOT put back on the list ───────────────
if (isShoppingBringMode()) {
    echo "SKIP: SHOPPING_MODE=bring — internal auto-add is disabled.\n";
} else {
    $cacheFile   = __DIR__ . '/../data/smart_shopping_cache.json';
    $cacheBackup = file_exists($cacheFile) ? file_get_contents($cacheFile) : null;
    register_shutdown_function(static function () use ($cacheFile, $cacheBackup): void {
        if ($cacheBackup === null) {
            @unlink($cacheFile);
        } else {
            @file_put_contents($cacheFile, $cacheBackup);
        }
    });
    // A cache that still predicts both products as depleted/urgent, exactly what the
    // cron sees five minutes after the external client removed them.
    file_put_contents($cacheFile, json_encode([
        'success' => true,
        'cached_ts' => time(),
        'items' => [
            ['name' => 'Avocado Hass', 'shopping_name' => 'Avocado', 'unit' => 'pz', 'urgency' => 'critical',
             'current_qty' => 0, 'period_usage' => 2, 'days_left' => 0],
            ['name' => 'Macedonia di Verdure', 'shopping_name' => 'Verdure', 'unit' => 'g', 'urgency' => 'medium',
             'current_qty' => 0, 'period_usage' => 100, 'days_left' => 0],
        ],
    ], JSON_UNESCAPED_UNICODE));

    $db->exec('DELETE FROM shopping_list');
    shoppingMarkDismissed($db, 'Avocado', 'Avocado Hass', 14);   // the user said "non lo compro"

    $res = internalShoppingAutoAddCritical($db);
    $names = $db->query('SELECT name FROM shopping_list')->fetchAll(PDO::FETCH_COLUMN);
    assert_true(!in_array('Avocado', $names, true), 'the cron does NOT re-add a dismissed product');
    assert_true(in_array('Verdure', $names, true), 'the same cron still adds everything else (control)');
    assert_true(shoppingIsDismissed($db, 'Avocado'),
        'the dismiss survives the cron run (the old code cleared the blocklist here)');

    // The code path is the one the report pointed at.
    $api = (string)file_get_contents(__DIR__ . '/../api/index.php');
    $removeFn = substr($api, (int)strpos($api, 'function shoppingRemoveInternal('), 4000);
    assert_true(str_contains($removeFn, 'shoppingMarkDismissed($db, $name, $rawName, $dismissDays)'),
        'shopping_remove with purchased=false stores the dismiss');
    assert_true(str_contains($removeFn, "isset(\$input['dismiss_days'])"),
        'shopping_remove accepts an optional dismiss_days');
    assert_true(str_contains($removeFn, 'shoppingClearDismissed('),
        'shopping_remove with purchased=true lifts the dismiss');

    $cronFn = substr($api, (int)strpos($api, 'function internalShoppingAutoAddCritical('), 4000);
    assert_true(str_contains($cronFn, 'shoppingIsDismissed('),
        'internalShoppingAutoAddCritical() skips dismissed products');

    // The real-time sync (a stock change) must not put it back either.
    $db->exec('DELETE FROM shopping_list');
    shoppingMarkDismissed($db, 'Verdure', 'Macedonia di Verdure', 14);
    bringQuickSyncProduct($db, 3);
    $names = $db->query('SELECT name FROM shopping_list')->fetchAll(PDO::FETCH_COLUMN);
    assert_true(!in_array('Verdure', $names, true),
        'a stock change does not re-add a dismissed product (bringQuickSyncProduct)');

    shoppingClearDismissedForProduct($db, 3);   // the same clear a purchase performs
    bringQuickSyncProduct($db, 3);
    $names = $db->query('SELECT name FROM shopping_list')->fetchAll(PDO::FETCH_COLUMN);
    assert_true(in_array('Verdure', $names, true),
        'once the dismiss is lifted the same sync adds it (control)');
}

if ($fail === 0) {
    echo "\nAll shopping dismiss tests passed.\n";
}
exit($fail === 0 ? 0 : 1);

assert_true(!shoppingIsDismissed($db, 'Avocado'), 'a purchase lifts the dismiss (shoppingClearDismissedForProduct)');
