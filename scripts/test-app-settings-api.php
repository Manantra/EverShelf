#!/usr/bin/env php
<?php
/**
 * Guard test — `app_settings` must stay a client store, not a window on the server.
 *
 * Regression history (audit C1/C2): `app_settings_get` echoed **every** row of the
 * table, including `env_overrides` — the fallback copy of environment values that
 * holds real API keys and passwords once `.env` is read-only (issue #298) — and
 * `app_settings_save` accepted any key, so a client could rewrite what `env()`
 * returns or clear a `migration_*` flag and re-run a data migration.
 *
 * What this test locks:
 *   1. the allowlist exists, is unique and contains the setup ledger;
 *   2. it never contains server-internal rows (`env_overrides`, `pairing*`,
 *      `migration_*`, `*_last_sync`, `waste_learning`, …);
 *   3. every key the frontend actually writes is in the allowlist (the two sides
 *      cannot drift silently);
 *   4. both handlers consult the allowlist and answer with the documented contract;
 *   5. the three error keys exist in all six locales;
 *   6. the API mirrors the security headers of the app shell.
 *
 * Run: php scripts/test-app-settings-api.php
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

$api    = (string)file_get_contents($root . '/api/index.php');
$sec    = (string)file_get_contents($root . '/api/lib/security.php');
$appJs  = (string)file_get_contents($root . '/assets/js/app.js');
$locales = ['it', 'en', 'de', 'fr', 'es', 'zh'];
$json = [];
foreach ($locales as $loc) {
    $json[$loc] = json_decode((string)file_get_contents($root . "/translations/{$loc}.json"), true);
}

// ---------------------------------------------------------------- the allowlist
require_once $root . '/api/lib/security.php';
$listed = evershelfClientSettingsKeys();

check('evershelfClientSettingsKeys() exists and is non-empty', count($listed) > 0);
check('the allowlist has no duplicates', count($listed) === count(array_unique($listed)));
check('every entry is a plain key name', count(array_filter($listed, fn($k) => is_string($k) && preg_match('/^[a-z0-9_]+$/', $k) === 1)) === count($listed));
check('the setup ledger is client-visible (issue #301)',
    in_array('setup_done', $listed, true) && in_array('setup_seen', $listed, true));

foreach (['env_overrides', 'pairing', 'test_key', 'barcode_catalog_last_sync', 'waste_learning',
          'migration_recipes_content_hash_v1', 'shopping_dismissed', 'finished_dismissed'] as $internal) {
    check("the allowlist refuses the server-internal row '{$internal}'", !in_array($internal, $listed, true));
}
check('no migration flag is reachable',
    count(array_filter($listed, fn($k) => str_starts_with($k, 'migration_'))) === 0);

// ------------------------------------------- the frontend and the allowlist agree
$written = [];
// _saveToServer('key', …)
preg_match_all("/_saveToServer\\('([a-z0-9_]+)'/", $appJs, $m1);
$written = array_merge($written, $m1[1]);
// app_settings_save … settings: { key: … }
preg_match_all("/app_settings_save[^\\n]*settings:\\s*\\{\\s*([a-z0-9_]+):/", $appJs, $m2);
$written = array_merge($written, $m2[1]);
$written = array_values(array_unique($written));
check('the frontend writes at least the user data we expect (scan_history, setup ledger)',
    in_array('scan_history', $written, true) && in_array('setup_seen', $written, true));
foreach ($written as $key) {
    check("the frontend writes '{$key}' and the allowlist accepts it", in_array($key, $listed, true));
}

// ------------------------------------------------------------- handler behaviour
check('app_settings_get filters through the allowlist',
    str_contains($api, '$allowed = array_flip(evershelfClientSettingsKeys());')
    && str_contains($api, "if (!isset(\$allowed[\$row['key']])) {"));
check('app_settings_get reports how many rows it hid',
    str_contains($api, "'hidden' => \$hidden"));
check('app_settings_save refuses keys outside the allowlist',
    str_contains($api, "'error_key' => 'error.settings_key_not_allowed'")
    && str_contains($api, "'event' => 'app_settings_key_rejected'"));
check('a refused key cancels the whole request (all-or-nothing)',
    str_contains($api, 'if ($rejected) {') && !str_contains($api, '$stmt->execute([$key, json_encode($value)]); // partial'));
check('the refused keys are logged, never their values',
    str_contains($api, "'keys'  => array_slice(\$rejected, 0, 20),"));
check('app_settings_save has a payload cap',
    str_contains($api, 'strlen($raw) > 1048576') && str_contains($api, "'error_key' => 'error.settings_too_large'"));
check('app_settings_save keeps the JSON contract',
    str_contains($api, "'error_key' => 'error.settings_missing'"));

// ------------------------------------------------------------------- i18n + headers
foreach ($locales as $loc) {
    foreach (['error.settings_missing', 'error.settings_key_not_allowed', 'error.settings_too_large'] as $key) {
        $node = $json[$loc];
        foreach (explode('.', $key) as $step) {
            $node = is_array($node) ? ($node[$step] ?? null) : null;
        }
        check("translations/{$loc}.json has {$key}", is_string($node) && trim($node) !== '');
    }
}
foreach (['X-Content-Type-Options', 'X-Frame-Options', 'Referrer-Policy', 'Permissions-Policy',
          'Cross-Origin-Opener-Policy'] as $header) {
    check("the API mirrors the {$header} header", str_contains($sec, "header('{$header}"));
}

echo "\n{$checks} checks, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
