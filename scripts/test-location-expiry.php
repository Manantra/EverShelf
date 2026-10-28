#!/usr/bin/env php
<?php
/**
 * Guard test — "changing where I put it must not change the expiry".
 *
 * The household's report: moving a jar from "Dispensa" to "Dispensa cucina" (a place it created)
 * changed the suggested expiry. It should not: shelf life depends on the KIND of place (pantry,
 * fridge, freezer), never on its name — and a pick between two places of the same kind must leave
 * the date alone, whether it came from the rule, from history or from the AI.
 *
 * What this locks:
 *   1. `storageKindOfLocation()` reads the kind from the built-in key, from the label the built-ins
 *      carry in EVERY locale ("Kühlschrank", "Nevera", "Réfrigérateur", "冰箱") and from the words
 *      the CSV import already knows — no new vocabulary, six locales covered.
 *   2. Both estimates (`estimateExpiryDays`, `estimateOpenedExpiryDays`) run on the KIND, and the
 *      server port (`estimateSealedExpiryDaysPHP`) agrees with them on the same inputs.
 *   3. The add form recomputes only when the kind changes (or on purpose: vacuum, fresh purchase),
 *      the edit modals do the same, and the AI hint never applies days that belong to another kind.
 *
 * Run: php scripts/test-location-expiry.php
 */
define('CRON_MODE', true);
require_once __DIR__ . '/../api/bootstrap.php';

$root = dirname(__DIR__);
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

/** The slice of app.js between two markers (both must exist, or the test fails loudly). */
function jsSlice(string $src, string $from, string $to): string
{
    $start = strpos($src, $from);
    $end = $start === false ? false : strpos($src, $to, $start + strlen($from));
    if ($start === false || $end === false || $end <= $start) {
        return '';
    }
    $code = substr($src, $start, $end - $start);
    // A dangling '/**' would swallow the next slice in the harness: drop an unfinished docblock.
    $code = rtrim($code);
    if (str_ends_with($code, '/**')) {
        $code = rtrim(substr($code, 0, -3));
    }
    return $code;
}

$slices = [
    'locations' => jsSlice($app, 'const BUILTIN_LOCATIONS', 'const MEDICAL_LOCATION_ICON'),
    'slugify'   => jsSlice($app, 'function slugifyCustomLocation', 'function resolveLocationKey'),
    'resolve'   => jsSlice($app, 'function resolveLocationKey', 'function storageKindOfLocation'),
    'kind'      => jsSlice($app, 'function storageKindOfLocation', 'function markLocationButton'),
    'sealed'    => jsSlice($app, 'const EXPIRY_DAYS', 'function formatEstimatedExpiry'),
    'opened'    => jsSlice($app, 'function estimateOpenedExpiryDays', 'function addDays'),
];
foreach ($slices as $name => $code) {
    check("the {$name} block of app.js is where the harness expects it", $code !== '');
}
if (in_array('', $slices, true)) {
    echo "\nFAIL — {$checks} checks, {$fail} failed\n";
    exit(1);
}

$langs = ['it', 'en', 'de', 'fr', 'es', 'zh'];

// One place per language: the label is what the household typed when it created the place.
$labelProbes = [
    'it' => ['Dispensa cucina' => 'dispensa', 'Frigo cantina' => 'frigo', 'Freezer garage' => 'freezer'],
    'en' => ['Kitchen pantry' => 'dispensa', 'Fridge garage' => 'frigo', 'Freezer drawer' => 'freezer'],
    'de' => ['Vorratskammer Küche' => 'dispensa', 'Kühlschrank Keller' => 'frigo', 'Gefrierschrank Garage' => 'freezer'],
    'fr' => ['Garde-manger cuisine' => 'dispensa', 'Réfrigérateur cave' => 'frigo', 'Congélateur garage' => 'freezer'],
    'es' => ['Despensa cocina' => 'dispensa', 'Nevera garaje' => 'frigo', 'Congelador garaje' => 'freezer'],
    'zh' => ['食品柜厨房' => 'dispensa', '冰箱厨房' => 'frigo', '冷冻柜车库' => 'freezer'],
];

// Products whose shelf life really depends on the kind of place.
$productProbes = [
    ['name' => 'Latte fresco intero', 'category' => 'latticini'],
    ['name' => 'Carote', 'category' => 'verdura'],
    ['name' => 'Parmigiano Reggiano', 'category' => 'latticini'],
    ['name' => 'Passata di pomodoro', 'category' => 'conserve'],
    ['name' => 'Mozzarella', 'category' => 'latticini'],
];
// dispensa / frigo / freezer / a second PANTRY the household created / one more pantry
$kindPlaces = ['dispensa', 'frigo', 'freezer', 'custom_dispensa_cucina', 'custom_cantina'];

