#!/usr/bin/env php
<?php
/**
 * Guard test — the urgency hub.
 *
 * Regression history (UI audit, blocks 2 & 3): the anomaly banner, the expired list
 * and the "quick recipe" CTA were three separate blocks with three frames. The same
 * product appeared in two of them, and the one action that could save the food sat
 * in a fourth block. They are one container now whose visibility and subtitle are
 * decided in exactly one place.
 *
 * The function under test is the shipped one, extracted and run in node with stubs.
 *
 * Run: php scripts/test-urgency-hub.php
 */

$root = dirname(__DIR__);
$html = (string)file_get_contents($root . '/index.html');
$app  = (string)file_get_contents($root . '/assets/js/app.js');
$api  = (string)file_get_contents($root . '/api/index.php');
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
libxml_use_internal_errors(true);
$doc = new DOMDocument();
$doc->loadHTML('<?xml encoding="UTF-8">' . $html);
$xp = new DOMXPath($doc);
check('the dashboard has one urgency hub', $xp->query("//*[@id='urgency-hub']")->length === 1);
check('the anomaly banner lives inside the hub',
    $xp->query("//*[@id='urgency-hub']//*[@id='alert-banner']")->length === 1);
check('the expired list lives inside the hub',
    $xp->query("//*[@id='urgency-hub']//*[@id='expired-list']")->length === 1);
check('the standalone recipe bar is gone', strpos($html, 'id="quick-recipe-bar"') === false);
check('the recipe CTA is the hub header button',
    $xp->query("//*[@id='urgency-hub']//*[@id='urgency-recipe-btn']")->length === 1
    && strpos($html, 'data-i18n="dashboard.quick_recipe_short"') !== false);
check('the hub starts hidden (no empty frame on a clean pantry)',
    $xp->query("//*[@id='urgency-hub'][@hidden]")->length === 1);

// ----------------------------------------------------------------- function
$src = extract_fn($app, '_syncUrgencyHub');
check('_syncUrgencyHub() is extractable', $src !== '');
check('visibility is decided in one place', substr_count($app, 'function _syncUrgencyHub') === 1);
check('the dashboard and the banner loader both call it', substr_count($app, '_syncUrgencyHub();') >= 2);

$harness = $src . <<<'JS'
const els = {};
function mk(id, display, hidden) {
    els[id] = { id, style: { display: display || '' }, hidden: !!hidden, textContent: '' };
    return els[id];
}
let EXPIRED = 0, EXPIRING = 0;
const document = {
    getElementById: (id) => els[id] || null,
    querySelectorAll: (sel) => {
        if (sel === '#expired-list .alert-item') return { length: EXPIRED };
        if (sel === '#expiring-list .alert-item') return { length: EXPIRING };
        return { length: 0 };
    },
};
const t = (k, p) => k + (p ? JSON.stringify(p) : '');

function scenario(cfg) {
    for (const k of Object.keys(els)) delete els[k];
    mk('urgency-hub');
    mk('urgency-sub');
    mk('urgency-recipe-btn');
    mk('alert-banner', cfg.banner ? '' : 'none');
    mk('alert-expired', cfg.expiredSection ? '' : 'none');
    mk('alert-expiring', cfg.expiringSection ? '' : 'none');
    EXPIRED = cfg.expiredRows || 0;
    EXPIRING = cfg.expiringRows || 0;
    _syncUrgencyHub();
    return {
        hub_hidden: els['urgency-hub'].hidden,
        cta_hidden: els['urgency-recipe-btn'].hidden,
        sub: els['urgency-sub'].textContent,
    };
}

const out = {};
out.quiet = scenario({});
out.expired = scenario({ expiredSection: true, expiredRows: 3, expiringSection: true, expiringRows: 5 });
out.soon = scenario({ expiringSection: true, expiringRows: 2 });
out.banner = scenario({ banner: true });
console.log(JSON.stringify(out));
JS;

$tmp = sys_get_temp_dir() . '/urgency_' . getmypid() . '.js';
file_put_contents($tmp, $harness);
$raw = shell_exec('node ' . escapeshellarg($tmp) . ' 2>/dev/null');
@unlink($tmp);
$out = json_decode(trim((string)$raw), true);
check('the harness runs in node', is_array($out), (string)$raw);
if (is_array($out)) {
    check('a clean pantry shows no hub', ($out['quiet']['hub_hidden'] ?? null) === true);
    check('expired stock opens the hub with its count',
        ($out['expired']['hub_hidden'] ?? null) === false
        && str_contains((string)($out['expired']['sub'] ?? ''), '"n":3')
        && str_contains((string)($out['expired']['sub'] ?? ''), 'dashboard.urgency_expired'));
    check('expiring stock alone does not open the hub (that is the stock hub)',
        ($out['soon']['hub_hidden'] ?? null) === true);
    check('the recipe CTA appears when something is about to expire',
        ($out['expired']['cta_hidden'] ?? null) === false
        && ($out['soon']['cta_hidden'] ?? null) === false);
    check('the recipe CTA stays hidden when nothing is expiring',
        ($out['quiet']['cta_hidden'] ?? null) === true
        && ($out['banner']['cta_hidden'] ?? null) === true);
    check('an anomaly alone opens the hub', ($out['banner']['hub_hidden'] ?? null) === false);
}

