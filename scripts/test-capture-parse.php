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
check('the sheet offers examples (and the whole lexicon) plus the full chat',
    substr_count($html, 'class="capture-hint"') >= 5 && str_contains($html, 'capture.open_chat')
    && str_contains($html, 'capture-examples-btn'));

// ------------------------------------------------------------------ wiring
check('_captureParse() is extractable', extract_fn($app, '_captureParse') !== '');
check('the voice uses the on-device Web Speech API (no server STT)',
    str_contains($app, 'window.SpeechRecognition') && str_contains($app, 'window.webkitSpeechRecognition'));
check('the transcript language follows the UI language', str_contains($app, '_captureRec.lang = _collationLocale();'));
check('a missing/denied mic degrades to typing, never to a dead button',
    str_contains($app, "'capture.unsupported'") && str_contains($app, "'capture.mic_denied'"));
check('nothing is applied without a confirmation step',
    str_contains($app, 'quickUse(best.item.product_id, best.item.location)')
    && str_contains($app, 'showAddForm();')
    && str_contains($app, 'showAlertItemDetail(best.item.inventory_id'));

$src = extract_fn($app, '_captureParse');
// The parser reads its lexicon and matcher: ship them to node as well.
$lexStart = strpos($app, 'const _CAPTURE_INTENT_WORDS');
$lexEnd = strpos($app, 'function _captureParse');
$lexicon = ($lexStart !== false && $lexEnd !== false && $lexEnd > $lexStart)
    ? substr($app, $lexStart, $lexEnd - $lexStart) : '';
check('the lexicon is extractable', $lexicon !== '' && str_contains($lexicon, '_captureMatchIntent'));
$harness = $lexicon . $src . <<<'JS'
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
    ["ho buttato il pane", "throw", "pane", 1],
    ["due mele", "consume", "mele", 2],
    ["three eggs", "consume", "eggs", 3],
    ["zwei Äpfel", "consume", "äpfel", 2],
    ["deux tomates", "consume", "tomates", 2],
    ["tres naranjas", "consume", "naranjas", 3],
    // Round 5: fractions, grams written without a space, "I finished it all".
    ["ho usato mezza mela", "consume", "mela", 0.5],
    ["ho usato un quarto di torta", "consume", "torta", 0.25],
    ["ho usato 300gr di fusilli", "consume", "fusilli", 300],
    ["I used half an apple", "consume", "apple", 0.5],
    ["ho finito tutti i fusilli", "consume", "fusilli", 0],
    ["he usado todo el pan", "consume", "pan", 0],
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
        ['consume', '橙子', 1], ['consume', 'latte', 0],
        ['throw', 'pane', 1], ['consume', 'mele', 2], ['consume', 'eggs', 3],
        ['consume', 'äpfel', 2], ['consume', 'tomates', 2], ['consume', 'naranjas', 3],
        ['consume', 'mela', 0.5], ['consume', 'torta', 0.25], ['consume', 'fusilli', 300],
        ['consume', 'apple', 0.5], ['consume', 'fusilli', 0], ['consume', 'pan', 0],
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

// ── Round 5: the parser knows fractions, units and "all of it" ────────────
check('fractions are quantities', str_contains($app, 'const _CAPTURE_FRACTIONS')
    && str_contains($app, "['mezza', 0.5]"));
check('spoken units have one canonical form', str_contains($app, 'const _CAPTURE_UNIT_ALIASES')
    && str_contains($app, "gr: 'g'"));
check('"ho finito tutti" sets use_all', str_contains($app, 'const _CAPTURE_ALL_WORDS')
    && str_contains($app, 'out.use_all = true'));
check('a dictated quantity opens the sheet on that number',
    str_contains($app, 'function _captureQtyFor(')
    && str_contains($app, 'showItemDetail(best.item.id, best.item.product_id, preset)'));
check('"ho finito" opens the sheet to confirm, it never writes by itself',
    str_contains($app, "t('capture.use_all_hint'")
    && str_contains($app, 'showItemDetail(best.item.id, best.item.product_id);
            });'));

// ── Round 5: the session listens long enough, then talks back ───────────────
check('listening survives the recogniser timeouts',
    str_contains($app, '_captureRec.continuous = true;')
    && str_contains($app, '_CAPTURE_VOICE_MAX_RESTARTS')
    && str_contains($app, "_captureStatus('capture.still_listening')"));
check('voice feedback is spoken only for dictated sentences',
    str_contains($app, 'function _captureSpeak(')
    && str_contains($app, '_captureSubmit(finalText.trim(), { voice: true })')
    && str_contains($app, 'if (fromVoice) _captureSpeak(_captureVoiceSay(parsed));'));
