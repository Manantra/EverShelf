#!/usr/bin/env php
<?php
/**
 * Guard test — the shopping session: scan first, type the total at the END.
 *
 * Regression history (audit, round 14): tapping the cart opened the camera AND the
 * "how much did you spend?" dialog at the same time, so the first product was scanned under
 * a form; the total was archived by uploading a receipt file — impossible on a wall tablet
 * or while emptying the bags, and exactly the input that text extraction gets wrong.
 *
 * The order is now: cart → continuous scanning → "Fine spesa" → optional keypad with the
 * total (asked only when something was actually loaded).
 *
 * Run: php scripts/test-spesa-flow.php
 */
$root = dirname(__DIR__);
$html = (string)file_get_contents($root . '/index.html');
$app  = (string)file_get_contents($root . '/assets/js/app.js');
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

// ── The camera comes first ─────────────────────────────────────────────────
$start = '';
if (preg_match('/async function startSpesaMode\(\) \{[\s\S]*?\n\}/', $app, $m)) {
    $start = $m[0];
}
check('startSpesaMode() is readable', $start !== '');
check('the shopping session opens the camera, not a form',
    str_contains($start, '_spesaBeginScanning();')
    && !str_contains($start, '_spesaPromptOptionalSpend('));
check('the old "amount first" prompt is gone from the code base',
    !str_contains($app, 'function _spesaPromptOptionalSpend(')
    && !str_contains($app, '_spesaPromptOptionalSpend();'));

// ── The total is asked at the end, and only if something was loaded ────────
$end = '';
if (preg_match('/function endSpesaMode\(\) \{[\s\S]*?\n\}/', $app, $m)) {
    $end = $m[0];
}
check('endSpesaMode() is readable', $end !== '');
check('the total is asked when the session produced something',
    str_contains($end, 'const count = _spesaSession.length;')
    && preg_match('/if \(count > 0\) _spesaFinishPrompt\(count\);/', $end) === 1);
check('a keypad, not a file picker',
    str_contains($app, 'function _spesaFinishPrompt(')
    && str_contains($app, 'function _spesaKeypad(')
    && str_contains($app, "id=\"spesa-total-display\"")
    && str_contains($app, "class=\"spesa-key\""));
check('the total still reaches the ledger through the same action',
    preg_match('/async function _spesaFinishSave\(\)[\s\S]{0,600}spend_add/', $app) === 1);
check('the keypad accepts a decimal comma and a backspace',
    str_contains($app, "if (key === '⌫')") && str_contains($app, "} else if (key === ',') {"));
check('the finish modal is styled (grid keypad, big amount)',
    preg_match('/\.spesa-keypad \{[^}]*grid-template-columns: repeat\(3, 1fr\)/s', (string)file_get_contents($root . '/assets/css/surfaces.css')) === 1
    && str_contains((string)file_get_contents($root . '/assets/css/surfaces.css'), '.spesa-finish-value'));

// ── No receipt upload on the scan page ────────────────────────────────────
$scanPage = '';
if (preg_match('/<section class="page" id="page-scan">[\s\S]*?<\/section>/', $html, $m)) {
    $scanPage = $m[0];
}
check('the scan page is readable', $scanPage !== '');
check('no "Scontrino" file-upload entry point on the scan page',
    !str_contains($scanPage, '_receiptStart()') && !str_contains($scanPage, 'type="file"'));
check('the parked receipt setting is not offered as dead config',
    preg_match('/<div class="form-group" style="margin-top:8px" hidden>/', $html) === 1);

// ── The copy exists in all six locales ────────────────────────────────────
foreach (['it', 'en', 'de', 'fr', 'es', 'zh'] as $loc) {
    $doc = json_decode((string)file_get_contents($root . "/translations/{$loc}.json"), true);
    $shop = (array)($doc['shopping'] ?? []);
    $keys = ['spesa_done_title', 'spesa_done_count', 'spesa_done_ask', 'spesa_done_save', 'spesa_done_skip'];
    $missing = array_values(array_filter($keys, static fn($k) => trim((string)($shop[$k] ?? '')) === ''));
    check("translations/{$loc}.json has the five end-of-session strings", $missing === [], implode(', ', $missing));
    check("translations/{$loc}.json keeps the {n} in the count sentence",
        str_contains((string)($shop['spesa_done_count'] ?? ''), '{n}'));
}

echo "\n{$checks} checks, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
