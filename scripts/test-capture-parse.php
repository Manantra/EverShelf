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
// The parser reads its lexicon from the vocabulary DATA, so node gets the generated
// vocabulary + the browser engine + the matchers, never a copy of the words.
$matchers = '';
foreach ([
    '_captureWordRe', '_captureIntentWords', '_captureHasWord', '_captureHasUnit',
    '_captureStripWord', '_captureMatchIntent', '_captureUnitInfo', '_captureFindUnit',
    '_captureFractions', '_captureAllWords',
] as $fnName) {
    $matchers .= extract_fn($app, $fnName) . "\n";
}
check('the parser and its matchers are extractable', $src !== '' && $matchers !== '');

/** The browser vocabulary (generated data + engine), as node sees it. */
function vocab_prelude(string $root): string
{
    return (string)file_get_contents($root . '/assets/js/vocab.generated.js') . "\n"
        . "let _currentLang = 'it';\n"
        . (string)file_get_contents($root . '/assets/js/core/vocab.js') . "\n";
}

// The sentences are DATA too: one per intent and language, plus quantities, fractions,
// units and "all of it" — scripts/fixtures/capture-cases.json, never a list in this file.
$fixture = json_decode((string)file_get_contents($root . '/scripts/fixtures/capture-cases.json'), true);
$cases = [];
foreach ((array)($fixture['cases'] ?? []) as $row) {
    if (!is_array($row) || !isset($row['text'])) {
        continue;
    }
    // [sentence, intent, product, qty, unit]
    $cases[] = [
        (string)$row['text'],
        (string)($row['intent'] ?? ''),
        (string)($row['product'] ?? ''),
        $row['qty'] ?? 1,
        (string)($row['unit'] ?? ''),
    ];
}
check('the parser fixtures are DATA (scripts/fixtures/capture-cases.json)',
    count($cases) >= 30, count($cases) . ' case(s)');
$jsCases = 'const cases = ' . json_encode(array_map(static fn($c) => [$c[0]], $cases), JSON_UNESCAPED_UNICODE) . ";\n";
$harness = vocab_prelude($root) . $matchers . $src . $jsCases . <<<'JS'
const out = {};
['it', 'en', 'de', 'fr', 'es', 'zh'].forEach(lang => {
    _currentLang = lang;
    out[lang] = cases.map(([text]) => _captureParse(text));
});
console.log(JSON.stringify(out));
JS;

$tmp = sys_get_temp_dir() . '/capture_' . getmypid() . '.js';
file_put_contents($tmp, $harness);
$raw = shell_exec('node ' . escapeshellarg($tmp) . ' 2>/dev/null');
@unlink($tmp);
$matrix = json_decode(trim((string)$raw), true);
check('the parser runs in node', is_array($matrix), (string)$raw);
if (is_array($matrix)) {
    check('every interface language gets the same merged lexicon',
        count($matrix) === 6, json_encode(array_keys($matrix)));
    $base = $matrix['it'] ?? [];
    check('every case returns a row', is_array($base) && count($base) === count($cases), json_encode($base));
    foreach ($cases as $i => [$text, $intent, $product, $qty, $unit]) {
        $row = is_array($base) ? ($base[$i] ?? []) : [];
        check("case {$i} ({$text}): intent {$intent}", ($row['intent'] ?? '') === $intent, json_encode($row));
        if ($product !== '') {
            check("case {$i} ({$text}): product {$product}", ($row['product'] ?? '') === $product, json_encode($row));
        }
        check("case {$i} ({$text}): qty {$qty}", (float)($row['qty'] ?? 0) === (float)$qty, json_encode($row));
        if ($unit !== '') {
            check("case {$i} ({$text}): unit {$unit}", ($row['unit'] ?? '') === $unit, json_encode($row));
        }
    }
    foreach (['en', 'de', 'fr', 'es', 'zh'] as $loc) {
        check("{$loc}: a sentence parses exactly as it does on an Italian UI",
            json_encode($matrix[$loc] ?? null) === json_encode($base));
    }
}

