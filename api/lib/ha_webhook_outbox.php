<?php
/** Private best-effort HA webhook outbox; never uses the pantry SQLite database. */
declare(strict_types=1);

const EVERSHELF_HA_OUTBOX_MAX = 500;
const EVERSHELF_HA_OUTBOX_TTL = 600;
const EVERSHELF_HA_OUTBOX_MAX_PAYLOAD = 262144;

function evershelfHaAsyncEnabled(): bool {
    return env('HA_WEBHOOK_ASYNC', 'false') === 'true';
}

// Bare-metal example runs once per minute; allow normal startup and a slow drain.
const EVERSHELF_HA_WORKER_STALE = 150;

function evershelfHaWorkerRequired(): bool {
    return evershelfHaAsyncEnabled() && env('HA_ENABLED', 'false') === 'true'
        && trim(env('HA_URL', '')) !== '' && trim(env('HA_WEBHOOK_ID', '')) !== '';
}

function evershelfHaOutboxDir(): string {
    return dirname(__DIR__, 2) . '/data/ha_webhook_outbox';
}

function evershelfHaOutboxOpen(): PDO {
    $dir = evershelfHaOutboxDir();
    $mask = umask(0077);
    try {
        if (!is_dir($dir) && !@mkdir($dir, 0700) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create private outbox');
        }
        if (is_link($dir) || (fileperms($dir) & 0077) !== 0) {
            throw new RuntimeException('Outbox directory is not private');
        }
        $file = $dir . '/events.db';
        if (is_link($file) || (file_exists($file) && (fileperms($file) & 0077) !== 0)) {
            throw new RuntimeException('Outbox database is not private');
        }
        $db = new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db->exec('PRAGMA busy_timeout=100');
        $db->exec('CREATE TABLE IF NOT EXISTS events (
            id INTEGER PRIMARY KEY AUTOINCREMENT, event TEXT NOT NULL,
            payload TEXT NOT NULL, target_hash TEXT NOT NULL, created_at INTEGER NOT NULL
        )');
        return $db;
    } finally {
        umask($mask);
    }
}

/** Status contains counts and timestamps only. Read-only and no network calls. */
function evershelfHaWorkerStatus(): array {
    $enabled = evershelfHaWorkerRequired();
    $out = ['enabled' => $enabled, 'ok' => !$enabled, 'state' => $enabled ? 'waiting' : 'disabled',
        'last_run' => null, 'pending' => null, 'oldest_age' => null];
    if (!$enabled) return $out;
    try {
        $dir = evershelfHaOutboxDir();
        $file = $dir . '/events.db';
        if (!is_dir($dir)) return $out;
        if (is_link($dir) || is_link($file) || (fileperms($dir) & 0077) !== 0
            || !is_file($file) || (fileperms($file) & 0077) !== 0) {
            $out['state'] = 'unavailable';
            return $out;
        }
        $db = new SQLite3($file, SQLITE3_OPEN_READONLY);
        try {
            $db->busyTimeout(100);
            $row = @$db->querySingle('SELECT count(*) AS n, min(created_at) AS oldest FROM events', true);
            if (!is_array($row) || !isset($row['n'])) throw new RuntimeException('Outbox unreadable');
            $out['pending'] = (int)$row['n'];
            $out['oldest_age'] = $row['oldest'] === null ? null : max(0, time() - (int)$row['oldest']);
        } finally {
            $db->close();
        }
        $heartbeat = $dir . '/worker.heartbeat';
        clearstatcache(true, $heartbeat);
        if (is_file($heartbeat) && !is_link($heartbeat)) {
            $ts = (int)filemtime($heartbeat);
            $out['last_run'] = $ts;
            $out['state'] = $ts <= time() && time() - $ts <= EVERSHELF_HA_WORKER_STALE ? 'running' : 'stale';
        }
        if ($out['oldest_age'] !== null && $out['oldest_age'] > EVERSHELF_HA_WORKER_STALE) {
            $out['state'] = 'backlog';
        }
        $out['ok'] = $out['state'] === 'running';
    } catch (Throwable $e) {
        $out['state'] = 'unavailable';
    }
    return $out;
}

function evershelfHaWorkerHeartbeat(): bool {
    $path = evershelfHaOutboxDir() . '/worker.heartbeat';
    return !is_link($path) && @touch($path);
}

function evershelfHaOutboxTargetHash(string $url, string $caBundle): string {
    return hash('sha256', $url . "\0" . $caBundle);
}

