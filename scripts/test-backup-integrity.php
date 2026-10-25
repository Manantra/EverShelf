#!/usr/bin/env php
<?php
/**
 * Guard test — a backup is only promoted after it proves it can be opened.
 *
 * Regression history (audit G3): `createLocalBackup()` wrote the snapshot with
 * `VACUUM INTO` (consistent) and renamed it into place, but nothing ever opened the
 * result. A truncated `.part` (disk full, killed process, a filesystem that returned
 * short writes) was therefore promoted to "the backup" and only discovered on restore
 * day — the worst possible moment.
 *
 * The check is deliberately cheap: `PRAGMA quick_check` walks the b-tree structure
 * (O(pages), no repair), which is exactly what "can this file be trusted to hold my
 * pantry?" means.
 *
 * Run: php scripts/test-backup-integrity.php
 */

$root = dirname(__DIR__);
$fail = 0;
$checks = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $fail, $checks;
    $checks++;
    if ($ok) {
        echo "ok    {$label}\n";
    } else {
        $fail++;
        echo "FAIL  {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

/** Pull a top-level function out of a PHP file by matching braces. */
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

$api = (string)file_get_contents($root . '/api/index.php');
$body = extract_fn($api, 'createLocalBackup');
check('createLocalBackup() is extractable', $body !== '');

check('the snapshot is verified with PRAGMA quick_check', str_contains($body, 'PRAGMA quick_check'));
check('the verification runs on the .part file, not on the live DB',
    str_contains($body, "new PDO('sqlite:' . \$tmpPath)"));
$checkAt  = strpos($body, 'PRAGMA quick_check');
$renameAt = strpos($body, '@rename($tmpPath, $destPath)');
check('the verification runs BEFORE the snapshot replaces the live one',
    $checkAt !== false && $renameAt !== false && $checkAt < $renameAt,
    "quick_check at {$checkAt}, rename at {$renameAt}");
check('a failed verification deletes the .part file',
    $checkAt !== false && str_contains(substr($body, (int)$checkAt, 900), '@unlink($tmpPath)'));
check('a failed verification answers with a translation key',
    str_contains($body, "'error_key' => 'error.backup_integrity'"));
check('a failed verification is logged with a stable event',
    str_contains($body, "'event'  => 'backup_integrity_failed'"));
check('the error key is resolved by the UI with a bare t() call',
    str_contains((string)file_get_contents($root . '/assets/js/app.js'), "t('error.backup_integrity')"));

// ── The probe really has teeth: a truncated SQLite file is not "ok" ──────────
$tmp = tempnam(sys_get_temp_dir(), 'evs_integ_');
@unlink($tmp);
$dbPath = $tmp . '.sqlite';
$db = new PDO('sqlite:' . $dbPath);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE t (id INTEGER PRIMARY KEY, v TEXT)');
$db->exec("INSERT INTO t (v) SELECT hex(randomblob(200)) FROM (WITH RECURSIVE c(x) AS (SELECT 1 UNION ALL SELECT x+1 FROM c WHERE x < 500) SELECT x FROM c)");
$db = null;

$good = new PDO('sqlite:' . $dbPath);
$goodResult = (string)$good->query('PRAGMA quick_check')->fetchColumn();
$good = null;
check('a healthy snapshot passes quick_check', $goodResult === 'ok', $goodResult);

$brokenPath = $tmp . '_broken.sqlite';
$raw = (string)file_get_contents($dbPath);
file_put_contents($brokenPath, substr($raw, 0, max(1024, intdiv(strlen($raw), 3))));
$broken = null;
$brokenResult = 'ok';
try {
    $broken = new PDO('sqlite:' . $brokenPath);
    $broken->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $brokenResult = (string)$broken->query('PRAGMA quick_check')->fetchColumn();
    $broken = null;
} catch (Throwable $e) {
    $brokenResult = 'unreadable: ' . $e->getMessage();
}
check('a truncated snapshot does NOT pass quick_check (the check has teeth)', $brokenResult !== 'ok', $brokenResult);

foreach ([$dbPath, $brokenPath] as $f) {
    @unlink($f);
}

echo "\n{$checks} checks, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