// ── Round 11: a sentence with no product is not an action ────────────────────
// "Ho usato" alone used to fall through to the add form of an empty name: a panel for
// a product that does not exist. It has to say "not understood" and stay open.
$actSrc = extract_fn($app, '_captureIsActionable');
check('_captureIsActionable() is extractable', $actSrc !== '');
$submitSrc = extract_fn($app, '_captureSubmit');
$guardPos = strpos($submitSrc, 'if (!_captureIsActionable(parsed)) {');
$notUnderstoodPos = strpos($submitSrc, '_captureNotUnderstood(');
check('_captureSubmit() refuses an empty action before opening anything',
    str_contains($app, "if (parsed.intent === 'recipe') return true;")
    && $guardPos !== false
    && ($notUnderstoodPos === false || $guardPos < $notUnderstoodPos));
check('the sheet never claims "I understood" above "not understood"',
    // The chips ARE the interpretation: when there is nothing to interpret they hide, and
    // the message says it did not understand. One helper owns both halves.
    preg_match('/function _captureNotUnderstood\([^)]*\) \{[\s\S]{0,400}capture-understood[\s\S]{0,200}_captureStatus/', $app) === 1
    && str_contains($app, '_captureShowUnderstood(parsed);')
    && str_contains($app, "_captureNotUnderstood('capture.not_understood');")
    && substr_count($app, "_captureNotUnderstood('capture.no_product'") >= 2);
// The report: "se non capisce non deve aprire «Nuovo prodotto», deve scrivere che non ha
// capito". Creating an article is the user's decision, never the end of a wrong parse.
$submitBody = extract_fn($app, '_captureSubmit');
$openAddBody = extract_fn($app, '_shelfyOpenAdd');
check('a spoken sentence never opens the "new product" form',
    $submitBody !== '' && $openAddBody !== ''
    && !str_contains($submitBody, 'startManualEntry(')
    && !str_contains($openAddBody, 'startManualEntry(')
    && substr_count($submitBody . $openAddBody, '_captureNotUnderstood(') >= 2);
if ($actSrc !== '') {
    $actHarness = vocab_prelude($root) . $matchers . $src . $actSrc . PHP_EOL . <<<'JS'
const probes = ['ho usato', 'ho finito', '', 'ho usato 200 grammi di pasta', 'aggiungi il latte', 'cosa cucino con le uova'];
console.log(JSON.stringify(probes.map(t => _captureIsActionable(_captureParse(t)))));
JS;
    $tmpA = sys_get_temp_dir() . '/actionable_' . getmypid() . '.js';
    file_put_contents($tmpA, $actHarness);
    $rawA = shell_exec('node ' . escapeshellarg($tmpA) . ' 2>/dev/null');
    @unlink($tmpA);
    $act = json_decode(trim((string)$rawA), true);
    check('the actionable probe answers', is_array($act), (string)$rawA);
    if (is_array($act)) {
        check('a bare intent is not actionable', ($act[0] ?? true) === false && ($act[1] ?? true) === false, json_encode($act));
        check('an empty sentence is not actionable', ($act[2] ?? true) === false, json_encode($act));
        check('a spoken product makes it actionable', ($act[3] ?? false) === true && ($act[4] ?? false) === true, json_encode($act));
        check('a recipe question is always actionable', ($act[5] ?? false) === true, json_encode($act));
    }
}

// ── Round 5 / 10: fractions, units and "all of it" come from the DATA ─────
$captureByLang = [];
foreach (['it', 'en', 'de', 'fr', 'es', 'zh'] as $loc) {
    $doc = json_decode((string)file_get_contents($root . "/api/vocab/{$loc}.json"), true);
    $captureByLang[$loc] = (array)($doc['capture'] ?? []);
    $cap = $captureByLang[$loc];
    $locale = (array)($doc['locale'] ?? []);
    $intents = array_filter(['recipe', 'throw', 'consume', 'add'],
        static fn($k) => !empty($cap['intent'][$k]));
    check("api/vocab/{$loc}.json carries the whole capture schema",
        count($intents) === 4
        && !empty($cap['numbers'])
        && !empty($cap['units'])
        && !empty($cap['fractions'])
        && !empty($cap['all_words'])
        && !empty($cap['stop_words'])
        // The recogniser tag is language metadata: one place per language, not per feature.
        && !isset($cap['speech_tag'])
        && preg_match('/^[a-z]{2}-[A-Z]{2}$/', (string)($locale['speech_tag'] ?? '')) === 1,
        json_encode(array_keys($cap)) . ' / ' . json_encode(array_keys($locale)));
}
$itVocab = json_decode((string)file_get_contents($root . '/api/vocab/it.json'), true);
$itCapture = $captureByLang['it'];
$enCapture = $captureByLang['en'];
$deCapture = $captureByLang['de'];
$esCapture = $captureByLang['es'];
check('fractions are quantities in the data',
    in_array(['mezza', 0.5], (array)($itCapture['fractions'] ?? []), true)
    && str_contains($app, 'function _captureFractions('));
