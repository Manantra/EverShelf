#!/usr/bin/env php
<?php
/**
 * Regression tests: the pantry cannot be *created* with an invisible quantity.
 *
 * The report: "I had lost the tomatoes — I noticed that 2 grams had been added
 * instead of 2 pieces. You should not let me add less than 20 grams of anything, and
 * anomalous quantities must be flagged either way."
 *
 * Two halves of the same accident:
 *
 *   * `inventory_add` only refused qty ≤ 0 or > 100000, so a 2 landed as 2 g. The row
 *     is then hidden by isInventoryDepleted() (threshold: 2 g / 2 ml) — the product
 *     "disappears" right after being added, which is exactly what the user saw.
 *   * "Pomodoro" was not piece produce (isSoldByPieceProduct()), so the article was
 *     born on a weight unit where 2 is a nonsense quantity.
 *
 * What must stay true:
 *   * every unit has a floor for NEW rows (20 g/ml — and the same as kg/l), mirrored
 *     between PHP and app.js so the two can never drift,
 *   * the API refuses it with a stable, translated error_key and a greppable log line,
 *   * the add form refuses it before posting, and a unit switch lifts the value above
 *     the floor instead of leaving an impossible quantity,
 *   * USING a product is not guarded: a recipe may still leave 5 g of butter behind,
 *   * tomatoes count by piece, and their preparations (pelati, passata, sugo, polpa,
 *     secchi) never do.
 *
 * Run: php scripts/test-product-quantity-floor.php
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
$js   = (string)file_get_contents($root . '/assets/js/app.js');
$api  = (string)file_get_contents($root . '/api/index.php');

function slice_from(string $source, string $needle, int $len = 4000): string
{
    $pos = strpos($source, $needle);
    return $pos === false ? '' : substr($source, $pos, $len);
}

/**
 * Extract a whole top-level function from app.js: from its signature to the next
 * top-level `function` (the app is one file, so the slice is what node has to run).
 */
function slice_function(string $source, string $signature): string
{
    $start = strpos($source, $signature);
    if ($start === false) {
        return '';
    }
    $end = strpos($source, "\nfunction ", $start + 1);
    return $end === false ? substr($source, $start) : substr($source, $start, $end - $start);
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
    $out = (string)shell_exec(escapeshellarg($node) . ' ' . escapeshellarg($file) . ' 2>/dev/null');
    @unlink($file);
    return trim($out);
}

/** True when the dotted key exists and is a non-empty string in that locale. */
function i18n_value(string $locale, string $dotted): string
{
    $data = json_decode((string)file_get_contents(__DIR__ . '/../translations/' . $locale . '.json'), true);
    $cur  = $data;
    foreach (explode('.', $dotted) as $part) {
        if (!is_array($cur) || !array_key_exists($part, $cur)) {
            return '';
        }
        $cur = $cur[$part];
    }
    return is_string($cur) ? $cur : '';
}

// ── The floor: 20 g/ml, the same amount written as kg/l, pieces and packs ───
assert_same(20.0, inventoryMinAddQuantity('g'), 'floor: 20 g is the smallest weight the user accepts');
assert_same(20.0, inventoryMinAddQuantity('ml'), 'floor: 20 ml');
assert_same(20.0, inventoryMinAddQuantity('ML'), 'floor: the unit is matched case-insensitively');
assert_same(0.02, inventoryMinAddQuantity('kg'), 'floor: 20 g written in kg');
assert_same(0.02, inventoryMinAddQuantity('l'), 'floor: 20 ml written in litres');
assert_same(1.0, inventoryMinAddQuantity('pz'), 'floor: one piece, as the add form always had');
assert_same(0.25, inventoryMinAddQuantity('conf'), 'floor: a quarter pack');

// ── PHP and the client must not drift ──────────────────────────────────────
assert_true(preg_match('~const _ADD_MIN_QTY_BY_UNIT = \{([^}]+)\}~', $js, $m) === 1,
    'app.js: the client floor table is readable');
