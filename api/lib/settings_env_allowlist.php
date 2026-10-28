<?php
/**
 * The `.env` keys that have no control on the settings page — on purpose.
 *
 * The settings registry maps a control to its `.env` key when they share the
 * slug; everything else must be listed here with the reason, so **no key is
 * forgotten** and the search bar can still find it (it shows the key with a
 * “server setting” note instead of a jump).
 *
 * Buckets:
 *   security  — credentials and hardening: never editable from the browser
 *   support   — donation / disclosed costs, written once at setup
 *   storage   — Google Drive service account, backup switch
 *   logging   — diagnostics, only meaningful from the shell
 *   advanced  — tuning knobs next to a control that edits something else
 *
 * Adding a key to `.env.example` without a control and without an entry here
 * fails the guard test with its name.
 */
function evershelfSettingsEnvAllowlist(): array
{
    return [
        // security
        'API_TOKEN'                 => 'security',
        'CORS_ORIGIN'               => 'security',
        'TRUSTED_PROXIES'           => 'security',
        'API_BOOTSTRAP_OPEN'        => 'security',
        'GH_ISSUE_TOKEN'            => 'security',
        'GH_ISSUE_TOKEN_ENC'        => 'security',
        'GH_ISSUE_TOKEN_KEY'        => 'security',
        'ICS_TOKEN'                 => 'security',
        'REPORT_ENABLED'            => 'security',
        // support (parked banner slots live here too)
        'SUPPORT_MODE'              => 'support',
        'SUPPORT_DECIDED'           => 'support',
        'SUPPORT_PAYPAL_URL'        => 'support',
        'SUPPORT_PAYPAL_CURRENCY'   => 'support',
        'SUPPORT_PAYPAL_AMOUNTS'    => 'support',
        'SUPPORT_COST_CURRENCY'     => 'support',
        'SUPPORT_COST_HOSTING'      => 'support',
        'SUPPORT_COST_DOMAIN'       => 'support',
        'SUPPORT_COST_DEVACCOUNT'   => 'support',
        'SUPPORT_COST_API'          => 'support',
        // storage
        'GDRIVE_SERVICE_ACCOUNT_JSON' => 'storage',
        'GDRIVE_SERVICE_ACCOUNT_FILE' => 'storage',
        'BACKUP_ENABLED'            => 'storage',
        // logging / diagnostics
        'LOG_LEVEL'                 => 'logging',
        'LOG_ROTATE_HOURS'          => 'logging',
        'LOG_MAX_FILES'             => 'logging',
        'CRON_LOG_MAX_BYTES'        => 'logging',
        'DISPLAY_ERRORS'            => 'logging',
        'DEMO_MODE'                 => 'logging',
        // advanced tuning
        'AI_PROVIDER'               => 'advanced',
        'SHOPPING_MODE'             => 'advanced',
        'NOTIFY_CHANNELS'           => 'advanced',
        'NOTIFY_EVENTS'             => 'advanced',
        'HA_WEBHOOK_EVENTS'         => 'advanced',
        'APPLIANCES'                => 'advanced',
        'CUSTOM_LOCATIONS'          => 'advanced',
        'INSTANCE_NAME'             => 'advanced',
        'TTS_ALLOWED_HOSTS'         => 'advanced',
        'TTS_INSECURE_SSL'          => 'advanced',
        'SCALE_GATEWAY_URL'         => 'advanced',
        'BARCODE_AI_FALLBACK'       => 'advanced',
        'BARCODE_OFFLINE_ENABLED'   => 'advanced',
        'BARCODE_OFFLINE_SYNC_DAYS' => 'advanced',
        'BARCODE_LOOKUP_TIMEOUT'    => 'advanced',
        'RECIPE_RETENTION_DAYS'     => 'advanced',
        'TRANSACTION_RETENTION_DAYS' => 'advanced',
        'MEALIE_CACHE_SYNC_DAYS'    => 'advanced',
        'GEMINI_CLASSIFY_DAILY_MAX' => 'advanced',
        'AUDIT_MODE_BATCH_SIZE'     => 'advanced',
        'RECEIPT_OCR_MAX_ITEMS'     => 'advanced',
        'AUTO_FAVORITE_TOP_N'       => 'advanced',
        'AUTO_FAVORITE_WINDOW_DAYS' => 'advanced',
        'PREF_POCAFAME'             => 'advanced',
        'PREF_SCADENZE'             => 'advanced',
        'PREF_HEALTHY'              => 'advanced',
        'PREF_OPENED'               => 'advanced',
        'PREF_ZEROWASTE'            => 'advanced',
        // A commercial, rate-limited trial endpoint: deliberately not a settings control
        // (the free, credited sources answer first). It stays an .env opt-in.
        'UPC_LOOKUP_ENABLED'        => 'advanced',
    ];
}
