#!/usr/bin/env php
<?php
/**
 * Regression tests: adding a product to the pantry must never dead-end on a
 * missing catalog id (GitHub issue #282).
 *
 * The report: "Identify with AI" filled the product details and the green Add
 * button answered "Product ID required", so nothing was saved. The pantry insert
 * is keyed by `product_id`, and two things could hand the add form a draft
 * without a row behind it:
 *
 *   api/index.php   addToInventory() / useFromInventory() answered the raw,
 *                   untranslatable English string (the user even saw it verbatim),
 *   app.js          a "success" without an id was accepted and moved to the add
 *                   form (createQuickProduct / saveAIProductDirect), and submitAdd()
 *                   posted `inventory_add` with a falsy product_id.
 *
 * The guard locks the contracts that fix it: a stable `error_key` server-side, a
 * client that materialises the row before inserting, and the translations of the
 * fallback message in all six locales.
 *
 * Run: php scripts/test-inventory-add-guard.php
 */
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

$root = __DIR__ . '/..';
$js   = (string)file_get_contents($root . '/assets/js/app.js');
$api  = (string)file_get_contents($root . '/api/index.php');

/** Window that starts at the first $needle (empty when the anchor is gone). */
function slice_from(string $source, string $needle, int $len = 4000): string
{
    $pos = strpos($source, $needle);
    return $pos === false ? '' : substr($source, $pos, $len);
}

/** True when the dotted key exists and is a non-empty string in that locale. */
function i18n_has(string $locale, string $dotted): bool
{
    $path = __DIR__ . '/../translations/' . $locale . '.json';
    $data = json_decode((string)file_get_contents($path), true);
    $cur  = $data;
    foreach (explode('.', $dotted) as $part) {
        if (!is_array($cur) || !array_key_exists($part, $cur)) {
            return false;
        }
        $cur = $cur[$part];
    }
    return is_string($cur) && trim($cur) !== '';
}

// ── the API answers with a stable key, and logs an event ────────────────────
$add = slice_from($api, 'function addToInventory(PDO $db): void {', 1200);
assert_true($add !== '', 'api: addToInventory() exists');
assert_true(str_contains($add, "'error_key' => 'error.product_id_required'"),
    'api: a missing product_id answers with the stable error_key');
assert_true(str_contains($add, "'event'     => 'api_inventory_add_missing_product_id'"),
    'api: the 400 is logged with a greppable event key');
assert_true(str_contains($add, "'success'   => false"),
    'api: the 400 keeps the documented error envelope');

$use = slice_from($api, 'function useFromInventory(PDO $db): void {', 1200);
assert_true($use !== '', 'api: useFromInventory() exists');
assert_true(str_contains($use, "'error_key' => 'error.product_id_required'"),
    'api: consuming without a product_id carries the same stable error_key');

// ── submitAdd() materialises the row instead of posting an id-less insert ───
$submit = slice_from($js, 'async function submitAdd(e) {', 1500);
assert_true($submit !== '', 'app.js: submitAdd() exists');
assert_true(str_contains($submit, 'await _ensureAddProductRow()'),
    'app.js: submitAdd() ensures a catalog row before posting inventory_add');
$submitStart = (int)strpos($js, 'async function submitAdd(e) {');
$postAt      = strpos($js, "'inventory_add'", $submitStart);
$ensureAt    = strpos($js, 'await _ensureAddProductRow()', $submitStart);
assert_true($postAt !== false && $ensureAt !== false && $ensureAt < $postAt,
    'app.js: the row is created before the inventory_add call, not after');
assert_true(str_contains($submit, "t('product.name_required')"),
    'app.js: a nameless draft is refused with a translated message');

// ── the helper owns the write, and honours the user-confirmed title ─────────
$ensure = slice_from($js, 'async function _ensureAddProductRow() {', 2000);
assert_true($ensure !== '', 'app.js: _ensureAddProductRow() exists');
assert_true(str_contains($ensure, "api('product_save'"),
    'app.js: the helper creates the catalog row through product_save');
assert_true(str_contains($ensure, 'name_user_set: 1'),
    'app.js: the row keeps the title the user confirmed on the add screen');
assert_true(str_contains($ensure, 'currentProduct = Object.assign('),
    'app.js: the helper stores the returned id on currentProduct');

// ── no "success without an id" may reach the add form any more ─────────────
assert_true(!str_contains($js, 'if (result.success || result.id) {'),
    'app.js: the loose "success || id" guard is gone (it accepted id-less saves)');
assert_true(str_contains($js, 'const newId = result?.id || result?.existing_id;'),
    'app.js: createQuickProduct() requires a real id');
assert_true(str_contains($js, "if (result?.error === 'barcode_already_used' && result.existing_id)"),
    'app.js: the barcode-owner branch still resolves the stored row first');

// ── the backend message is translated, not printed raw ─────────────────────
$errText = slice_from($js, 'function _apiErrorText(data) {', 400);
assert_true($errText !== '', 'app.js: _apiErrorText() exists');
assert_true(str_contains($errText, "t('error.product_id_required')"),
    'app.js: the error_key is resolved with a bare t() the i18n audit can see');
assert_true(str_contains($js, "showToast(_apiErrorText(result), 'error')"),
    'app.js: the add failure toast goes through the translator');

foreach (['it', 'en', 'de', 'fr', 'es', 'zh'] as $locale) {
    assert_true(i18n_has($locale, 'error.product_id_required'),
        "translations/{$locale}: error.product_id_required exists");
    assert_true(i18n_has($locale, 'product.name_required'),
        "translations/{$locale}: the name-required fallback exists");
}

if ($fail === 0) {
    echo "\nAll inventory-add guard tests passed.\n";
}
exit($fail === 0 ? 0 : 1);
