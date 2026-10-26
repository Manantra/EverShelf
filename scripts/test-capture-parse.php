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
check('the transcript language follows the device (falling back to the UI language)',
    str_contains($app, '_captureRec.lang = _captureSpeechLang();')
    && str_contains($app, 'function _captureSpeechLang('));
check('a missing/denied mic degrades to typing, never to a dead button',
    str_contains($app, "'capture.unsupported'") && str_contains($app, "'capture.mic_denied'"));
check('nothing is applied without a confirmation step',
    // Round 8: SHELFY opens the panel it is about to act on and arms its confirm;
    // the sentence alone never writes.
    str_contains($app, 'function _shelfyArmButton(')
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
check('a dictated quantity reaches the USE panel',
    str_contains($app, 'function _captureQtyFor(')
    && str_contains($app, 'async function _shelfyRunConsume(')
    && str_contains($app, "const qtyEl = document.getElementById('use-quantity');"));
check('"ho finito" arms "use it all" on the USE panel',
    str_contains($app, "document.querySelector('#page-use .use-all-btn')")
    && str_contains($app, '() => submitUseAll()'));

// ── Round 8: SHELFY, the named assistant ────────────────────────────────────
check('the assistant has a name', str_contains($html, 'data-i18n="capture.title">SHELFY')
    && str_contains($app, 'const SHELFY_INTRO_KEY'));
check('it introduces itself once per device',
    str_contains($html, 'id="shelfy-intro"')
    && str_contains($app, "localStorage.getItem(SHELFY_INTRO_KEY)")
    && str_contains($app, 'if (!_shelfyIntroSeen()) { _shelfyOpenIntro(); return; }')
    && str_contains($html, 'id="shelfy-help-btn"'));
check('the confirm drains for ten seconds',
    str_contains($app, "_shelfyArmButton(document.getElementById('btn-use-submit'), 10000")
    && str_contains($app, "_shelfyArmButton(document.getElementById('btn-add-submit'), 10000")
    && str_contains((string)file_get_contents($root . '/assets/css/surfaces.css'), 'animation: shelfy-drain var(--shelfy-ms, 10s) linear forwards;'));
check('any touch stops it',
    str_contains($app, "document.addEventListener('pointerdown', stop, true)")
    && str_contains($app, "document.addEventListener('keydown', stop, true)")
    && str_contains($app, 'function _shelfyDisarm('));
check('four candidates, and the closest wins after 15 seconds',
    str_contains($app, '.slice(0, 4);')
    && str_contains($app, '_shelfyArmButton(best, 15000')
    && str_contains($app, "t('shelfy.pick_auto'"));
check('the candidates show picture, name, brand, place',
    str_contains($app, 'class="shelfy-pick')
    && str_contains($app, '_validProductImageUrl(item.image_url)')
    && str_contains($app, 'class="shelfy-pick-meta"'));
// The add form speaks pz/conf/g/ml only: a dictated litre must arrive as 1000 ml.
$unitSrc = extract_fn($app, '_shelfyAddUnit');
check('_shelfyAddUnit() is extractable', $unitSrc !== '');
if ($unitSrc !== '') {
    $unitHarness = $unitSrc . <<<'JS'
console.log(JSON.stringify([[1,'l'],[0.5,'kg'],[300,'g'],[30,'cl'],[2,'conf'],[1,'']].map(([q,u]) => _shelfyAddUnit(q,u))));
JS;
    $tmpU = sys_get_temp_dir() . '/addunit_' . getmypid() . '.js';
    file_put_contents($tmpU, $unitHarness);
    $rawU = shell_exec('node ' . escapeshellarg($tmpU) . ' 2>/dev/null');
    @unlink($tmpU);
    $u = json_decode(trim((string)$rawU), true);
    check('the dictated units reach the add form', is_array($u), (string)$rawU);
    if (is_array($u)) {
        check('1 litre becomes 1000 ml', ($u[0]['qty'] ?? 0) == 1000 && ($u[0]['unit'] ?? '') === 'ml', json_encode($u[0]));
        check('half a kilo becomes 500 g', ($u[1]['qty'] ?? 0) == 500 && ($u[1]['unit'] ?? '') === 'g', json_encode($u[1]));
        check('grams stay grams', ($u[2]['qty'] ?? 0) == 300 && ($u[2]['unit'] ?? '') === 'g');
        check('centilitres become millilitres', ($u[3]['qty'] ?? 0) == 300 && ($u[3]['unit'] ?? '') === 'ml');
        check('packs stay packs', ($u[4]['unit'] ?? '') === 'conf');
    }
}

foreach (['it', 'en', 'de', 'fr', 'es', 'zh'] as $loc) {
    $doc = json_decode((string)file_get_contents($root . "/translations/{$loc}.json"), true);
    check("translations/{$loc}.json names the assistant",
        str_contains((string)($doc['capture']['title'] ?? ''), 'SHELFY')
        && str_contains((string)($doc['capture']['idle'] ?? ''), 'SHELFY')
        && str_contains((string)($doc['capture']['not_understood'] ?? ''), 'SHELFY'));
    check("translations/{$loc}.json has the presentation and the countdown copy",
        isset($doc['shelfy']['intro_title'], $doc['shelfy']['intro_start'], $doc['shelfy']['pick_auto'], $doc['shelfy']['auto_confirm_use'])
        && str_contains((string)$doc['shelfy']['pick_auto'], '{name}')
        && str_contains((string)$doc['shelfy']['auto_confirm_use'], '{qty}'));
}

// ── Round 9: ONE utterance per tap (the restart loop beeped and ate the sentence) ──
check('the recogniser is not restarted in a loop',
    str_contains($app, '_captureRec.continuous = false;')
    && !str_contains($app, '_CAPTURE_VOICE_MAX_RESTARTS')
    && !str_contains($app, '_captureStartVoice(true)')
    && str_contains($app, "_captureVoice = { active: false, text: '' };"));
// The recogniser must hear the language the user SPEAKS: an English UI used to make an
// Italian sentence unrecognisable even though the parser understands all six languages.
$langSrc = extract_fn($app, '_captureSpeechLang');
check('_captureSpeechLang() is extractable', $langSrc !== '');
if ($langSrc !== '') {
    $langHarness = "globalThis._SUPPORTED_LANGS={it:1,en:1,de:1,fr:1,es:1,zh:1};
"
        . "globalThis._collationLocale=()=>'en-GB';
"
        . "globalThis._currentLang='en';
"
        . $langSrc . "
"
        . "const f=_captureSpeechLang;
"
        . "const out={uiEnDeviceIt:f('it-IT')};
"
        . "globalThis._currentLang='it';
"
        . "out.uiItDeviceDe=f('de-DE'); out.uiItDeviceRu=f('ru-RU'); out.uiItDeviceZh=f('zh-CN'); out.uiItNoDevice=f('');
"
        . "console.log(JSON.stringify(out));";
    $tmpL = sys_get_temp_dir() . '/speechlang_' . getmypid() . '.js';
    file_put_contents($tmpL, $langHarness);
    $rawL = shell_exec('node ' . escapeshellarg($tmpL) . ' 2>/dev/null');
    @unlink($tmpL);
    $L = json_decode(trim((string)$rawL), true);
    check('the speech language picks the device language', is_array($L), (string)$rawL);
    if (is_array($L)) {
        check('an English UI still listens in Italian', ($L['uiEnDeviceIt'] ?? '') === 'it-IT', json_encode($L));
        check('a German device listens in German', ($L['uiItDeviceDe'] ?? '') === 'de-DE', json_encode($L));
        check('a Chinese device listens in Chinese', ($L['uiItDeviceZh'] ?? '') === 'zh-CN', json_encode($L));
        check('an unsupported device language falls back to the interface', ($L['uiItDeviceRu'] ?? '') === 'it-IT', json_encode($L));
    }
}

check('an interim transcript counts when the browser never finalises',
    str_contains($app, 'const said = (finalText || interimText).trim();')
    && str_contains($app, 'if (said) { finish(said); return; }')
    && str_contains($app, 'if (interim.trim()) interimText = interim.trim();'));
check('opening the sheet does not open the soft keyboard',
    str_contains($app, 'input.blur();')
    && !preg_match('/function _openCaptureSheet\(\)[\s\S]{0,1200}input\.focus\(\)/', $app));
check('voice feedback is spoken only for dictated sentences',
    str_contains($app, 'function _captureSpeak(')
    && str_contains($app, '_captureSubmit(said, { voice: true })')
    && str_contains($app, 'if (fromVoice) _captureSpeak(_captureVoiceSay(parsed));'));
check('the speaker toggle is remembered per device',
    str_contains($app, "localStorage.setItem('evershelf_capture_voice'")
    && str_contains($html, 'id="capture-voice-toggle"'));
check('"Cosa posso dire" lists the whole lexicon',
    str_contains($html, 'id="capture-examples-btn"')
    && str_contains($app, 'function _captureRenderExamples(')
    && str_contains($app, "'capture.example_use_grams'"));

// ── Round 7: the mic really has an animated border ───────────────────────────
$css = (string)file_get_contents($root . '/assets/css/surfaces.css');
check('the mic sits in a wrapper so the border can be drawn outside it',
    str_contains($html, 'class="capture-mic-wrap" id="capture-mic-wrap"')
    && str_contains($css, '.capture-mic-wrap::before {')
    && str_contains($css, '.capture-mic-wrap .capture-mic {'));
check('the border is a ring that turns (not a static edge)',
    str_contains($css, 'conic-gradient(')
    && str_contains($css, '@keyframes capture-ring-turn { to { transform: rotate(1turn); } }')
    && str_contains($css, 'animation: capture-ring-turn 3.6s linear infinite;')
    && str_contains($css, 'mask: radial-gradient(farthest-side'));
check('a halo breathes behind the button', str_contains($css, '@keyframes capture-mic-halo'));
check('recording makes the ring turn faster',
    str_contains($css, '.capture-mic-wrap.is-listening::before { animation-duration: 1.1s; }')
    && str_contains($app, "if (wrap) wrap.classList.toggle('is-listening', on);"));
check('the ring is hidden with the button and still without motion',
    str_contains($app, 'if (wrap) wrap.hidden = !Ctor;')
    && preg_match('/prefers-reduced-motion: reduce\) \{\s*\.capture-mic-wrap::before \{ animation: none;/s', $css) === 1);

// ── Round 6: the capture speaks with the SAME voice as the cooking steps ─────
check('the capture uses the app voice, not a second engine',
    str_contains($app, '_speakBrowser(String(text), { lang: _collationLocale() })')
    && !preg_match('/function _captureSpeak\([\s\S]{0,700}new SpeechSynthesisUtterance/', $app));
check('the voice is chosen for the interface language (no hardcoded Italian)',
    str_contains($app, 'function _pickTtsVoice(') && !str_contains($app, "startsWith('it')"));
check('closing the sheet does not cut the spoken confirmation',
    !preg_match('/function _captureVoiceStop\([\s\S]{0,900}speechSynthesis\.cancel/', $app));

// The picker itself, in node, with a fake voice list covering all six languages.
$pickSrc = extract_fn($app, '_pickTtsVoice');
check('_pickTtsVoice() is extractable', $pickSrc !== '');
$voiceHarness = $pickSrc . <<<'JS'
const V = [
    { name: 'Alice IT', lang: 'it-IT', localService: true },
    { name: 'Anna DE', lang: 'de-DE', localService: true },
    { name: 'Amelie FR', lang: 'fr-FR', localService: false },
    { name: 'Tingting ZH', lang: 'zh-CN', localService: true },
    { name: 'Daniel EN', lang: 'en-GB', localService: true },
];
const pick = (lang, sel) => { const v = _pickTtsVoice(V, lang, sel); return v ? v.lang : null; };
console.log(JSON.stringify({
    it: pick('it-IT', ''), de: pick('de-DE', ''), fr: pick('fr-FR', ''),
    zh: pick('zh-CN', ''), en: pick('en-GB', ''), es: pick('es-ES', ''),
    mismatch: pick('fr-FR', 'Anna DE'), match: pick('de-DE', 'Anna DE'),
    empty: _pickTtsVoice([], 'it-IT', '') === null,
}));
JS;
$tmpVoice = sys_get_temp_dir() . '/voice_' . getmypid() . '.js';
file_put_contents($tmpVoice, $voiceHarness);
$rawVoice = shell_exec('node ' . escapeshellarg($tmpVoice) . ' 2>/dev/null');
@unlink($tmpVoice);
$voice = json_decode(trim((string)$rawVoice), true);
check('the voice picker runs in node', is_array($voice), (string)$rawVoice);
if (is_array($voice)) {
    check('Italian text gets an Italian voice', ($voice['it'] ?? '') === 'it-IT', json_encode($voice));
    check('German text gets a German voice', ($voice['de'] ?? '') === 'de-DE', json_encode($voice));
    check('French text gets a French voice', ($voice['fr'] ?? '') === 'fr-FR', json_encode($voice));
    check('Chinese text gets a Chinese voice', ($voice['zh'] ?? '') === 'zh-CN', json_encode($voice));
    check('English text gets an English voice', ($voice['en'] ?? '') === 'en-GB', json_encode($voice));
    check('a missing language falls back to an offline voice', ($voice['es'] ?? '') !== null, json_encode($voice));
    check('a Settings voice that speaks another language is not used', ($voice['mismatch'] ?? '') === 'fr-FR', json_encode($voice));
    check('a Settings voice for the right language wins', ($voice['match'] ?? '') === 'de-DE', json_encode($voice));
    check('no voice at all is null, not a crash', ($voice['empty'] ?? false) === true);
}

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
check('the recogniser starts inside the gesture, before any meter/await',
    !str_contains($app, 'await _captureStartMeter()')
    && str_contains($app, '_captureRec.start();')
    // The meter is a second microphone client: it starts on the first result.
    && str_contains($app, 'if (!_captureMeterStarted) { _captureMeterStarted = true; _captureStartMeter(); }'));
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