check('the speaker toggle is remembered per device',
    str_contains($app, "localStorage.setItem('evershelf_capture_voice'")
    && str_contains($html, 'id="capture-voice-toggle"'));
check('"Cosa posso dire" lists the whole lexicon',
    str_contains($html, 'id="capture-examples-btn"')
    && str_contains($app, 'function _captureRenderExamples(')
    && str_contains($app, "'capture.example_use_grams'"));

// ── Feedback: level meter, live transcript, "what I understood", choices ─────
check('the mic button fills with the incoming level',
    str_contains($app, 'analyser.getByteTimeDomainData(buf)')
    && str_contains($app, "setProperty('--level'"));
check('the level meter is released when listening stops',
    str_contains($app, 'function _captureStopMeter()')
    && substr_count($app, '_captureStopMeter();') >= 4);
check('opening the sheet starts listening by itself',
    str_contains($app, "if (Ctor) {\n        _captureStartVoice();")
    && str_contains($app, 'const Ctor = window.SpeechRecognition || window.webkitSpeechRecognition;'));
check('the recogniser starts inside the gesture, before any await',
    !str_contains($app, 'await _captureStartMeter()')
    && str_contains($app, "_captureRec.start();\n        _captureStartMeter();"));
check('the first run explains the permission prompt instead of looking stuck',
    str_contains($app, "navigator.permissions.query({ name: 'microphone' })")
    && str_contains($app, "_captureStatus('capture.mic_prompt')"));
check('the prompt hint exists in all six locales',
    array_reduce(['it','en','de','fr','es','zh'], function ($ok, $loc) use ($root) {
        $doc = json_decode((string)file_get_contents($root . "/translations/{$loc}.json"), true);
        $val = $doc['capture']['mic_prompt'] ?? '';
        return $ok && is_string($val) && trim($val) !== '';
    }, true));

check('the idle line no longer asks for a tap',
    !str_contains($html, 'Tocca il microfono e parla'));

check('the transcript is shown while it is still guessing',
    str_contains($app, '_captureRec.interimResults = true;')
    && str_contains($app, 'function _captureShowTranscript('));
check('the interpreted action is shown before routing',
    str_contains($app, 'function _captureShowUnderstood(')
    && str_contains($app, '_captureShowUnderstood(parsed);')
    && str_contains($html, 'id="capture-understood"'));
check('an ambiguous product asks instead of guessing',
    str_contains($app, 'function _captureShowChoices(')
    && str_contains($app, 'function _captureChoose(')
    && str_contains($app, "_captureStatus('capture.which_one')"));
check('the sheet markup carries the meter, bars, transcript and chips',
    str_contains($html, 'id="capture-mic-level"') && str_contains($html, 'id="capture-bars"')
    && str_contains($html, 'id="capture-transcript"') && str_contains($html, 'id="capture-choices"'));
check('the matcher is internal and offline (no AI call, no network)',
    !str_contains(extract_fn($app, '_captureParse'), 'api(')
    && !str_contains(extract_fn($app, '_captureParse'), 'fetch('));
check('the lexicon covers all six UI languages',
    str_contains($app, "'cosa cucino'") && str_contains($app, "'recipe'") && str_contains($app, "'rezept'")
    && str_contains($app, "'recette'") && str_contains($app, "'receta'") && str_contains($app, "'食谱'"));

$score = extract_fn($app, '_captureScoreName');
check('_captureScoreName() is extractable', $score !== '');
if ($score !== '') {
    $jsScore = $score . <<<'JS'
console.log(JSON.stringify([
    _captureScoreName('arancia', 'Arancia'),
    _captureScoreName('arancia', 'Arance'),
    _captureScoreName('latte', 'Latte intero'),
    _captureScoreName('latte', 'Biscotti al latte'),
    _captureScoreName('latte', 'Passata di pomodoro'),
]));
JS;
    $tmpS = sys_get_temp_dir() . '/capture_score_' . getmypid() . '.js';
    file_put_contents($tmpS, $jsScore);
    $rawS = shell_exec('node ' . escapeshellarg($tmpS) . ' 2>/dev/null');
    @unlink($tmpS);
    $scores = json_decode(trim((string)$rawS), true);
    check('the matcher ranks exact over plural/prefix over partial',
        is_array($scores)
        && (float)($scores[0] ?? 0) === 1.0          // exact
        && (float)($scores[1] ?? 0) >= 0.6           // arance ~ arancia (stem)
        && (float)($scores[2] ?? 0) >= 0.85          // latte -> Latte intero
        && (float)($scores[3] ?? 1) < (float)($scores[2] ?? 0)
        && (float)($scores[4] ?? 1) === 0.0, json_encode($scores));
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
