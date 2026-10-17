<?php
/**
 * Guard test — parent / child products (#284).
 *
 * Functional half: the family module against an in-memory SQLite (one level,
 * no self-parent, no adopting an umbrella that has children, no joining through
 * a child, per-unit totals, detach, resolve-or-create by name).
 *
 * Wiring half: the three actions, the pantry rows carrying the family, the
 * consolidator refusing to merge a child away, the option end-to-end and the
 * strings in the six locales.
 *
 * Run: php scripts/test-product-family.php
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
require_once $root . '/api/lib/product_family.php';

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE products (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    brand TEXT DEFAULT "",
    category TEXT DEFAULT "",
    unit TEXT DEFAULT "pz",
    default_quantity REAL DEFAULT 1,
    parent_id INTEGER DEFAULT NULL
)');
$db->exec('CREATE TABLE inventory (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id INTEGER NOT NULL,
    quantity REAL NOT NULL DEFAULT 0
)');
$db->exec("INSERT INTO products (id, name, brand, unit) VALUES
    (5, 'Spaghetti', '', 'conf'),
    (6, 'Spaghetti Barilla n.5', 'Barilla', 'conf'),
    (7, 'Spaghetti Rummo', 'Rummo', 'conf'),
    (8, 'Penne', '', 'g')");
$db->exec("INSERT INTO inventory (product_id, quantity) VALUES (6, 3), (7, 1), (8, 500)");

check('families are enabled by default', productVariantsEnabled() === true);

$res = familySetParent($db, 6, 5);
check('a child joins an umbrella', $res[0] === true && (int)$db->query('SELECT parent_id FROM products WHERE id = 6')->fetchColumn() === 5);

$res = familySetParent($db, 5, 5);
check('a product cannot be its own parent', $res === [false, 'error.family_self_parent']);

$res = familySetParent($db, 8, 5);
check('a plain product can join a family', $res[0] === true);
check('and leave it again', familySetParent($db, 8, null)[0] === true);
$res = familySetParent($db, 5, 8);
check('an umbrella with children cannot become a child', $res === [false, 'error.family_parent_has_children']);

$res = familySetParent($db, 8, 6);
check('a child cannot become an umbrella', $res === [false, 'error.family_parent_is_child']);

$res = familySetParent($db, 999, 5);
check('an unknown product is refused', $res === [false, 'error.product_not_found']);

familySetParent($db, 7, 5);
$kids = familyChildren($db, 5);
$names = array_column($kids, 'name');
check('the children are listed with their stock',
    count($kids) === 2 && in_array('Spaghetti Barilla n.5', $names, true) && (float)$kids[0]['quantity'] === 3.0);

$families = familyNames($db);
check('the umbrella is listed with its child count',
    count($families) === 1 && $families[0]['id'] === 5 && $families[0]['children'] === 2);

check('a name resolves case-insensitively', familyResolveParent($db, 'spaghetti') === 5);
$newId = familyResolveParent($db, 'Pasta lunga');
check('an unknown umbrella is created', is_int($newId) && $newId > 8);

$rows = [
    ['product_id' => 6, 'unit' => 'conf', 'quantity' => 3],
    ['product_id' => 7, 'unit' => 'conf', 'quantity' => 1],
    ['product_id' => 8, 'unit' => 'g', 'quantity' => 500],
];
familyAttachToRows($db, $rows);
check('the family total is per unit', $rows[0]['family_qty'] === 4.0 && $rows[0]['family_count'] === 2);
check('the child keeps its own name and parent', $rows[0]['parent_name'] === 'Spaghetti');
check('a product outside any family is untouched', !isset($rows[2]['parent_name']));

$res = familySetParent($db, 6, null);
check('a child can be detached', $res[0] === true && $db->query('SELECT parent_id FROM products WHERE id = 6')->fetchColumn() === null);

// -------------------------------------------------------------------- wiring
$api = (string)file_get_contents($root . '/api/index.php');
$dbSrc = (string)file_get_contents($root . '/api/database.php');
$html = (string)file_get_contents($root . '/index.html');
$app = (string)file_get_contents($root . '/assets/js/app.js');

foreach (['families_list', 'product_family', 'product_set_parent'] as $action) {
    check("router exposes $action", strpos($api, "case '$action':") !== false);
}
check('the pantry rows carry the family', strpos($api, 'familyAttachToRows($db, $rows);') !== false);
check('product_get returns the umbrella name', strpos($api, "\$product['parent_name']") !== false);
check('the consolidator never treats a child as a merge seed',
    strpos($api, "if (productVariantsEnabled() && !empty(\$row['parent_id'])) {") !== false);
check('the consolidator never merges a child away',
    substr_count($api, 'AND parent_id IS NULL"') >= 2);
check('existing installs get the column', strpos($dbSrc, 'ADD COLUMN parent_id INTEGER DEFAULT NULL') !== false);
check('fresh installs get the column', strpos($dbSrc, 'parent_id INTEGER DEFAULT NULL,') !== false);

check('the option is in the settings payload', strpos($api, "'product_variants_enabled'") !== false);
check('the option is written to .env', strpos($api, "'PRODUCT_VARIANTS_ENABLED'") !== false);
check('the settings page has the toggle', strpos($html, 'id="setting-product-variants-enabled"') !== false);
check('the product form has the family field', strpos($html, 'id="pf-parent"') !== false);
check('the client loads the umbrellas', strpos($app, 'async function _loadFamilies()') !== false);
check('the client saves the family after the product', strpos($app, "_saveProductFamily(result.id") !== false);
check('the card draws the family line', strpos($app, 'familyBadge') !== false);
check('the .env example documents it',
    strpos((string)file_get_contents($root . '/.env.example'), 'PRODUCT_VARIANTS_ENABLED') !== false);

$keys = ['form_label', 'placeholder', 'form_hint', 'card'];
$errors = ['family_disabled', 'family_self_parent', 'family_parent_is_child', 'family_parent_has_children'];
foreach (['it', 'en', 'de', 'fr', 'es', 'zh'] as $lang) {
    $data = json_decode((string)file_get_contents($root . '/translations/' . $lang . '.json'), true);
    $missing = [];
    foreach ($keys as $key) {
        if (empty($data['product_family'][$key])) {
            $missing[] = 'product_family.' . $key;
        }
    }
    foreach ($errors as $key) {
        if (empty($data['error'][$key])) {
            $missing[] = 'error.' . $key;
        }
    }
    foreach (['family_label', 'family_hint'] as $key) {
        if (empty($data['settings']['product_rules'][$key])) {
            $missing[] = 'settings.product_rules.' . $key;
        }
    }
    check("$lang has every family string" . ($missing ? ' (' . implode(', ', $missing) . ')' : ''), !$missing);
    // The card line must keep its three placeholders, or it prints raw braces.
    $card = (string)($data['product_family']['card'] ?? '');
    check("$lang card line has {name}, {qty} and {count}",
        strpos($card, '{name}') !== false && strpos($card, '{qty}') !== false && strpos($card, '{count}') !== false);
}

echo "\n{$checks} checks, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);

