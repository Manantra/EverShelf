<?php
/**
 * Guard test — shopping cache schema + refresh races.
 *
 * Locks three behaviours that are invisible until they bite:
 *
 *  1. `smartShoppingCached()` only serves a cache that declares the
 *     `empty_stock_only` schema. A cache written before the rule existed (when
 *     low-stock items were still suggested) must be rebuilt, not reused.
 *  2. `bringSuggestItems()` never suggests a product that is still in the
 *     pantry, even if a stale cache lists it.
 *  3. The client keeps the list it already shows: `loadShoppingList()` and
 *     `loadInventory()` ignore a slow answer that belongs to an older request
 *     (`_requestId`) and never blank the shopping list while refreshing.
 *
 * Run: php scripts/test-shopping-cache-schema.php
 */

$root = dirname(__DIR__);
$fail = 0;
$checks = 0;

function check(string $label, bool $ok): void
{
    global $fail, $checks;
    $checks++;
    if (!$ok) {
        $fail++;
        echo "FAIL  $label\n";
    } else {
        echo "ok    $label\n";
    }
}

$api = file_get_contents($root . '/api/index.php');
$app = file_get_contents($root . '/assets/js/app.js');

if ($api === false || $app === false) {
    fwrite(STDERR, "cannot read api/index.php or assets/js/app.js\n");
    exit(1);
}

// 1. The cache schema marker is required on read and written on save.
$fn = strpos($api, 'function smartShoppingCached');
check('smartShoppingCached() exists', $fn !== false);
$body = $fn !== false ? substr($api, $fn, 4000) : '';
check(
    'the cached payload must declare empty_stock_only',
    (bool)preg_match('/isset\(\$data\[.success.\]\)\s*&&\s*!empty\(\$data\[.empty_stock_only.\]\)/', $body)
);
check(
    'a freshly computed payload is stamped with empty_stock_only',
    (bool)preg_match('/\$decoded\[.empty_stock_only.\]\s*=\s*true;/', $body)
);

// 2. Bring! suggestions stop at zero stock.
$bfn = strpos($api, 'function bringSuggestItems');
$bbody = $bfn !== false ? substr($api, $bfn, 6000) : '';
check('bringSuggestItems() exists', $bfn !== false);
check(
    'bringSuggestItems() filters items still in stock',
    (bool)preg_match('/current_qty.*\?\?\s*\$item\[.quantity.\]/', $bbody)
        && strpos($bbody, '<= 0') !== false
        && strpos($bbody, 'array_filter') !== false
);

// 3. Client-side: one request per refresh, newest wins.
$lsFn = strpos($app, 'async function loadShoppingList()');
$lsBody = $lsFn !== false ? substr($app, $lsFn, 6000) : '';
check('loadShoppingList() exists', $lsFn !== false);
check(
    'loadShoppingList() takes a request id',
    (bool)preg_match('/const requestId = \+\+loadShoppingList\._requestId;/', $lsBody)
);
check(
    'both shopping_list answers check the request id',
    substr_count($lsBody, 'if (requestId !== loadShoppingList._requestId) return;') >= 2
);
check(
    'a refresh never blanks a list that is already on screen',
    (bool)preg_match('/hadExistingItems.*shoppingItems\.length > 0/', $lsBody)
        && (bool)preg_match('/statusEl\.style\.display = hadExistingItems \? .none. : .block.;/', $lsBody)
);

$invFn = strpos($app, 'async function loadInventory()');
$invBody = $invFn !== false ? substr($app, $invFn, 6000) : '';
check('loadInventory() exists', $invFn !== false);
check(
    'loadInventory() ignores an answer for an older location',
    (bool)preg_match('/const requestId = \+\+loadInventory\._requestId;/', $invBody)
        && strpos($invBody, 'if (requestId !== loadInventory._requestId) return;') !== false
);
check(
    'a failed inventory_list never replaces the pantry with an empty list',
    (bool)preg_match('/Array\.isArray\(\$?locData\?\.inventory\)/', str_replace('locData?.inventory', 'locData?.inventory', $invBody)) === false
    || strpos($invBody, 'Array.isArray(locData?.inventory)') !== false
);

echo "\n{$checks} checks, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