check('spoken units have one canonical form in the data',
    (($itCapture['units']['gr'][0] ?? '') === 'g')
    && (($itCapture['units']['etti'][1] ?? 0) === 100)
    && str_contains($app, 'function _captureUnitInfo('));
check('"ho finito tutti" sets use_all from the data',
    in_array('tutti', (array)($itCapture['all_words'] ?? []), true)
    && str_contains($app, 'out.use_all = true'));
check('the whole lexicon is data, none of it is hardcoded in app.js',
    !str_contains($app, 'const _CAPTURE_INTENT_WORDS')
    && !str_contains($app, 'const _CAPTURE_NUMBER_WORDS')
    && !str_contains($app, 'const _CAPTURE_FRACTIONS')
    && !str_contains($app, 'const _CAPTURE_UNIT_ALIASES')
    && !str_contains($app, 'const _CAPTURE_ALL_WORDS')
    && !str_contains($app, 'const _CAPTURE_STOP')
    && str_contains($app, 'vocabCaptureLexicon()'));
check('a dictated quantity reaches the USE panel',
    str_contains($app, 'function _captureQtyFor(')
    && str_contains($app, 'async function _shelfyRunConsume(')
    && str_contains($app, "const qtyEl = document.getElementById('use-quantity');"));
check('"ho finito" opens the USE panel, where the tap confirms',
    str_contains($app, "t('capture.use_all_hint'")
    && !str_contains($app, "document.querySelector('#page-use .use-all-btn')"));

// ── Round 8: SHELFY, the named assistant ────────────────────────────────────
check('the assistant has a name', str_contains($html, 'data-i18n="capture.title">SHELFY')
    && str_contains($app, 'const SHELFY_INTRO_KEY'));
check('it introduces itself once per device',
    str_contains($html, 'id="shelfy-intro"')
    && str_contains($app, "localStorage.getItem(SHELFY_INTRO_KEY)")
    && str_contains($app, 'if (!_shelfyIntroSeen()) { _shelfyOpenIntro(); return; }')
    && str_contains($html, 'id="shelfy-help-btn"'));
check('the USE confirm drains for 10 s and then acts (SHELFY auto-confirms)',
    str_contains($app, "const confirmBtn = document.getElementById('btn-use-submit');")
    && str_contains($app, '_shelfyArmButton(confirmBtn, 10000, () => submitUse({ preventDefault() {} }));')
    && str_contains($app, 'function _shelfyArmButton(')
    && str_contains((string)file_get_contents($root . '/assets/css/surfaces.css'), 'animation: shelfy-drain var(--shelfy-ms, 10s) linear forwards;'));
check('adding and "use it all" still wait for the user',
    !str_contains($app, "_shelfyArmButton(document.getElementById('btn-add-submit')")
    && !str_contains($app, "document.querySelector('#page-use .use-all-btn')")
    && str_contains($app, "t('capture.use_all_hint'"));
check('only one countdown runs on the USE confirm (no double progress bar)',
    preg_match('/function _shelfyRunConsume[\s\S]*?clearUseFormIdleCountdown\(\);[\s\S]*?_shelfyArmButton\(confirmBtn, 10000/', $app) === 1);
check('any touch stops it',
    str_contains($app, "document.addEventListener('pointerdown', stop, true)")
    && str_contains($app, "document.addEventListener('keydown', stop, true)")
    && str_contains($app, 'function _shelfyDisarm('));
