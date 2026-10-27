#!/usr/bin/env php
<?php
/**
 * Guard test — WHERE a storage location sits and HOW it is drawn.
 *
 * The request: "Armadietto farmaci must always come after Dispensa and must have a different
 * icon". A custom location used to be appended in the order the household typed it, wearing
 * the same cardboard box as a cellar: the medicine cabinet ended up last in the rail, the
 * pantry tabs and the add/use pickers, indistinguishable from any other place.
 *
 * The rule now lives in ONE list (`mergeCustomLocations`): Dispensa first, the marked
 * medicine cabinet right after it (💊), then the fridges and the other custom places (📦).
 * Every surface reads that list, so the rail, the tabs, the pickers, the Settings card and
 * the medical picker cannot disagree.
 *
 * The shipped function is extracted and run in node with stubs (same technique as
 * scripts/test-location-rail.php).
 *
 * Run: php scripts/test-location-order.php
 */

$root = dirname(__DIR__);
$app = (string)file_get_contents($root . '/assets/js/app.js');
$api = (string)file_get_contents($root . '/api/index.php');
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

// ── One pair of constants, one order ────────────────────────────────────────
check('the two location icons are declared once',
    preg_match("/^const MEDICAL_LOCATION_ICON = '💊';$/mu", $app) === 1
    && preg_match("/^const CUSTOM_LOCATION_ICON = '📦';$/mu", $app) === 1);
check('the medical place is resolved from the marking in both stored forms',
    str_contains($app, 'function isMedicalLocation(key, label)')
    && str_contains($app, 'function _medicalLocationKeys()')
    && str_contains($app, '(getSettings().medical_locations || []).map(_medLocationKey).filter(Boolean)'));
check('the order is: Dispensa, the medicine cabinet, then the rest',
    (bool)preg_match('/if \(key === .dispensa.\) medical\.forEach/', $app));
check('the Settings list draws the cabinet with its own pill too',
    (bool)preg_match('/function renderCustomLocations[\s\S]{0,900}?MEDICAL_LOCATION_ICON : CUSTOM_LOCATION_ICON/s', $app));
$choicesBody = substr(explode('function _medLocationChoices', $app)[1] ?? '', 0, 700);
check('the medical picker reads the ONE ordered list (no second order)',
    str_contains($choicesBody, 'Object.entries(LOCATIONS)') && !str_contains($choicesBody, 'LOCATION_META'));
check('the cabinet is known at boot (med_status carries it, the client re-merges)',
    str_contains($api, "'medical_locations' => array_values(\$medLocations)")
    && (bool)preg_match('/if \(Array\.isArray\(r\.medical_locations\)\) \{[\s\S]{0,400}?mergeCustomLocations\(s\.custom_locations \|\| \[\]\)/s', $app));

// ── The shipped mergeCustomLocations(), run in node ─────────────────────────
$consts = '';
foreach (['MEDICAL_LOCATION_ICON', 'CUSTOM_LOCATION_ICON'] as $name) {
    if (preg_match('/^const ' . $name . ' = .*;$/mu', $app, $m)) {
        $consts .= $m[0] . "\n";
    }
}
$fns = $consts
    . extract_fn($app, 'slugifyCustomLocation') . "\n"
    . extract_fn($app, '_medicalLocationKeys') . "\n"
    . extract_fn($app, 'isMedicalLocation') . "\n"
    . extract_fn($app, '_medLocationKey') . "\n"
    . extract_fn($app, 'mergeCustomLocations') . "\n";
check('every function the harness needs is extractable', substr_count($fns, 'function ') === 5, (string)substr_count($fns, 'function '));

$harness = <<<'JS'
const settings = JSON.parse(process.argv[2] || '{}');
const getSettings = () => settings;
const t = (k) => ({ 'locations.dispensa': 'Dispensa', 'locations.frigo': 'Frigo', 'locations.freezer': 'Freezer' }[k] || k);
const BUILTIN_LOCATIONS = {
    dispensa: { icon: '🗄️', labelKey: 'locations.dispensa' },
    frigo:    { icon: '🧊', labelKey: 'locations.frigo' },
    freezer:  { icon: '❄️', labelKey: 'locations.freezer' },
};
let LOCATIONS = {};
const rebuildLocationPickers = () => {};
JS;
$harness .= "\n" . $fns . <<<'JS'

mergeCustomLocations(settings.custom_locations || []);
const icons = {};
for (const [k, v] of Object.entries(LOCATIONS)) icons[k] = v.icon;
console.log(JSON.stringify({ order: Object.keys(LOCATIONS), icons }));
JS;

$tmp = sys_get_temp_dir() . '/loc_order_' . getmypid() . '.js';
file_put_contents($tmp, $harness);

$run = function (array $settings) use ($tmp): array {
    $raw = shell_exec('node ' . escapeshellarg($tmp) . ' ' . escapeshellarg(json_encode($settings)) . ' 2>&1');
    $out = json_decode(trim((string)$raw), true);
    return is_array($out) ? $out : ['raw' => (string)$raw];
};

// The real case: two custom places, the cabinet typed LAST.
$typedLast = $run([
    'custom_locations'  => ['Mobile Soggiorno', 'Armadietto Farmaci'],
    'medical_locations' => ['Armadietto Farmaci'],        // the wizard stores the typed label
]);
check('the medicine cabinet sits right after Dispensa, wherever it was typed',
    ($typedLast['order'] ?? null) === ['dispensa', 'custom_armadietto_farmaci', 'frigo', 'freezer', 'custom_mobile_soggiorno'],
    json_encode($typedLast));
check('the cabinet wears the pill, the other places keep the box',
    ($typedLast['icons']['custom_armadietto_farmaci'] ?? '') === '💊'
    && ($typedLast['icons']['custom_mobile_soggiorno'] ?? '') === '📦'
    && ($typedLast['icons']['dispensa'] ?? '') === '🗄️',
    json_encode($typedLast['icons'] ?? []));

// A client that saved the slug instead of the label is the same place.
$asSlug = $run([
    'custom_locations'  => ['Mobile Soggiorno', 'Armadietto Farmaci'],
    'medical_locations' => ['custom_armadietto_farmaci'],
]);
check('the marking is understood as a slug as well as a label',
    ($asSlug['order'] ?? null) === ($typedLast['order'] ?? null)
    && ($asSlug['icons']['custom_armadietto_farmaci'] ?? '') === '💊',
    json_encode($asSlug));

// No marking: nothing is promoted, nothing is painted as a medicine.
$none = $run([
    'custom_locations'  => ['Mobile Soggiorno', 'Armadietto Farmaci'],
    'medical_locations' => [],
]);
check('without a marked cabinet no place jumps the queue',
    ($none['order'] ?? null) === ['dispensa', 'frigo', 'freezer', 'custom_mobile_soggiorno', 'custom_armadietto_farmaci']
    && ($none['icons']['custom_armadietto_farmaci'] ?? '') === '📦',
    json_encode($none));

@unlink($tmp);

echo "\n{$checks} checks, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
