#!/usr/bin/env php
<?php
/**
 * Guard test — the offline queue is capped, and a drop is never silent.
 *
 * Regression history (audit B1): `_offlineQueuePush()` trimmed the queue to 100
 * operations *silently*. A long offline session (the kiosk loses Wi-Fi for a day)
 * therefore lost the oldest work with no log line and no message: the user only
 * found out when something they had done was missing.
 *
 * The function under test is the shipped one, extracted and run in node with stubs.
 *
 * Run: php scripts/test-offline-queue.php
 */

$root = dirname(__DIR__);
$app = (string)file_get_contents($root . '/assets/js/app.js');
$fail = 0;
$checks = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $fail, $checks;
    $checks++;
    if ($ok) {
        echo "ok    {$label}\n";
    } else {
        $fail++;
        echo "FAIL  {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

/** Pull a top-level function out of app.js by matching braces. */
function extract_fn(string $src, string $name): string
{
    $start = strpos($src, 'function ' . $name . '(');
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

$src = extract_fn($app, '_offlineQueuePush');
check('_offlineQueuePush() is extractable', $src !== '');

check('the cap is a named constant, not a magic number', str_contains($app, 'const _OFFLINE_QUEUE_MAX = 100;'));
check('the trim happens in a loop (a burst can overflow by more than one)',
    str_contains($src, 'while (q.length >= _OFFLINE_QUEUE_MAX) { q.shift(); dropped++; }'));
check('a drop is logged', str_contains($src, 'console.warn(`[Offline] queue full: dropped ${dropped}'));
check('a drop is shown to the user once per session',
    str_contains($src, "showToast(t('error.offline_queue_full', { n: dropped }), 'warning')")
    && str_contains($src, '_offlineQueueFullNotified = true;'));
check('the banner is repainted after a push', str_contains($src, '_renderOfflineBanner();'));

// ── Behaviour: 105 pushes keep the 5 newest and report 5 drops ───────────────
$harness = $src . <<<'JS'
let LS = {};
const localStorage = {
    getItem: (k) => (Object.prototype.hasOwnProperty.call(LS, k) ? LS[k] : null),
    setItem: (k, v) => { LS[k] = String(v); },
};
function _offlineQueueGet() { try { return JSON.parse(localStorage.getItem('evs_q')) || []; } catch (e) { return []; } }
function _offlineQueueSet(q) { try { localStorage.setItem('evs_q', JSON.stringify(q)); } catch (e) {} }
function _renderOfflineBanner() {}
const toasts = [];
function showToast(msg, kind) { toasts.push(kind + ':' + msg); }
function t(key, params) { return key + JSON.stringify(params || {}); }
const _OFFLINE_QUEUE_MAX = 100;
let _offlineQueueFullNotified = false;
for (let i = 0; i < 105; i++) _offlineQueuePush('op_' + i, { i });
const q = _offlineQueueGet();
console.log(JSON.stringify({
    size: q.length,
    first: q.length ? q[0].action : '',
    last: q.length ? q[q.length - 1].action : '',
    toasts: toasts.length,
    toast: toasts[0] || '',
}));
JS;

$tmp = sys_get_temp_dir() . '/offline_q_' . getmypid() . '.js';
file_put_contents($tmp, $harness);
$raw = shell_exec('node ' . escapeshellarg($tmp) . ' 2>/dev/null');
@unlink($tmp);
$out = json_decode(trim((string)$raw), true);
check('the harness runs in node', is_array($out), (string)$raw);
if (is_array($out)) {
    check('the queue never grows past the cap', (int)($out['size'] ?? 0) === 100);
    check('the oldest operations are the ones dropped', ($out['first'] ?? '') === 'op_5');
    check('the newest operation is kept', ($out['last'] ?? '') === 'op_104');
    check('exactly one warning is shown, however many were dropped', (int)($out['toasts'] ?? 0) === 1);
    check('the warning carries the number of dropped operations',
        str_contains((string)($out['toast'] ?? ''), '"n":1'));
}

// ---------------------------------------------------------------- i18n of the key
foreach (['it', 'en', 'de', 'fr', 'es', 'zh'] as $loc) {
    $doc = json_decode((string)file_get_contents($root . "/translations/{$loc}.json"), true);
    $val = $doc['error']['offline_queue_full'] ?? '';
    check("translations/{$loc}.json has error.offline_queue_full with {n}",
        is_string($val) && str_contains($val, '{n}'));
}

echo "\n{$checks} checks, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
