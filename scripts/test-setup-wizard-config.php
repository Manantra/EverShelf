#!/usr/bin/env php
<?php
/**
 * Guard test — the first-run wizard must obey the instance configuration.
 *
 * Regression history (issue #301): a server fully configured through a read-only
 * .env still opened the wizard on every new device, starting with the Google Drive
 * step even though GDRIVE_ENABLED=false. Two causes:
 *
 *  - step 3 was pushed whenever no Drive credentials existed, without ever looking
 *    at `gdrive_enabled`, so an explicit "no" looked exactly like "not decided";
 *  - `evershelf_setup_done` and the assistant's `evershelf_setup_seen` ledger lived
 *    in localStorage only, so every new browser profile (and the Kiosk WebView)
 *    answered the same questions again.
 *
 * The functions under test are the shipped ones: they are extracted from app.js and
 * run in node against stubs, so the test cannot drift from the app.
 *
 * Run: php scripts/test-setup-wizard-config.php
 */

$root = dirname(__DIR__);
$fail = 0;
$checks = 0;

function check(string $label, bool $ok): void
{
    global $fail, $checks;
    $checks++;
    if ($ok) {
        echo "ok    $label\n";
    } else {
        $fail++;
        echo "FAIL  $label\n";
    }
}

/** Pull a top-level function out of app.js by matching braces. */
function extract_fn(string $src, string $name): string
{
    $needle = 'function ' . $name . '(';
    $start = strpos($src, $needle);
    if ($start === false) {
        return '';
    }
    $open = strpos($src, '{', $start);
    if ($open === false) {
        return '';
    }
    $depth = 0;
    for ($i = $open, $len = strlen($src); $i < $len; $i++) {
        if ($src[$i] === '{') {
            $depth++;
        } elseif ($src[$i] === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($src, $start, $i - $start + 1);
            }
        }
    }
    return '';
}

$app = (string)file_get_contents($root . '/assets/js/app.js');
$api = (string)file_get_contents($root . '/api/index.php');

// ------------------------------------------------------------------ static
check('get_settings exposes the explicit GDRIVE_ENABLED decision',
    strpos($api, "'gdrive_enabled_set'") !== false);
check('get_settings exposes the operator language (APP_LANG)',
    strpos($api, "'app_lang'") !== false);
check('get_settings exposes the instance setup ledger',
    strpos($api, "'setup_done'") !== false && strpos($api, "'setup_seen'") !== false);
check('the ledger reader exists and is used by get_settings',
    strpos($api, 'function evershelfSetupLedgerValue') !== false
    && strpos($api, "evershelfSetupLedgerValue('setup_done')") !== false
    && strpos($api, "evershelfSetupLedgerValue('setup_seen')") !== false);

check('the wizard reads the server-side setup flag',
    strpos($app, "localStorage.getItem('evershelf_setup_done') || (srv.setup_done ? '1' : '')") !== false);
check('the language step honours APP_LANG',
    strpos($app, '!localStorage.getItem(\'evershelf_lang\') && !srv.app_lang && !setupDone') !== false);
check('the Drive step asks a helper before appearing',
    strpos($app, 'if (_gdriveWizardNeeded(srv)) missing.push(3);') !== false);
check('finishing the setup writes the instance flag',
    strpos($app, "api('app_settings_save', {}, 'POST', { settings: { setup_done: true } })") !== false);
check('the assistant ledger is written back to the instance',
    strpos($app, "api('app_settings_save', {}, 'POST', { settings: { setup_seen: seen } })") !== false);

// --------------------------------------------------------------- functional
$sources = [
    '_getMissingSetupSteps'  => extract_fn($app, '_getMissingSetupSteps'),
    '_gdriveWizardNeeded'    => extract_fn($app, '_gdriveWizardNeeded'),
    '_assistantPendingSteps' => extract_fn($app, '_assistantPendingSteps'),
    '_setupSeen'             => extract_fn($app, '_setupSeen'),
];
foreach ($sources as $name => $src) {
    check("$name() is extractable", $src !== '');
}

$harness = implode("\n\n", $sources) . "\n" . <<<'JS'
const SETTINGS_CHECKLIST = [
    { id: 'notify',   ask: true,  askVersion: 2, step: 4, tab: 'tab-notify' },
    { id: 'telegram', ask: true,  askVersion: 1, step: 7, tab: 'tab-notify' },
    { id: 'ai',       ask: false, askVersion: 1, tab: 'tab-api' },
];
const STATES = { notify: 'todo', telegram: 'todo', ai: 'todo' };
function _checklistState(item) { return STATES[item.id] || 'todo'; }
function getSettings() {
    return { gemini_key: '', bring_email: '', bring_password: '', shopping_mode: 'internal' };
}
let LS = {};
const localStorage = {
    getItem: (k) => (Object.prototype.hasOwnProperty.call(LS, k) ? LS[k] : null),
    setItem: (k, v) => { LS[k] = String(v); },
    removeItem: (k) => { delete LS[k]; },
};
let _serverSettings = {};

