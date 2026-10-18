#!/usr/bin/env php
<?php
/**
 * Regression tests: the container's scheduler and the backup it promises.
 *
 * Why it exists: `BACKUP_ENABLED` defaulted to true, the backup panel said
 * "daily automatic snapshot", and the UI never warned — but the image had no
 * cron daemon and no pseudo-cron, so on Docker/Unraid the daily backup never
 * ran. A template change wiped an appdata folder and there was no backup to go
 * back to (issue #290). This test locks the chain that makes the promise true:
 *
 *   Dockerfile installs cron + /etc/cron.d/evershelf
 *   → the job runs cron_smart_shopping.php every 5 minutes as www-data
 *   → that script rotates its log and the daily backup uses VACUUM INTO
 *   → get_settings exposes backup_last_ts
 *   → the panel warns when the last backup is missing/old and the checklist row
 *     stays open, in all six locales
 *   → the wiki says where the backups are and that they live inside the volume.
 *
 * Run: php scripts/test-docker-cron.php
 */
$root = dirname(__DIR__);
$fail = 0;

function assert_true(bool $cond, string $msg): void
{
    global $fail;
    if (!$cond) {
        echo "FAIL: {$msg}\n";
        $fail++;
    } else {
        echo "OK: {$msg}\n";
    }
}

$dockerfile = (string)file_get_contents($root . '/Dockerfile');
$cronFile   = $root . '/docker/evershelf-cron';
$cron       = is_file($cronFile) ? (string)file_get_contents($cronFile) : '';
$api        = (string)file_get_contents($root . '/api/index.php');
$cronScript = (string)file_get_contents($root . '/api/cron_smart_shopping.php');
$appJs      = (string)file_get_contents($root . '/assets/js/app.js');
$html       = (string)file_get_contents($root . '/index.html');
$install    = (string)file_get_contents($root . '/docs/wiki/Installation.md');
$unraid     = (string)file_get_contents($root . '/docs/wiki/Unraid.md');
$faq        = (string)file_get_contents($root . '/docs/wiki/FAQ.md');

// ── The image installs and starts the daemon ────────────────────────────────
preg_match('/RUN apt-get update.*?&& apt-get install -y(.*?)&&/s', $dockerfile, $m);
$apt = $m[1] ?? '';
assert_true(preg_match('/\bcron\b/', $apt) === 1, 'the image installs the cron package');
assert_true(
    str_contains($dockerfile, 'COPY docker/evershelf-cron /etc/cron.d/evershelf'),
    'the job file is installed in /etc/cron.d (where the daemon reads it)'
);
assert_true(
    str_contains($dockerfile, 'chmod 0644 /etc/cron.d/evershelf'),
    'the job file is root-readable only (cron refuses group/other-writable files)'
);
assert_true(
    str_contains($dockerfile, 'cron -f -L 15') && str_contains($dockerfile, 'exec apache2-foreground'),
    'the container starts cron and keeps Apache as its main process'
);

// ── The job: every 5 minutes, as www-data, into the persistent volume ───────
assert_true($cron !== '', 'docker/evershelf-cron exists');
assert_true(str_ends_with($cron, "\n"), 'the cron file ends with a newline (cron ignores the last line without it)');
foreach (['SHELL=', 'PATH=', 'MAILTO='] as $directive) {
    assert_true(str_contains($cron, $directive), "the cron file declares {$directive}");
}
assert_true(
    preg_match('/^\*\/5 \* \* \* \* www-data php \/var\/www\/html\/api\/cron_smart_shopping\.php >> \/var\/www\/html\/data\/cron\.log 2>&1$/m', $cron) === 1,
    'the job runs cron_smart_shopping.php every 5 minutes as www-data'
);
assert_true(
    !preg_match('/^\*\/5 .*\broot\b/m', $cron),
    'the job does not run as root'
);
assert_true(
    str_contains($cron, '/var/www/html/data/cron.log'),
    'the output goes to data/cron.log (the persistent volume, not the container layer)'
);

// ── The script the job runs: log rotation + the daily backup gate ───────────
assert_true(str_contains($cronScript, 'PHP_SAPI !== \'cli\''), 'the cron script refuses HTTP execution');
assert_true(str_contains($cronScript, 'evershelfRotateCronLog();'), 'the cron script rotates its own log');
assert_true(
    str_contains($cronScript, "env('BACKUP_ENABLED', 'true') === 'true'"),
    'the daily backup is on by default in the cron'
);
assert_true(
    str_contains($cronScript, '82800'),
    'the backup is rate-limited to once every 23 hours (not once per 5-minute run)'
);

