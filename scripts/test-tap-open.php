#!/usr/bin/env php
<?php
/**
 * Regression tests: every dashboard alert row must react to a TAP, on the phone too.
 *
 * The report: "da mobile (Android) se faccio tap su qualsiasi elemento in scaduti, in
 * scadenza, aperti o aperti da troppo tempo non accade nulla". Two causes:
 *
 *   1. `_bindRowHoldOpen()` ignored the synthetic click on touch/pen on purpose and
 *      waited for a 900 ms hold — a tap did nothing at all. A tap now opens the row;
 *      the hold still works (and a scroll, i.e. a pointermove > 12 px, still cancels).
 *      Lists where a tap has another meaning keep `tapToOpen: false` (the shopping
 *      "suggested" rows, which swipe and would add items by accident).
 *   2. The "aperti da troppo tempo" rows were rendered without
 *      `alert-item-clickable`/`data-alert-inv-id` and never bound, so they were inert
 *      even on desktop.
 *
 * Run: php scripts/test-tap-open.php
 */
define('CRON_MODE', true);
require_once __DIR__ . '/../api/bootstrap.php';
require_once __DIR__ . '/../api/index.php';

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

$root = __DIR__ . '/..';
$js   = (string)file_get_contents($root . '/assets/js/app.js');

function js_eval(string $source, string $code): string
{
    static $node = null;
    if ($node === null) {
        $node = trim((string)shell_exec('command -v node 2>/dev/null'));
    }
    if ($node === '') {
        return '';
    }
    $file = tempnam(sys_get_temp_dir(), 'evershelf-') . '.js';
    file_put_contents($file, $source . "\n" . $code . "\n");
    $out = (string)shell_exec(escapeshellarg($node) . ' ' . escapeshellarg($file) . ' 2>&1');
    @unlink($file);
    return trim($out);
}

function slice_js_function(string $source, string $name): string
{
    $start = strpos($source, 'function ' . $name . '(');
    if ($start === false) {
        return '';
    }
    $end = strpos($source, "\n}\n", $start);
    return $end === false ? '' : substr($source, $start, $end - $start + 3);
}

// ── The stale list is wired like the other three ----------------------------
assert_true(str_contains($js, 'alert-item stale-item alert-item-clickable'),
    'the "aperti da troppo tempo" rows carry the clickable class');
assert_true(str_contains($js, 'data-alert-inv-id="${iid}"'),
    'the stale row exposes data-alert-inv-id (the binder needs it)');
assert_true(substr_count($js, '_bindAlertItemsHoldOpen(') >= 4,
    'all four alert lists are bound (' . substr_count($js, '_bindAlertItemsHoldOpen(') . ' calls)');
assert_true(str_contains($js, 'if (row._swipeMoved)'), 'the shopping suggest rows keep their own binding');
assert_true(str_contains($js, "{ tapToOpen: false }"), 'the shopping suggest rows opt out of tap-to-open');

// ── Tap / hold / scroll on a fake element ───────────────────────────────────
$bindFn = slice_js_function($js, '_bindRowHoldOpen');
assert_true($bindFn !== '', '_bindRowHoldOpen() exists');

$helpers = "const ROW_OPEN_HOLD_MS = 30;\nconst ROW_OPEN_MOVE_PX = 12;\n"
    . $bindFn . "\n"
    . "function fakeEl() {\n"
    . "  const l = {};\n"
    . "  return {\n"
    . "    classList: { add() {}, remove() {} },\n"
    . "    addEventListener: (t, fn) => { (l[t] = l[t] || []).push(fn); },\n"
    . "    removeEventListener: () => {},\n"
    . "    fire: (t, e) => (l[t] || []).forEach(fn => fn(e)),\n"
    . "  };\n"
    . "}\n"
    . "function ev(type, more = {}) {\n"
    . "  return Object.assign({ type, button: 0, clientX: 10, clientY: 10, pointerId: 1,\n"
    . "    pointerType: 'touch', target: { closest: () => null },\n"
    . "    preventDefault() {}, stopPropagation() {} }, more);\n"
    . "}\n"
    . "const sleep = ms => new Promise(r => setTimeout(r, ms));\n";

