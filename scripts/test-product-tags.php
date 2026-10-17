<?php
/**
 * Guard test — custom product tags (#97).
 *
 * Two halves:
 *  - functional: the tag module against a real in-memory SQLite database
 *    (dedupe by case/space, rename that merges, delete that keeps products,
 *    the 12-tag cap, batch attach for the pantry list);
 *  - wiring: the four API actions exist, the inventory list carries the tags,
 *    the option is a normal `.env` setting surfaced in the settings payload,
 *    and the six locales have the strings the UI prints.
 *
 * Run: php scripts/test-product-tags.php
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
    // The module only reads one variable; the test does not need the .env loader.
    function env(string $key, $default = null)
    {
        return $default;
    }
}
require_once $root . '/api/lib/tags.php';

// ---------------------------------------------------------------- functional
$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE products (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)');
$db->exec("INSERT INTO products (name) VALUES ('Pasta'), ('Latte'), ('Pane')");
tagsEnsureTables($db);

check('tags are enabled by default', tagsEnabled() === true);
check('keys are case- and space-insensitive',
    tagNameKey('  Senza   Glutine ') === tagNameKey('senza glutine'));
check('names are trimmed and capped',
    tagNameClean('  bio  ') === 'bio' && mb_strlen(tagNameClean(str_repeat('x', 40))) === 24);

$stored = tagsSetForProduct($db, 1, ['Senza Glutine', 'bio', 'Senza  glutine', '']);
check('a repeated tag is stored once, sorted', $stored === ['bio', 'Senza Glutine']);
check('reading a product returns its tags', tagsForProduct($db, 1) === ['bio', 'Senza Glutine']);
check('another product starts empty', tagsForProduct($db, 2) === []);

$stored = tagsSetForProduct($db, 1, ['bio']);
check('setting again replaces the set', $stored === ['bio'] && tagsForProduct($db, 1) === ['bio']);
check('replacing does not leak an orphan link',
    (int)$db->query("SELECT COUNT(*) FROM product_tag_links WHERE tag_id NOT IN (SELECT id FROM product_tags)")->fetchColumn() === 0);

tagsSetForProduct($db, 2, ['bio', 'bimbi']);
$byName = [];
foreach (tagsAll($db) as $tagRow) {
    $byName[$tagRow['name']] = $tagRow;
}
check('tagsAll reports how many products carry each tag',
    isset($byName['bio'], $byName['bimbi']) && $byName['bio']['products'] === 2);

$bioId = (int)$byName['bio']['id'];
$bimbiId = (int)$byName['bimbi']['id'];
check('rename merges onto an existing tag and moves the links',
    tagsUpdate($db, $bimbiId, 'BIO') === true
    && tagsForProduct($db, 2) === ['bio']
    // the merged-away name is gone; a tag left with zero products is kept for reuse
    && (int)$db->query("SELECT COUNT(*) FROM product_tags WHERE name_key = 'bimbi'")->fetchColumn() === 0
    && (int)$db->query("SELECT COUNT(*) FROM product_tags WHERE name_key = 'bio'")->fetchColumn() === 1);

$map = tagsMapForProducts($db, [1, 2, 3]);
check('one query returns the tags of a whole page',
    $map[1] === ['bio'] && $map[2] === ['bio'] && !isset($map[3]));

$rows = [['product_id' => 1], ['product_id' => 3]];
tagsAttachToRows($db, $rows);
check('inventory rows get a tags array (empty when none)',
    $rows[0]['tags'] === ['bio'] && $rows[1]['tags'] === []);

$names = [];
for ($i = 1; $i <= 20; $i++) {
    $names[] = 'tag ' . $i;
}
tagsSetForProduct($db, 3, $names);
check('a product keeps at most 12 tags', count(tagsForProduct($db, 3)) === 12);

check('deleting a tag leaves the product in place',
    tagsDelete($db, $bioId) === true
    && tagsForProduct($db, 1) === []
    && (int)$db->query('SELECT COUNT(*) FROM products')->fetchColumn() === 3);
check('deleting an unknown tag is a clean false', tagsDelete($db, 99999) === false);

// -------------------------------------------------------------------- wiring
$api = file_get_contents($root . '/api/index.php');
$html = file_get_contents($root . '/index.html');
$app = file_get_contents($root . '/assets/js/app.js');
$tagsLib = file_get_contents($root . '/api/lib/tags.php');

foreach (['tags_list', 'tag_save', 'tag_delete', 'product_tags_set'] as $action) {
    check("router exposes $action", strpos($api, "case '$action':") !== false);
}
check('the inventory list carries the tags', strpos($api, 'tagsAttachToRows($db, $rows);') !== false);
check('reading one product carries its tags',
    strpos($api, "tagsForProduct(\$db, (int)\$product['id'])") !== false);
check('the option is in the settings payload', strpos($api, "'tags_enabled'") !== false);
check('the option is written to .env by saveSettings', strpos($api, "'TAGS_ENABLED'") !== false);
check('the option defaults to on', strpos($tagsLib, "env('TAGS_ENABLED', 'true')") !== false);

check('settings has the toggle', strpos($html, 'id="setting-tags-enabled"') !== false);
check('the product form has the tag field', strpos($html, 'id="pf-tags"') !== false);
check('the inventory has the filter bar', strpos($html, 'id="inventory-tag-filter"') !== false);
check('the card draws the pills', strpos($app, 'inv-tag-pill') !== false);
check('the filter is applied to the visible list', strpos($app, '_inventoryTagFilter.every') !== false);

$i18n = ['product_tags.form_label', 'product_tags.placeholder', 'product_tags.form_hint',
         'product_tags.filter_clear', 'settings.product_rules.tags_label',
         'settings.product_rules.tags_hint', 'error.tags_disabled', 'error.tag_name_required'];
foreach (['it', 'en', 'de', 'fr', 'es', 'zh'] as $lang) {
    $data = json_decode((string)file_get_contents($root . '/translations/' . $lang . '.json'), true);
    $missing = [];
    foreach ($i18n as $dotted) {
        $node = $data;
        foreach (explode('.', $dotted) as $part) {
            $node = is_array($node) && isset($node[$part]) ? $node[$part] : null;
        }
        if (!is_string($node) || $node === '') {
            $missing[] = $dotted;
        }
    }
    check("$lang has every tag string" . ($missing ? ' (' . implode(', ', $missing) . ')' : ''), !$missing);
}

echo "\n{$checks} checks, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);

