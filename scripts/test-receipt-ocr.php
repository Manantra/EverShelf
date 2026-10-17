<?php
/**
 * Guard test — receipt OCR (#114).
 *
 * Functional half: the sanitiser and the JSON reader against junk (the model is
 * untrusted input), including the comma decimal, the unknown unit and the cap.
 *
 * Wiring half: the action exists and is rate-limited as AI, the endpoint never
 * writes (no INSERT/UPDATE/DELETE in its body — the pantry is touched only from
 * the browser after the review), the client calls inventory_add only from the
 * confirm handler, the option end-to-end, and the strings in six locales.
 *
 * Run: php scripts/test-receipt-ocr.php
 */

$root = dirname(__DIR__);
$fail = 0;
$checks = 0;

function check(string $label, bool $ok): void
{
    global $fail, $checks;
    $checks++;
    if ($ok) {
        echo "ok    $label\n";
    } else {
        $fail++;
        echo "FAIL  $label\n";
    }
}

if (!function_exists('env')) {
    function env(string $key, $default = null)
    {
        return $default;
    }
}
require_once $root . '/api/lib/receipt_ocr.php';

// ---------------------------------------------------------------- functional
check('receipt scanning is enabled by default', receiptOcrEnabled() === true);
check('the cap defaults to 60', receiptOcrMaxItems() === 60);

check('a non-array row is dropped', receiptSanitizeItem(null) === null && receiptSanitizeItem('x') === null);
check('a row without a name is dropped', receiptSanitizeItem(['name' => '   ']) === null);
check('an absurdly long name is dropped', receiptSanitizeItem(['name' => str_repeat('x', 90)]) === null);

$row = receiptSanitizeItem(['name' => '  Pasta   integrale ', 'brand' => '  Barilla ', 'qty' => '2', 'unit' => 'CONF', 'price' => '1,49']);
check('names and brands are cleaned', $row !== null && $row['name'] === 'Pasta integrale' && $row['brand'] === 'Barilla');
check('the unit is lower-cased and kept', $row !== null && $row['unit'] === 'conf');
check('a comma decimal becomes a number', $row !== null && $row['price'] === 1.49);

$row = receiptSanitizeItem(['name' => 'Latte', 'qty' => '-3', 'unit' => 'kg', 'price' => '-1']);
check('an unknown unit falls back to pieces', $row !== null && $row['unit'] === 'pz');
check('a negative quantity becomes one piece', $row !== null && $row['qty'] === 1.0);
check('a negative price becomes zero', $row !== null && $row['price'] === 0.0);

$items = receiptParseModelJson("```json\n[{\"name\":\"Pane\",\"qty\":1,\"unit\":\"conf\",\"price\":2}]\n```");
check('a fenced array is read', count($items) === 1 && $items[0]['name'] === 'Pane');

$items = receiptParseModelJson('Here you go: {"items":[{"name":"Latte","qty":2,"unit":"conf"}]}');
check('an object with items is read', count($items) === 1 && $items[0]['qty'] === 2.0);

$items = receiptParseModelJson('{"name":"Uova","qty":6}');
check('a single object is read', count($items) === 1 && $items[0]['name'] === 'Uova');

check('junk is an empty list, not an error', receiptParseModelJson('I cannot read this') === []);

$many = [];
for ($i = 1; $i <= 70; $i++) {
    $many[] = ['name' => 'Prodotto ' . $i, 'qty' => 1, 'unit' => 'pz'];
}
check('one photo returns at most the cap', count(receiptParseModelJson(json_encode($many))) === 60);

// -------------------------------------------------------------------- wiring
$api = (string)file_get_contents($root . '/api/index.php');
$js = (string)file_get_contents($root . '/assets/js/receipt.js');
$app = (string)file_get_contents($root . '/assets/js/app.js');
$html = (string)file_get_contents($root . '/index.html');

check('the router exposes parse_receipt', strpos($api, "case 'parse_receipt':") !== false);
check('it is rate-limited as an AI action', strpos($api, "'gemini_barcode_visual', 'parse_receipt'") !== false);

