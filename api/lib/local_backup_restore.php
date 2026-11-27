<?php
/**
 * Restore a vetted EverShelf backup with SQLite's online backup protocol.
 *
 * NEVER overwrite an in-use SQLite file with copy() or delete its WAL/SHM
 * sidecars. sqlite3_backup is transactional, works with the live PDO already
 * opened by the API, and honors SQLite's file locks.
 *
 * Returns: ok | invalid_backup | busy | failed | rollback_failed.
 * A private recovery snapshot is kept if rollback itself fails.
 */

function evershelfRestoreSchemaAndIntegrityOkay(SQLite3 $connection): bool
{
    try {
        if (@$connection->querySingle('PRAGMA quick_check') !== 'ok') {
            return false;
        }
        // Reject valid but unrelated SQLite files, or lookalike databases
        // missing columns needed to read the stock and persisted settings.
        $requiredColumns = [
            'products' => ['id', 'name'],
            'inventory' => ['id', 'product_id', 'quantity'],
            'app_settings' => ['key', 'value'],
        ];
        foreach ($requiredColumns as $table => $columns) {
            $query = @$connection->query("PRAGMA table_info('$table')");
            if (!$query instanceof SQLite3Result) {
                return false;
            }
            $found = [];
            while (($row = $query->fetchArray(SQLITE3_ASSOC)) !== false) {
                $found[] = (string)$row['name'];
            }
            foreach ($columns as $column) {
                if (!in_array($column, $found, true)) {
                    return false;
                }
            }
        }
        return true;
    } catch (Throwable $exception) {
        return false;
    }
}

/**
 * @param null|callable(SQLite3):bool $afterRestoreCheck Internal test seam only.
 * The HTTP route never supplies this callback, and users cannot control it.
 * @param null|callable():bool $beforeRestoreWrite Internal invalidation hook,
 * called under the exclusive restore lock, after validation and safety copy.
 */
function evershelfRestoreSqliteBackupSafely(
    string $backupPath,
    string $livePath,
    ?callable $afterRestoreCheck = null,
    ?callable $beforeRestoreWrite = null
): string {
    clearstatcache(true, $backupPath);
    if (!is_file($backupPath) || is_link($backupPath) || (int)@filesize($backupPath) <= 0) {
        return 'invalid_backup';
    }
    if (!is_file($livePath) || is_link($livePath)) {
        return 'failed';
    }
    $lock = @fopen(dirname($livePath) . '/backup_restore.lock', 'c');
    if ($lock === false) {
        return 'failed';
    }
    if (!@flock($lock, LOCK_EX | LOCK_NB)) {
        fclose($lock);
        return 'busy';
    }

    $source = $live = $safety = null;
    $safetyPath = null;
    $keepRecovery = false;
    try {
        // Read-only access never creates an empty database for a missing or
        // corrupt backup. Check app schema, not just SQLite file structure.
        $source = new SQLite3($backupPath, SQLITE3_OPEN_READONLY);
        $source->busyTimeout(3000);
        if (!evershelfRestoreSchemaAndIntegrityOkay($source)) {
            return 'invalid_backup';
        }

        $live = new SQLite3($livePath, SQLITE3_OPEN_READWRITE);
        $live->busyTimeout(3000);
        if (!evershelfRestoreSchemaAndIntegrityOkay($live)) {
            return 'failed';
        }

        $safetyPath = @tempnam(dirname($livePath), 'restore-safety-');
        if ($safetyPath === false) {
            return 'failed';
        }
        @chmod($safetyPath, 0600);
        $safety = new SQLite3($safetyPath, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
        if (!@$live->backup($safety) || !evershelfRestoreSchemaAndIntegrityOkay($safety)) {
            return 'failed';
        }

        // External pending events must be invalidated while the restore lock is held.
        // Only after source and recovery snapshot are both validated.
        if ($beforeRestoreWrite !== null && $beforeRestoreWrite() !== true) {
            return 'failed';
        }

        // sqlite3_backup atomically restores pages into the existing SQLite
        // database. Other readers/writers use SQLite locking/WAL semantics.
        // Never unlink live -wal or -shm files.
        $changed = false;
        try {
            $changed = @$source->backup($live);
        } catch (Throwable $exception) {
            // A failed SQLite backup is normally rolled back by SQLite.
            // The safety snapshot is still available for a conservative retry.
        }

        $valid = false;
        if ($changed) {
            try {
                // Re-open to verify what a new HTTP request will see.
                $probe = new SQLite3($livePath, SQLITE3_OPEN_READWRITE);
                try {
                    $probe->busyTimeout(3000);
                    $valid = evershelfRestoreSchemaAndIntegrityOkay($probe);
                    if ($valid && $afterRestoreCheck !== null) {
                        $valid = $afterRestoreCheck($probe) === true;
                    }
                } finally {
                    $probe->close();
                }
            } catch (Throwable $exception) {
                $valid = false;
            }
        }
        if ($changed && $valid) {
            return 'ok';
        }
        if (!$changed) {
            // SQLite's backup transaction is atomic: a failed/busy backup
            // cannot leave a partially replaced destination behind.
            // Do not attempt a second write into an already busy database.
            return in_array($live->lastErrorCode(), [5, 6], true) ? 'busy' : 'failed';
        }

        // Post-restore verification failed after a committed backup: restore
        // the private, already validated pre-restore snapshot.
        try {
            $rollback = @$safety->backup($live);
            if ($rollback && evershelfRestoreSchemaAndIntegrityOkay($live)) {
                return 'failed';
            }
        } catch (Throwable $exception) {
            // Preserve the safety copy for an operator-assisted recovery.
        }
        $keepRecovery = true;
        return 'rollback_failed';
    } catch (Throwable $exception) {
        // Before the live restore, no live pages were changed. The only
        // mutation after that point happens in the guarded backup/rollback
        // branch above. Never report success on an unexpected exception.
        return 'failed';
    } finally {
        if ($source instanceof SQLite3) {
            @$source->close();
        }
        if ($safety instanceof SQLite3) {
            @$safety->close();
        }
        if ($live instanceof SQLite3) {
            @$live->close();
        }
        if ($safetyPath !== null && !$keepRecovery) {
            @unlink($safetyPath);
            @unlink($safetyPath . '-wal');
            @unlink($safetyPath . '-shm');
        }
        @flock($lock, LOCK_UN);
        fclose($lock);
    }
}
