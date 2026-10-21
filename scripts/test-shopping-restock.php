#!/usr/bin/env php
<?php
/**
 * Regression tests: a FINISHED product must reach the shopping list.
 *
 * The report: "prodotti che sono finiti come marmellata, yogurt, ecc non sono nella
 * lista della spesa". Two independent causes, both fixed here:
 *
 *   1. smartShopping() suppressed a depleted product as soon as any other product of
 *      the same `shopping_name` family had stock — adding grams, packs and pieces
 *      together, counting expired batches and 0.5 g traces. In a real pantry that hid
 *      74 finished products with history: "360 g" of lemon jam kept every jar of jam
 *      off the list, an expired pack kept butter off, a smear kept tomatoes off.
 *      Now the family must still hold a USABLE purchase: fresh, above the trace
 *      threshold, converted to the same base family with the package size
 *      (`evershelfBaseQty()`), and at least one pack / half the usual bought quantity.
 *   2. bringAddDepletedProduct() skipped the add for the same reason. An explicit
 *      "I finished this" (usa tutto) now always lands on the list — the row carries
 *      🛒 Esaurito, so the cleanup never removes it behind the user's back.
 *
 * Run: php scripts/test-shopping-restock.php
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

// ── Unit helpers: a trace, an expired pack and another scale never cover ---------
assert_true(!evershelfStockRowCoversUnit(0.3, 'g', null, 'g'), 'a 0.3 g trace is not stock');
assert_true(evershelfStockRowCoversUnit(360.0, 'g', null, 'g'), '360 g of the same scale is stock');
assert_true(!evershelfStockRowCoversUnit(2.0, 'conf', '2020-01-01', 'conf'), 'an expired pack never covers a purchase');
assert_true(evershelfStockRowCoversUnit(2.0, 'conf', date('Y-m-d', strtotime('+1 year')), 'conf'), 'a fresh pack covers');

// ── Fixture: a realistic family mix (never the production DB) ───────────────────
$dbFile = sys_get_temp_dir() . '/evershelf_test_restock.sqlite';
@unlink($dbFile);
register_shutdown_function(static function () use ($dbFile): void { @unlink($dbFile); });

$db = new PDO('sqlite:' . $dbFile);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->exec("CREATE TABLE products (id INTEGER PRIMARY KEY, name TEXT, brand TEXT DEFAULT '', category TEXT DEFAULT '',
    unit TEXT DEFAULT 'pz', default_quantity REAL DEFAULT 1, package_unit TEXT DEFAULT '', shopping_name TEXT DEFAULT '',
    favorite INTEGER DEFAULT 0, kind TEXT DEFAULT '', favorite_user_override INTEGER DEFAULT 0)");
$db->exec("CREATE TABLE inventory (id INTEGER PRIMARY KEY AUTOINCREMENT, product_id INTEGER, quantity REAL,
    unit TEXT DEFAULT '', location TEXT DEFAULT 'dispensa', expiry_date TEXT, opened_at TEXT)");
$db->exec("CREATE TABLE transactions (id INTEGER PRIMARY KEY AUTOINCREMENT, product_id INTEGER, type TEXT,
    quantity REAL, location TEXT DEFAULT 'dispensa', notes TEXT DEFAULT '', undone INTEGER DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
$db->exec("CREATE TABLE shopping_list (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, raw_name TEXT DEFAULT '',
    specification TEXT DEFAULT '')");
$db->exec("CREATE TABLE app_settings (key TEXT PRIMARY KEY, value TEXT NOT NULL DEFAULT '',
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP)");

$soon = date('Y-m-d', strtotime('+1 year'));
$past = date('Y-m-d', strtotime('-10 days'));

// id 1: 360 g of lemon jam at home (fresh)   → 0.58 of the 620 g pack the household buys
// id 2: the finished 620 g jar of jam        → must APPEAR (shortfall, not cover)
// id 3: 2 kg of greek yogurt at home (fresh) → 16 packs of the 125 g one
// id 4: the finished 125 g yogurt            → must stay hidden (a full purchase is home)
// id 5: a 0.3 g trace of coffee              → must APPEAR
// id 6: the finished 250 g coffee            → must APPEAR
// id 7: an expired pack of tuna              → must APPEAR
// id 8: the finished tin of tuna             → must APPEAR
$db->exec("INSERT INTO products (id, name, unit, default_quantity, package_unit, shopping_name) VALUES
    (1, 'Marmellata di limone', 'g',    360, '',  'Marmellata'),
    (2, 'Marmellata',           'conf', 620, 'g', 'Marmellata'),
    (3, 'Yogurt greco',         'g',   2000, '',  'Yogurt'),
    (4, 'Yogurt bianco',        'conf', 125, 'g', 'Yogurt'),
    (5, 'Caffè in grani',       'g',     10, '',  'Caffè'),
    (6, 'Caffè macinato',       'g',    250, '',  'Caffè'),
    (7, 'Tonno all olio',       'conf',  80, 'g', 'Tonno'),
    (8, 'Tonno naturale',       'conf',  80, 'g', 'Tonno')");
$db->exec("INSERT INTO inventory (product_id, quantity, expiry_date) VALUES
    (1, 360, '{$soon}'),
    (3, 2000, '{$soon}'),
    (5, 0.3, '{$soon}'),
    (7, 1, '{$past}')");
$db->exec("INSERT INTO transactions (product_id, type, quantity, created_at) VALUES
    (2, 'in', 620, datetime('now','-90 days')), (2, 'out', 620, datetime('now','-10 days')),
    (4, 'in', 125, datetime('now','-90 days')), (4, 'out', 125, datetime('now','-10 days')),
    (6, 'in', 250, datetime('now','-90 days')), (6, 'out', 250, datetime('now','-10 days')),
    (8, 'in',  80, datetime('now','-90 days')), (8, 'out',  80, datetime('now','-10 days'))");

ob_start();
smartShopping($db);
$raw = ob_get_clean();
$out = json_decode($raw, true);
$byName = [];
foreach (($out['items'] ?? []) as $it) {
    $byName[mb_strtolower((string)$it['shopping_name'])] = $it;

// ── The explicit "usa tutto" must always land on the list ──────────────────────
if (!isShoppingBringMode()) {
    $res = shoppingAddDepletedProduct($db, 4);   // the yogurt whose family has 16 packs
    assert_true(!empty($res['added']), 'finishing a product adds it to the list even when the family is stocked');
    $row = $db->query("SELECT name, specification FROM shopping_list WHERE lower(name) = 'yogurt'")->fetch(PDO::FETCH_ASSOC);
    assert_true($row !== false, 'the list row uses the generic shopping name (Yogurt)');
    assert_true($row !== false && evershelfSpecIsDeliberate((string)$row['specification']),
        'the row is stamped as deliberate (🛒 Esaurito), so the cleanup keeps it');

    // A second finish of the same family must not duplicate the row.
    $again = shoppingAddDepletedProduct($db, 3);
    assert_true(empty($again['added']), 'a second product of the same family does not duplicate the row');

    // The cleanup still drops a row left over from a STALE cache when the family is
    // genuinely stocked (the cache no longer lists it, so the row is obsolete).
    $idx = evershelfSmartItemsIndex([[
        'name' => 'Yogurt bianco', 'shopping_name' => 'Yogurt', 'unit' => 'conf',
        'urgency' => 'critical', 'current_qty' => 0,
        'default_qty' => 125, 'package_unit' => 'g',
    ]]);
    $family = static fn(string $g, string $u, float $ps, string $pu): float
        => evershelfFamilyUsableStockQty($db, $g, $u, $ps, $pu);
    assert_true(!evershelfShoppingRowStillNeeded($db, $idx, 'Yogurt', 'Yogurt bianco', $family),
        'a stale row is removed when the family really holds a full pack');

    // …but a row whose family has less than a pack stays: that is the jam case.
    $idxJam = evershelfSmartItemsIndex([[
        'name' => 'Marmellata', 'shopping_name' => 'Marmellata', 'unit' => 'conf',
        'urgency' => 'medium', 'current_qty' => 0, 'period_usage' => 1, 'days_left' => 0,
        'default_qty' => 620, 'package_unit' => 'g',
    ]]);
    assert_true(evershelfShoppingRowStillNeeded($db, $idxJam, 'Marmellata', 'Marmellata', $family),
        'a row stays while the family has only 360 g against a 620 g pack');
}

if ($fail === 0) {
    echo "\nAll shopping restock tests passed.\n";
}
exit($fail === 0 ? 0 : 1);

}
assert_true(is_array($out), 'smartShopping() answers JSON');
assert_true(isset($byName['marmellata']), 'a finished jar of jam is predicted when the family has less than a pack');
assert_true(!isset($byName['yogurt']), 'a finished yogurt stays hidden while 16 packs of the same family are in stock');
assert_true(isset($byName['caffè']), 'a finished coffee is predicted when the family only has a 0.3 g trace');
assert_true(isset($byName['tonno']), 'a finished tuna is predicted when the family pack is expired');

