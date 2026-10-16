#!/usr/bin/env php
<?php
/**
 * Regression tests: the "Spesa stimata" is ONE number, the same in the app, in the
 * kiosk and in Home Assistant.
 *
 * The report: "how did the estimated shopping total get to €166 when a few hours ago it
 * was just over €80?" — it was two numbers wearing the same name.
 * `data/shopping_total_cache.json` had a single `_canonical` slot, and two producers
 * wrote it with two different keys:
 *
 *   app / kiosk   get_all_shopping_prices with the smart quantities
 *                 (`_shoppingListPriceHash`: name+qty+unit)   → e.g. €81.55
 *   Home Assistant ha_sensor, names only (`_shoppingListHash`), every line priced as
 *                 "1 conf"                                     → e.g. €123.84
 *
 * Each write evicted the other entry, so every request recomputed the whole list (and
 * re-fetched prices), and the figure the household saw depended on which surface asked
 * last. What must stay true now:
 *
 *   * the SERVER resolves the basket from the smart cache; a caller that arrives without
 *     the smart suggestions (dashboard, screensaver, HA) prices the same basket as the
 *     shopping page — the caller's quantity is only the fallback for an unknown item,
 *   * one list ⇒ one key ⇒ one cached total, and the cache keeps a few baskets instead
 *     of one evicting slot,
 *   * there is no second, names-only hash to disagree with,
 *   * the API and the HA sensor go through the same function.
 *
 * Run: php scripts/test-shopping-total-canonical.php
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

$root = __DIR__ . '/..';
$api  = (string)file_get_contents($root . '/api/index.php');

/** Window that starts at the first $needle (empty when the anchor is gone). */
function slice_from(string $source, string $needle, int $len = 3000): string
{
    $pos = strpos($source, $needle);
    return $pos === false ? '' : substr($source, $pos, $len);
}

// ── The basket does not depend on what the caller happened to know ──────────
$smart = [[
    'name'           => 'Mozzarella 1O00 g (4x250 g)',
    'shopping_name'  => 'Mozzarella',
    'unit'           => 'conf',
    'default_qty'    => 250,
    'package_unit'   => 'g',
    'suggested_qty'  => 2,
    'suggested_unit' => 'conf',
]];

$withQuantities = _shoppingListPriceItems([
    ['name' => 'Mozzarella', 'quantity' => 1, 'unit' => 'conf', 'default_quantity' => 0, 'package_unit' => ''],
], $smart);
$namesOnly = _shoppingListPriceItems([['name' => 'Mozzarella']], $smart);

assert_same(2.0, (float)$withQuantities[0]['quantity'],
    'the smart cache decides the quantity (a coarse per-line payload no longer wins)');
assert_same($withQuantities, $namesOnly,
    'a caller without quantities prices the same basket (HA sensor = app = kiosk)');
assert_same(
    _shoppingListPriceHash($withQuantities, 'Italia', 'EUR'),
    _shoppingListPriceHash($namesOnly, 'Italia', 'EUR'),
    'one list ⇒ one cache key'
);

// ── An item the smart cache does not know keeps the caller's quantity ──────
$unknown = _shoppingListPriceItems([
    ['name' => 'Prodotto mai visto', 'quantity' => 3, 'unit' => 'pz', 'default_quantity' => 1, 'package_unit' => ''],
], []);
assert_same(3.0, (float)$unknown[0]['quantity'], 'the caller quantity is the fallback for an unknown item');
assert_same('pz', (string)$unknown[0]['unit'], 'the fallback keeps the caller unit');

$blank = _shoppingListPriceItems([['name' => 'Nessun dato']], []);
assert_same(1.0, (float)$blank[0]['quantity'], 'a line nobody knows is priced as one retail unit');
assert_same('conf', (string)$blank[0]['unit'], 'and stays countable (never grams)');

// ── The cache keeps baskets apart instead of evicting them ─────────────────
$entries = [];
foreach ([['a', 100], ['b', 200], ['c', 300], ['d', 400], ['e', 500], ['f', 600]] as [$key, $ts]) {
    $entries[$key] = ['ts' => $ts, 'result' => ['total' => $ts]];
}
$pruned = _pruneShoppingTotalEntries($entries, 4);
assert_same(4, count($pruned), 'the cache is capped');
assert_same(['f', 'e', 'd', 'c'], array_keys($pruned), 'the newest baskets are the ones kept');
assert_same(['total' => 600], $pruned['f']['result'], 'each entry keeps its own result');
assert_same(4, count(_pruneShoppingTotalEntries($entries, SHOPPING_TOTAL_CACHE_MAX)),
    'the default cap is SHOPPING_TOTAL_CACHE_MAX');

$save = slice_from($api, 'function _saveCanonicalShoppingTotal(', 1200);
assert_true($save !== '', 'api: _saveCanonicalShoppingTotal() exists');
assert_true(str_contains($save, "unset(\$tc['_canonical'])"), 'the single-slot shape is dropped on write');
assert_true(str_contains($save, '$tc[\'totals\'] = _pruneShoppingTotalEntries($entries);'),
    'writes go through the pruning helper');
$load = slice_from($api, 'function _loadCanonicalShoppingTotal(', 1400);
assert_true($load !== '', 'api: _loadCanonicalShoppingTotal() exists');
assert_true(str_contains($load, "\$tc['totals']"), 'reads look in the per-key map');
assert_true(str_contains($load, "isset(\$tc['_canonical'])"), 'an existing single-slot file is still honoured once');
assert_true(str_contains($load, "\$legacy['list_hash'] ?? '') === \$listHash"),
    'and only when it describes the very same basket');

// ── One key, one path, for the API and Home Assistant ─────────────────────
assert_true(!str_contains($api, 'function _shoppingListHash('),
    'the second (names-only) key is gone — it is what let the two numbers diverge');

$ha = slice_from($api, 'function haInventorySensor(PDO $db): void {', 12000);
assert_true($ha !== '', 'api: the HA sensor handler exists');
assert_true(str_contains($ha, '_computeAllShoppingPrices('),
    'the HA sensor prices through the shared computation');
assert_true(!str_contains($ha, '_shoppingListHash'),
    'and no longer keys a cache of its own');

$getAll = slice_from($api, 'function getAllShoppingPrices(PDO $db): void {', 1600);
assert_true($getAll !== '', 'api: getAllShoppingPrices() exists');
assert_true(str_contains($getAll, '_computeAllShoppingPrices($clientItems'),
    'the app asks the same computation for its own list');
assert_true(str_contains($api, 'function _shoppingListPriceHash('),
    'the single key describes what was really priced');

$single = slice_from($api, 'function _resolveShoppingPriceItem(string $name, array $smartItems, array $clientItem = []): array {', 900);
assert_true($single !== '', 'api: _resolveShoppingPriceItem() takes the caller item as a fallback');
assert_true(str_contains($single, '$si = _matchSmartShoppingItem($name, $smartItems);'),
    'the smart cache is consulted first');

if ($fail === 0) {
    echo "\nAll shopping-total canonical tests passed.\n";
}
exit($fail === 0 ? 0 : 1);
