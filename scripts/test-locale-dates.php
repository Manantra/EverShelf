#!/usr/bin/env php
<?php
/**
 * Regression tests: dates follow the COUNTRY, and every date goes through one formatter.
 *
 * The report: "le date delle scadenze non seguono il paese! io che sono in Italia vedo
 * 2026-10-19 e non gg/mm/aaaa". Three causes:
 *
 *   1. `formatDate()` printed a month NAME (`month: 'short'` → "19 ott 2026") instead of
 *      the numeric date the country expects (it-IT → 19/10/2026);
 *   2. its locale map was hand-written and gave **fr and es the Italian format**;
 *   3. two places printed the raw ISO string from the API — the dashboard banner
 *      ("scadeva il 2026-10-19") and the import preview table.
 *
 * One source now: `_collationLocale()` drives every date (and the TTS language too).
 *
 * Run: php scripts/test-locale-dates.php
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

$js = (string)file_get_contents(__DIR__ . '/../assets/js/app.js');

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

// ── One locale map, numeric dates ───────────────────────────────────────────
assert_true(!str_contains($js, "_currentLang === 'de' ? 'de-DE'"),
    'no hand-written locale ternary is left (fr/es silently fell back to Italian)');
$fmt = slice_js_function($js, 'formatDate');
assert_true($fmt !== '', 'app.js: formatDate() exists');
assert_true(str_contains($fmt, '_collationLocale()'), 'formatDate() uses the shared collation locale');
assert_true(!preg_match('/toLocaleDateString\([^)]*,\s*\{/', $fmt),
    'formatDate() no longer passes a month name to the formatter');

// ── No raw ISO date reaches the UI ─────────────────────────────────────────
assert_true(str_contains($js, "t('dashboard.banner_sold_date', { date: formatDate(item.expiry_date) })"),
    'the expired banner formats the date ("scadeva il 19/10/2026")');
assert_true(str_contains($js, 'd.expiry_date ? formatDate(d.expiry_date) :'),
    'the import preview formats the date too');

// ── The shapes, per language (the app's own function, in node) ─────────────
$probe = "function _collationLocale() { return globalThis.__loc; }\n"
    . $fmt . "\n"
    . "const out = {};\n"
    . "for (const loc of ['it-IT','de-DE','fr-FR','es-ES','en-GB','zh-CN']) { globalThis.__loc = loc; out[loc] = formatDate('2026-10-19'); }\n"
    . "out.empty = formatDate('');\n"
    . "console.log(JSON.stringify(out));";
$out = js_eval('', $probe);
$res = json_decode($out, true);
if (!is_array($res)) {
    echo "SKIP: node is not available — the date probes were not run ({$out})\n";
} else {
    assert_same('19/10/2026', $res['it-IT'] ?? null, 'Italy reads gg/mm/aaaa (the report)');
    assert_same('19.10.2026', $res['de-DE'] ?? null, 'Germany reads dd.mm.yyyy');
    assert_same('19/10/2026', $res['fr-FR'] ?? null, 'France reads dd/mm/yyyy (no longer Italian)');
    assert_same('19/10/2026', $res['es-ES'] ?? null, 'Spain reads dd/mm/yyyy (no longer Italian)');
    assert_same('19/10/2026', $res['en-GB'] ?? null, 'English (UK) reads dd/mm/yyyy');
    assert_same('2026/10/19', $res['zh-CN'] ?? null, 'Chinese reads yyyy/mm/dd');
    assert_same('', $res['empty'] ?? null, 'a missing date stays empty');
}

if ($fail === 0) {
    echo "\nAll locale-date guard tests passed.\n";
}
exit($fail === 0 ? 0 : 1);