/** Share the existing restore gate; never wait behind a restore in an API write. */
function evershelfHaOutboxRestoreGate() {
    $path = dirname(evershelfHaOutboxDir()) . '/backup_restore.lock';
    if (is_link($path)) return false;
    $lock = @fopen($path, 'c');
    if ($lock === false) return false;
    if (!@flock($lock, LOCK_SH | LOCK_NB)) {
        fclose($lock);
        return false;
    }
    return $lock;
}

/** Called only while the verified restore owns the exclusive restore gate. */
function evershelfHaOutboxInvalidateForRestore(): bool {
    if (!is_dir(evershelfHaOutboxDir())) return true;
    try {
        $db = evershelfHaOutboxOpen();
        $removed = $db->exec('DELETE FROM events');
        EverLog::info('HA outbox: pending events discarded before restore', ['count' => $removed]);
        return true;
    } catch (Throwable $e) {
        EverLog::warn('HA outbox: restore blocked; cannot invalidate pending events');
        return false;
    }
}

/** No network I/O here. Failure must not change the stock/shopping result. */
function evershelfHaOutboxEnqueue(string $event, string $payload, string $url, string $caBundle): void {
    $gate = null;
    try {
        $gate = evershelfHaOutboxRestoreGate();
        if ($gate === false) throw new RuntimeException('Restore gate busy');
        if (strlen($payload) > EVERSHELF_HA_OUTBOX_MAX_PAYLOAD) {
            throw new RuntimeException('Outbox payload too large');
        }
        $db = evershelfHaOutboxOpen();
        $q = $db->prepare('INSERT INTO events (event,payload,target_hash,created_at)
            SELECT ?,?,?,? WHERE (SELECT count(*) FROM events) < ?');
        $q->bindValue(1, $event, PDO::PARAM_STR);
        $q->bindValue(2, $payload, PDO::PARAM_STR);
        $q->bindValue(3, evershelfHaOutboxTargetHash($url, $caBundle), PDO::PARAM_STR);
        $q->bindValue(4, time(), PDO::PARAM_INT);
        // SQLite compares storage classes for count(*) < ?; bind numeric limit as an integer.
        $q->bindValue(5, EVERSHELF_HA_OUTBOX_MAX, PDO::PARAM_INT);
        $q->execute();
        if ($q->rowCount() !== 1) {
            EverLog::warn("HA outbox[$event]: queue full; event dropped");
        }
    } catch (Throwable $e) {
        // Do not log payloads, filesystem paths or credentials.
        EverLog::warn("HA outbox[$event]: enqueue failed; event dropped");
    } finally {
        if (is_resource($gate)) fclose($gate);
    }
}

/** CLI worker only. Delete before send: no automatic retry after a crash or timeout. */
function evershelfHaOutboxDrain(): int {
    if (PHP_SAPI !== 'cli') return 0;
    $dir = evershelfHaOutboxDir();
    if (!is_dir($dir) && !evershelfHaWorkerRequired()) return 0;
    $lock = $gate = null;
    $mask = umask(0077);
    try {
        $gate = evershelfHaOutboxRestoreGate();
        if ($gate === false) return 0;
        $db = evershelfHaOutboxOpen();
        if (is_link($dir . '/worker.lock')) throw new RuntimeException('Invalid lock');
        $lock = fopen($dir . '/worker.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) return 0;
        $deadline = microtime(true) + 45;
        $processed = 0;
        while ($processed < 50 && microtime(true) < $deadline) {
            $row = $db->query('SELECT * FROM events ORDER BY id LIMIT 1')->fetch(PDO::FETCH_ASSOC);
            if (!$row) break;
            // Commit removal before any network call. A killed worker cannot resend this event.
            $delete = $db->prepare('DELETE FROM events WHERE id=?');
            $delete->execute([$row['id']]);
            if ($delete->rowCount() !== 1) continue;
            $processed++;
            loadEnv(true);
            $target = evershelfHaWebhookTarget($row['event']);
            if (!evershelfHaAsyncEnabled() || $target === null
                || (int)$row['created_at'] < time() - EVERSHELF_HA_OUTBOX_TTL
                || !hash_equals($row['target_hash'], evershelfHaOutboxTargetHash($target[0], $target[1]))) {
                EverLog::warn("HA outbox[" . $row['event'] . "]: stale or disabled event dropped");
                continue;
            }
            evershelfHaWebhookSend($row['event'], $row['payload'], $target[0], $target[1]);
        }
        if (!evershelfHaWorkerHeartbeat()) throw new RuntimeException('Heartbeat write failed');
        return $processed;
    } catch (Throwable $e) {
        EverLog::warn('HA outbox: worker failed');
        return -1;
    } finally {
        if (is_resource($lock)) fclose($lock);
        if (is_resource($gate)) fclose($gate);
        umask($mask);
    }
}
