#!/usr/bin/env php
<?php
/**
 * Guard test — the dashboard locations control.
 *
 * Regression history (UI audit, block 1): the dashboard showed a fixed 2x2 grid
 * (Dispensa / Frigo / Freezer / Spesa), so a custom location had no entry point and
 * the purchase flow sat in the middle of the stocking states. It became a snapping
 * pill rail; the second pass turned the *places* into one native dropdown (a pill row
 * stops working at the fourth place, and the pantry filter already speaks that
 * grammar) while the **medicine cabinet keeps its own 💊 pill outside the list** — a
 * cabinet buried in a menu is a cabinet nobody opens.
 *
 * The functions under test are the shipped ones, extracted and run in node with stubs.
 *
 * Run: php scripts/test-location-rail.php
 */

$root = dirname(__DIR__);
$app = (string)file_get_contents($root . '/assets/js/app.js');
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

$src = extract_fn($app, 'renderLocationRail');
$go = extract_fn($app, '_dashboardLocationGo');
check('renderLocationRail() is extractable', $src !== '');
check('_dashboardLocationGo() is extractable', $go !== '');
check('the control reads the dynamic location list', str_contains($src, 'Object.keys(LOCATIONS)'));
check('the places live in a native dropdown', str_contains($src, 'dashboard-location-select')
    && str_contains($src, 'class="location-select"') && str_contains($src, '<option value="'));
check('the medicine cabinet is NOT an option of that dropdown',
    str_contains($src, 'filter(key => !isCabinet(key))')
    && str_contains($src, 'const cabinetKeys = keys.filter(isCabinet);'));
check('the cabinet keeps its own pill outside the list',
    str_contains($src, 'location-pill location-pill-medical'));
check('the add control is still there',
    str_contains($src, 'location-pill-add') && str_contains($src, '_openLocationsSettings()'));
check('the count comes from inventory_summary', str_contains($src, 'row.product_count'));
check('the urgency only counts expired / expiring stock',
    str_contains($src, 'flag(stats && stats.expired)') && str_contains($src, 'flag(stats && stats.expiring_soon)'));
check('the shopping card is gone from the dashboard control', !str_contains($src, "'shopping'"));

// ── Behaviour in node ───────────────────────────────────────────────────────
$harness = $src . "\n" . $go . <<<'JS'
const MEDICAL_LOCATION_ICON = '💊';
const LOCATIONS = {
    dispensa: { icon: '🗄️', label: 'Dispensa' },
    custom_armadietto: { icon: '💊', label: 'Armadietto' },
    frigo:    { icon: '🧊', label: 'Frigo' },
    freezer:  { icon: '❄️', label: 'Freezer' },
    custom_cantina: { icon: '📦', label: '<img src=x onerror=alert(1)>Cantina' },
};
const isMedicalLocation = (key) => key === 'custom_armadietto';
const bar = { innerHTML: '' };
const document = { getElementById: (id) => (id === 'location-rail' ? bar : null) };
const escapeHtml = (s) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
const escapeAttr = escapeHtml;
const normalizeLocationKey = (k) => (String(k || '') === 'altro' ? 'dispensa' : String(k || ''));
const t = (k, p) => k + (p ? JSON.stringify(p) : '');
let navigated = null;
const showPage = (page, param) => { navigated = [page, param]; };

renderLocationRail(
    [
        { location: 'dispensa', product_count: 32 },
        { location: 'custom_armadietto', product_count: 4 },
        { location: 'frigo', product_count: 7 },
        { location: 'freezer', product_count: 0 },
        { location: 'custom_cantina', product_count: 3 },
    ],
    { expired: [{ location: 'frigo' }], expiring_soon: [{ location: 'frigo' }] }
);
const html = bar.innerHTML;
const count = (re) => (html.match(re) || []).length;
// A cabinet that holds stock to save wears the red dot (the places carry a ⚠ instead).
renderLocationRail([{ location: 'custom_armadietto', product_count: 4 }],
    { expired: [{ location: 'custom_armadietto' }], expiring_soon: [] });
