#!/usr/bin/env php
<?php
/** Config compatibility: planned horizons, units and manually entered shopping data. */
declare(strict_types=1);
define('CRON_MODE', true);
require_once __DIR__ . '/../api/bootstrap.php';
require_once __DIR__ . '/../api/index.php';

function checkConfig(bool $ok, string $name): void {
    if (!$ok) { fwrite(STDERR, "FAIL: {$name}\n"); exit(1); }
    echo "OK: {$name}\n";
}

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE app_settings (key TEXT PRIMARY KEY, value TEXT)');
$db->exec("INSERT INTO app_settings (key,value) VALUES ('shopping_plan_days','7')");
checkConfig(smartResolvePlanDays(null, $db) === 7, 'stored planning horizon 7d wins over inferred default');
checkConfig(smartResolvePlanDays(20, $db) === 20, 'explicit request overrides stored horizon');
$db->exec("UPDATE app_settings SET value='30' WHERE key='shopping_plan_days'");
checkConfig(smartResolvePlanDays(null, $db) === 30, 'stored planning horizon 30d remains intact');
checkConfig(smartResolvePlanDays(0, $db) === 1, 'explicit planning horizon clamps only to existing allowed range');

// Same auto-prediction, different allowed purchase window. No global hard-coded 3-pack policy.
foreach ([7 => 3, 14 => 3, 30 => 5] as $days => $max) {
    $cacheItem = [
        'name' => 'Passierte Tomaten', 'shopping_name' => 'Passierte',
        'unit' => 'conf', 'default_qty' => 690, 'package_unit' => 'g',
        'suggested_qty' => 24, 'suggested_unit' => 'conf',
        'edible_days' => $days, 'urgency' => 'medium', 'suggested_approx' => true,
    ];
    $original = $cacheItem;
    $spec = evershelfBuildShoppingSpec($cacheItem);
    checkConfig(str_contains($spec, 'Almeno: ' . $max . ' conf'),
        "cached 24 conf is capped for {$days}-day auto plan (expected {$max})");
    checkConfig($cacheItem === $original, 'builder does not change cached prediction data');
}

// Legacy cache without edible_days/plan_days must use stored household setting.
$db->exec("UPDATE app_settings SET value='7' WHERE key='shopping_plan_days'");
$legacy = [
    'name' => 'Passierte Tomaten', 'shopping_name' => 'Passierte',
    'unit' => 'conf', 'default_qty' => 690, 'package_unit' => 'g',
    'suggested_qty' => 24, 'suggested_unit' => 'conf', 'suggested_approx' => true,
];
checkConfig(str_contains(evershelfBuildShoppingSpec($legacy, $db), 'Almeno: 3 conf'),
    'legacy cache without horizon respects stored 7-day shopping setting');
$db->exec("UPDATE app_settings SET value='30' WHERE key='shopping_plan_days'");
checkConfig(str_contains(evershelfBuildShoppingSpec($legacy, $db), 'Almeno: 5 conf'),
    'legacy cache without horizon respects stored 30-day shopping setting');

// Other auto-generated unit families preserve scale; a stale suggestion cannot
// bypass the same guard through the display/specification layer.
foreach ([
    ['pz', 24, 7, 11],
    ['g', 9000, 14, 1400],
    ['ml', 9000, 14, 1500],
] as [$unit, $qty, $days, $expected]) {
    $item = [
        'name' => 'Test item', 'shopping_name' => 'Test item',
        'unit' => $unit, 'default_qty' => $unit === 'g' ? 500 : ($unit === 'ml' ? 500 : 1),
        'package_unit' => $unit === 'g' || $unit === 'ml' ? $unit : '',
        'suggested_qty' => $qty, 'suggested_unit' => $unit,
        'edible_days' => $days, 'urgency' => 'medium',
    ];
    $capped = shoppingCapSuggestedQty((float)$qty, $unit, (float)$item['default_qty'], (string)$item['package_unit'], $days);
    $spec = evershelfBuildShoppingSpec($item);
    checkConfig(!str_contains($spec, 'Compra: ' . $qty . ' ' . $unit),
        "cached {$unit} auto suggestion is not left oversized");
    $expectedLabel = match ($unit) {
        'g' => 'Compra: 1.4 kg',
        'ml' => 'Compra: 1.5 l',
        default => 'Compra: 11 pz',
    };
    checkConfig(str_contains($spec, $expectedLabel),
        "{$unit} suggestion keeps the correct unit and bounded quantity");
}

// The manual shopping-list path is separate; do not clamp its specification.
$db->exec("CREATE TABLE shopping_list (id INTEGER PRIMARY KEY, name TEXT, raw_name TEXT DEFAULT '', specification TEXT DEFAULT '')");
$db->exec("CREATE TABLE products (id INTEGER PRIMARY KEY, name TEXT, brand TEXT DEFAULT '', shopping_name TEXT DEFAULT '')");
// The manually entered row should remain 24 packs if that is what the person chose.
$db->prepare('INSERT INTO shopping_list (name,raw_name,specification) VALUES(?,?,?)')
   ->execute(['Passierte', 'Passierte Tomaten', '24 conf (manuell)']);
$manualSpec = $db->query("SELECT specification FROM shopping_list WHERE name='Passierte'")->fetchColumn();
checkConfig($manualSpec === '24 conf (manuell)', 'manual list quantity is not rewritten by auto-suggestion guard');

// Configured remove-on-buy and Bring modes are tested in isolated PHP processes
// so that env() sees each dedicated .env fixture exactly as it would at boot.
$root = dirname(__DIR__);
$probe = 'define("CRON_MODE", true); require ' . var_export($root . '/api/bootstrap.php', true)
       . '; require ' . var_export($root . '/api/index.php', true)
       . '; echo json_encode([shoppingRemoveOnBuyMode(), isShoppingBringMode()]);';
$fixtures = [
    ['internal', 'plan', '', '', 'plan', false],
    ['internal', 'trip', '', '', 'trip', false],
    ['bring', 'plan', '', '', 'plan', false], // no credentials → internal fallback
    ['bring', 'trip', 'test@example.invalid', 'dummy-only', 'trip', true],
];
foreach ($fixtures as [$mode, $removal, $email, $password, $expectedRemoval, $expectedBring]) {
    $configFile = tempnam(sys_get_temp_dir(), 'evershelf-settings-');
    file_put_contents($configFile, "SHOPPING_MODE={$mode}\nSHOPPING_REMOVE_ON_BUY={$removal}\nBRING_EMAIL={$email}\nBRING_PASSWORD={$password}\n");
    $command = 'EVERSHELF_ENV_FILE=' . escapeshellarg($configFile)
        . ' php -r ' . escapeshellarg($probe) . ' 2>&1';
    $decoded = json_decode((string)shell_exec($command), true);
    @unlink($configFile);
    checkConfig($decoded === [$expectedRemoval, $expectedBring],
        "mode={$mode}, remove-on-buy={$removal}, credentials " . ($email !== '' ? 'configured' : 'absent'));
}

echo "All shopping configuration compatibility tests passed.\n";