// ── Publishing the image follows the release, automatically ────────────────
// A workflow push cannot trigger another workflow, so the tag alone never built
// the image; v1.16.1/v1.16.2 needed a manual dispatch. The release job now calls
// publish-docker as a reusable workflow (no PAT, no dispatch).
$ci = (string)file_get_contents($root . '/.github/workflows/ci.yml');
$publisher = (string)file_get_contents($root . '/.github/workflows/publish-docker.yml');
assert_true(
    str_contains($ci, 'uses: ./.github/workflows/publish-docker.yml'),
    'the release job calls publish-docker as a reusable workflow'
);
assert_true(
    str_contains($ci, 'version: ${{ needs.create-release.outputs.version }}')
    && str_contains($ci, 'latest: true'),
    'the call passes the version and the latest tag'
);
assert_true(str_contains($ci, 'created: ${{ steps.tag_check.outputs.exists') ,'create-release reports whether it created a release');
assert_true(str_contains($publisher, 'workflow_call:'), 'publish-docker can be called by another workflow');
assert_true(str_contains($publisher, 'inputs.version'), 'the reusable workflow accepts the version to tag');
assert_true(!str_contains($ci, 'gh workflow run publish-docker'), 'publishing no longer depends on a PAT-authenticated dispatch');

// ── The backup itself is a consistent snapshot, not a plain copy ────────────
assert_true(str_contains($api, "'backup_last_ts' =>"), 'get_settings exposes backup_last_ts (the checklist/panel need it)');
assert_true(str_contains($api, "env('BACKUP_ENABLED', 'true') === 'true'"), 'backup_enabled defaults to true in get_settings');
assert_true(str_contains($api, 'VACUUM INTO'), 'the in-app backup uses VACUUM INTO (consistent while the app writes)');
assert_true(
    str_contains($api, "\$tmpPath  = \$destPath . '.part';") && str_contains($api, '@rename($tmpPath, $destPath)'),
    'the snapshot is written to a .part file and renamed (no half backups)'
);

// ── The UI tells the truth and warns ────────────────────────────────────────
assert_true(
    str_contains($html, 'id="setting-backup-enabled"') && str_contains($html, 'data-i18n="settings.backup.local_hint"'),
    'the backup panel exposes the automatic-backup toggle and the honest hint'
);
assert_true(str_contains($appJs, 'const _BACKUP_STALE_DAYS = 8;'), 'the stale threshold is one constant');
assert_true(str_contains($appJs, "id: 'backup', tab: 'tab-backup'"), 'the checklist has a backup row');
assert_true(
    str_contains($appJs, "t('settings.backup.stale_warning')") && str_contains($appJs, "t('settings.backup.never_warning')"),
    'the panel uses the stale/never warning strings through t()'
);

// ── Six locales, and the hint names the real folder ─────────────────────────
foreach (['it', 'en', 'de', 'fr', 'es', 'zh'] as $loc) {
    $json = json_decode((string)file_get_contents($root . "/translations/{$loc}.json"), true);
    $backup = $json['settings']['backup'] ?? [];
    assert_true(isset($backup['checklist_hint']) && trim((string)$backup['checklist_hint']) !== '', "{$loc}: settings.backup.checklist_hint exists");
    assert_true(
        isset($backup['stale_warning']) && str_contains((string)$backup['stale_warning'], '{days}'),
        "{$loc}: settings.backup.stale_warning keeps the {days} slot"
    );
    assert_true(isset($backup['never_warning']) && trim((string)$backup['never_warning']) !== '', "{$loc}: settings.backup.never_warning exists");
    assert_true(
        isset($backup['local_hint']) && str_contains((string)$backup['local_hint'], 'data/backups'),
        "{$loc}: the hint names data/backups (where the files really are)"
    );
}

// ── Docs: what changed and where the backups are ────────────────────────────
assert_true(str_contains($install, '/etc/cron.d/evershelf'), 'Installation documents the in-container cron');
assert_true(str_contains($install, 'The backups live inside the data volume'), 'Installation warns that backups are inside the volume');
assert_true(str_contains($unraid, 'data/backups'), 'Unraid documents where the automatic backup lands');
assert_true(str_contains($faq, 'Are backups automatic?'), 'the FAQ answers "are backups automatic?"');

if ($fail > 0) {
    echo "\n{$fail} test(s) failed\n";
    exit(1);
}
echo "\nAll docker-cron / backup tests passed\n";