$start = strpos($api, 'function parseReceipt(): void');
$endpoint = $start !== false ? substr($api, $start, 4500) : '';
check('the endpoint exists', $start !== false);
check('the endpoint never writes to the database',
    $endpoint !== ''
        && stripos($endpoint, 'INSERT INTO') === false
        && stripos($endpoint, 'UPDATE ') === false
        && stripos($endpoint, 'DELETE FROM') === false);
check('it sends the image to the vision model', strpos($endpoint, "'inline_data'") !== false);
check('the prompt asks for qty and price', strpos($endpoint, 'qty') !== false && strpos($endpoint, 'price') !== false);
check('the answer is sanitised through the module', strpos($endpoint, 'receiptParseModelJson($text)') !== false);

check('the client calls the endpoint', strpos($js, "api('parse_receipt'") !== false);
check('the review table comes before any write', strpos($js, '_receiptReview(items)') !== false);
check('the pantry is written only from the confirm handler', substr_count($js, "api('inventory_add'") === 1);
$confirmAt = strpos($js, 'async function _receiptConfirm()');
$addAt = strpos($js, "api('inventory_add'");
check('and that handler is the confirm one', $confirmAt !== false && $addAt !== false && $addAt > $confirmAt);
check('a reviewed title is locked against a later rename', strpos($js, 'name_user_set: 1') !== false);
check('a per-row failure is counted, not swallowed', strpos($js, 'failed++') !== false);

check('the option is in the settings payload', strpos($api, "'receipt_ocr_enabled'") !== false);
check('the cap is a setting too', strpos($api, "'receipt_ocr_max_items'") !== false);
check('both are written to .env',
    strpos($api, "'RECEIPT_OCR_ENABLED'") !== false && strpos($api, "'RECEIPT_OCR_MAX_ITEMS'") !== false);
check('the settings page has the toggle', strpos($html, 'id="setting-receipt-ocr-enabled"') !== false);
check('the scan page has the button', strpos($html, '_receiptStart()') !== false);
check('the module is loaded', strpos($html, 'assets/js/receipt.js') !== false);
check('the service worker precaches it',
    strpos((string)file_get_contents($root . '/sw.js'), 'assets/js/receipt.js') !== false);
check('the client sends the option', strpos($app, 'receipt_ocr_enabled:') !== false);
check('the .env example documents it',
    strpos((string)file_get_contents($root . '/.env.example'), 'RECEIPT_OCR_ENABLED') !== false);

$keys = ['photo', 'title', 'reading', 'review_title', 'hint', 'col_name', 'col_qty',
         'col_unit', 'col_price', 'remove', 'confirm', 'cancel', 'empty', 'done'];
foreach (['it', 'en', 'de', 'fr', 'es', 'zh'] as $lang) {
    $data = json_decode((string)file_get_contents($root . '/translations/' . $lang . '.json'), true);
    $missing = [];
    foreach ($keys as $key) {
        if (empty($data['receipt'][$key])) {
            $missing[] = 'receipt.' . $key;
        }
    }
    foreach (['receipt_disabled', 'receipt_failed'] as $key) {
        if (empty($data['error'][$key])) {
            $missing[] = 'error.' . $key;
        }
    }
    foreach (['receipt_label', 'receipt_hint'] as $key) {
        if (empty($data['settings']['product_rules'][$key])) {
            $missing[] = 'settings.product_rules.' . $key;
        }
    }
    check("$lang has every receipt string" . ($missing ? ' (' . implode(', ', $missing) . ')' : ''), !$missing);
    check("$lang confirm counts the rows", strpos((string)($data['receipt']['confirm'] ?? ''), '{n}') !== false);
    check("$lang done reports both counters",
        strpos((string)($data['receipt']['done'] ?? ''), '{ok}') !== false
        && strpos((string)($data['receipt']['done'] ?? ''), '{ko}') !== false);
}

echo "\n{$checks} checks, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);

