<?php
/**
 * Verify a SQLite backup candidate before making it visible as a .db backup.
 *
 * This deliberately validates the new snapshot, not the live database:
 * VACUUM INTO and the checkpoint+copy fallback both produce a .part candidate.
 * The caller must not update its last-backup timestamp or purge old backups
 * unless this function returns 'ok'.
 *
 * @return 'ok'|'integrity_failed'|'publish_failed'
 */
function evershelfValidateAndPublishLocalBackup(string $candidate, string $destination): string
{
    clearstatcache(true, $candidate);
    if (!is_file($candidate) || (int)@filesize($candidate) <= 0) {
        @unlink($candidate);
        return 'integrity_failed';
    }

    $integrity = null;
    try {
        // The candidate must exist *before* opening: PDO SQLite could otherwise
        // create an empty database and wrongly report a successful quick_check.
        $probe = new PDO('sqlite:' . $candidate);
        $probe->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $integrity = $probe->query('PRAGMA quick_check')->fetchColumn();
        $probe = null;
    } catch (Throwable $e) {
        // A corrupt/truncated/non-SQLite file is not a backup, regardless of
        // whether the snapshot itself was created by VACUUM INTO or by copy().
    }

    if ($integrity !== 'ok') {
        @unlink($candidate);
        return 'integrity_failed';
    }

    if (!@rename($candidate, $destination)) {
        @unlink($candidate);
        return 'publish_failed';
    }
    return 'ok';
}
