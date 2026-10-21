#!/usr/bin/env php
<?php
/**
 * Regression tests: a FINISHED product must reach the shopping list, and the row must
 * report the stock of its FAMILY.
 *
 * The reports:
 *   * "prodotti che sono finiti come marmellata, yogurt, ecc non sono nella lista":
 *     smartShopping() suppressed a depleted product as soon as any other product of the
 *     same `shopping_name` family had stock — adding grams, packs and pieces together and
 *     counting expired batches and 0.5 g traces. Coverage now needs a USABLE purchase:
 *     fresh, above the trace threshold, expanded to the same base family with the package
 *     size (`evershelfBaseQty()`), worth at least one pack (or half the usual buy for g/ml).
 *   * "usa tutto" (bringAddDepletedProduct) always lands on the list now; it used to skip
 *     the add for the same family reason.
 *   * "Zucchero di canna: Esaurito … se ne ho in dispensa??" — the row is ONE buyable
 *     generic, so it also exposes `family_qty`, and the "Hai già X in dispensa" hint only
 *     counts the SAME family (the old first-token fallback matched any product starting
 *     with the same word).
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
    if (!$cond) { echo "FAIL: {$msg}\n"; $fail++; } else { echo "OK: {$msg}\n"; }
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

/** Run a slice of the app's own JS in node and return stdout ('' when node is missing). */
function js_eval(string $source, string $code): string
{
    static $node = null;
    if ($node === null) {
        $node = trim((string)shell_exec('command -v node 2>/dev/null'));
    }
    if ($node === '') {
        return '';
    }
    $file = tempnam(sys_get_temp_dir(), 'evershelf-') . '.js';
    file_put_contents($file, $source . "\n" . $code . "\n");
    $out = (string)shell_exec(escapeshellarg($node) . ' ' . escapeshellarg($file) . ' 2>&1');
    @unlink($file);
    return trim($out);
}

/** A whole top-level JS function: from `function name(` to the closing brace at column 0. */
function slice_js_function(string $source, string $name): string
{
    $start = strpos($source, 'function ' . $name . '(');
    if ($start === false) {
        return '';
    }
    $end = strpos($source, "\n}\n", $start);
    return $end === false ? '' : substr($source, $start, $end - $start + 3);
}

$root = __DIR__ . '/..';
$js   = (string)file_get_contents($root . '/assets/js/app.js');

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
}
assert_true(is_array($out), 'smartShopping() answers JSON');
assert_true(isset($byName['marmellata']), 'a finished jar of jam is predicted when the family has less than a pack');
assert_true(!isset($byName['yogurt']), 'a finished yogurt stays hidden while 16 packs of the same family are in stock');
assert_true(isset($byName['caffè']), 'a finished coffee is predicted when the family only has a 0.3 g trace');
assert_true(isset($byName['tonno']), 'a finished tuna is predicted when the family pack is expired');

// The row is a FAMILY row: it must report the family's stock next to its own, or the UI
// says "Esaurito" while `pct_left` (family) says 47% — the reported sugar contradiction.
$jam = $byName['marmellata'] ?? [];
assert_true(isset($jam['family_qty']), 'the payload exposes family_qty');
assert_true((float)($jam['current_qty'] ?? -1) === 0.0, 'the finished jar has current_qty 0');
assert_true((float)($jam['family_qty'] ?? 0) > 0, 'but family_qty reports the 360 g still in the pantry');

// ── "Hai già X in dispensa" must speak about the SAME family ─────────────────
$hintFn = slice_js_function($js, '_shoppingFamilyInventoryRows');
assert_true($hintFn !== '', 'app.js: _shoppingFamilyInventoryRows() exists');
$hintProbe = $hintFn . "\n"
    . "const inv = [\n"
    . "  { name: 'Zucchero grezzo di canna', shopping_name: 'Zucchero', quantity: 235 },\n"
    . "  { name: 'Zucchero di canna', shopping_name: 'Zucchero di canna', quantity: 100 },\n"
    . "  { name: 'Zucchero di canna', shopping_name: 'Zucchero di canna', quantity: 0 },\n"
    . "];\n"
    . "const rows = _shoppingFamilyInventoryRows({ name: 'Zucchero di canna' }, { shopping_name: 'Zucchero di canna' }, inv);\n"
    . "console.log(JSON.stringify(rows.map(r => r.quantity)));";
$hintOut  = js_eval('', $hintProbe);
$hintRows = json_decode($hintOut, true);
if (!is_array($hintRows)) {
    echo "SKIP: node is not available — the pantry-hint probe was not run ({$hintOut})\n";
} else {
    assert_same([100], $hintRows, 'the pantry hint only counts the same shopping_name family (not "Zucchero grezzo")');
}

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

    // The cleanup still drops a row left over from a STALE cache when the family is stocked.
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

// ── The prune must not fight the auto-add (add → prune → add every 5 minutes) ──
if (!isShoppingBringMode()) {
    $cacheFile   = __DIR__ . '/../data/smart_shopping_cache.json';
    $cacheBackup = file_exists($cacheFile) ? file_get_contents($cacheFile) : null;
    register_shutdown_function(static function () use ($cacheFile, $cacheBackup): void {
        if ($cacheBackup === null) { @unlink($cacheFile); } else { @file_put_contents($cacheFile, $cacheBackup); }
    });
    file_put_contents($cacheFile, json_encode([
        'success' => true,
        'cached_ts' => time(),
        'items' => [[
            'name' => 'Marmellata', 'shopping_name' => 'Marmellata', 'unit' => 'conf',
            'urgency' => 'medium', 'current_qty' => 0, 'period_usage' => 1, 'days_left' => 0,
            'default_qty' => 620, 'package_unit' => 'g',
        ]],
    ], JSON_UNESCAPED_UNICODE));

    $db->exec('DELETE FROM shopping_list');
    $db->exec("INSERT INTO shopping_list (name, raw_name, specification) VALUES
        ('Marmellata', 'Marmellata', '🟡 A breve · 🛒 Compra: 1 conf'),
        ('Yogurt', 'Yogurt bianco', 'Yogurt bianco · 🛒 Esaurito')");
    $prune = internalShoppingPruneCovered($db);
    $left  = (int)$db->query('SELECT COUNT(*) FROM shopping_list')->fetchColumn();
    assert_true($left === 2,
        'the prune keeps the row the cache still predicts and the row the user finished (left: ' . $left . ', removed: ' . (int)($prune['removed'] ?? 0) . ')');
}

if ($fail === 0) {
    echo "\nAll shopping restock tests passed.\n";
}
exit($fail === 0 ? 0 : 1);
