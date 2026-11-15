#!/usr/bin/env php
<?php
/**
 * Regression tests: the backup panel is translated end to end.
 *
 * The panel's static labels were always wired with data-i18n, but its dynamic
 * messages were not: the restore endpoint answered with English prose that the
 * UI printed inside an Italian page, and several alerts/statuses carried
 * hardcoded English ("Error deleting backup", "Code exchange failed", a "gg"
 * fallback for the retention unit). This test locks the chain:
 *
 *   backend failure → error_key → _apiErrorText() → bare t('key')
 *   restore success → message_key, UI shows its own literal key
 *   every key exists in all six locales, with its placeholders
 *   no hardcoded English fallback is left in the backup functions
 *
 * Run: php scripts/test-backup-i18n.php
 */
$root = dirname(__DIR__);
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

$api   = (string)file_get_contents($root . '/api/index.php');
$app   = (string)file_get_contents($root . '/assets/js/app.js');
$html  = (string)file_get_contents($root . '/index.html');
$langs = ['it', 'en', 'de', 'fr', 'es', 'zh'];
$json  = [];
foreach ($langs as $l) {
    $json[$l] = json_decode((string)file_get_contents($root . "/translations/{$l}.json"), true);
}

/** Nested translation lookup. */
function tkey(array $doc, string $key): ?string
{
    $node = $doc;
    foreach (explode('.', $key) as $step) {
        $node = is_array($node) ? ($node[$step] ?? null) : null;
    }
    return is_string($node) && trim($node) !== '' ? $node : null;
}

/** Pull a top-level function out of app.js by matching braces. */
function extract_fn(string $src, string $name): string
{
    $start = strpos($src, 'function ' . $name . '(');
    if ($start === false) {
        return '';
    }
    $open = strpos($src, '{', $start);
    if ($open === false) {
        return '';
    }
    $depth = 0;
    for ($i = $open, $len = strlen($src); $i < $len; $i++) {
        if ($src[$i] === '{') {
            $depth++;
        } elseif ($src[$i] === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($src, $start, $i - $start + 1);
            }
        }
    }
    return '';
}

// ── The backend answers with keys, not English prose ────────────────────────
$backupFns = '';
foreach (['createLocalBackup', 'deleteLocalBackup', 'restoreLocalBackup', 'backupToGDrive'] as $fn) {
    $body = extract_fn($api, $fn);
    assert_true($body !== '', "{$fn}() is extractable");
    $backupFns .= "\n" . $body;
}
$errorKeys = [
    'error.backup_no_dir',
    'error.backup_no_db',
    'error.backup_copy_failed',
    'error.backup_integrity',
    'error.backup_invalid_name',
    'error.backup_not_found',
    'error.backup_delete_failed',
    'error.backup_restore_failed',
];
foreach ($errorKeys as $key) {
    assert_true(str_contains($backupFns, "'{$key}'"), "the backup functions can answer {$key}");
    assert_true(str_contains($app, "t('{$key}')"), "the UI resolves {$key} with a bare t('…') call");
    foreach ($langs as $l) {
        assert_true(tkey($json[$l], $key) !== null, "translations/{$l}.json has {$key}");
    }
}
// GDrive config errors are emitted by the token/endpoint helpers, not only by
// backupToGDrive, so they are checked against the whole router.
$gdriveKeys = [
    'error.gdrive_disabled',
    'error.gdrive_folder_missing',
    'error.gdrive_not_configured',
    'error.gdrive_not_authorized',
    'error.gdrive_code_missing',
];
foreach ($gdriveKeys as $key) {
    assert_true(str_contains($api, "'{$key}'"), "the backend can answer {$key}");
    assert_true(str_contains($app, "t('{$key}')"), "the UI resolves {$key} with a bare t('…') call");
    foreach ($langs as $l) {
        assert_true(tkey($json[$l], $key) !== null, "translations/{$l}.json has {$key}");
    }
}
assert_true(str_contains($backupFns, "'message_key' => 'settings.backup.restore_done'"), 'restore returns message_key for the UI');

// ── The dynamic messages carry their placeholders ───────────────────────────
foreach ($langs as $l) {
    foreach (['gdrive_error' => '{error}', 'gdrive_pushed' => '{file}'] as $k => $slot) {
        $value = (string)tkey($json[$l], "settings.backup.{$k}");
        assert_true(str_contains($value, $slot), "{$l}: settings.backup.{$k} keeps {$slot}");
    }
    assert_true(str_contains((string)tkey($json[$l], 'settings.backup.gdrive_pushed'), '{purged}'), "{$l}: gdrive_pushed keeps {purged}");
}

// ── The English labels are really English (not Italian copied over) ────────
foreach (['backup_now', 'local_title', 'local_hint', 'enabled', 'retention_days', 'retention_info', 'last_backup', 'no_backup_yet', 'delete_confirm', 'restore_confirm', 'restore_warning', 'restore_done', 'reload_hint'] as $k) {
    $it = (string)tkey($json['it'], "settings.backup.{$k}");
    $en = (string)tkey($json['en'], "settings.backup.{$k}");
    assert_true($it !== '' && $en !== '' && $it !== $en, "settings.backup.{$k} is translated, not copied from Italian");
}

// ── No hardcoded English fallback left in the backup panel ─────────────────
$backupUi = '';
foreach (['_backupNow', '_backupDelete', '_backupRestore', '_gdriveTest', '_gdrivePushNow', '_gdriveAuthorize', '_gdriveSubmitCode', '_renderBackupTab', '_loadBackupTab', '_apiErrorText'] as $fn) {
    $body = extract_fn($app, $fn);
    assert_true($body !== '', "{$fn}() is extractable");
    $backupUi .= "\n" . $body;
}
foreach ([
    "'Error deleting backup'",
    "'Failed to get OAuth URL'",
    "'Code exchange failed'",
    "'Error'",
    "'Authorized'",
    "'Not authorized yet'",
    "'Paste the URL or code first'",
    "|| 'gg'",
] as $literal) {
    assert_true(!str_contains($backupUi, $literal), "no hardcoded {$literal} left in the backup panel");
}
// Every mutable surface goes through t() / _apiErrorText.
foreach (['t(' . "'settings.backup.backing_up'", "t('settings.backup.restore_done')", "t('settings.backup.gdrive_error'", "t('settings.backup.gdrive_pushed'", '_apiErrorText('] as $needle) {
    assert_true(str_contains($backupUi, $needle), "the panel uses {$needle}");
}

// ── The static labels are wired, and the panel renders them ────────────────
foreach (['settings.backup.local_title', 'settings.backup.local_hint', 'settings.backup.enabled', 'settings.backup.retention_days', 'settings.backup.backup_now'] as $key) {
    assert_true(str_contains($html, 'data-i18n="' . $key . '"'), "index.html wires {$key} with data-i18n");
}
assert_true(str_contains($html, 'id="setting-backup-enabled"'), 'the automatic-backup toggle exists on the page');

if ($fail > 0) {
    echo "\n{$fail} test(s) failed\n";
    exit(1);
}
echo "\nAll backup i18n tests passed\n";