$jsFloors = [];
foreach (explode(',', $m[1] ?? '') as $pair) {
    if (preg_match('~([a-z]+)\s*:\s*([0-9.]+)~', trim($pair), $p) === 1) {
        $jsFloors[$p[1]] = (float)$p[2];
    }
}
foreach (['g', 'ml', 'kg', 'l', 'pz', 'conf'] as $unit) {
    assert_same(inventoryMinAddQuantity($unit), $jsFloors[$unit] ?? null,
        "app.js and api/index.php agree on the floor for {$unit}");
}

// ── Tomatoes are piece produce; their preparations are not ─────────────────
assert_true(isSoldByPieceProduct('Pomodoro'), 'produce: Pomodoro is counted by piece');
assert_true(isSoldByPieceProduct('Pomodori'), 'produce: Pomodori is counted by piece');
assert_true(isSoldByPieceProduct('Pomodorini'), 'produce: Pomodorini is counted by piece');
assert_true(!isSoldByPieceProduct('Pomodori pelati'), 'preparation: pelati are not pieces');
assert_true(!isSoldByPieceProduct('Passata di pomodoro'), 'preparation: passata is not pieces');
assert_true(!isSoldByPieceProduct('Sugo di pomodoro'), 'preparation: sugo is not pieces');
assert_true(!isSoldByPieceProduct('Pomodori secchi'), 'preparation: dried tomatoes are not pieces');
assert_true(isSoldByPieceProduct('Zucchine'), 'produce: the existing piece list is untouched');
assert_true(!isSoldByPieceProduct('Latte intero'), 'produce: milk is not piece produce');

// ── The UI defaults agree with the API (the app's own JS, run in node) ──────
$unitQtySource = slice_function($js, 'function suggestCategoryUnitQty(category, name) {');
assert_true($unitQtySource !== '', 'app.js: suggestCategoryUnitQty() is extractable');
$probe  = "\nconsole.log(JSON.stringify([" .
    "suggestCategoryUnitQty('verdura','Pomodoro')," .
    "suggestCategoryUnitQty('verdura','Pomodorini')," .
    "suggestCategoryUnitQty('verdura','Pomodori pelati')," .
    "suggestCategoryUnitQty('verdura','Passata di pomodoro')," .
    "suggestCategoryUnitQty('verdura','Zucchine')" .
    "]));";
$units = json_decode(js_eval($unitQtySource, $probe), true);
if (!is_array($units)) {
    echo "SKIP: node is not available — the JS default-unit probes were not run\n";
} else {
    assert_same(['unit' => 'pz', 'qty' => 1], $units[0], 'app.js: a fresh tomato defaults to PIECES, not grams');
    assert_same(['unit' => 'pz', 'qty' => 1], $units[1], 'app.js: cherry tomatoes too');
    assert_same(['unit' => 'g', 'qty' => 500], $units[2], 'app.js: pelati keep the weight default of their category');
    assert_same(['unit' => 'g', 'qty' => 500], $units[3], 'app.js: passata keeps the weight default of its category');
    assert_same(['unit' => 'g', 'qty' => 500], $units[4], 'app.js: only tomatoes changed — courgettes still follow the category');
}

$mirrorSource = slice_function($js, 'function _isSoldByPieceProductName(name, category = \'\') {');
assert_true($mirrorSource !== '', 'app.js: _isSoldByPieceProductName() is extractable');
$probeNames = ['Pomodoro', 'Pomodori', 'Pomodorini', 'Pomodori pelati', 'Passata di pomodoro', 'Zucchine', 'Latte intero'];
$mirrorProbe = "\nconsole.log(JSON.stringify(" . json_encode($probeNames) . ".map(n => _isSoldByPieceProductName(n))));";
$mirror = json_decode(js_eval((string)file_get_contents($root . '/assets/js/vocab.generated.js') . "\n" . (string)file_get_contents($root . '/assets/js/core/vocab.js') . "\n" . $mirrorSource, $mirrorProbe), true);
if (!is_array($mirror)) {
    echo "SKIP: node is not available — the mirror probes were not run\n";
} else {
    foreach ($probeNames as $i => $name) {
        assert_same(isSoldByPieceProduct($name), $mirror[$i] ?? null,
            "app.js mirror agrees with PHP on {$name}");
    }
}