check('four candidates, and the closest wins after ten seconds',
    str_contains($app, '.slice(0, 4);')
    && str_contains($app, '_shelfyArmButton(best, 10000')
    && str_contains($app, "t('shelfy.pick_auto'"));
check('a derived article is not a confident match',
    str_contains($app, 'POSITION MATTERS')
    && str_contains($app, "if (_captureWordRe(b, 'i').test(a)) return 0.55;")
    && str_contains($app, 'const confident = !!best && best.score >= 0.85;')
    && str_contains($app, 'const uncertain = !!best && rows.length > 1 && (best.score - rows[1].score) < 0.08;'));
check('eaten and drunk are consumption too',
    // One vocabulary, six languages: the words live in api/vocab/<lang>.json.
    in_array('mangiato', (array)($itCapture['intent']['consume'] ?? []), true)
    && in_array('bevuto', (array)($itCapture['intent']['consume'] ?? []), true)
    && in_array('drunk', (array)($enCapture['intent']['consume'] ?? []), true)
    && in_array('getrunken', (array)($deCapture['intent']['consume'] ?? []), true)
    && in_array('bebido', (array)($esCapture['intent']['consume'] ?? []), true));
check('the candidates show picture, name, brand, place',
    str_contains($app, 'class="shelfy-pick')
    && str_contains($app, '_validProductImageUrl(item.image_url)')
    && str_contains($app, 'class="shelfy-pick-meta"'));
// The add form speaks pz/conf/g/ml only: a dictated litre must arrive as 1000 ml.
$unitSrc = extract_fn($app, '_shelfyAddUnit');
check('_shelfyAddUnit() is extractable', $unitSrc !== '');
if ($unitSrc !== '') {
    $unitHarness = vocab_prelude($root)
        . extract_fn($app, '_captureUnitInfo') . "\n"
        . $unitSrc . <<<'JS'
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
    check("translations/{$loc}.json has the presentation and the pick copy",
        isset($doc['shelfy']['intro_title'], $doc['shelfy']['intro_start'], $doc['shelfy']['pick_auto'])
        && str_contains((string)$doc['shelfy']['pick_auto'], '{name}'));
}

// ── Round 9/11: ONE session per tap, and it is not cut at the first pause ────
// `continuous = false` made the browser finalise "Ho usato" at the first pause and the
// old `onresult` submitted that chunk, closing the sheet mid-sentence (and the restart
// loop before it beeped). One session now runs until the user is silent.
check('the recogniser runs one session per tap, and only our silence deadline submits',
    str_contains($app, '_captureRec.continuous = true;')
    && preg_match('/const _CAPTURE_SILENCE_MS = (\d+);/', $app, $silenceNow) === 1
    && (int)$silenceNow[1] >= 2000
    && str_contains($app, 'const armSilenceStop = () => {')
    && str_contains($app, '_captureVoice.stopRequested = true;')
    && !str_contains($app, '_CAPTURE_VOICE_MAX_RESTARTS')
    && !str_contains($app, '_captureStartVoice(true)')
    && str_contains($app, "_captureVoice = { active: false, text: '', timer: 0, stopRequested: false };"));
// The report: "si mangia sempre l'ultima parola". Chrome on Android closes the session at
// the first final phrase even with `continuous = true`; submitting on that `onend` sent
// "Ho usato 2" for "Ho usato 2 arance". A browser-ended session is now RESUMED (bounded),
// and only the session our own deadline stopped hands the sentence over.
check('a session the browser ends mid-sentence is resumed instead of submitted',
    preg_match('/const _CAPTURE_RESTARTS_MAX = (\d+);/', $app, $resumeMax) === 1
    && (int)($resumeMax[1] ?? 0) >= 1
    && str_contains($app, 'const deadlineReached = _captureVoice.stopRequested;')
    && str_contains($app, 'if (!deadlineReached && said && restarts < _CAPTURE_RESTARTS_MAX) {')
    && str_contains($app, 'const startSession = () => {'));
check('a final phrase never submits: only onend hands the sentence over',
    preg_match('/_captureRec\.onresult = \(ev\) => \{[\s\S]*?\n        \};/', $app, $onres) === 1
    && !str_contains($onres[0] ?? '', 'finish(')
    && str_contains($app, 'if (said) { finish(said); return; }'));

