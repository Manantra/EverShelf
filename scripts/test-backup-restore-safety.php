<?php
/**
 * Disposable PHP/SQLite test of the restore safety helper, including rollback.
 * Never opens EverShelf's real data directory or an existing installation.
 */
require_once dirname(__DIR__) . '/api/lib/local_backup_restore.php';

$dir = sys_get_temp_dir() . '/evershelf-safe-restore-qa-' . bin2hex(random_bytes(8));
if (!mkdir($dir, 0700)) {
    throw new RuntimeException('No private test directory');
}
register_shutdown_function(static function () use ($dir): void {
    if (!is_dir($dir) || !str_contains($dir, '/evershelf-safe-restore-qa-')) return;
    foreach (glob($dir . '/*') ?: [] as $p) {
        if (is_file($p)) @unlink($p);
    }
    @rmdir($dir);
});
function checkRestore(bool $condition, string $description): void {
    if (!$condition) throw new RuntimeException('FAIL ' . $description);
    echo 'PASS ' . $description . PHP_EOL;
}
function makeHouseholdDB(string $path, string $tag): void {
    $db = new SQLite3($path);
    $db->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
    $db->exec('CREATE TABLE inventory (id INTEGER PRIMARY KEY, product_id INTEGER NOT NULL, quantity REAL NOT NULL)');
    $db->exec('CREATE TABLE app_settings (key TEXT PRIMARY KEY, value TEXT)');
    $st = $db->prepare('INSERT INTO app_settings (key,value) VALUES (:key,:value)');
    $st->bindValue(':key', 'restore_test_marker');
    $st->bindValue(':value', $tag);
    checkRestore((bool)$st->execute(), 'test household inserted');
    $db->close();
}
function marker(PDO $pdo): string {
    return (string)$pdo->query("SELECT value FROM app_settings WHERE key='restore_test_marker'")->fetchColumn();
}
function safetyFiles(string $dir): array {
    return glob($dir . '/restore-safety-*') ?: [];
}