$harnessLabels = [];
foreach ($langs as $lang) {
    $tr = json_decode((string)file_get_contents($root . "/translations/{$lang}.json"), true);
    $harnessLabels[$lang] = [
        'locations.dispensa' => (string)($tr['locations']['dispensa'] ?? 'Dispensa'),
        'locations.frigo'    => (string)($tr['locations']['frigo'] ?? 'Frigo'),
        'locations.freezer'  => (string)($tr['locations']['freezer'] ?? 'Freezer'),
    ];
}

$harness = 'let window = globalThis;' . "\n"
    . 'let LABELS = ALL_LABELS.it;' . "\n"
    . 'const t = (k) => (LABELS[k] || k);' . "\n"
    . $slices['locations'] . "\n"
    . $slices['slugify'] . "\n"
    . $slices['resolve'] . "\n"
    . $slices['kind'] . "\n"
    . $slices['sealed'] . "\n"
    . $slices['opened'] . "\n"
    . "/* Register a custom place the way Settings does (slug + typed label), then ask its kind. */\n"
    . 'function kindOf(label) {' . "\n"
    . "    const slug = slugifyCustomLocation(label);\n"
    . "    LOCATIONS[slug] = { icon: '📦', label: label };\n"
    . '    return storageKindOfLocation(slug);' . "\n"
    . '}' . "\n"
    . "/* Repaint the built-ins in ONE language: their labels are what a custom name is read against. */\n"
    . 'function setLang(l) {' . "\n"
    . '    LABELS = ALL_LABELS[l];' . "\n"
    . '    LOCATIONS = {' . "\n"
    . "        dispensa: { icon: '🗄️', label: LABELS['locations.dispensa'] },\n"
    . "        frigo: { icon: '🧊', label: LABELS['locations.frigo'] },\n"
    . "        freezer: { icon: '❄️', label: LABELS['locations.freezer'] },\n"
    . '    };' . "\n"
    . '}' . "\n"
    . 'function daysOf(name, category, loc) { return estimateExpiryDays({ name, category }, loc); }' . "\n"
    . 'console.log(JSON.stringify({' . "\n"
    . '    labels: Object.keys(LABEL_PROBES).map(l => { setLang(l); return [l, Object.entries(LABEL_PROBES[l]).map(([k, want]) => [k, kindOf(k), want])]; }),' . "\n"
    . '    builtins: BUILTIN_KEYS.map(k => [k, storageKindOfLocation(k)]),' . "\n"
    . '    days: DAYS_PROBES.map(p => [p.name, p.category, DAYS_PLACES.map(loc => daysOf(p.name, p.category, loc))]),' . "\n"
    . '}));';

$js = 'const ALL_LABELS = ' . json_encode($harnessLabels) . ";\n"
    . 'const LABEL_PROBES = ' . json_encode($labelProbes) . ";\n"
    . 'const BUILTIN_KEYS = ' . json_encode(['dispensa', 'frigo', 'freezer']) . ";\n"
    . 'const DAYS_PROBES = ' . json_encode($productProbes) . ";\n"
    . 'const DAYS_PLACES = ' . json_encode($kindPlaces) . ";\n"
    . $harness;

$tmp = sys_get_temp_dir() . '/loc_expiry_' . getmypid() . '.js';
file_put_contents($tmp, $js);
$raw = shell_exec('node ' . escapeshellarg($tmp) . ' 2>&1');
@unlink($tmp);
$out = json_decode(trim((string)$raw), true);
check('the location harness runs in node', is_array($out), (string)$raw);

