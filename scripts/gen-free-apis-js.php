#!/usr/bin/env php
<?php
/**
 * Generates assets/js/free_apis.generated.js from data/free_apis.json.
 *
 * Why baked in: the splash is on screen *before* the app can talk to the API (there is no
 * token yet) and has to work offline. The credits row under the boot rail, the Settings
 * card and the footer all read the same list, and a service is added in one row of JSON.
 *
 * Run it after editing data/free_apis.json; scripts/test-vocabulary.php fails when the
 * generated file and the data drift apart.
 */
$root = dirname(__DIR__);
$src  = $root . '/data/free_apis.json';
$out  = $root . '/assets/js/free_apis.generated.js';

$data = json_decode((string)@file_get_contents($src), true);
if (!is_array($data) || !is_array($data['apis'] ?? null)) {
    fwrite(STDERR, "invalid JSON: {$src}\n");
    exit(1);
}

$apis = [];
foreach ($data['apis'] as $api) {
    if (!is_array($api) || trim((string)($api['id'] ?? '')) === '') {
        continue;
    }
    $url = (string)($api['url'] ?? '');
    $logo = (string)($api['logo'] ?? '');
    // The logo is a LOCAL asset: the splash must paint it offline, before any network.
    $logoOk = preg_match('#^assets/img/[A-Za-z0-9_./-]+\.(svg|png)$#', $logo) && !str_contains($logo, '..');
    $apis[] = [
        'id'      => (string)$api['id'],
        'name'    => (string)($api['name'] ?? $api['id']),
        'mark'    => (string)($api['mark'] ?? ''),
        'logo'    => $logoOk ? $logo : '',
        // Only a real http(s) URL: the field must never become a javascript: link.
        'url'     => preg_match('#^https?://#i', $url) ? $url : '',
        'licence' => (string)($api['licence'] ?? ''),
    ];
}
if (!$apis) {
    fwrite(STDERR, "no apis in {$src}\n");
    exit(1);
}

$json = json_encode($apis, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$js = "/**\n"
    . " * GENERATED FILE — do not edit. Source: data/free_apis.json\n"
    . " * Regenerate: php scripts/gen-free-apis-js.php   (locked by scripts/test-vocabulary.php)\n"
    . " * Data only: no logic here.\n"
    . " */\n"
    . "const EVERSHELF_FREE_APIS = {$json};\n";
file_put_contents($out, $js);
echo 'written: assets/js/free_apis.generated.js (' . count($apis) . " service(s), " . strlen($js) . " bytes)\n";
