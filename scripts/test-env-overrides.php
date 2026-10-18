#!/usr/bin/env php
<?php
/**
 * Regression tests: a setting stored in the DB fallback must be read back within
 * the same request.
 *
 * Run: php scripts/test-env-overrides.php
 *
 * Issue #261: on an install whose .env is not writable (Docker, a .env owned by
 * root), save_settings stores the value in app_settings.env_overrides and answers
 * "success: true, stored: database" — and then the app kept showing the old value,
 * because `env()` ignored it from then on. Reason: bootstrap.php calls
 * env('DISPLAY_ERRORS') *before* api/database.php is loaded, so on every HTTP
 * request the first `loadEnvOverrides()` ran while getDB() did not exist yet and
 * cached the empty answer for the rest of the request. Custom storage units,
 * dietary preferences and every other fallback setting were affected — they looked
 * "lost" and could not be re-added.
 *
 * The child process below reproduces the bootstrap order faithfully, because the
 * bug is a static cache and cannot be observed twice in one process.
 *
 * Issue #298: the same fallback used to shadow a hand-edited `.env` forever — a
 * value saved while the file was read-only kept winning after the user changed
 * the file and restarted. The scenarios at the end check that the file wins once
 * its content no longer matches what the fallback was saved against, and that a
 * legacy entry (no baseline) is judged by the file's mtime.
 */
$fail = 0;

function assert_true(bool $cond, string $msg): void
{
    global $fail;
    if (!$cond) {
        echo "FAIL: {$msg}\n";
        $fail++;
    } else {
        echo "OK: {$msg}\n";
    }
}

const TEST_ENV_KEY = 'EVERSHELF_TEST_OVERRIDE';

// Parent: a normal bootstrap, so the DB exists and the fallback can be exercised.
define('CRON_MODE', true);
require_once __DIR__ . '/../api/bootstrap.php';

$api = realpath(__DIR__ . '/../api');
$src = (string)file_get_contents($api . '/lib/env.php');

// ── Structural guard: the "database not loaded yet" answer must not be cached ──
assert_true(
    !preg_match('/function loadEnvOverrides.*?\$cache = \[\];\s*if \(!function_exists\(.getDB.\)\)/s', $src),
    'the DB-unavailable answer is not stored in the static cache'
);
assert_true(
    (bool)preg_match('/if \(!function_exists\(.getDB.\)\) \{.*?return \[\];/s', $src),
    'loadEnvOverrides() answers "no overrides" while the DB is unavailable'
);

// ── The fallback itself still round-trips in a normal request ─────────────────
assert_true(saveEnvOverrides([TEST_ENV_KEY => 'from-db']), 'the DB fallback accepts a value');
assert_true(env(TEST_ENV_KEY) === 'from-db', 'env() reads the value written to the DB fallback');

// ── A fresh request that starts like bootstrap.php must see it too ────────────
$key   = TEST_ENV_KEY;
$child = sys_get_temp_dir() . '/evershelf-env-override-child-' . getmypid() . '.php';
file_put_contents($child, <<<PHP
<?php
// Same order as api/bootstrap.php: env() is used before api/database.php loads.
require '{$api}/lib/env.php';
\$early = env('{$key}', 'missing');
require '{$api}/lib/constants.php';
require '{$api}/logger.php';
require '{$api}/database.php';
\$late = env('{$key}', 'missing');
echo json_encode(['early' => \$early, 'late' => \$late]);
PHP);
$out = shell_exec('php ' . escapeshellarg($child) . ' 2>&1');
@unlink($child);
$seen = json_decode((string)$out, true);
assert_true(is_array($seen), 'the child process ran and answered — got: ' . trim((string)$out));
assert_true(($seen['early'] ?? null) === 'missing', 'before the DB is loaded there is nothing to read');
assert_true(
    ($seen['late'] ?? null) === 'from-db',
    'after the DB is loaded the same request reads the saved override (issue #261)'
);