// ── The transcript join: no doubled sentence, no eaten word ──────────────────
// Chrome capitalises the interim result and lowercases the final one, so comparing the
// RAW strings duplicated the phrase ("Ho mangiato ho mangiato una mela"); comparing single
// characters could match one letter and swallow the start of a word ("aggiungi il latte"
// arrived as "aggiungi il atte"). The join is now word-level and case-insensitive.
$joinSrc = extract_fn($app, '_captureJoinText');
check('_captureJoinText() is extractable', $joinSrc !== '');
if ($joinSrc !== '') {
    $joinHarness = $joinSrc . <<<'JS'
const T = [
    ['Ho mangiato', 'ho mangiato una mela'],
    ['Ho mangiato', 'ho mangiato'],
    ['aggiungi il', 'latte'],
    ['Aggiungi il la', 'latte'],
    ['ho consumato', '250 grammi di pasta'],
    ['ho consumato', 'ho consumato 250'],
    ['ho usato una', 'ho usato una banana'],
    ['aggiungi il latte', 'latte'],
    ['Ho usato 2', '2 arance'],
];
console.log(JSON.stringify(T.map(([a, b]) => _captureJoinText(a, b))));
JS;
    $tmpJ = sys_get_temp_dir() . '/join_' . getmypid() . '.js';
    file_put_contents($tmpJ, $joinHarness);
    $rawJ = shell_exec('node ' . escapeshellarg($tmpJ) . ' 2>/dev/null');
    @unlink($tmpJ);
    $joined = json_decode(trim((string)$rawJ), true);
    check('the transcript join runs in node', is_array($joined), (string)$rawJ);
    if (is_array($joined)) {
        check('a capitalised interim and a lowercase final are ONE sentence',
            ($joined[0] ?? '') === 'ho mangiato una mela' && ($joined[1] ?? '') === 'ho mangiato',
            json_encode($joined));
        check('the last word survives ("aggiungi il" + "latte")',
            ($joined[2] ?? '') === 'aggiungi il latte' && ($joined[3] ?? '') === 'Aggiungi il latte',
            json_encode($joined));
        check('a restarted phrase is stitched once, never twice',
            ($joined[4] ?? '') === 'ho consumato 250 grammi di pasta'
            && ($joined[5] ?? '') === 'ho consumato 250'
            && ($joined[6] ?? '') === 'ho usato una banana', json_encode($joined));
        check('a shorter echo never erases the whole sentence',
            ($joined[7] ?? '') === 'aggiungi il latte', json_encode($joined));
        check('a repeated number is one quantity, not two',
            ($joined[8] ?? '') === 'Ho usato 2 arance', json_encode($joined));
    }
}
check('the silence timer ends the session and closing clears it',
    str_contains($app, '_captureVoice.timer = setTimeout(() => {')
    && preg_match('/function _captureVoiceStop\(\) \{[\s\S]{0,400}clearTimeout\(_captureVoice\.timer\)/', $app) === 1);
