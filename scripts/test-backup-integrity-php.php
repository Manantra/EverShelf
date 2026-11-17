<?php
/**
 * Fail-closed snapshot publication regression — disposable SQLite files only.
 * php scripts/test-backup-integrity-php.php
 */
require_once dirname(__DIR__) . '/api/lib/local_backup_integrity.php';

$dir = sys_get_temp_dir() . '/evershelf-backup-qa-' . bin2hex(random_bytes(8));
if (!mkdir($dir, 0700)) {
    throw new RuntimeException('Cannot make private test directory');
}
register_shutdown_function(static function () use ($dir): void {
    if (!is_dir($dir) || !str_contains($dir, 'evershelf-backup-qa-')) return;
    foreach (glob($dir . '/*') ?: [] as $file) {
        if (is_file($file)) @unlink($file);
    }
    @rmdir($dir);
});
function verify(bool $cond, string $label): void {
    if (!$cond) throw new RuntimeException('FAIL ' . $label);
    echo 'PASS ' . $label . PHP_EOL;
}
function sqliteCheck(string $p): string {
    $check = new PDO('sqlite:' . $p);
    $check->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return (string)$check->query('PRAGMA quick_check')->fetchColumn();
}

$source = new PDO('sqlite:' . $dir . '/source.db');
$source->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$source->exec('PRAGMA journal_mode=WAL');
$source->exec('CREATE TABLE proof (id INTEGER PRIMARY KEY, description TEXT NOT NULL)');
$insert = $source->prepare('INSERT INTO proof (description) VALUES (?)');
for ($i = 0; $i < 400; ++$i) {
    $insert->execute([str_repeat('a reliable snapshot ', 6) . $i]);
}

$candidate = $dir . '/evershelf_test.db.part';
$dest = $dir . '/evershelf_test.db';
$source->exec('VACUUM INTO ' . $source->quote($candidate));
verify(is_file($candidate) && filesize($candidate) > 4096, 'WAL snapshot candidate exists');
verify(evershelfValidateAndPublishLocalBackup($candidate, $dest) === 'ok',
    'verified snapshot is promoted to the finished backup');
verify(is_file($dest) && !file_exists($candidate) && sqliteCheck($dest) === 'ok',
    'finished backup remains readable and no temp file remains');
$backup = new PDO('sqlite:' . $dest);
verify((int)$backup->query('SELECT COUNT(*) FROM proof')->fetchColumn() === 400,
    'verified backup includes all committed rows');
$backup = null;
$originalHash = hash_file('sha256', $dest);

file_put_contents($candidate, str_repeat('not a SQLite file', 1024));
verify(evershelfValidateAndPublishLocalBackup($candidate, $dest) === 'integrity_failed',
    'corrupt non-SQLite snapshot is rejected');
verify(!file_exists($candidate) && hash_file('sha256', $dest) === $originalHash,
    'corrupt snapshot removed, previous backup preserved');

file_put_contents($candidate, '');
verify(evershelfValidateAndPublishLocalBackup($candidate, $dest) === 'integrity_failed',
    'zero-length candidate is not mistaken for a new empty SQLite database');
verify(!file_exists($candidate) && hash_file('sha256', $dest) === $originalHash,
    'empty temporary file removed; valid existing backup preserved');

verify(evershelfValidateAndPublishLocalBackup($candidate, $dest) === 'integrity_failed',
    'missing candidate is rejected without creating an empty database');
verify(!file_exists($candidate) && hash_file('sha256', $dest) === $originalHash,
    'missing candidate cannot modify previous good backup');

file_put_contents($candidate, substr((string)file_get_contents($dest), 0, 2048));
verify(evershelfValidateAndPublishLocalBackup($candidate, $dest) === 'integrity_failed',
    'truncated SQLite candidate fails PRAGMA quick_check');
verify(!file_exists($candidate) && hash_file('sha256', $dest) === $originalHash,
    'truncated snapshot does not overwrite valid backup');

$source->exec('VACUUM INTO ' . $source->quote($candidate));
$directoryDestination = $dir . '/not-a-file';
mkdir($directoryDestination);
verify(evershelfValidateAndPublishLocalBackup($candidate, $directoryDestination) === 'publish_failed',
    'failed filesystem rename is distinguished from failed integrity');
verify(!file_exists($candidate) && hash_file('sha256', $dest) === $originalHash,
    'rename failure cleans temp without changing existing good backup');
rmdir($directoryDestination);
$source = null;

echo 'PASS all SQLite backup integrity and atomic publication cases' . PHP_EOL;