// ── A hand-edited .env wins over a stored fallback (issue #298) ───────────────
// The DB fallback exists because .env was not writable; it must not shadow a
// value the user later changed in the file by hand. Each child gets its own
// EVERSHELF_ENV_FILE, so the real .env is never touched.
$originalOverrides = getDB()->query("SELECT value, updated_at FROM app_settings WHERE key = 'env_overrides'")->fetch();
$restoreOverrides = static function () use ($originalOverrides): void {
    $db = getDB();
    if ($originalOverrides) {
        $db->prepare("INSERT INTO app_settings (key, value, updated_at) VALUES ('env_overrides', ?, ?)
                      ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at")
            ->execute([$originalOverrides['value'], $originalOverrides['updated_at']]);
    } else {
        $db->exec("DELETE FROM app_settings WHERE key = 'env_overrides'");
    }
    loadEnvOverrides(true);
};

$scenarioChild = sys_get_temp_dir() . '/evershelf-env-scenario-' . getmypid() . '.php';
file_put_contents($scenarioChild, <<<PHP
<?php
putenv('EVERSHELF_ENV_FILE=' . \$argv[1]);
require '{$api}/lib/env.php';
require '{$api}/lib/constants.php';
require '{$api}/logger.php';
require '{$api}/database.php';

\$key  = \$argv[3];
\$mode = \$argv[2];
\$out  = ['mode' => \$mode];
if (\$mode === 'new') {
    // Fallback saved against the file as it is now: it must win while the file
    // is untouched, and lose as soon as the file changes.
    \$out['saved']      = saveEnvOverrides([\$key => 'from-db']);
    \$out['unchanged']  = env(\$key);
    file_put_contents(\$argv[1], \$key . '=from-env-2' . "\\n");
    clearstatcache();
    loadEnv(true);
    \$out['after_edit'] = env(\$key);
    \$out['pruned']     = !array_key_exists(\$key, loadEnvOverrides(true));
} else {
    // Legacy row written by an older version: a bare string, no baseline. Only
    // the .env mtime can tell which side is newer.
    \$row = getDB()->query("SELECT value FROM app_settings WHERE key = 'env_overrides'")->fetchColumn();
    \$map = \$row ? json_decode((string)\$row, true) : [];
    if (!is_array(\$map)) { \$map = []; }
    \$map[\$key] = 'from-db';
    getDB()->prepare("INSERT INTO app_settings (key, value, updated_at) VALUES ('env_overrides', ?, ?)
                      ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at")
        ->execute([json_encode(\$map), \$argv[4]]);
    loadEnv(true);
    \$out['older_file'] = env(\$key);
    file_put_contents(\$argv[1], \$key . '=from-env-new' . "\\n");
    touch(\$argv[1], time() + 60);
    clearstatcache();
    loadEnv(true);
    \$out['newer_file'] = env(\$key);
    \$out['pruned']     = !array_key_exists(\$key, loadEnvOverrides(true));
}
echo json_encode(\$out);
PHP);

$runScenario = static function (string $mode, string $envLine, int $envMtime) use ($scenarioChild, $api): array {
    $envFile = tempnam(sys_get_temp_dir(), 'evershelf-env-');
    file_put_contents($envFile, $envLine . "\n");
    touch($envFile, $envMtime);
    $cmd = 'php ' . escapeshellarg($scenarioChild)
        . ' ' . escapeshellarg($envFile)
        . ' ' . escapeshellarg($mode)
        . ' ' . escapeshellarg(TEST_ENV_KEY)
        . ' ' . escapeshellarg(gmdate('Y-m-d H:i:s'));
    $out = shell_exec($cmd . ' 2>&1');
    @unlink($envFile);
    $decoded = json_decode((string)$out, true);
    return is_array($decoded) ? $decoded : ['raw' => trim((string)$out)];
};

try {
    $new = $runScenario('new', TEST_ENV_KEY . '=from-env-1', time() - 60);
    assert_true(is_array($new) && ($new['saved'] ?? false) === true, 'the fallback still accepts a value');
    assert_true(($new['unchanged'] ?? null) === 'from-db', 'with .env untouched the fallback still wins (issue #261)');
    assert_true(($new['after_edit'] ?? null) === 'from-env-2', 'a hand-edited .env wins over the stored fallback (issue #298)');
    assert_true(($new['pruned'] ?? false) === true, 'the stale fallback entry is removed, not just ignored');

    $legacy = $runScenario('legacy', TEST_ENV_KEY . '=from-env-old', time() - 3600);
    assert_true(($legacy['older_file'] ?? null) === 'from-db', 'a legacy fallback still wins while .env is older');
    assert_true(($legacy['newer_file'] ?? null) === 'from-env-new', 'a .env modified after a legacy fallback wins (issue #298)');
    assert_true(($legacy['pruned'] ?? false) === true, 'the stale legacy entry is removed too');
} finally {
    @unlink($scenarioChild);
    $restoreOverrides();
}

// ── Cleanup: the test key must not linger in the app's overrides ──────────────
clearEnvOverrides([TEST_ENV_KEY]);
assert_true(env(TEST_ENV_KEY) === '', 'the test key is removed again');
assert_true(!array_key_exists(TEST_ENV_KEY, loadEnvOverrides()), 'no test key is left in the DB fallback');

if ($fail > 0) {
    echo "\n{$fail} test(s) failed\n";
    exit(1);
}
echo "\nAll env override tests passed\n";