// ── The API refuses it, before writing, with a translatable key ─────────────
$add = slice_from($api, 'function addToInventory(PDO $db): void {', 9000);
assert_true($add !== '', 'api: addToInventory() exists');
assert_true(str_contains($add, '$minAdd = inventoryMinAddQuantity($effectiveUnit)'),
    'api: the guard reads the floor of the unit being written');
assert_true(str_contains($add, "'error_key'  => 'error.quantity_below_min'"),
    'api: the 400 carries the stable error_key');
assert_true(str_contains($add, "'error_args' => ['min' => \$minAdd, 'unit' => \$effectiveUnit]"),
    'api: and the values the message needs');
assert_true(str_contains($add, "'event'      => 'api_inventory_add_below_minimum'"),
    'api: the refusal is logged with a greppable event key');
$guardAt = strpos($add, 'api_inventory_add_below_minimum');
$insertAt = strpos($add, 'INSERT INTO inventory (product_id, location, quantity');
assert_true($guardAt !== false && $insertAt !== false && $guardAt < $insertAt,
    'api: the floor is checked before a single inventory row is written');

// ── Using a product is NOT guarded: a trace may legitimately remain ────────
$use = slice_from($api, 'function useFromInventory(PDO $db): void {', 6000);
assert_true($use !== '', 'api: useFromInventory() exists');
assert_true(!str_contains($use, 'inventoryMinAddQuantity'),
    'api: consuming may still leave 5 g of butter behind — the floor is only for adds');

// ── The client refuses it first, and lifts the value on a unit switch ──────
$step = slice_from($js, 'function updateAddQtyStep() {', 600);
assert_true(str_contains($step, 'qtyInput.min = String(_addMinQtyForUnit(unit))'),
    'app.js: the field minimum is the floor of the selected unit');

$unitChange = slice_from($js, 'function onAddUnitChange() {', 3200);
assert_true(str_contains($unitChange, 'if ((parseFloat(qtyInput.value) || 0) < floor) qtyInput.value = floor;'),
    'app.js: switching the unit lifts a too-small value instead of keeping it');

$submit = slice_from($js, 'async function submitAdd(e) {', 6000);
assert_true(str_contains($submit, 'const minAdd = _addMinQtyForUnit(unitForAdd);'),
    'app.js: submitAdd() knows the floor of the unit about to be written');
assert_true(str_contains($submit, "t('error.quantity_below_min', { min: minAdd, unit: _addUnitLabel(unitForAdd) })"),
    'app.js: and says it with a translated, bare t() the i18n audit can see');

// ── The API answer reaches the UI as text, in all six locales ──────────────
$errText = slice_from($js, 'function _apiErrorText(data) {', 500);
assert_true(str_contains($errText, "if (key === 'error.quantity_below_min') return t('error.quantity_below_min', data?.error_args || {});"),
    'app.js: the error_key is resolved with the values the server sent');
foreach (['it', 'en', 'de', 'fr', 'es', 'zh'] as $locale) {
    $value = i18n_value($locale, 'error.quantity_below_min');
    assert_true($value !== '', "translations/{$locale}: error.quantity_below_min exists");
    assert_true(str_contains($value, '{min}') && str_contains($value, '{unit}'),
        "translations/{$locale}: the message states the floor and the unit");
}

if ($fail === 0) {
    echo "\nAll quantity-floor guard tests passed.\n";
}
exit($fail === 0 ? 0 : 1);
