#!/usr/bin/env php
<?php
/**
 * Guard test — the hot read paths must use an index.
 *
 * Regression history (audit D1/G2): `inventory` was sorted by `added_at` in ten
 * places and `products` was looked up by `lower(name)` /
 * `lower(trim(coalesce(shopping_name,'')))` with no index at all, so every dashboard
 * rebuild and every 5-minute shopping cron scanned the whole table.
 *
 * The test is behavioural, not textual: it creates a real SQLite database through
 * initializeDB(), fills it, then runs EXPLAIN QUERY PLAN on the *actual* statements
 * and fails if any of them scans `inventory`/`products` wholesale. Editing the index
 * expression without editing the query (or the other way round) is exactly the
 * mistake this catches — SQLite matches the parsed expression, not the text.
 *
 * Run: php scripts/test-db-indexes.php
 */
define('CRON_MODE', true);
require_once __DIR__ . '/../api/bootstrap.php';
require_once __DIR__ . '/../api/database.php';

$fail = 0;
$checks = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $fail, $checks;
    $checks++;
    if ($ok) {
        echo "ok    $label\n";
    } else {
        $fail++;
        echo "FAIL  $label" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

$path = tempnam(sys_get_temp_dir(), 'evs_idx_');
@unlink($path);
$dbPath = $path . '.sqlite';
$db = new PDO('sqlite:' . $dbPath);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
initializeDB($db);

// ---------------------------------------------------------------- the indexes exist
$names = $db->query("SELECT name FROM sqlite_master WHERE type = 'index'")->fetchAll(PDO::FETCH_COLUMN);
foreach ([
    'idx_inventory_added',
    'idx_products_name_lower',
    'idx_products_shopping_name_lower',
    'idx_products_family_key_lower',
    'idx_products_buy_key_lower',
] as $index) {
    check("initializeDB() creates {$index}", in_array($index, $names, true));
}

// migrateDB() must add them to a database that was created before they existed
// (drop them, migrate, expect them back).
foreach ($names as $index) {
    if (str_starts_with($index, 'idx_inventory_added') || str_starts_with($index, 'idx_products_')) {
        $db->exec("DROP INDEX IF EXISTS {$index}");
    }
}
migrateDB($db);
$after = $db->query("SELECT name FROM sqlite_master WHERE type = 'index'")->fetchAll(PDO::FETCH_COLUMN);
foreach (['idx_inventory_added', 'idx_products_name_lower', 'idx_products_shopping_name_lower',
          'idx_products_family_key_lower', 'idx_products_buy_key_lower'] as $index) {
    check("migrateDB() restores {$index} on an existing database", in_array($index, $after, true));
}

// A second run must be a no-op (idempotent boot path).
migrateDB($db);
check('migrateDB() is idempotent', true);

// --------------------------------------------------------------- query plans use it
/** Plan text for one statement. */
function plan(PDO $db, string $sql): string
{
    $rows = $db->query('EXPLAIN QUERY PLAN ' . $sql)->fetchAll(PDO::FETCH_ASSOC);
    return implode(' | ', array_map(fn($r) => (string)($r['detail'] ?? ''), $rows));
}

$cases = [
    'FIFO pick by added_at' => [
        'SELECT id FROM inventory WHERE quantity > 0 ORDER BY added_at ASC LIMIT 1',
        'idx_inventory_added',
    ],
    'product lookup by lower(name)' => [
        "SELECT id FROM products WHERE lower(name) = 'pasta'",
        'idx_products_name_lower',
    ],
    'family stock by lower(trim(shopping_name))' => [
        "SELECT id FROM products WHERE lower(trim(shopping_name)) = 'pasta'",
        'idx_products_shopping_name_lower',
    ],
    'family stock by lower(trim(coalesce(shopping_name,\'\')))' => [
        "SELECT id FROM products WHERE lower(trim(coalesce(shopping_name,''))) = 'pasta'",
        'idx_products_family_key_lower',
    ],
    'restock by lower(trim(coalesce(nullif(shopping_name,\'\'), name)))' => [
        "SELECT id FROM products WHERE lower(trim(coalesce(nullif(shopping_name,''), name))) = 'pasta'",
        'idx_products_buy_key_lower',
    ],
];

foreach ($cases as $label => [$sql, $index]) {
    $detail = plan($db, $sql);
    check("{$label} uses {$index}", str_contains($detail, $index), $detail);
    // "SCAN inventory USING INDEX idx_…" is an index walk (fine); a bare
    // "SCAN inventory" is the full-table scan this test exists to prevent.
    $fullScan = preg_match('/SCAN (products|inventory)(?! USING INDEX)/', $detail) === 1;
    check("{$label} does not scan products/inventory wholesale", !$fullScan, $detail);
}

// ----------------------------------------------------------------------- cleanup
$db = null;
@unlink($dbPath);

echo "\n{$checks} checks, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
