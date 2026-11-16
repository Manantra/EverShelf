#!/usr/bin/env php
<?php
/**
 * Regression: user-locked title casing survives a save and a later catalog refresh.
 * No production database; exercises mergeIncomingProductFields() directly.
 */
declare(strict_types=1);
define('CRON_MODE', true);
require_once __DIR__ . '/../api/bootstrap.php';
require_once __DIR__ . '/../api/index.php';

function checkTitle(string $expected, string $actual, string $message): void {
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL {$message}: expected " . var_export($expected, true)
            . ", got " . var_export($actual, true) . "\n");
        exit(1);
    }
}

$base = [
    'name' => 'Passierte tomaten',
    'brand' => '',
    'category' => 'conserve',
    'unit' => 'conf',
    'default_quantity' => 1,
    'package_unit' => 'conf',
    'name_user_set' => 1,
    'barcode' => null,
    'shopping_name' => 'Passierte',
    'kind' => '',
    'image_url' => '',
    'notes' => '',
    'nutriments_json' => null,
];

// Case-only change to a previously manually-locked product.
$changed = mergeIncomingProductFields($base, [
    'name' => 'Passierte Tomaten',
    'name_user_set' => 1,
    'unit' => 'conf',
    'default_quantity' => 1,
    'package_unit' => 'conf',
    'lang' => 'de',
], null);
checkTitle('Passierte Tomaten', $changed['name'], 'case-only user rename');

$stored = array_merge($base, $changed);
// A later catalog update must not silently overwrite the manual casing.
$catalog = mergeIncomingProductFields($stored, [
    'name' => 'passierte tomaten',
    'name_user_set' => 0,
    'unit' => 'conf',
    'default_quantity' => 1,
], null);
checkTitle('Passierte Tomaten', $catalog['name'], 'preserve locked title during catalog refresh');

// New manually entered titles can still start with a lowercase letter, which
// is capitalized, but the other letters must not be changed.
$new = mergeIncomingProductFields(null, [
    'name' => 'passierte Tomaten',
    'name_user_set' => 1,
    'category' => 'conserve',
    'unit' => 'conf',
    'default_quantity' => 1,
    'lang' => 'de',
], null);
checkTitle('Passierte Tomaten', $new['name'], 'manual title capitalizes only first letter');

// Catalog names retain their historical normalization.
$automatic = mergeIncomingProductFields(null, [
    'name' => 'PASSIERTE TOMATEN',
    'name_user_set' => 0,
    'category' => 'conserve',
    'unit' => 'conf',
    'default_quantity' => 1,
], null);
checkTitle('Passierte tomaten', $automatic['name'], 'automatic title stays canonically normalized');

echo "All manually locked title casing tests passed.\n";
