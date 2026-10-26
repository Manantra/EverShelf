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
// Round 3: an inventory anomaly is not food to save, so the banner left the hub.
check('the system banner is NOT inside the hub',
    $xp->query("//*[@id='urgency-hub']//*[@id='alert-banner']")->length === 0);
check('the system banner sits above the hub',
    strpos($html, 'id="alert-banner"') < strpos($html, 'id="urgency-hub"'));
check('the banner is a dismissible system strip',
    $xp->query("//*[@id='alert-banner'][@class='alert-banner system-alert']")->length === 1);
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
    check('an anomaly alone does NOT open the food hub (it is a system strip now)',
        ($out['banner']['hub_hidden'] ?? null) === true);
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

// ── Every banner action is visible: no ⋮, and always a "Modifica" (round 8) ──
check('no banner hides its actions behind a menu any more',
    !str_contains($app, '_collapseBannerActions')
    && !str_contains($app, '_toggleBannerMenu')
    && !str_contains($app, 'banner-menu-list')
    && substr_count($app, 'actionsEl.innerHTML = btns;') >= 6
    && !str_contains((string)file_get_contents($root . '/assets/css/surfaces.css'), '.banner-menu-list'));
check('the banner actions wrap instead of hiding',
    preg_match('/\.alert-banner-actions \{[^}]*flex-wrap: wrap/s', (string)file_get_contents($root . '/assets/css/surfaces.css')) === 1);
check('the expired strip offers four useful actions',
    str_contains($app, 'btn-banner btn-banner-use" onclick="bannerQuickUse()"')
    && str_contains($app, 'btn-banner btn-banner-finish" onclick="bannerFinishAll()"')
    && str_contains($app, 'btn-banner btn-banner-throw" onclick="bannerThrowAway()"')
    && str_contains($app, 'btn-banner btn-banner-edit" onclick="editReviewItem(')
    && !str_contains($app, 'onclick="dismissBannerExpired()"')
    && !str_contains($app, 'onclick="bannerExtendExpiry()"')
    && !str_contains($app, 'onclick="bannerMarkVacuum()"'));
check('every banner type carries an edit action',
    substr_count($app, "t('dashboard.banner_review_action_edit')") >= 2
    && str_contains($app, "t('dashboard.banner_expired_action_modify')")
    && str_contains($app, "t('dashboard.banner_anomaly_edit_qty')")
    && str_contains($app, "t('dashboard.banner_dup_loss_action_fix')"));

// The anomaly strip keeps its own rules from round 6.
check('the anomaly banner keeps its actions inline',
    str_contains($app, 'btn-banner btn-banner-use" onclick="alignBannerAnomaly()"'));
check('the anomaly strip offers exactly two choices',
    str_contains($app, 'btn-banner btn-banner-edit-qty" onclick="editBannerAnomalyQuantity()"')
    && !str_contains($app, 'onclick="dismissBannerAnomaly()"')
    && !str_contains($app, 'onclick="finishBannerAnomaly()"')
    && !str_contains($app, 'onclick="throwBannerAnomaly()"')
    && !str_contains($app, 'onclick="explainBannerAnomaly()"'));
check('the anomaly primary names the number it writes',
    str_contains($app, "t('dashboard.banner_anomaly_align', { qty: an.expected_qty, unit: an.unit })"));
check('the second choice opens the product sheet',
    str_contains($app, 'function editBannerAnomalyQuantity(')
    && str_contains($app, 'showItemDetail(invId, Number(an.product_id) || row.product_id)'));
check('✕ dismisses an anomaly for good and never touches the stock',
    preg_match('/function dismissBannerItem\(\)[\s\S]{0,900}entry\.type === .anomaly.[\s\S]{0,200}_bannerMarkAnomalyDismissed/', $app) === 1
    && !preg_match('/function _bannerMarkAnomalyDismissed[\s\S]{0,500}inventory_confirm_finished/', $app));
foreach (['it', 'en', 'de', 'fr', 'es', 'zh'] as $loc) {
    $doc = json_decode((string)file_get_contents($root . "/translations/{$loc}.json"), true);
    $align = (string)($doc['dashboard']['banner_anomaly_align'] ?? '');
    check("translations/{$loc}.json says confirm-N-unit",
        str_contains($align, '{qty}') && str_contains($align, '{unit}'));
    check("translations/{$loc}.json has the second choice",
    isset($doc['dashboard']['banner_anomaly_edit_qty']) && $doc['dashboard']['banner_anomaly_edit_qty'] !== '');
}
check('an expired entry already listed is not shown again',
    str_contains($app, "document.querySelectorAll('#expired-list .alert-item')")
    && str_contains($app, "entry.type !== 'expired' || !listedIds.has("));

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