// The recogniser must hear the language the user SPEAKS: an English UI used to make an
// Italian sentence unrecognisable even though the parser understands all six languages.
$langSrc = extract_fn($app, '_captureSpeechLang');
check('_captureSpeechLang() is extractable', $langSrc !== '');
if ($langSrc !== '') {
    $langHarness = vocab_prelude($root)
        . "globalThis._SUPPORTED_LANGS={it:1,en:1,de:1,fr:1,es:1,zh:1};\n"
        . "globalThis._collationLocale=()=>'en-GB';\n"
        . "_currentLang='en';\n"
        . $langSrc . "\n"
        . "const f=_captureSpeechLang;\n"
        . "const out={uiEnDeviceIt:f('it-IT')};\n"
        . "_currentLang='it';\n"
        . "out.uiItDeviceDe=f('de-DE'); out.uiItDeviceRu=f('ru-RU'); out.uiItDeviceZh=f('zh-CN'); out.uiItNoDevice=f('');\n"
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

// ── Round 10: closing the sheet and starting the microphone must always work ──
// A refactor deleted _captureVoiceStop() while three callers kept calling it: the
// ReferenceError stopped _closeCaptureSheet() at its first line (the dialog stayed on
// screen) and _captureStartVoice() at its first line (nothing was ever heard, the mic
// never filled). Both are locked here.
preg_match_all('/\b(_capture[A-Za-z]+)\s*\(/', $app, $captureCalls);
$undefinedCapture = [];
foreach (array_unique($captureCalls[1] ?? []) as $fnName) {
    if (!str_contains($app, 'function ' . $fnName . '(')) {
        $undefinedCapture[] = $fnName;
    }
}
check('every _capture*() call has a definition', $undefinedCapture === [], implode(', ', $undefinedCapture));
check('the voice session has one stop function',
    str_contains($app, 'function _captureVoiceStop(')
    && substr_count($app, '_captureVoiceStop()') >= 3);
check('closing the sheet hides it before the cleanup runs',
    preg_match('/function _closeCaptureSheet\(\)\s*\{[^}]*display = \'none\';[^}]*_shelfyDisarm\(\);[^}]*_captureVoiceStop\(\);/s', $app) === 1);
check('opening the sheet starts listening by itself',
    preg_match('/function _openCaptureSheet\(\)[\s\S]{0,1600}_captureStartVoice\(\);/', $app) === 1);

check('an interim transcript counts when the browser never finalises',
    str_contains($app, "let heard = '';")
    && str_contains($app, 'heard = _captureJoinText(heard, eventText);')
    && str_contains($app, 'const said = heard.trim();')
    && str_contains($app, 'if (said) { finish(said); return; }'));
check('opening the sheet does not open the soft keyboard',
    str_contains($app, 'input.blur();')
    && !preg_match('/function _openCaptureSheet\(\)[\s\S]{0,1200}input\.focus\(\)/', $app));
check('the agent has no voice of its own any more',
    !str_contains($app, '_captureSpeak')
    && !str_contains($app, '_captureVoiceSay')
    && !str_contains($html, 'capture-voice-toggle')
    && !str_contains($html, 'capture-voice-toggle'));
check('the recipes keep their voice',
    str_contains($app, 'function _speakBrowser(')
    && str_contains($app, 'function speakCookingStep('));
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
// The sentence SHELFY heard is what the user checks before confirming an action: it must be
// the largest text in the sheet, not a 15 px footnote in italics.
check('the transcript is big and evident (highlighted, 1 rem+, semibold)',
    preg_match('/\.capture-transcript \{([^}]*)\}/', $css, $trCss) === 1
    && preg_match('/font-size:\s*([\d.]+)rem/', $trCss[1] ?? '', $trSize) === 1
    && (float)($trSize[1] ?? 0) >= 1.0
    && preg_match('/font-weight:\s*(\d{3})/', $trCss[1] ?? '', $trWeight) === 1
    && (int)($trWeight[1] ?? 0) >= 600
    && str_contains($trCss[1] ?? '', 'background: var(--brand-surface);')
    && preg_match('/\.capture-transcript\.is-final \{[^}]*font-weight: (\d{3})/', $css, $trFinal) === 1
    && (int)($trFinal[1] ?? 0) >= 600,
    json_encode($trCss[1] ?? ''));

// ── Round 6: the capture speaks with the SAME voice as the cooking steps ─────
check('the capture never speaks (the recipes do)',
    !str_contains($app, '_captureSpeak')
    && str_contains($app, 'function _speakBrowser('));
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
    in_array('cosa cucino', (array)($captureByLang['it']['intent']['recipe'] ?? []), true)
    && in_array('recipe', (array)($captureByLang['en']['intent']['recipe'] ?? []), true)
    && in_array('rezept', (array)($captureByLang['de']['intent']['recipe'] ?? []), true)
    && in_array('recette', (array)($captureByLang['fr']['intent']['recipe'] ?? []), true)
    && in_array('receta', (array)($captureByLang['es']['intent']['recipe'] ?? []), true)
    && in_array('食谱', (array)($captureByLang['zh']['intent']['recipe'] ?? []), true));

