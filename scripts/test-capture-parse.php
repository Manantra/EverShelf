#!/usr/bin/env php
<?php
/**
 * Guard test — the capture button ("one sentence in, one confirmed action out").
 *
 * Regression history (UI audit, voice block): the centre of the bottom nav was a
 * product name ("Gemini") opening a chat. It is now a capture button: it accepts a
 * spoken or typed sentence and routes it to the flow that already does the job.
 * Nothing is applied without confirmation — the use form, the add form and the chat
 * all ask.
 *
 * `_captureParse()` is deterministic (no AI call, no network) and runs in the six UI
 * languages; this test locks the intents the parser must never lose.
 *
 * Run: php scripts/test-capture-parse.php
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

// ------------------------------------------------------------------- markup
check('the centre of the nav is the capture button', str_contains($html, 'id="nav-capture-btn"'));
check('the product name is gone from the nav', !str_contains($html, 'data-i18n="nav.gemini"'));
check('the capture sheet is a dialog',
    str_contains($html, 'id="capture-sheet"') && str_contains($html, 'aria-modal="true"'));
check('the sheet accepts typed input too (no speech API required)',
    str_contains($html, 'id="capture-input"') && str_contains($html, '_captureSubmit('));
check('the sheet offers three examples and the full chat',
    substr_count($html, 'class="capture-hint"') === 3 && str_contains($html, 'capture.open_chat'));

// ------------------------------------------------------------------ wiring
check('_captureParse() is extractable', extract_fn($app, '_captureParse') !== '');
check('the voice uses the on-device Web Speech API (no server STT)',
    str_contains($app, 'window.SpeechRecognition') && str_contains($app, 'window.webkitSpeechRecognition'));
check('the transcript language follows the UI language', str_contains($app, '_captureRec.lang = _collationLocale();'));
check('a missing/denied mic degrades to typing, never to a dead button',
    str_contains($app, "'capture.unsupported'") && str_contains($app, "'capture.mic_denied'"));
check('nothing is applied without a confirmation step',
    str_contains($app, 'await quickUse(item.product_id, item.location)')
    && str_contains($app, 'showAddForm();'));

$src = extract_fn($app, '_captureParse');
$harness = $src . <<<'JS'
const cases = [
    ["Ho usato 1 arancia", "consume", "arancia", 1],
    ["ho usato 250 g di pasta", "consume", "pasta", 250],
    ["Aggiungi il latte", "add", "latte", 1],
    ["Cosa cucino con le uova?", "recipe", "", 1],
    ["I used 2 apples", "consume", "apples", 2],
    ["add the milk", "add", "milk", 1],
    ["Ich habe 1 Apfel verbraucht", "consume", "apfel", 1],
    ["J'ai utilisé une orange", "consume", "orange", 1],
    ["He usado 1 naranja", "consume", "naranja", 1],
    ["我用了一个橙子", "consume", "橙子", 1],
    ["finito il latte", "consume", "latte", 1],
];
const out = cases.map(([text]) => _captureParse(text));
console.log(JSON.stringify(out));
JS;

$tmp = sys_get_temp_dir() . '/capture_' . getmypid() . '.js';
file_put_contents($tmp, $harness);
$raw = shell_exec('node ' . escapeshellarg($tmp) . ' 2>/dev/null');
@unlink($tmp);
$out = json_decode(trim((string)$raw), true);
check('the parser runs in node', is_array($out), (string)$raw);
if (is_array($out)) {
    $expect = [
        ['consume', 'arancia', 1], ['consume', 'pasta', 250], ['add', 'latte', 1],
        ['recipe', '', 1], ['consume', 'apples', 2], ['add', 'milk', 1],
        ['consume', 'apfel', 1], ['consume', 'orange', 1], ['consume', 'naranja', 1],
        ['consume', '橙子', 1], ['consume', 'latte', 1],
    ];
    foreach ($expect as $i => [$intent, $product, $qty]) {
        $row = $out[$i] ?? [];
        check("case {$i}: intent {$intent}", ($row['intent'] ?? '') === $intent, json_encode($row));
        if ($product !== '') {
            check("case {$i}: product {$product}", ($row['product'] ?? '') === $product, json_encode($row));
        }
        check("case {$i}: qty {$qty}", (float)($row['qty'] ?? 0) === (float)$qty, json_encode($row));
    }
}

// --------------------------------------------------------------------- i18n
foreach (['title', 'nav_label', 'idle', 'listening', 'unsupported', 'mic_denied',
          'not_understood', 'placeholder', 'run', 'example_use', 'example_add',
          'example_recipe', 'open_chat', 'no_product', 'use_hint', 'add_hint'] as $key) {
    foreach (['it', 'en', 'de', 'fr', 'es', 'zh'] as $loc) {
        $data = json_decode((string)file_get_contents($root . "/translations/{$loc}.json"), true);
        $val = $data['capture'][$key] ?? '';
        check("translations/{$loc}.json has capture.{$key}", is_string($val) && trim($val) !== '');
    }
}

echo "\n{$checks} checks, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
