<?php
/** Private best-effort HA webhook outbox; never uses the pantry SQLite database. */
declare(strict_types=1);

const EVERSHELF_HA_OUTBOX_MAX = 500;
const EVERSHELF_HA_OUTBOX_TTL = 600;
const EVERSHELF_HA_OUTBOX_MAX_PAYLOAD = 262144;

function evershelfHaAsyncEnabled(): bool {
    return env('HA_WEBHOOK_ASYNC', 'false') === 'true';
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

function evershelfHaOutboxTargetHash(string $url, string $caBundle): string {
    return hash('sha256', $url . "\0" . $caBundle);
}

/** No network I/O here. Failure must not change the stock/shopping result. */
function evershelfHaOutboxEnqueue(string $event, string $payload, string $url, string $caBundle): void {
    try {
        if (strlen($payload) > EVERSHELF_HA_OUTBOX_MAX_PAYLOAD) {
            throw new RuntimeException('Outbox payload too large');
        }
        $db = evershelfHaOutboxOpen();
        $q = $db->prepare('INSERT INTO events (event,payload,target_hash,created_at)
            SELECT ?,?,?,? WHERE (SELECT count(*) FROM events) < ?');
        $q->execute([$event, $payload, evershelfHaOutboxTargetHash($url, $caBundle),
                     time(), EVERSHELF_HA_OUTBOX_MAX]);
        if ($q->rowCount() !== 1) {
            EverLog::warn("HA outbox[$event]: queue full; event dropped");
        }
    } catch (Throwable $e) {
        // Do not log payloads, filesystem paths or credentials.
        EverLog::warn("HA outbox[$event]: enqueue failed; event dropped");
    }
}

/** CLI worker only. Delete before send: no automatic retry after a crash or timeout. */
function evershelfHaOutboxDrain(): int {
    if (PHP_SAPI !== 'cli') return 0;
    $dir = evershelfHaOutboxDir();
    if (!is_dir($dir)) return 0;
    $lock = null;
    $mask = umask(0077);
    try {
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
        return $processed;
    } catch (Throwable $e) {
        EverLog::warn('HA outbox: worker failed');
        return -1;
    } finally {
        if (is_resource($lock)) fclose($lock);
        umask($mask);
    }
}
