#!/usr/bin/env php
<?php
/**
 * Guard test — the dashboard location rail.
 *
 * Regression history (UI audit, block 1): the dashboard showed a fixed 2x2 grid
 * (Dispensa / Frigo / Freezer / Spesa), so a custom location had no entry point and
 * the purchase flow sat in the middle of the stocking states. The rail is one
 * snapping row of dynamic pills with a count and an urgency dot per location.
 *
 * The function under test is the shipped one, extracted and run in node with stubs.
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
check('renderLocationRail() is extractable', $src !== '');
check('the rail reads the dynamic location list', str_contains($src, 'Object.entries(LOCATIONS)'));
check('the rail offers an add pill', str_contains($src, 'location-pill-add') && str_contains($src, '_openLocationsSettings()'));
check('the count comes from inventory_summary', str_contains($src, 'row.product_count'));
check('the urgency dot only counts expired / expiring stock',
    str_contains($src, 'flag(stats && stats.expired)') && str_contains($src, 'flag(stats && stats.expiring_soon)'));
check('the shopping card is gone from the dashboard rail', !str_contains($src, "'shopping'"));

// ── Behaviour in node ───────────────────────────────────────────────────────
$harness = $src . <<<'JS'
const LOCATIONS = {
    dispensa: { icon: '🗄️', label: 'Dispensa' },
    frigo:    { icon: '🧊', label: 'Frigo' },
    freezer:  { icon: '❄️', label: 'Freezer' },
    altro:    { icon: '📦', label: 'Altro' },
    custom_cantina: { icon: '📦', label: '<img src=x onerror=alert(1)>Cantina' },
};
const rail = { innerHTML: '' };
const document = { getElementById: (id) => (id === 'location-rail' ? rail : null) };
const escapeHtml = (s) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
const escapeAttr = escapeHtml;
const t = (k, p) => k + (p ? JSON.stringify(p) : '');

renderLocationRail(
    [
        { location: 'dispensa', product_count: 32 },
        { location: 'frigo', product_count: 7 },
        { location: 'freezer', product_count: 0 },
        { location: 'custom_cantina', product_count: 3 },
    ],
    { expired: [{ location: 'frigo' }], expiring_soon: [{ location: 'frigo' }, { location: 'dispensa' }] }
);
const html = rail.innerHTML;
const count = (re) => (html.match(re) || []).length;
console.log(JSON.stringify({
    pills: count(/class="location-pill[^"]*"/g),
    add_last: /location-pill-add[\s\S]*$/.test(html),
    cantina: html.includes('custom_cantina') && html.includes('Cantina'),
    escaped: !html.includes('<img src=x') && html.includes('&lt;img src=x'),
    dispensa_count: html.includes('>32<'),
    frigo_count: html.includes('>7<'),
    dots: count(/class="loc-dot"/g),
    freezer_empty: /is-empty[^>]*data-loc="freezer"/.test(html),
    no_shopping_pill: !html.includes('data-loc="shopping"'),
}));
JS;

$tmp = sys_get_temp_dir() . '/loc_rail_' . getmypid() . '.js';
file_put_contents($tmp, $harness);
$raw = shell_exec('node ' . escapeshellarg($tmp) . ' 2>/dev/null');
@unlink($tmp);
$out = json_decode(trim((string)$raw), true);
check('the harness runs in node', is_array($out), (string)$raw);
if (is_array($out)) {
    check('one pill per location plus the add pill', (int)($out['pills'] ?? 0) === 6);
    check('the add pill is the last one', (bool)($out['add_last'] ?? false));
    check('a custom location gets its own pill', (bool)($out['cantina'] ?? false));
    check('a hostile location name is escaped', (bool)($out['escaped'] ?? false));
    check('counts come from the summary', (bool)($out['dispensa_count'] ?? false) && (bool)($out['frigo_count'] ?? false));
    check('the urgency dot appears once per urgent location', (int)($out['dots'] ?? -1) === 2);
    check('an empty location is dimmed', (bool)($out['freezer_empty'] ?? false));
    check('the shopping pill is gone (it is a nav tab now)', (bool)($out['no_shopping_pill'] ?? false));
}

// ---------------------------------------------------------------- i18n + wiring
foreach (['dashboard.locations_aria', 'dashboard.add_location', 'dashboard.location_alerts'] as $key) {
    foreach (['it', 'en', 'de', 'fr', 'es', 'zh'] as $loc) {
        $doc = json_decode((string)file_get_contents($root . "/translations/{$loc}.json"), true);
        $node = $doc;
        foreach (explode('.', $key) as $step) {
            $node = is_array($node) ? ($node[$step] ?? null) : null;
        }
        check("translations/{$loc}.json has {$key}", is_string($node) && trim($node) !== '');
    }
}
check('the dashboard markup hosts the rail', str_contains((string)file_get_contents($root . '/index.html'), 'id="location-rail"'));
check('the nav badge has a style', str_contains((string)file_get_contents($root . '/assets/css/surfaces.css'), '.nav-badge'));

echo "\n{$checks} checks, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