if (is_array($out)) {
    // ── 1. The kind is read from the label, in every shipped language ───────────
    $badLabels = [];
    foreach (($out['labels'] ?? []) as $row) {
        $lang = $row[0] ?? '';
        foreach (($row[1] ?? []) as $probe) {
            if (($probe[1] ?? '') !== ($probe[2] ?? 'x')) {
                $badLabels[] = "{$lang}:{$probe[0]}=" . ($probe[1] ?? '') . ' (want ' . ($probe[2] ?? '') . ')';
            }
        }
    }
    check('a place is classified by its LABEL in all six locales', $badLabels === [], implode(', ', $badLabels));
    check('the built-in places keep their own kind',
        ($out['builtins'] ?? []) === [['dispensa', 'dispensa'], ['frigo', 'frigo'], ['freezer', 'freezer']],
        json_encode($out['builtins'] ?? null));

    // ── 2. Two places of the same kind give the SAME number of days ─────────────
    $sameKind = [];
    foreach (($out['days'] ?? []) as $row) {
        $name = $row[0] ?? '';
        $days = $row[2] ?? [];
        if (($days[0] ?? null) !== ($days[3] ?? 'x') || ($days[0] ?? null) !== ($days[4] ?? 'x')) {
            $sameKind[] = "{$name}: " . json_encode($days);
        }
    }
    check('"Dispensa cucina" is dated exactly like "Dispensa"', $sameKind === [], implode(' | ', $sameKind));
    check('the fridge and the freezer DO differ (the estimate is not flat)',
        array_reduce($out['days'] ?? [], function ($c, $row) {
            $days = $row[2] ?? [];
            return $c || (($days[1] ?? 0) !== ($days[0] ?? 0)) || (($days[2] ?? 0) !== ($days[0] ?? 0));
        }, false),
        json_encode($out['days'] ?? null));

    // ── 3. The server port agrees with the browser on the same inputs ───────────
    require_once $root . '/api/database.php';
    $mismatch = [];
    foreach (($out['days'] ?? []) as $row) {
        $name = (string)($row[0] ?? '');
        $category = (string)($row[1] ?? '');
        $days = $row[2] ?? [];
        foreach ($kindPlaces as $j => $loc) {
            $php = estimateSealedExpiryDaysPHP($name, $category, (string)$loc);
            if ((int)($days[$j] ?? -1) !== $php) {
                $mismatch[] = "{$name}@{$loc}: js=" . json_encode($days[$j] ?? null) . " php={$php}";
            }
        }
    }
    check('the PHP port of the estimate answers what the browser answers', $mismatch === [], implode(' | ', $mismatch));
    check('the PHP port reads the kind of a custom place too',
        storageKindOfLocationPHP('custom_frigo_cantina') === 'frigo'
        && storageKindOfLocationPHP('custom_dispensa_cucina') === 'dispensa'
        && storageKindOfLocationPHP('custom_freezer_garage') === 'freezer');
}

// ── 4. A place pick does not re-date the box (the household's complaint) ─────
check('the add form recomputes only when the KIND changes',
    (bool)preg_match('/function recalculateAddExpiry\(force = false\) \{[\s\S]{0,900}?const kind = storageKindOfLocation\(loc\);[\s\S]{0,200}?if \(!force && _addExpiryKind && kind === _addExpiryKind\) return;/s', $app)
    && (bool)preg_match('/function selectLocation\(btn, loc\) \{[\s\S]{0,700}?recalculateAddExpiry\(\);/s', $app)
    && (bool)preg_match('/function onVacuumSealedChange\(\)[\s\S]{0,600}?recalculateAddExpiry\(true\);/s', $app));
check('the edit modals keep the date when only the place changes',
    (bool)preg_match('/function recalcEditExpiry\(locInputId, vacuumInputId, expiryInputId, force = false\) \{[\s\S]{0,1200}?_editExpiryKind\.get\(expiryInputId\) === kind\) return;/s', $app)
    && substr_count($app, "recalcEditExpiry('edit-loc','edit-vacuum','edit-expiry', true)") === 1
    && substr_count($app, "recalcEditExpiry('action-edit-loc','action-edit-vacuum','action-edit-expiry', true)") === 1
    && substr_count($app, "_editExpiryKind.set('edit-expiry'") === 1
    && substr_count($app, "_editExpiryKind.set('action-edit-expiry'") === 1);
check('the AI hint never applies days that belong to another kind of place',
    (bool)preg_match('/const sameKind = !aiKind \|\| aiKind === hereKind;/s', $app)
    && (bool)preg_match("/if \(!window\._historyExpiryDays && !_isExpiryManuallySet\('add-expiry'\) && sameKind\)/s", $app));
check('the estimates run on the kind, not on the slug',
    (bool)preg_match("/if \(kind === 'frigo'\) \{/s", $app)
    && (bool)preg_match("/if \(kind === 'freezer' && days < 180\)/s", $app)
    && (bool)preg_match("/const inFreezer = storageKindOfLocation\(item\.location\) === 'freezer';/s", $app));

echo $fail === 0
    ? "\nPASS — {$checks} checks, 0 failed\n"
    : "\nFAIL — {$checks} checks, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);