const out = {};
function run(name, ls, srv) {
    LS = Object.assign({}, ls || {});
    _serverSettings = srv || {};
    out[name] = _getMissingSetupSteps(srv || {}).slice().sort((a, b) => a - b).join(',');
}

// A brand-new install on a server with nothing configured: everything is asked.
run('fresh', {}, {});
// The report in #301: a fully .env-configured instance, answered ledger, Drive off.
run('env_configured', {}, {
    gemini_key_set: true, shopping_mode: 'bring', bring_email: 'a@b.c', bring_password_set: true,
    gdrive_enabled: false, gdrive_enabled_set: true, app_lang: 'fr',
    setup_seen: { notify: 2, telegram: 1 },
});
// Same instance before the ledger has travelled: no Drive step, no language step.
run('env_configured_no_ledger', {}, {
    gemini_key_set: true, gdrive_enabled: false, gdrive_enabled_set: true, app_lang: 'it',
});
// GDRIVE_ENABLED absent (default false) is "not decided": a fresh install keeps the step.
run('drive_undecided', {}, {
    gemini_key_set: true, gdrive_enabled: false, gdrive_enabled_set: false, app_lang: 'it',
});
// Someone finished the setup on another device: only genuinely new questions remain.
run('server_done', {}, { setup_done: true, setup_seen: { notify: 2, telegram: 1 } });
// An askVersion bump still wins over the stored ledger entry.
run('askversion_bump', {}, { setup_done: true, setup_seen: { notify: 1, telegram: 1 } });
// A device that already finished: same result as the instance flag.
run('local_done', { evershelf_setup_done: '1' }, {});
// APP_LANG alone is enough to stop asking for a language.
run('app_lang_only', {}, { app_lang: 'de' });

out.gdrive = [
    _gdriveWizardNeeded({}),
    _gdriveWizardNeeded({ gdrive_enabled: false, gdrive_enabled_set: true }),
    _gdriveWizardNeeded({ gdrive_enabled: true, gdrive_enabled_set: true }),
    _gdriveWizardNeeded({ gdrive_refresh_token_set: true }),
    _gdriveWizardNeeded({ gdrive_client_id_set: true }),
    _gdriveWizardNeeded({ gdrive_folder_id: 'abc' }),
].join(',');

// The merge keeps the highest version, so a stale instance entry cannot hide news.
LS = { evershelf_setup_seen: JSON.stringify({ notify: 2 }) };
_serverSettings = { setup_seen: { notify: 1, telegram: 1 } };
out.merged = JSON.stringify(_setupSeen());

console.log(JSON.stringify(out));
JS;


$tmp = sys_get_temp_dir() . '/setup_wizard_' . getmypid() . '.js';
file_put_contents($tmp, $harness);
$raw = shell_exec('node ' . escapeshellarg($tmp) . ' 2>/dev/null');
@unlink($tmp);
$out = json_decode(trim((string)$raw), true);

check('the harness runs in node', is_array($out));
if (getenv('WIZARD_DEBUG')) { fwrite(STDERR, "HARNESS: " . (string)$raw . "\n"); }
if (is_array($out)) {
    check('a fresh install is asked for language, AI key, Drive and the assistant steps',
        ($out['fresh'] ?? '') === '0,1,3,4,7');
    check('a .env-configured instance with the shared ledger shows no wizard at all',
        ($out['env_configured'] ?? '') === '');
    check('the Drive step disappears once GDRIVE_ENABLED=false, the language step once APP_LANG is set',
        ($out['env_configured_no_ledger'] ?? '') === '4,7');
    check('an undecided Drive (default false) is still offered on a first run',
        ($out['drive_undecided'] ?? '') === '3,4,7');
    check('setup_done stored on the server silences the first-run steps on a new device',
        ($out['server_done'] ?? '') === '');
    check('a bumped askVersion is asked again despite the stored ledger',
        ($out['askversion_bump'] ?? '') === '4');
    check('a device that already finished does not reopen the first-run steps',
        ($out['local_done'] ?? '') === '4,7');
    check('APP_LANG alone removes the language question',
        ($out['app_lang_only'] ?? '') === '1,3,4,7');
    check('the Drive helper answers "no" only for credentials or an explicit disable',
        ($out['gdrive'] ?? '') === 'true,false,true,false,false,false');
    check('the merged ledger keeps the highest version per item',
        json_decode((string)($out['merged'] ?? '{}'), true) === ['notify' => 2, 'telegram' => 1]);
}

echo "\n{$checks} checks, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);