$livePath = $dir . '/household.db';
$backupPath = $dir . '/evershelf_2026-11-16_0730.db';
makeHouseholdDB($livePath, 'live-before-restore');
makeHouseholdDB($backupPath, 'backed-up-before-restore');
$pdo = new PDO('sqlite:' . $livePath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA journal_mode=WAL');
$pdo->exec('PRAGMA busy_timeout=2000');
$pdo->exec("UPDATE app_settings SET value='committed-in-live-WAL' WHERE key='restore_test_marker'");
checkRestore(marker($pdo)==='committed-in-live-WAL', 'open live PDO holds WAL-committed data');

$invalidPath = $dir . '/broken.db';
file_put_contents($invalidPath, 'NOT-A-SQLITE-DATABASE');
checkRestore(evershelfRestoreSqliteBackupSafely($invalidPath,$livePath)==='invalid_backup',
    'non-SQLite backup rejected before replacing live DB');
checkRestore(marker($pdo)==='committed-in-live-WAL' && safetyFiles($dir)===[],
    'bad source leaves live data unchanged and no temporary recovery file');

file_put_contents($invalidPath,'');
checkRestore(evershelfRestoreSqliteBackupSafely($invalidPath,$livePath)==='invalid_backup',
    'zero-byte backup rejected');
checkRestore(evershelfRestoreSqliteBackupSafely($dir.'/missing.db',$livePath)==='invalid_backup',
    'missing backup rejected without creating a SQLite database');

file_put_contents($invalidPath,substr(file_get_contents($backupPath),0,1800));
checkRestore(evershelfRestoreSqliteBackupSafely($invalidPath,$livePath)==='invalid_backup',
    'truncated backup rejected');
checkRestore(marker($pdo)==='committed-in-live-WAL',
    'truncated candidate cannot touch live marker');

$wrongSchema=$dir.'/wrong-schema.db';
$fake=new SQLite3($wrongSchema);
$fake->exec('CREATE TABLE random_unrelated (value TEXT)');
$fake->close();
checkRestore(evershelfRestoreSqliteBackupSafely($wrongSchema,$livePath)==='invalid_backup',
    'structurally valid SQLite file with wrong application schema rejected');

$lookalikePath=$dir.'/lookalike.db';
$lookalike=new SQLite3($lookalikePath);
$lookalike->exec('CREATE TABLE products (id INTEGER)');
$lookalike->exec('CREATE TABLE inventory (id INTEGER)');
$lookalike->exec('CREATE TABLE app_settings (key TEXT)');
$lookalike->close();
checkRestore(evershelfRestoreSqliteBackupSafely($lookalikePath,$livePath)==='invalid_backup',
    'lookalike tables without required columns cannot replace household');

$link=$dir.'/symlink.db';
symlink($backupPath,$link);
checkRestore(evershelfRestoreSqliteBackupSafely($link,$livePath)==='invalid_backup',
    'symlink backup rejected');

$lock=fopen($dir.'/backup_restore.lock','c');
flock($lock,LOCK_EX);
checkRestore(evershelfRestoreSqliteBackupSafely($backupPath,$livePath)==='busy',
    'concurrent restore lock returns busy without modifying DB');
flock($lock,LOCK_UN);
fclose($lock);
checkRestore(marker($pdo)==='committed-in-live-WAL','restore lock contention preserves data');

// Induce a post-restore verification failure from this unit test. The HTTP
// route cannot provide this callback, so production does not have a test bypass.
$result=evershelfRestoreSqliteBackupSafely($backupPath,$livePath,
    static fn(SQLite3 $db): bool => false);
checkRestore($result==='failed','forced post-restore failure is reported, not acknowledged');
checkRestore(marker($pdo)==='committed-in-live-WAL',
    'automatic rollback restored WAL-committed pre-restore marker');
checkRestore(safetyFiles($dir)===[],'confirmed rollback cleans private safety snapshot');

// Exercise the rare case where a new writer blocks automatic rollback after
// a successful restore. The previous data must survive in a private snapshot.
$externalWriter=null;
$blockedRollback=evershelfRestoreSqliteBackupSafely($backupPath,$livePath,
    static function (SQLite3 $probe) use (&$externalWriter, $livePath): bool {
        $externalWriter=new SQLite3($livePath, SQLITE3_OPEN_READWRITE);
        $externalWriter->busyTimeout(0);
        $externalWriter->exec('BEGIN IMMEDIATE');
        return false;
    });
checkRestore($blockedRollback==='rollback_failed',
    'blocked emergency rollback never reports a successful restore');
$recovery=safetyFiles($dir);
checkRestore(count($recovery)===1, 'private pre-restore safety copy retained for manual recovery');
$externalWriter->exec('ROLLBACK');
$externalWriter->close();
$externalWriter=null;
checkRestore(evershelfRestoreSqliteBackupSafely($recovery[0],$livePath)==='ok',
    'retained safety copy can restore the original household after lock clears');
checkRestore(marker($pdo)==='committed-in-live-WAL',
    'recovery after blocked automatic rollback restores original records');
foreach (safetyFiles($dir) as $temporaryRecoveryFile) {
    @unlink($temporaryRecoveryFile);
}
checkRestore(safetyFiles($dir)===[], 'manual recovery fixture leaves no private snapshots');

// External writer holding IMMEDIATE lock must block online restore cleanly.
$writer=new SQLite3($livePath, SQLITE3_OPEN_READWRITE);
$writer->exec('PRAGMA busy_timeout=1000');
$writer->exec('BEGIN IMMEDIATE');
$result=evershelfRestoreSqliteBackupSafely($backupPath,$livePath);
checkRestore(in_array($result,['busy','failed'],true),
    'active SQLite writer prevents unsafe restore and receives an error');
$writer->exec('ROLLBACK');
$writer->close();
checkRestore(marker($pdo)==='committed-in-live-WAL','concurrent writer conflict preserved live data');

checkRestore(evershelfRestoreSqliteBackupSafely($backupPath,$livePath)==='ok',
    'valid backup restored through online SQLite backup API');
checkRestore(marker($pdo)==='backed-up-before-restore',
    'same already-open PDO sees restored rows after online restore');
$after=new SQLite3($livePath,SQLITE3_OPEN_READONLY);
checkRestore($after->querySingle('PRAGMA quick_check')==='ok',
    'new SQLite connection verifies final database integrity');
checkRestore($after->querySingle("SELECT value FROM app_settings WHERE key='restore_test_marker'")==='backed-up-before-restore',
    'new connection sees the restored snapshot');
$after->close();

$pdo->exec("UPDATE app_settings SET value='post-restore-change' WHERE key='restore_test_marker'");
checkRestore(evershelfRestoreSqliteBackupSafely($backupPath,$livePath)==='ok',
    'second identical restore works after a separate committed write');
checkRestore(marker($pdo)==='backed-up-before-restore' && safetyFiles($dir)===[],
    'repeat restore has stable data and no leftover safety files');
$pdo=null;
echo 'PASS isolated SQLite restore, rollback, lock and WAL matrix' . PHP_EOL;
