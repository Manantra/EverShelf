<?php
/**
 * EverShelf — safe one-time API-token initialization (fork v1.16.24).
 *
 * Initialises *fresh* HTTP installations only. Existing installations with a
 * database remain unchanged until an operator explicitly opts in, so upgrading
 * this fork cannot silently lock out already-configured phones / HA / kiosks.
 *
 * The lock protects competing PHP workers; the marker prevents a removed token
 * from being silently replaced with a different one after it was paired.
 */

/** Persisted marker has no credentials. */
function evershelfApiTokenInitMarker(): string {
    return EVERSHELF_ROOT . '/data/api_token_autogen.json';
}

function evershelfApiTokenInitFail(string $reason): void {
    if (class_exists('EverLog', false)) {
        EverLog::error('API token initialisation blocked HTTP request', [
            'event' => 'api_token_autogen_failed', 'reason' => $reason,
        ]);
    }
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode([
        'success' => false,
        'error' => 'api_token_initialization_failed',
        'hint' => 'Restore API_TOKEN or fix writable configuration. To explicitly keep an open LAN, set ALLOW_OPEN_LAN=true.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/** Only an explicit opt-out can keep a new LAN installation unauthenticated. */
function evershelfApiTokenInitOpenLan(): bool {
    return in_array(strtolower(trim(env('ALLOW_OPEN_LAN', ''))),
        ['true', '1', 'yes', 'on'], true);
}

/**
 * Protect the first HTTP request, not merely the second request after it.
 * Every decision after acquiring the lock is re-evaluated against persisted
 * settings to avoid two workers issuing different tokens and pairing codes.
 */
function evershelfEnsureFirstRunApiToken(): void {
    if (PHP_SAPI === 'cli' || defined('CRON_MODE') || env('DEMO_MODE') === 'true') {
        return;
    }
    if (evershelfApiTokenInitOpenLan() || evershelfEffectiveApiToken() !== '') {
        return;
    }

    $dir = EVERSHELF_ROOT . '/data';
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        evershelfApiTokenInitFail('data_directory_unwritable');
    }
    $lock = @fopen($dir . '/api_token_autogen.lock', 'c');
    if ($lock === false) {
        evershelfApiTokenInitFail('lock_unwritable');
    }
    $acquired = false;
    for ($i = 0; $i < 150; $i++) {
        if (@flock($lock, LOCK_EX | LOCK_NB)) {
            $acquired = true;
            break;
        }
        usleep(50000);
    }
    if (!$acquired) {
        fclose($lock);
        evershelfApiTokenInitFail('lock_timeout');
    }

    try {
        // Another worker may have completed the first boot while we waited.
        loadEnv(true);
        loadEnvOverrides(true);
        if (evershelfApiTokenInitOpenLan() || evershelfEffectiveApiToken() !== '') {
            return;
        }

        $marker = evershelfApiTokenInitMarker();
        if (is_file($marker)) {
            // If an operator removed the generated token, a different token
            // would strand paired devices. Never auto-rotate in that situation.
            evershelfApiTokenInitFail('generated_token_missing');
        }

        // Upgrade safety: an existing household DB indicates a working install
        // that might have HA, kiosk or an unpaired browser relying on open LAN.
        // Require explicit opt-in to change its authentication policy.
        $upgradeOptIn = in_array(strtolower(trim(env('API_TOKEN_AUTOGENERATE', ''))),
            ['true', '1', 'yes', 'on'], true);
        $pending = $dir . '/api_token_autogen.pending';
        // Also retry a failed first boot: bootstrap's earlier config readers
        // may have created a DB before a token could be persisted.
        if (EVERSHELF_DB_PREEXISTING_AT_BOOT && !is_file($pending) && !$upgradeOptIn) {
            $notice = $dir . '/api_token_legacy_open.notice';
            $handle = @fopen($notice, 'x');
            if ($handle !== false) {
                @fwrite($handle, 'Existing open-LAN installation: opt in to token generation with API_TOKEN_AUTOGENERATE=true or set a token manually.' . "\n");
                @fclose($handle);
                @chmod($notice, 0600);
                EverLog::warn('Existing LAN installation has no API token; left unchanged to prevent device lockout', [
                    'event' => 'api_token_legacy_open',
                    'hint' => 'Explicitly set API_TOKEN or API_TOKEN_AUTOGENERATE=true; review phone, kiosk and HA pairing',
                ]);
            }
            return;
        }

        if (!is_file($pending) && @file_put_contents($pending, 'initializing' . "\n", LOCK_EX) === false) {
            evershelfApiTokenInitFail('pending_marker_unwritable');
        }
        @chmod($pending, 0600);
        $token = bin2hex(random_bytes(32));
        $envFile = evershelfEnvFile();
        // An old empty override can shadow a newly written .env value.
        clearEnvOverrides(['API_TOKEN']);
        $storedVia = 'env';
        $stored = false;
        if ((is_file($envFile) && is_writable($envFile)) ||
            (!is_file($envFile) && is_writable(dirname($envFile)))) {
            $stored = @evershelfWriteEnvFile($envFile, ['API_TOKEN' => $token],
                EVERSHELF_ROOT . '/.env.example');
        }
        if ($stored) {
            loadEnv(true);
        } else {
            // Read-only external .env / Unraid mounted secret: reuse the app's
            // standard persisted DB override, with its .env baseline.
            $storedVia = 'env_overrides';
            $stored = saveEnvOverrides(['API_TOKEN' => $token]);
            loadEnvOverrides(true);
        }
        if (!$stored || !hash_equals($token, evershelfEffectiveApiToken())) {
            evershelfApiTokenInitFail('persist_unverified');
        }
        // No secret is stored in this marker.
        $marked = @file_put_contents($marker, json_encode([
            'created_at' => gmdate('c'), 'storage' => $storedVia,
        ], JSON_UNESCAPED_SLASHES), LOCK_EX);
        if ($marked === false) {
            evershelfApiTokenInitFail('marker_write_failed');
        }
        @chmod($marker, 0600);

        EverLog::warn('API token generated for first HTTP start; pair new devices with a one-time code', [
            'event' => 'api_token_autogenerated', 'storage' => $storedVia,
        ]);
        // Once on disk, one-time code survives the first request and is also
        // available in the authenticated Settings → Security view.
        evershelfPairingEnsure();
        if (!evershelfPairingFresh(evershelfPairingRead())) {
            evershelfApiTokenInitFail('pairing_code_write_failed');
        }
        @unlink($pending);
    } finally {
        @flock($lock, LOCK_UN);
        fclose($lock);
    }
}
