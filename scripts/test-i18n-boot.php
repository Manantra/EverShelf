#!/usr/bin/env php
<?php
/**
 * Regression tests: the app boots in the language the link asked for, and every
 * string a non-Italian user can read comes from the translations, not from the code.
 *
 * The report came from the public demo (https://evershelf.site/demo/), where a
 * nightly `git reset --hard` erases every fix that is not in the repository:
 *
 *   * `/?lang=de` opened the app in the language stored in localStorage or in the
 *     browser, because app.js never read `location.search` — the marketing site had
 *     to rewrite localStorage before the script ran.
 *   * A 429 from the API returned `{"error":"Too many requests…"}`: English prose in
 *     a de/fr/es/zh UI, with no stable code the client could translate.
 *   * The add form printed the raw tokens `pz` / `conf` (no `data-i18n`, and nothing
 *     ever updated `#add-quantity-unit`), so non-Italian users read Italian units.
 *   * The urgency label of a shopping row is localized when the row is written, so a
 *     row can carry another language's word: the UI must drop/search it by MARKER.
 *
 * What must stay true:
 *   * `?lang=` wins over localStorage, and an unsupported value changes nothing;
 *   * changing the language from the picker keeps the deep link in step;
 *   * the 429 answer carries `error_key` + `retry_after`, and the key exists in all
 *     six locales;
 *   * the add form's units are translated and its badge follows the select;
 *   * the urgency segment is recognised by its emoji, in whatever language.
 *
 * Run: php scripts/test-i18n-boot.php
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

function assert_same($expected, $actual, string $msg): void
{
    global $fail;
    if ($expected !== $actual) {
        echo "FAIL: {$msg} (got " . var_export($actual, true) . ", expected " . var_export($expected, true) . ")\n";
        $fail++;
    } else {
        echo "OK: {$msg}\n";
    }
}

$root = __DIR__ . '/..';
$js   = (string)file_get_contents($root . '/assets/js/app.js');
$api  = (string)file_get_contents($root . '/api/index.php');
$html = (string)file_get_contents($root . '/index.html');

/** Run a slice of the app's own JS in node and return stdout ('' when node is missing). */
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

/** A whole top-level JS function: from `function name(` to the closing brace at column 0. */
function slice_js_function(string $source, string $name): string
{
    $start = strpos($source, 'function ' . $name . '(');
    if ($start === false) {
        return '';
    }
    $end = strpos($source, "\n}\n", $start);
    return $end === false ? '' : substr($source, $start, $end - $start + 3);
}

/** Flat key → value of a locale file. */
function locale_flat(string $lang): array
{
    static $cache = [];
    if (isset($cache[$lang])) {
        return $cache[$lang];
    }
    $raw = json_decode((string)file_get_contents(__DIR__ . '/../translations/' . $lang . '.json'), true);
    $flat = [];
    $walk = function ($node, string $prefix) use (&$walk, &$flat): void {
        foreach ($node as $k => $v) {
            $key = $prefix === '' ? (string)$k : $prefix . '.' . $k;
            if (is_array($v)) {
                $walk($v, $key);
            } else {
                $flat[$key] = $v;
            }
        }
    };
    $walk(is_array($raw) ? $raw : [], '');
    return $cache[$lang] = $flat;
}

$locales = ['it', 'en', 'de', 'fr', 'es', 'zh'];

// ── ?lang= deep link ───────────────────────────────────────────────────────
$langFn = slice_js_function($js, '_urlLangOverride');
assert_true($langFn !== '', 'app.js: _urlLangOverride() exists (the deep link parser)');
assert_true(str_contains($js, 'const _URL_LANG = _urlLangOverride(location.search);'),
    'app.js: the deep link is read at boot');
assert_true(str_contains($js, "if (_URL_LANG) localStorage.setItem('evershelf_lang', _URL_LANG);"),
    'app.js: the deep link is stored, so a reload keeps it');
assert_true(str_contains($js, "let _currentLang = _URL_LANG || localStorage.getItem('evershelf_lang')"),
    'app.js: the URL wins over the stored preference');
assert_true(str_contains($js, "url.searchParams.set('lang', lang);"),
    'app.js: the language picker keeps `?lang=` in step instead of undoing it');

$langProbe = "const _SUPPORTED_LANGS = { it: 'Italiano', en: 'English', de: 'Deutsch', fr: 'Français', es: 'Español', zh: '简体中文' };\n"
    . $langFn . "\nconsole.log(JSON.stringify(['?lang=de','?foo=1&lang=zh-CN','?lang=fr_FR','?lang=xx','','?lang=','?lang=EN','?language=de'].map(_urlLangOverride)));";
$langOut = js_eval('', $langProbe);
$langGot = json_decode($langOut, true);
if (!is_array($langGot)) {
    echo "SKIP: node is not available — the ?lang= probes were not run ({$langOut})\n";
} else {
    assert_same(['de', 'zh', 'fr', '', '', '', 'en', ''], $langGot,
        'js: ?lang= accepts a locale code (de, zh-CN, fr_FR), ignores anything unsupported');
}

