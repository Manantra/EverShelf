<?php
/**
 * Guard test — the settings checklist ("Da decidere" / "Novità").
 *
 * The card must empty itself when the user decides:
 *  - an option that was answered at this `askVersion` leaves "Da decidere";
 *  - an option that the save just configured leaves "Da decidere";
 *  - a "Novità" row in the tab that was saved is acknowledged and leaves "Novità";
 *  - a "Novità" row in another tab stays, and saving an unrelated key never
 *    clears the news of an option nobody looked at.
 *
 * The functions are the shipped ones: they are extracted from app.js and run in
 * node against a stub checklist, so the test cannot drift from the app.
 *
 * Run: php scripts/test-settings-checklist.php
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

// ------------------------------------------------------------------ static
check('the todo list knows about answered items',
    strpos($app, 'if (item.ask && (seen[item.id] || 0) >= (item.askVersion || 1)) return false;') !== false);
check('the save captures what was pending',
    strpos($app, 'const checklistPendingBefore = _checklistPendingBeforeSave();') !== false);
$captureAt = strpos($app, 'const checklistPendingBefore = _checklistPendingBeforeSave();');
$postAt = strpos($app, "api('save_settings'", $captureAt ?: 0);
check('the capture runs before the save request', $captureAt !== false && $postAt !== false && $postAt > $captureAt);
$refreshAt = strpos($app, '_serverSettings = refreshed;');
$ackAt = strpos($app, '_ackSavedChecklistItems(');
$renderAt = strpos($app, '_renderSettingsChecklist();', $ackAt ?: 0);
check('the acknowledgement runs after the state refresh', $refreshAt !== false && $ackAt !== false && $ackAt > $refreshAt);
check('and the card is repainted afterwards', $renderAt !== false && $renderAt > $ackAt);
check('the active tab is passed to the acknowledgement',
    strpos($app, "document.querySelector('.settings-tabs .settings-tab.active')") !== false);

// --------------------------------------------------------------- functional
$sources = [
    '_checklistState'             => extract_fn($app, '_checklistState'),
    '_checklistTodoItems'         => extract_fn($app, '_checklistTodoItems'),
    '_checklistNewsItems'         => extract_fn($app, '_checklistNewsItems'),
    '_checklistPendingBeforeSave' => extract_fn($app, '_checklistPendingBeforeSave'),
    '_ackSavedChecklistItems'     => extract_fn($app, '_ackSavedChecklistItems'),
];
foreach ($sources as $name => $src) {
    check("$name() is extractable", $src !== '');
}

$harness = implode("\n\n", $sources) . "\n" . <<<'JS'
const SETTINGS_CHECKLIST = [
    { id: 'answered',   ask: true,  askVersion: 1, tab: 'tab-a' },
    { id: 'todo_item',  ask: true,  askVersion: 1, tab: 'tab-a' },
    { id: 'news_here',  ask: true,  askVersion: 2, tab: 'tab-b' },
    { id: 'news_else',  ask: true,  askVersion: 2, tab: 'tab-c' },
    { id: 'just_done',  ask: true,  askVersion: 1, tab: 'tab-d' },
    { id: 'plain_open', ask: false, askVersion: 1, tab: 'tab-a' },
    { id: 'plain_done', ask: false, askVersion: 1, tab: 'tab-a' },
];
let STATES = {
    answered: 'todo', todo_item: 'todo', news_here: 'ok', news_else: 'ok',
    just_done: 'todo', plain_open: 'todo', plain_done: 'ok',
};
let SEEN = { answered: 1 };           // the user already answered "not now"
function _checklistState(item) { return STATES[item.id] || 'todo'; }
function _setupSeen() { return SEEN; }
function _markSetupSeen(ids) {
    for (const id of (ids || [])) {
        const item = SETTINGS_CHECKLIST.find(i => i.id === id);
        if (item) SEEN[id] = item.askVersion || 1;
    }
}
const ids = (rows) => rows.map(r => r.id).sort().join(',');

const out = {};
out.todo = ids(_checklistTodoItems());
out.news_before = ids(_checklistNewsItems());

// The app snapshots what is pending BEFORE the request, then the save applies it.
const pending = _checklistPendingBeforeSave();
out.pending = pending.slice().sort().join(',');
STATES.just_done = 'ok';                       // the user configured it, then saved
out.acked = _ackSavedChecklistItems(pending, 'tab-b').slice().sort().join(',');
out.news_after = ids(_checklistNewsItems());
out.todo_after = ids(_checklistTodoItems());
out.seen = SEEN;
console.log(JSON.stringify(out));
JS;

$tmp = sys_get_temp_dir() . '/checklist_' . getmypid() . '.js';
file_put_contents($tmp, $harness);
$raw = shell_exec('node ' . escapeshellarg($tmp) . ' 2>/dev/null');
@unlink($tmp);
$out = json_decode(trim((string)$raw), true);

check('the harness runs in node', is_array($out));
if (getenv('CHECKLIST_DEBUG')) { fwrite(STDERR, "HARNESS: " . (string)$raw . "\n"); }
if (is_array($out)) {
    check('"Da decidere" keeps only the open, unanswered items',
        ($out['todo'] ?? '') === 'just_done,plain_open,todo_item');
    check('"Novità" starts with the two configured, unseen options',
        ($out['news_before'] ?? '') === 'news_else,news_here');
    check('the pending snapshot leaves out the options already configured',
        ($out['pending'] ?? '') === 'answered,just_done,todo_item');
    check('saving acknowledges the just-configured option and the saved tab',
        ($out['acked'] ?? '') === 'just_done,news_here');
    check('the news of the saved tab is gone',
        strpos((string)($out['news_after'] ?? ''), 'news_here') === false);
    check('the news of another tab survives',
        strpos((string)($out['news_after'] ?? ''), 'news_else') !== false);
    check('nothing left in "Da decidere" but the open, unanswered item',
        ($out['todo_after'] ?? '') === 'plain_open,todo_item');
    check('the answered option is recorded at its version',
        (int)($out['seen']['answered'] ?? 0) === 1);
    check('a non-ask item is never acknowledged',
        !isset($out['seen']['plain_open']) && !isset($out['seen']['plain_done']));
}

echo "\n{$checks} checks, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);