$scenarios = "(async () => {\n"
    . "  const out = {};\n"
    . "  let n = 0;\n"
    . "  let el = fakeEl(); _bindRowHoldOpen(el, () => n++);\n"
    . "  el.fire('pointerdown', ev('pointerdown'));\n"
    . "  el.fire('pointerup', ev('pointerup'));\n"
    . "  el.fire('click', ev('click'));\n"
    . "  await sleep(20); out.tap = n;\n"
    . "  n = 0; el = fakeEl(); _bindRowHoldOpen(el, () => n++);\n"
    . "  el.fire('pointerdown', ev('pointerdown'));\n"
    . "  await sleep(60);\n"
    . "  el.fire('pointerup', ev('pointerup'));\n"
    . "  el.fire('click', ev('click'));\n"
    . "  await sleep(20); out.hold = n;\n"
    . "  n = 0; el = fakeEl(); _bindRowHoldOpen(el, () => n++);\n"
    . "  el.fire('pointerdown', ev('pointerdown'));\n"
    . "  el.fire('pointermove', ev('pointermove', { clientX: 60 }));\n"
    . "  el.fire('pointerup', ev('pointerup'));\n"
    . "  await sleep(20); out.scroll = n;\n"
    . "  n = 0; el = fakeEl(); _bindRowHoldOpen(el, () => n++, { tapToOpen: false });\n"
    . "  el.fire('pointerdown', ev('pointerdown'));\n"
    . "  el.fire('pointerup', ev('pointerup'));\n"
    . "  await sleep(20); out.holdOnly = n;\n"
    . "  n = 0; el = fakeEl(); _bindRowHoldOpen(el, () => n++);\n"
    . "  el.fire('pointerdown', ev('pointerdown', { pointerType: 'mouse' }));\n"
    . "  el.fire('pointerup', ev('pointerup', { pointerType: 'mouse' }));\n"
    . "  el.fire('click', ev('click', { pointerType: 'mouse' }));\n"
    . "  await sleep(20); out.mouse = n;\n"
    . "  n = 0; el = fakeEl();\n"
    . "  _bindRowHoldOpen(el, () => n++, { ignoreSelector: '.btn-alert-extend, button' });\n"
    . "  const onButton = ev('pointerdown', { target: { closest: sel => sel.includes('button') ? {} : null } });\n"
    . "  el.fire('pointerdown', onButton);\n"
    . "  el.fire('pointerup', ev('pointerup', { target: onButton.target }));\n"
    . "  await sleep(20); out.button = n;\n"
    . "  console.log(JSON.stringify(out));\n"
    . "})();";

$out = js_eval('', $helpers . $scenarios);
$res = json_decode($out, true);
if (!is_array($res)) {
    echo "SKIP: node is not available — the tap probes were not run ({$out})\n";
} else {
    assert_true($res['tap'] === 1, 'a TAP on touch opens the row once (' . $res['tap'] . ')');
    assert_true($res['hold'] === 1, 'a long press opens once, the synthetic click is swallowed (' . $res['hold'] . ')');
    assert_true($res['scroll'] === 0, 'a scroll never opens a row (' . $res['scroll'] . ')');
    assert_true($res['holdOnly'] === 0, 'a row with tapToOpen:false ignores the tap (' . $res['holdOnly'] . ')');
    assert_true($res['mouse'] === 1, 'a mouse click still opens once (' . $res['mouse'] . ')');
    assert_true($res['button'] === 0, 'a tap on the row button does not open the detail (' . $res['button'] . ')');
}

if ($fail === 0) {
    echo "\nAll tap-to-open guard tests passed.\n";
}
exit($fail === 0 ? 0 : 1);