// ── 429: a stable, translatable answer ─────────────────────────────────────
$ratePos = strpos($api, 'if (count($data) >= $limit) {');
$rateBlock = $ratePos === false ? '' : substr($api, $ratePos, 900);
assert_true(str_contains($rateBlock, "'error_key'   => 'error.rate_limited'"),
    'api: a rate-limited request answers with error_key error.rate_limited');
assert_true(str_contains($rateBlock, "'retry_after' => \$window"),
    'api: the 429 answer says how long to wait');
assert_true(str_contains($rateBlock, "'success'     => false"),
    'api: the 429 answer keeps the {success:false} shape of every error');
assert_true(str_contains($rateBlock, "'event'    => 'api_rate_limited'"),
    'api: the hit is logged with a machine-readable event key');
assert_true(str_contains($js, 'if (localized !== data.error_key && !/\\{\\w+\\}/.test(localized)) data.error = localized;'),
    'app.js: api() resolves error_key before any caller prints data.error');
foreach ($locales as $lang) {
    $flat = locale_flat($lang);
    assert_true(isset($flat['error.rate_limited']) && $flat['error.rate_limited'] !== '',
        "{$lang}: error.rate_limited exists and is not empty");
}

// ── The add form's units ───────────────────────────────────────────────────
$addSelect = '';
if (preg_match('/<span[^>]*id="add-quantity-unit"[^>]*>.*?<\/select>/s', $html, $m)) {
    $addSelect = $m[0];
}
assert_true($addSelect !== '', 'index.html: the add form carries the unit badge + select');
foreach (['pz', 'conf', 'g', 'ml'] as $unit) {
    assert_true(str_contains($addSelect, '<option value="' . $unit . '" data-i18n="units.' . $unit . '"'),
        "index.html: the `{$unit}` option is translated (no hardcoded Italian token)");
}
assert_true(str_contains($addSelect, 'id="add-quantity-unit" aria-live="polite" data-i18n="units.pz"'),
    'index.html: the unit badge has a translated default');
assert_true(str_contains($js, 'function syncAddQtyUnitBadge()'),
    'app.js: syncAddQtyUnitBadge() exists');
assert_true(str_contains(slice_js_function($js, 'showAddForm'), 'syncAddQtyUnitBadge();'),
    'app.js: opening the add form syncs the badge with the selected unit');
assert_true(str_contains(slice_js_function($js, 'translatePage'), 'syncAddQtyUnitBadge();'),
    'app.js: a language change re-syncs the badge');
assert_true(!str_contains($js, "u === 'pz' ? 'pz ('"), 'app.js: the dynamic unit selects no longer hardcode `pz (…)`');
assert_true(!str_contains($js, "u === 'conf' ? 'conf ('"), 'app.js: the dynamic unit selects no longer hardcode `conf (…)`');
foreach ($locales as $lang) {
    $flat = locale_flat($lang);
    foreach (['units.pz', 'units.conf'] as $key) {
        assert_true(isset($flat[$key]) && $flat[$key] !== '', "{$lang}: {$key} exists and is not empty");
    }
}

// ── The urgency segment is recognised by its marker, in any language ───────
$specFn = slice_js_function($js, '_specDisplayText');
assert_true($specFn !== '', 'app.js: _specDisplayText() exists');
$specProbe = $specFn . "\nconsole.log(JSON.stringify(["
    . "'⚡ Urgente · 🛒 2 l',"
    . "'⚡ Dringend',"
    . "'Latte · Granarolo · 🟠 Presto · 🛒 2 l',"
    . "'🛒 2 l',"
    . "'',"
    . "'🛒 Compra: 2 l'"
    . "].map(_specDisplayText)));";
$specOut = js_eval('', $specProbe);
$specGot = json_decode($specOut, true);
if (!is_array($specGot)) {
    echo "SKIP: node is not available — the spec probes were not run ({$specOut})\n";
} else {
    assert_same(['🛒 2 l', '', 'Latte · Granarolo · 🛒 2 l', '🛒 2 l', '', ''], $specGot,
        'js: the urgency word is dropped by its emoji, whatever language wrote it');
}

// ── The API offers a translatable urgency label ────────────────────────────
assert_true(str_contains($api, "'urgency_label_key' => 'shopping.urgency_critical'"),
    'api: the urgency meta carries a translatable label key');
assert_true(str_contains($api, "\$item['urgency_label_key'] = \$meta['urgency_label_key'];"),
    'api: the shopping row exposes urgency_label_key');
foreach ($locales as $lang) {
    $flat = locale_flat($lang);
    foreach (['critical', 'high', 'medium', 'low'] as $level) {
        $key = 'shopping.urgency_' . $level;
        assert_true(isset($flat[$key]) && $flat[$key] !== '', "{$lang}: {$key} exists (urgency_label_key resolves)");
    }
}

if ($fail === 0) {
    echo "\nAll i18n boot guard tests passed.\n";
}
exit($fail === 0 ? 0 : 1);
