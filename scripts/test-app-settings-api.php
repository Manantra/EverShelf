#!/usr/bin/env php
<?php
/**
 * Regression tests for the client-facing app_settings allowlist.
 * Uses an in-memory SQLite database; never touches the household database.
 */
declare(strict_types=1);
require_once __DIR__ . '/../api/lib/app_settings.php';

$checks = 0;
function checkAppSettings(bool $condition, string $label): void {
    global $checks;
    $checks++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: $label\n");
        exit(1);
    }
    echo "OK: $label\n";
}

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE app_settings (key TEXT PRIMARY KEY, value TEXT, updated_at TEXT)');
$stmt = $db->prepare('INSERT INTO app_settings (key, value) VALUES (?, ?)');
$stmt->execute(['env_overrides', '{"API_TOKEN":"test-internal-secret"}']);
$stmt->execute(['user_prefs', '{"default_persons":2}']);
$stmt->execute(['setup_done', 'true']);
$stmt->execute(['waste_learning', '{"internal":true}']);

$expected = [
    'user_prefs', 'review_confirmed', 'meal_plan', 'tts_voice',
    'scan_history', 'shopping_tags', 'pinned_bring', 'pref_use_loc',
    'pref_move_loc', 'auto_added_bring', 'bring_blocklist',
    'no_expiry_dismissed', 'family_sibling_confirmed',
    'shopping_plan_days', 'setup_seen', 'setup_done',
];
$allowed = evershelfClientSettingsKeys();
checkAppSettings(count($allowed) === count(array_unique($allowed)), 'allowlist has no duplicates');
sort($allowed);
sort($expected);
checkAppSettings($allowed === $expected, 'allowlist contains the reviewed client keys');

$frontend = file_get_contents(__DIR__ . '/../assets/js/app.js');
preg_match_all("/_saveToServer\\('([^']+)'/", $frontend, $matches);
foreach (array_unique($matches[1]) as $key) {
    checkAppSettings(in_array($key, $allowed, true), "dynamic frontend writer accepted: $key");
}
foreach (['user_prefs', 'review_confirmed', 'meal_plan', 'tts_voice', 'setup_seen', 'setup_done'] as $key) {
    checkAppSettings(str_contains($frontend, $key), "direct frontend consumer present: $key");
    checkAppSettings(in_array($key, $allowed, true), "direct frontend key accepted: $key");
}

$read = appSettingsReadClientData($db);
checkAppSettings($read['success'] === true, 'read succeeds');
checkAppSettings(($read['settings']['user_prefs']['default_persons'] ?? null) === 2, 'read preserves normal values');
checkAppSettings(($read['settings']['setup_done'] ?? null) === true, 'read preserves setup state');
checkAppSettings(!isset($read['settings']['env_overrides']), 'read never returns environment overrides');
checkAppSettings(!isset($read['settings']['waste_learning']), 'read never returns internal server state');

$saved = appSettingsWriteClientData($db, json_encode([
    'settings' => ['meal_plan' => ['2026-11-15' => 'soup'], 'setup_done' => false],
], JSON_THROW_ON_ERROR));
checkAppSettings($saved['success'] === true, 'valid multi-key update succeeds');
$read = appSettingsReadClientData($db);
checkAppSettings(($read['settings']['meal_plan']['2026-11-15'] ?? '') === 'soup', 'JSON values survive a roundtrip');
checkAppSettings($read['settings']['setup_done'] === false, 'boolean false survives a roundtrip');

$before = $db->query("SELECT value FROM app_settings WHERE key='user_prefs'")->fetchColumn();
$denied = appSettingsWriteClientData($db, '{"settings":{"user_prefs":{"default_persons":9},"env_overrides":{"API_TOKEN":"altered"}}}');
checkAppSettings($denied['success'] === false && $denied['error'] === 'settings_key_not_allowed', 'a mixed request is rejected');
checkAppSettings($db->query("SELECT value FROM app_settings WHERE key='user_prefs'")->fetchColumn() === $before, 'mixed request did not partially write allowed key');
checkAppSettings(str_contains($db->query("SELECT value FROM app_settings WHERE key='env_overrides'")->fetchColumn(), 'test-internal-secret'), 'internal environment record unchanged');

$denied = appSettingsWriteClientData($db, '{"settings":{"arbitrary_new_key":42}}');
checkAppSettings($denied['success'] === false, 'arbitrary new keys denied');
checkAppSettings($db->query("SELECT count(*) FROM app_settings WHERE key='arbitrary_new_key'")->fetchColumn() == 0, 'arbitrary new key not persisted');

foreach (['invalid json', '[]', '{"settings":[]}', '{"settings":42}', '{"settings":null}', '{}', '{"settings":{"user_prefs":3}', ''] as $input) {
    $result = appSettingsWriteClientData($db, $input);
    checkAppSettings($result['success'] === false && $result['error'] === 'invalid_settings', 'invalid payload rejected');
}
$big = appSettingsWriteClientData($db, str_repeat('x', 1048577));
checkAppSettings($big['error'] === 'settings_too_large', 'request size capped at one MiB');

$empty = appSettingsWriteClientData($db, '{"settings":{}}');
checkAppSettings($empty['success'] === true, 'empty settings object allowed');

$db->exec("CREATE TRIGGER fail_setup AFTER UPDATE ON app_settings WHEN NEW.key='setup_done'
           BEGIN SELECT RAISE(ABORT, 'deliberate test error'); END;");
$thrown = false;
try {
    appSettingsWriteClientData($db, '{"settings":{"user_prefs":{"default_persons":5},"setup_done":true}}');
} catch (PDOException $e) {
    $thrown = true;
}
checkAppSettings($thrown, 'SQL failure propagates');
checkAppSettings($db->query("SELECT value FROM app_settings WHERE key='user_prefs'")->fetchColumn() === $before, 'multi-key update rolls back on SQL failure');
checkAppSettings(!$db->inTransaction(), 'database transaction closed after failure');

echo "PASS: $checks app_settings API regression checks\n";