$score = extract_fn($app, '_captureScoreName');
check('_captureScoreName() is extractable', $score !== '');
if ($score !== '') {
    $jsScore = extract_fn($app, '_captureWordRe') . "\n" . $score . <<<'JS'
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

// ── Round 11: the real voice loop, driven by a fake recogniser ───────────────
// A browser finalises a phrase at the first short pause. The old loop submitted that
// first final, so "Ho usato 200 grammi di pasta" arrived as "Ho usato" and the sheet
// closed. Here the real _captureStartVoice() runs against a fake API that emits exactly
// that sequence: nothing may be submitted until the silence timer stops the session.
$voiceSrc = extract_fn($app, '_captureStartVoice');
$stopSrc  = extract_fn($app, '_captureVoiceStop');
preg_match('/const _CAPTURE_SILENCE_MS = (\d+);/', $app, $silenceM);
preg_match('/const _CAPTURE_RESTARTS_MAX = (\d+);/', $app, $restartsM);
check('the voice loop is extractable',
    $voiceSrc !== '' && $stopSrc !== '' && !empty($silenceM[1]) && !empty($restartsM[1]),
    json_encode([$silenceM, $restartsM]));
if ($voiceSrc !== '' && $stopSrc !== '' && !empty($silenceM[1]) && !empty($restartsM[1])) {
    $voiceHarness = "const _CAPTURE_SILENCE_MS = " . $silenceM[1] . ";\n"
        . "const _CAPTURE_RESTARTS_MAX = " . $restartsM[1] . ";\n"
        . "let _captureRec = null;\nlet _captureMeterStarted = false;\n"
        . "let _captureVoice = { active: false, text: '', timer: 0, stopRequested: false };\n"
        . "const submitted = [];\n"
        . "const _statuses = [];\n"
        . "globalThis.document = { getElementById: () => null };\n"
        . "const _captureStatus = (k) => { _statuses.push(k); };\n"
        . "const _captureShowTranscript = () => {};\n"
        . "const _captureShowChoices = () => {};\n"
        . "const _captureSetState = () => {};\n"
        . "const _captureStartMeter = () => {};\n"
        . "const _captureStopMeter = () => {};\n"
        . "const _captureSpeechLang = () => 'it-IT';\n"
        . "const _captureSubmit = (text) => { submitted.push(text); };\n"
        . extract_fn($app, '_captureJoinText') . "\n"
        . $stopSrc . "\n" . $voiceSrc . "\n"
        . <<<'JS'
class FakeRec {
    constructor() { FakeRec.last = this; FakeRec.started = false; }
    start() { FakeRec.started = true; }
    stop() { const self = this; setTimeout(() => { if (self.onend) self.onend(); }, 0); }
    // The BROWSER closing the session on its own (Chrome on Android, first final phrase):
    // no stop(), just onend. This is the shape that used to cut the last word.
    endByBrowser() { const self = this; setTimeout(() => { if (self.onend) self.onend(); }, 0); }
    // The Web Speech API hands the WHOLE session list on every event, never just the new
    // chunk: that is what made the old append duplicate "ho ho ho usato…".
    emit(list) {
        const results = list.map(([text, isFinal]) => Object.assign([{ transcript: text }], { isFinal: isFinal }));
        if (this.onresult) this.onresult({ resultIndex: 0, results: results });
    }
}
window = { SpeechRecognition: FakeRec };
async function runSession(events) {
    submitted.length = 0;
    await _captureStartVoice();
    const rec = FakeRec.last;
    const afterEachEvent = [];
    for (const ev of events) {
        rec.emit(ev);
        afterEachEvent.push(submitted.slice());
    }
    await new Promise(r => setTimeout(r, _CAPTURE_SILENCE_MS + 300));
    return { afterEachEvent: afterEachEvent, submitted: submitted.slice() };
}
// "Ho usato 2 arance" on a browser that closes the session after "Ho usato 2".
async function runBrowserEnd() {
    submitted.length = 0;
    await _captureStartVoice();
    const first = FakeRec.last;
    first.emit([['Ho usato 2', true]]);
    first.endByBrowser();
    await new Promise(r => setTimeout(r, 60));
    const resumed = FakeRec.last !== first;
    const afterEnd = submitted.slice();
    FakeRec.last.emit([['arance', true]]);
    await new Promise(r => setTimeout(r, _CAPTURE_SILENCE_MS + 300));
    return { resumed: resumed, afterEnd: afterEnd, submitted: submitted.slice() };
}
(async () => {
    const cut = await runSession([
        [['Ho usato', true]],
        [['Ho usato', true], ['200 grammi di pasta', true]]
    ]);
    const repeated = await runSession([
        [['ho usato una', false]],
        [['ho usato una', false]],
        [['ho usato una banana', false]],
        [['ho usato una banana', true]],
        [['ho usato una banana', true]]
    ]);
    // Chrome on Android restarts the list at every phrase, and the new phrase is cumulative:
    // this is the "ho consumato ho consumato ho consumato 250 …" report.
    const resetCumulative = await runSession([
        [['ho consumato', true]],
        [['ho consumato 250', false]],
        [['ho consumato 250 grammi', false]],
        [['ho consumato 250 grammi di pasta', true]]
    ]);
    // The same reset with a phrase that does NOT repeat the beginning.
    const resetPieces = await runSession([
        [['ho consumato', true]],
        [['250 grammi di pasta', true]]
    ]);
    // The browser closes the session mid-sentence: resume, never submit the stub.
    const browserEnd = await runBrowserEnd();
    // And the same with a capitalised interim, the shape that duplicated the sentence.
    const mixedCase = await runSession([
        [['Ho mangiato', true]],
        [['ho mangiato una mela', true]]
    ]);
    console.log(JSON.stringify({
        cut: cut, repeated: repeated, resetCumulative: resetCumulative,
        resetPieces: resetPieces, browserEnd: browserEnd, mixedCase: mixedCase,
        started: FakeRec.started
    }));
})();
JS;
    $tmpV = sys_get_temp_dir() . '/voice_' . getmypid() . '.js';
    file_put_contents($tmpV, $voiceHarness);
    $rawV = shell_exec('node ' . escapeshellarg($tmpV) . ' 2>/dev/null');
    @unlink($tmpV);
    $voice = json_decode(trim((string)$rawV), true);
    check('the voice loop runs against a fake recogniser', is_array($voice), (string)$rawV);
    if (is_array($voice)) {
        check('the first final phrase is NOT submitted (the sentence is still going)',
            ($voice['cut']['afterEachEvent'][0] ?? null) === [], json_encode($voice['cut']['afterEachEvent'][0] ?? null));
        check('the whole sentence is submitted once, when the user stops talking',
            ($voice['cut']['submitted'] ?? []) === ['Ho usato 200 grammi di pasta'], json_encode($voice['cut']['submitted'] ?? null));
        check('re-delivered results are not appended twice ("ho ho ho usato…")',
            ($voice['repeated']['submitted'] ?? []) === ['ho usato una banana'], json_encode($voice['repeated']['submitted'] ?? null));
        check('no event submits while the user is still talking',
            array_filter((array)($voice['repeated']['afterEachEvent'] ?? [null])) === [],
            json_encode($voice['repeated']['afterEachEvent'] ?? null));
        check('a restarted result list is not stitched twice (the "ho consumato ho consumato" report)',
            ($voice['resetCumulative']['submitted'] ?? []) === ['ho consumato 250 grammi di pasta'],
            json_encode($voice['resetCumulative']['submitted'] ?? null));
        check('a restarted list without the beginning is joined once',
            ($voice['resetPieces']['submitted'] ?? []) === ['ho consumato 250 grammi di pasta'],
            json_encode($voice['resetPieces']['submitted'] ?? null));
        check('a session the browser closed mid-sentence is resumed, never submitted as is',
            ($voice['browserEnd']['afterEnd'] ?? null) === []
            && ($voice['browserEnd']['resumed'] ?? false) === true
            && ($voice['browserEnd']['submitted'] ?? []) === ['Ho usato 2 arance'],
            json_encode($voice['browserEnd'] ?? null));
        check('a capitalised interim plus the lowercase final is one sentence, not two',
            ($voice['mixedCase']['submitted'] ?? []) === ['ho mangiato una mela'],
            json_encode($voice['mixedCase']['submitted'] ?? null));
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
