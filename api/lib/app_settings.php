<?php
/**
 * Client-visible app_settings API. Internal server settings share this table,
 * but must never be exposed or rewritten by the generic client endpoints.
 */
declare(strict_types=1);

/** Keys actually used by the browser's cross-device settings sync. */
function evershelfClientSettingsKeys(): array {
    return [
        'user_prefs',
        'review_confirmed',
        'meal_plan',
        'tts_voice',
        'scan_history',
        'shopping_tags',
        'pinned_bring',
        'pref_use_loc',
        'pref_move_loc',
        'auto_added_bring',
        'bring_blocklist',
        'no_expiry_dismissed',
        'family_sibling_confirmed',
        'shopping_plan_days',
        'setup_seen',
        'setup_done',
    ];
}

function appSettingsReadClientData(PDO $db): array {
    $keys = evershelfClientSettingsKeys();
    $placeholders = implode(',', array_fill(0, count($keys), '?'));
    $stmt = $db->prepare("SELECT key, value FROM app_settings WHERE key IN ($placeholders)");
    $stmt->execute($keys);
    $settings = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $settings[$row['key']] = json_decode($row['value'], true) ?? $row['value'];
    }
    return ['success' => true, 'settings' => $settings];
}

function appSettingsClientError(string $reason): array {
    return [
        'success' => false,
        'error' => $reason,
        'error_key' => 'error.' . $reason,
    ];
}

/**
 * Validate the *entire* request before writing anything.
 * A single unsupported key rejects the whole batch; an SQL failure rolls back.
 */
function appSettingsWriteClientData(PDO $db, string $rawBody): array {
    if (strlen($rawBody) > 1048576) {
        return appSettingsClientError('settings_too_large');
    }

    try {
        $request = json_decode($rawBody, false, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        return appSettingsClientError('invalid_settings');
    }
    if (!is_object($request) || !isset($request->settings) || !is_object($request->settings)) {
        return appSettingsClientError('invalid_settings');
    }

    $entries = get_object_vars($request->settings);
    $allowed = array_fill_keys(evershelfClientSettingsKeys(), true);
    foreach ($entries as $key => $value) {
        if (!isset($allowed[$key])) {
            return appSettingsClientError('settings_key_not_allowed');
        }
    }

    $stmt = $db->prepare("INSERT INTO app_settings (key, value, updated_at) VALUES (?, ?, datetime('now'))
                          ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at");
    $db->beginTransaction();
    try {
        foreach ($entries as $key => $value) {
            $stmt->execute([$key, json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
    return ['success' => true];
}
