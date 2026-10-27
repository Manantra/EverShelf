#!/usr/bin/env php
<?php
/**
 * Regression tests: the planning window the user chose, and the expiry verdict.
 *
 * Two reports from the same pantry:
 *
 *   1. "se di limoni ne uso 5 al mese perché me ne proponi 2?" — Settings → Spesa said
 *      "quantità calcolate per 25 giorni" (`app_settings.shopping_plan_days = 25`), but
 *      `smartResolvePlanDays()` ignored the stored value and used the *inferred* cycle
 *      (7 days here), so every quantity was a fraction of the truth: 5 lemons/month
 *      became "2 pz". Only an explicit `plan_days` request was honoured, and the cron
 *      never sends one.
 *
 *   2. "il latte in dispensa scade oggi e mi dici Non mangiare / buttalo" — the expired
 *      query compared `julianday('now')` (with the clock) to the date, so at 00:01 of its
 *      own best-before day the milk was already "scaduto"; and a high-risk item outside
 *      the fridge went straight to 🚫, with a "Non mangiare" label for a drink.
 *
 * What must stay true:
 *   * the stored window wins over the inferred cycle, an explicit request wins over both;
 *   * the expired list uses a DATE-ONLY comparison (expires today ≠ expired);
 *   * day 0 is never a danger verdict, and 1–2 days past the date are "Controlla";
 *   * a liquid is never told "Non mangiare": it says "Non bere".
 *
 * Run: php scripts/test-expiry-and-plan-days.php
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

// ── The planning window ────────────────────────────────────────────────────
$dbFile = sys_get_temp_dir() . '/evershelf_test_plan_days.sqlite';
@unlink($dbFile);
register_shutdown_function(static function () use ($dbFile): void { @unlink($dbFile); });
$db = new PDO('sqlite:' . $dbFile);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->exec('CREATE TABLE app_settings (key TEXT PRIMARY KEY, value TEXT NOT NULL DEFAULT \'\', updated_at DATETIME DEFAULT CURRENT_TIMESTAMP)');
$db->exec('CREATE TABLE transactions (id INTEGER PRIMARY KEY AUTOINCREMENT, product_id INTEGER, type TEXT, quantity REAL, location TEXT DEFAULT \'dispensa\', notes TEXT DEFAULT \'\', undone INTEGER DEFAULT 0, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)');

$setPlan = static function (PDO $db, $value): void {
    $db->exec("DELETE FROM app_settings WHERE key = 'shopping_plan_days'");
    if ($value !== null) {
        $st = $db->prepare("INSERT INTO app_settings (key, value) VALUES ('shopping_plan_days', ?)");
        $st->execute([(string)$value]);
    }
};

$setPlan($db, 25);
assert_same(25, smartResolvePlanDays(null, $db), 'the window chosen in Settings (25 days) is used by the cron');
assert_same(25, smartResolvePlanDays('', $db), 'an empty request falls back to the stored window, not to the inferred cycle');
assert_same(7, smartResolvePlanDays('7', $db), 'an explicit request still wins over the stored window');
assert_same(30, smartResolvePlanDays('99', $db), 'an out-of-range request is clamped to 30');
$setPlan($db, null);
assert_same(smartDefaultPlanDays($db), smartResolvePlanDays(null, $db), 'with "auto" the inferred cycle is still used');
$setPlan($db, 'abc');
assert_same(smartDefaultPlanDays($db), smartResolvePlanDays(null, $db), 'a garbage value falls back to the inferred cycle');
$setPlan($db, 0);
assert_same(smartDefaultPlanDays($db), smartResolvePlanDays(null, $db), 'zero is not a window: fall back (a 0-day plan would ask for nothing)');

// The bug in one number: 5 lemons a month over a 7-day window vs a 25-day one.
$monthly = 5.0;
$week = smartConsumptionForPlanDays($monthly, $monthly / 30, 'used_30d', 7, $monthly, 'pz');
$plan = smartConsumptionForPlanDays($monthly, $monthly / 30, 'used_30d', 25, $monthly, 'pz');
assert_true($week['amount'] < 2.0, 'a 7-day window asks for ~1-2 lemons (the reported "2 pz")');
assert_true($plan['amount'] >= 4.0, 'the 25-day window asks for the ~4-5 lemons actually used');

// ── The purchase horizon uses the place the item is actually stored ────────
$lemon = smartPurchaseHorizonDays('limone', 'frutta', 25);
assert_same(21, $lemon['shelf_days'], 'lemons are estimated in the FRIDGE (21 days), not in the pantry (7)');
assert_same(21, $lemon['days'], 'a 25-day plan is capped by the lemon shelf life, not by 7 pantry days');
$lemonNeed = smartConsumptionForPlanDays(6.48, 0.22, 'prev_month', $lemon['days'], 5.4, 'pz');
assert_true($lemonNeed['amount'] >= 4.0, '5 lemons a month on the real horizon give ~4.5, so the list asks for 5');

$courgette = smartPurchaseHorizonDays('Zucchina', 'verdura', 25);
assert_same(14, $courgette['shelf_days'], 'courgettes use the fridge estimate (14 days)');
$bread = smartPurchaseHorizonDays('Pane', 'pane', 25);
assert_true($bread['days'] <= 7, 'bread keeps a short horizon: the anti-waste cap is still there');

// ── Expires today ≠ expired (server side) ──────────────────────────────────
assert_true(
    str_contains($api, "julianday(date('now')) - julianday(date(i.expiry_date))"),
    'the expired list compares DATES, so a product expiring today is not expired at 00:01'
);
assert_true(
    !str_contains($api, "julianday('now') - julianday(i.expiry_date)"),
    'the timestamp comparison that flagged the expiry day itself is gone'
);

// ── The verdict in the UI (the app's own function, run in node) ────────────
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

$safetyFn = slice_js_function($js, 'getExpiredSafety');
assert_true($safetyFn !== '', 'app.js: getExpiredSafety() exists');

$probe = "function mapToLocalCategory(cat) { return cat || 'altro'; }\n"
    . "function t(k) { return k; }\n"
    . $safetyFn . "\n"
    . "const cases = [\n"
    . "  ['milk today',        {category:'latticini', location:'dispensa', unit:'ml'}, 0],\n"
    . "  ['milk tomorrow',     {category:'latticini', location:'dispensa', unit:'ml'}, 1],\n"
    . "  ['milk 5 days',       {category:'latticini', location:'dispensa', unit:'ml'}, 5],\n"
    . "  ['yogurt 5 days',     {category:'latticini', location:'frigo',    unit:'g'},  5],\n"
    . "  ['meat 1 day',        {category:'carne',     location:'frigo',    unit:'g'},  1],\n"
    . "  ['bread 10 days',     {category:'pane',      location:'dispensa', unit:'g'}, 10],\n"
    . "  ['pasta 10 days',     {category:'pasta',     location:'dispensa', unit:'g'}, 10],\n"
    . "  ['juice 5 days',      {category:'bevande',   location:'frigo',    unit:'ml'}, 5],\n"
    . "  ['cocoa 5 days',      {category:'bevande',   location:'dispensa', unit:'g'},  5],\n"
    . "  ['juice 40 days',     {category:'bevande',   location:'frigo',    unit:'ml'}, 40],\n"
    . "  ['milk in freezer',   {category:'latticini', location:'freezer',  unit:'ml'}, 10]\n"
    . "];\n"
    . "console.log(JSON.stringify(cases.map(([n,i,d]) => { const r = getExpiredSafety(i,d); return [n, r.level, r.label, r.tip]; })));";
$out = js_eval('', $probe);
$rows = json_decode($out, true);
if (!is_array($rows)) {
    echo "SKIP: node is not available — the verdict probes were not run ({$out})\n";
} else {
    $byCase = [];
    foreach ($rows as $r) { $byCase[$r[0]] = $r; }
    assert_same(['warning', 'status.check', 'status.tip_expires_today'], array_slice($byCase['milk today'], 1),
        'a product expiring today is a warning ("Scade oggi"), never "Non bere"');
    assert_same(['warning', 'status.check', 'status.tip_drink_check'], array_slice($byCase['milk tomorrow'], 1),
        'one day past the date is still "Controlla" (quality, not danger)');
    assert_same(['danger', 'status.dont_drink', 'status.tip_drink_danger'], array_slice($byCase['milk 5 days'], 1),
        'a liquid past its date says "Non bere", never "Non mangiare"');
    assert_same(['danger', 'status.dont_eat', 'status.tip_highRisk_danger'], array_slice($byCase['yogurt 5 days'], 1),
        'a solid dairy still says "Non mangiare"');
    assert_same('warning', $byCase['meat 1 day'][1], 'one day past the date in the fridge is a warning');
    assert_same('warning', $byCase['bread 10 days'][1], 'bread at 10 days is still "Controlla"');
    assert_same('ok', $byCase['pasta 10 days'][1], 'dry pasta at 10 days stays "Ancora buono"');
    assert_same('ok', $byCase['milk in freezer'][1], 'the freezer bonus still applies');
    // Regression (reported: "Preparato solubile Ovomaltine era scaduto e non me lo hai
    // detto"): a beverage past its date used to fall in the low-risk group and answer
    // "Va bene" for 30 days — which is also what kept it off the dashboard.
    assert_same(['warning', 'status.check', 'status.tip_drink_check'], array_slice($byCase['juice 5 days'], 1),
        'a drink five days past its date is "Controlla", never "Va bene"');
    assert_same('warning', $byCase['cocoa 5 days'][1], 'a soluble preparation is a drink too');
    assert_same('danger', $byCase['juice 40 days'][1], 'after a month a drink is a discard');
}

// ── The dashboard lists every past-date row, whatever its verdict ──────────
assert_true(str_contains($js, 'const _daysPast = (it) => {'),
    'the expired list sorts on a date-only distance');
assert_true(!preg_match('/visibleExpired = [\s\S]{0,500}getExpiredSafety/', $js),
    'the expired list is never filtered by the verdict (a "Va bene" row is still past its date)');
assert_true(str_contains($js, '.sort((a, b) => _daysPast(b) - _daysPast(a));'),
    'the newest expiry comes first, so the cap cannot hide the row that just expired');
assert_true(str_contains($js, "const visibleExpiredAll = (statsData.expired || [])"),
    'the expired list starts from the server payload');
// EverShelf Med: the medical rows leave the food block for their own one, but only when
// the module is on — with the module off nothing is filtered out.
assert_true(str_contains($js, 'const medicalExpired = _medOn() ? visibleExpiredAll.filter(_isMedicalRow) : [];'),
    'the medical rows are split out only while the module is on');
assert_true(str_contains($js, "const visibleExpired = medicalExpired.length"),
    'the food block keeps every non-medical row when the module is on');

// ── The new labels exist in all six locales ────────────────────────────────
$required = ['status.dont_drink', 'status.tip_expires_today', 'status.tip_drink_check', 'status.tip_drink_danger'];
foreach (['it', 'en', 'de', 'fr', 'es', 'zh'] as $lang) {
    $data = json_decode((string)file_get_contents($root . '/translations/' . $lang . '.json'), true);
    foreach ($required as $key) {
        [$group, $name] = explode('.', $key, 2);
        assert_true(isset($data[$group][$name]) && $data[$group][$name] !== '', "{$lang}: {$key} exists");
    }
}

if ($fail === 0) {
    echo "\nAll expiry and plan-days guard tests passed.\n";
}
exit($fail === 0 ? 0 : 1);

