#!/usr/bin/env php
<?php
/**
 * Regression tests: the dashboard's "Dimenticati" list is "the N nearest expiry dates
 * among the rows nobody uses".
 *
 * The report: the panel listed a rotation of the longest-idle packs, so a sealed box of
 * salt could sit there while a yoghurt expiring tomorrow was missing, and the row did not
 * say when that product expires at all.
 *
 * Run: php scripts/test-forgotten-expiring.php
 */
define('CRON_MODE', true);
require_once __DIR__ . '/../api/bootstrap.php';
require_once __DIR__ . '/../api/index.php';

$fail = 0;
function assert_true(bool $cond, string $msg, string $detail = ''): void
{
    global $fail;
    if (!$cond) { echo "FAIL: {$msg}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; $fail++; }
    else { echo "OK: {$msg}\n"; }
}

$dbFile = sys_get_temp_dir() . '/evershelf_test_forgotten.sqlite';
@unlink($dbFile);
register_shutdown_function(static function () use ($dbFile): void { @unlink($dbFile); });

$db = new PDO('sqlite:' . $dbFile);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->exec("CREATE TABLE products (id INTEGER PRIMARY KEY, name TEXT, brand TEXT DEFAULT '', category TEXT DEFAULT '',
    unit TEXT DEFAULT 'pz', default_quantity REAL DEFAULT 1, package_unit TEXT DEFAULT '', image_url TEXT DEFAULT '')");
$db->exec("CREATE TABLE inventory (id INTEGER PRIMARY KEY AUTOINCREMENT, product_id INTEGER, quantity REAL,
    unit TEXT DEFAULT 'pz', location TEXT DEFAULT 'dispensa', expiry_date TEXT, opened_at TEXT,
    added_at DATETIME, updated_at DATETIME)");
$db->exec("CREATE TABLE transactions (id INTEGER PRIMARY KEY AUTOINCREMENT, product_id INTEGER, type TEXT,
    quantity REAL, location TEXT DEFAULT 'dispensa', notes TEXT DEFAULT '', undone INTEGER DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");

$in = static function (PDO $db, string $name, string $category, float $qty, string $unit, ?string $expiry, string $addedDaysAgo): int {
    $db->prepare('INSERT INTO products (name, category, unit, default_quantity) VALUES (?, ?, ?, 1)')
        ->execute([$name, $category, $unit]);
    $pid = (int)$db->lastInsertId();
    $db->prepare('INSERT INTO inventory (product_id, quantity, unit, location, expiry_date, added_at, updated_at)
                  VALUES (?, ?, ?, "dispensa", ?, ?, ?)')
        ->execute([$pid, $qty, $unit, $expiry, date('Y-m-d H:i:s', strtotime('-' . $addedDaysAgo . ' days')), date('Y-m-d H:i:s')]);
    return $pid;
};
$used = static function (PDO $db, int $pid, string $when): void {
    $db->prepare("INSERT INTO transactions (product_id, type, quantity, created_at) VALUES (?, 'out', 1, ?)")
        ->execute([$pid, date('Y-m-d H:i:s', strtotime($when))]);
};

// 1. never used, expires in 2 days          → in, first
$yogurt = $in($db, 'Yogurt bianco', 'latticini', 2, 'pz', date('Y-m-d', strtotime('+2 days')), '40');
// 2. used 3 days ago, expires in 4 days     → out (it is in use)
$milk = $in($db, 'Latte fresco', 'latticini', 1, 'pz', date('Y-m-d', strtotime('+4 days')), '20');
$used($db, $milk, '-3 days');
// 3. never used, expires in 6 days          → in, after the yogurt
$tomato = $in($db, 'Passata di pomodoro', 'conserve', 2, 'conf', date('Y-m-d', strtotime('+6 days')), '120');
// 4. expired yesterday, never used          → in (it is the closest date of all)
$old = $in($db, 'Ricotta', 'latticini', 1, 'conf', date('Y-m-d', strtotime('-1 days')), '60');
// 5. no expiry date                         → out
$in($db, 'Sale grosso', 'cereali', 1, 'conf', null, '400');
// 6. hygiene even with an expiry            → out
$in($db, 'Bagnoschiuma', 'igiene', 1, 'pz', date('Y-m-d', strtotime('+1 days')), '50');
// 7. empty row                              → out
$in($db, 'Cracker finiti', 'cereali', 0, 'conf', date('Y-m-d', strtotime('+1 days')), '50');
// 8. used long ago, expires in 30 days      → in (idle beyond the window)
$pasta = $in($db, 'Pasta lunga', 'cereali', 1, 'conf', date('Y-m-d', strtotime('+30 days')), '200');
$used($db, $pasta, '-90 days');

$items = forgottenExpiringItems($db, 5, 21);
$names = array_column($items, 'name');
assert_true(count($items) === 4, 'four rows qualify (expiry + idle/never used)', json_encode($names));
assert_true($names[0] === 'Ricotta', 'the already-expired row comes first', json_encode($names));
assert_true($names[1] === 'Yogurt bianco', 'then the nearest future expiry', json_encode($names));
assert_true(!in_array('Latte fresco', $names, true), 'a product used three days ago is not forgotten', json_encode($names));
assert_true(!in_array('Sale grosso', $names, true), 'no expiry date, no row');
assert_true(!in_array('Bagnoschiuma', $names, true), 'hygiene and cleaning never reach this list');
assert_true(!in_array('Cracker finiti', $names, true), 'an empty row is not forgotten stock');

$yogurtRow = null;
foreach ($items as $it) { if ($it['name'] === 'Yogurt bianco') { $yogurtRow = $it; break; } }
assert_true((bool)$yogurtRow, 'the yogurt row is in the payload');
if ($yogurtRow) {
    assert_true($yogurtRow['never_used'] === true, 'never_used is exposed');
    assert_true($yogurtRow['days_to_expiry'] === 2, 'days_to_expiry counts the days left', (string)$yogurtRow['days_to_expiry']);
    assert_true($yogurtRow['expiry_date'] === date('Y-m-d', strtotime('+2 days')), 'expiry_date travels with the row');
    assert_true($yogurtRow['inventory_id'] > 0 && $yogurtRow['product_id'] === $yogurt, 'the row keeps both ids');
}
$expired = $items[0] ?? [];
assert_true(($expired['days_to_expiry'] ?? 0) === -1, 'an expired row has a negative countdown', json_encode($expired));

$limited = forgottenExpiringItems($db, 2, 21);
assert_true(count($limited) === 2, 'the limit is honoured');
assert_true(forgottenExpiringItems($db, 999, 21) !== [], 'a huge limit is clamped, not fatal');
$wide = forgottenExpiringItems($db, 5, 365);
$wideNames = array_column($wide, 'name');
// "never used" always qualifies: the window only decides about the rows that were used.
assert_true(!in_array('Pasta lunga', $wideNames, true), 'a wider idle window drops the merely idle row', json_encode($wideNames));
assert_true(count($wide) === 3, 'the never-used rows stay', json_encode($wideNames));

// Wiring: the dashboard asks for this mode, the action answers with it.
$app = (string)file_get_contents(__DIR__ . '/../assets/js/app.js');
$api = (string)file_get_contents(__DIR__ . '/../api/index.php');
assert_true(str_contains($app, "mode: 'expiry'"), 'the dashboard asks for mode=expiry');
assert_true(str_contains($app, "escapeHtml(formatDate(item.expiry_date))"), 'the row prints the expiry date');
assert_true(str_contains($api, "if (\$mode === 'expiry')") && str_contains($api, 'forgottenExpiringItems('), 'the action dispatches to the new query');
assert_true(str_contains((string)file_get_contents(__DIR__ . '/../index.html'), 'data-i18n="dashboard.stale_hint"'), 'the hint is still the i18n key');

// Six locales describe the new rule.
foreach (['it', 'en', 'de', 'fr', 'es', 'zh'] as $loc) {
    $doc = json_decode((string)file_get_contents(__DIR__ . '/../translations/' . $loc . '.json'), true);
    $hint = (string)($doc['dashboard']['stale_hint'] ?? '');
    assert_true(str_contains($hint, '5'), "translations/{$loc}.json says five products", $hint);
}

echo "\n" . ($fail === 0 ? 'All forgotten-expiring tests passed.' : "{$fail} failed") . "\n";
exit($fail === 0 ? 0 : 1);