const withDot = bar.innerHTML;
const sel = { value: 'frigo' };
_dashboardLocationGo(sel);
console.log(JSON.stringify({
    has_select: /<select[^>]*id="dashboard-location-select"/.test(html),
    placeholder: html.includes('dashboard.locations_pick'),
    options: count(/<option value="[^"]+"/g),
    cabinet_not_option: !/<option value="custom_armadietto"/.test(html),
    cabinet_pill: /class="location-pill location-pill-medical[^"]*"[^>]*data-loc="custom_armadietto"/.test(html),
    cabinet_count: /data-loc="custom_armadietto"[^>]*>[\s\S]*?loc-count">4</.test(html),
    cantina_option: /<option value="custom_cantina">/.test(html) && html.includes('Cantina'),
    escaped: !html.includes('<img src=x') && html.includes('&lt;img src=x'),
    dispensa_count: html.includes('· 32'),
    frigo_warning: /<option value="frigo">[^<]*⚠/.test(html),
    dots: count(/class="loc-dot"/g),
    cabinet_dot: /data-loc="custom_armadietto"[^>]*>[\s\S]*?class="loc-dot"/.test(withDot)
        && (withDot.match(/class="loc-dot"/g) || []).length === 1,
    add_last: /location-pill-add[\s\S]*$/.test(html),
    no_shopping_option: !html.includes('value="shopping"'),
    go_page: navigated && navigated[0] === 'inventory',
    go_param: navigated && navigated[1] === 'frigo',
    go_resets: sel.value === '',
}));
JS;

$tmp = sys_get_temp_dir() . '/loc_rail_' . getmypid() . '.js';
file_put_contents($tmp, $harness);
$raw = shell_exec('node ' . escapeshellarg($tmp) . ' 2>/dev/null');
@unlink($tmp);
$out = json_decode(trim((string)$raw), true);
check('the harness runs in node', is_array($out), (string)$raw);
if (is_array($out)) {
    check('the places are one dropdown', (bool)($out['has_select'] ?? false));
    check('the dropdown opens with a placeholder, not a fake filter', (bool)($out['placeholder'] ?? false));
    check('one option per NON-medical place', (int)($out['options'] ?? 0) === 4);
    check('the cabinet is not buried in the dropdown', (bool)($out['cabinet_not_option'] ?? false));
    check('the cabinet keeps its own pill', (bool)($out['cabinet_pill'] ?? false));
    check('the cabinet pill still shows its count', (bool)($out['cabinet_count'] ?? false));
    check('a custom place gets its own option', (bool)($out['cantina_option'] ?? false));
    check('a hostile location name is escaped', (bool)($out['escaped'] ?? false));
    check('counts come from the summary', (bool)($out['dispensa_count'] ?? false));
    check('a place with stock to save carries a warning in its option', (bool)($out['frigo_warning'] ?? false));
    check('the places carry a ⚠ in the option, not a red dot', (int)($out['dots'] ?? -1) === 0);
    check('a cabinet with stock to save wears the red dot', (bool)($out['cabinet_dot'] ?? false));
    check('the add control is the last one', (bool)($out['add_last'] ?? false));
    check('the shopping option is gone (it is a nav tab now)', (bool)($out['no_shopping_option'] ?? false));
    check('picking a place opens the pantry on it', (bool)($out['go_page'] ?? false) && (bool)($out['go_param'] ?? false));
    check('the dropdown resets so the same place can be picked again', (bool)($out['go_resets'] ?? false));
}

// ---------------------------------------------------------------- i18n + wiring
foreach (['dashboard.locations_aria', 'dashboard.locations_pick', 'dashboard.add_location', 'dashboard.location_alerts'] as $key) {
    foreach (['it', 'en', 'de', 'fr', 'es', 'zh'] as $loc) {
        $doc = json_decode((string)file_get_contents($root . "/translations/{$loc}.json"), true);
        $node = $doc;
        foreach (explode('.', $key) as $step) {
            $node = is_array($node) ? ($node[$step] ?? null) : null;
        }
        check("translations/{$loc}.json has {$key}", is_string($node) && trim($node) !== '');
    }
}
$html = (string)file_get_contents($root . '/index.html');
check('the dashboard markup hosts the control', str_contains($html, 'class="location-bar" id="location-rail"'));
check('the select style is shared with the pantry filter',
    str_contains((string)file_get_contents($root . '/assets/css/style.css'), '.location-bar .location-select')
    && str_contains((string)file_get_contents($root . '/assets/css/surfaces.css'), '.location-bar {'));
check('the cabinet pill has the medical tint',
    str_contains((string)file_get_contents($root . '/assets/css/surfaces.css'), '.location-pill.location-pill-medical'));
check('the nav badge has a style', str_contains((string)file_get_contents($root . '/assets/css/surfaces.css'), '.nav-badge'));

echo "\n{$checks} checks, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