// ── Every banner entry says WHERE the product is ────────────────────────────
check('the anomaly payload carries the locations',
    str_contains($api, 'GROUP_CONCAT(DISTINCT i.location) AS locations')
    && str_contains($api, "'locations'") && str_contains($api, "'location'"));
check('one helper builds the "where" line',
    str_contains($app, 'function _bannerLocationParts(')
    && str_contains($app, 'function _bannerLocationLine(')
    && str_contains($app, 'function _bannerLocationText('));
check('a product in two places shows both',
    str_contains($app, 'Array.isArray(item.locations) && item.locations.length'));
foreach ([
    '_bannerLocationLine(an)'                       => 'anomaly (both directions use it)',
    '+ _bannerLocationText(item)'                   => 'review and no-expiry rows',
    '_bannerLocationLine(pred)'                     => 'consumption prediction',
    "banner_finished_check') + _bannerLocationLine" => 'finished / vanished row',
] as $needle => $label) {
    check("the location is shown on: {$label}", str_contains($app, $needle));
}
foreach (['it', 'en', 'de', 'fr', 'es', 'zh'] as $loc) {
    $doc = json_decode((string)file_get_contents($root . "/translations/{$loc}.json"), true);
    $val = $doc['dashboard']['banner_location'] ?? '';
    check("translations/{$loc}.json has dashboard.banner_location with {location}",
        is_string($val) && str_contains($val, '{location}'));
}

// ── The banner is one row with two controls, and never repeats a listed product ──
check('every banner type renders through _collapseBannerActions()',
    substr_count($app, 'actionsEl.innerHTML = _collapseBannerActions(btns);') === 7);
$collapse = extract_fn($app, '_collapseBannerActions');
check('_collapseBannerActions() is extractable', $collapse !== '');
check('the menu toggle exists', extract_fn($app, '_toggleBannerMenu') !== '');
check('an expired entry already listed is not shown again',
    str_contains($app, "document.querySelectorAll('#expired-list .alert-item')")
    && str_contains($app, "entry.type !== 'expired' || !listedIds.has("));
check('the menu panel is styled', str_contains((string)file_get_contents($root . '/assets/css/surfaces.css'), '.banner-menu-list'));

$harness = $collapse . <<<'JS'
const t = (k) => ({ 'dashboard.row_actions': 'More actions' }[k] || k);
const escapeAttr = (s) => String(s);
const html = [
    '<button class="btn-banner btn-banner-finish" onclick="bannerFinishAll()">Finito</button>',
    '<button class="btn-banner btn-banner-use" onclick="bannerQuickUse()">Usa</button>',
    '<button class="btn-banner btn-banner-throw" onclick="bannerThrowAway()">Buttato</button>',
    '<button class="btn-banner btn-banner-edit2" onclick="editReviewItem(1,2)">Modifica</button>',
    '<button class="btn-banner btn-banner-extend" onclick="bannerExtendExpiry()">Estendi</button>',
    '<button class="btn-banner btn-banner-ok" onclick="dismissBannerExpired()">Chiudi</button>',
].join('');
const out = _collapseBannerActions(html);
console.log(JSON.stringify({
    primary: /^<button class="btn-banner btn-banner-use"/.test(out),
    primary_only_one: (out.match(/btn-banner-use"/g) || []).length === 1,
    menu_button: out.includes('banner-menu-btn'),
    menu_items: (out.match(/<button/g) || []).length,
    keeps_handlers: ['bannerFinishAll', 'bannerThrowAway', 'editReviewItem', 'bannerExtendExpiry', 'dismissBannerExpired'].every(f => out.includes(f)),
    single: _collapseBannerActions('<button class="btn-banner">Solo</button>').includes('banner-menu-list') === false,
}));
JS;

$tmp2 = sys_get_temp_dir() . '/banner_collapse_' . getmypid() . '.js';
file_put_contents($tmp2, $harness);
$raw2 = shell_exec('node ' . escapeshellarg($tmp2) . ' 2>/dev/null');
@unlink($tmp2);
$out2 = json_decode(trim((string)$raw2), true);
check('the collapse harness runs in node', is_array($out2), (string)$raw2);
if (is_array($out2)) {
    check('"Usa" becomes the primary action', (bool)($out2['primary'] ?? false));
    check('only one filled action stays visible', (bool)($out2['primary_only_one'] ?? false));
    check('the rest go behind a 40px menu button', (bool)($out2['menu_button'] ?? false));
    check('every handler survives the move', (bool)($out2['keeps_handlers'] ?? false));
    check('a single-action banner gets no empty menu', (bool)($out2['single'] ?? false));
}

// --------------------------------------------------------------------- i18n
foreach (['dashboard.urgency_title', 'dashboard.urgency_expired', 'dashboard.urgency_soon', 'dashboard.quick_recipe_short'] as $key) {
    foreach (['it', 'en', 'de', 'fr', 'es', 'zh'] as $loc) {
        $data = json_decode((string)file_get_contents($root . "/translations/{$loc}.json"), true);
        $node = $data;
        foreach (explode('.', $key) as $step) {
            $node = is_array($node) ? ($node[$step] ?? null) : null;
        }
        check("translations/{$loc}.json has {$key}", is_string($node) && trim($node) !== '');
    }
}

echo "\n{$checks} checks, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
