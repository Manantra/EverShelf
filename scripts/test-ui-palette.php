#!/usr/bin/env php
<?php
/**
 * Guard test — the "Fresh & Crisp Slate" palette, the three text levels and the one
 * relative-date format.
 *
 * Regression history (UI review round 2): the dashboard had five pastel tones, six
 * text sizes between 10 and 15 px, a deep forest-green header against white cards and
 * three different ways to say "36 days" in the same screen ("Da 36g", "6 giorni",
 * "8gg"). This locks the rules instead of the pixels:
 *
 *   canvas #F8FAFC · card #FFFFFF · border #E2E8F0 · text #0F172A · muted #64748B
 *   brand #059669 · danger/warning/info as light background + dark text + accent line
 *   title 15/600 · metadata 13/400 · badge 12/600
 *   "tra N gg" / "N gg fa" everywhere on the dashboard
 *
 * Run: php scripts/test-ui-palette.php
 */

$root = dirname(__DIR__);
$css  = (string)file_get_contents($root . '/assets/css/surfaces.css');
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

// ── Tokens ─────────────────────────────────────────────────────────────────
foreach ([
    '--bg-app: #F8FAFC'        => 'canvas slate',
    '--surface-bg: #FFFFFF'    => 'pure white surface',
    '--surface-border: #E2E8F0' => 'hairline border',
    '--text-main: #0F172A'     => 'main text',
    '--text-muted: #64748B'    => 'muted text',
    '--brand-primary: #059669' => 'brand emerald',
    '--brand-primary-hover: #047857' => 'brand pressed',
    '--brand-surface: #ECFDF5' => 'brand surface',
    '--tone-danger-soft: #FEE2E2' => 'danger badge background',
    '--tone-danger-text: #991B1B' => 'danger badge text',
    '--tone-soon-soft: #FEF3C7' => 'warning badge background',
    '--tone-soon-text: #92400E' => 'warning badge text',
    '--tone-info-soft: #E0F2FE' => 'info badge background',
    '--tone-info-text: #075985' => 'info badge text',
] as $needle => $label) {
    check("palette: {$label} ({$needle})", str_contains($css, $needle));
}
check('the canvas is applied to the body, not only declared', str_contains($css, "body {\n    background: var(--bg-app);"));

// ── Chrome ─────────────────────────────────────────────────────────────────
check('the header is a white surface with a hairline',
    preg_match('/\.app-header \{[^}]*background: var\(--surface-bg\)/s', $css) === 1);
check('the header text is dark on white',
    str_contains($css, '.app-header .header-title'));
check('the active nav item uses the brand colour',
    preg_match('/\.nav-btn\.active \{[^}]*color: var\(--brand-primary\)/s', $css) === 1);
$navBlock = '';
if (preg_match('/id="nav-capture-btn".*?<\/button>/s', $html, $m)) {
    $navBlock = $m[0];
}
check('the centre button exists', $navBlock !== '');
check('the centre button is a microphone, not the old sparkle',
    str_contains($navBlock, 'nav-capture-icon') && !str_contains($navBlock, 'M12 0C12 6.627'));
check('the centre button carries no text label',
    !preg_match('/id="nav-capture-btn".*?class="nav-label"/s', $navBlock));
check('the centre button is bigger than the other tabs',
    preg_match('/\.nav-capture-icon-wrap \{[^}]*width: clamp\(48px/s', $css) === 1);
check('the centre button always breathes (a still FAB reads as decoration)',
    str_contains($css, 'animation: capture-breathe') && str_contains($css, '@keyframes capture-breathe')
    && str_contains($css, '@keyframes capture-halo'));
check('the animation respects reduced motion',
    preg_match('/@media \(prefers-reduced-motion: reduce\) \{[^}]*\.nav-capture-icon-wrap/s', $css) === 1);
check('no Gemini branding survives in the nav (markup or CSS)',
    !str_contains($html, 'nav-gemini') && !str_contains($css, 'nav-gemini')
    && !str_contains((string)file_get_contents($root . '/assets/css/style.css'), 'nav-gemini'));
check('the capture button stays available offline (use/add work locally)',
    !str_contains((string)file_get_contents($root . '/assets/css/style.css'), 'offline-mode .nav-gemini'));
check('blocks carry a 4px accent line instead of a coloured frame',
    str_contains($css, 'border-left: 4px solid var(--tone-danger)')
    && str_contains($css, 'border-left: 4px solid var(--tone-soon)')
    && str_contains($css, 'border-left: 4px solid var(--tone-info)'));

// ── Round 3: no cabins, slim verdict, tappable extend ──────────────────────
check('semantic blocks reset all four sides to one hairline',
    preg_match('/\.alert-danger,\s*\.alert-opened,[\s\S]{0,200}\.system-alert \{[^}]*border: 1px solid var\(--surface-border\)/s', $css) === 1);
check('the tone survives only as a 4px left rail',
    preg_match('/\.alert-danger, \.urgency-hub \{ border-left: 4px solid var\(--tone-danger\)/', $css) === 1
    && str_contains($css, '#alert-expiring            { border-left: 4px solid var(--tone-soon); }'));
check('the verdict rides on the meta line instead of a full bar',
    str_contains($html, 'alert-item-meta-row') || str_contains($app, 'alert-item-meta-row'));
check('the verdict chip is compact',
    preg_match('/\.alert-item-meta-row \.safety-pill \{[^}]*font-size: 12px/s', $css) === 1);
// ── Round 4: the row's frequent action, one modal hierarchy ─────────────────
check('the row offers consume + ⋮, not a permanent "extend expiry" pill',
    str_contains($app, 'class="row-use-btn"')
    && preg_match('/\.row-use-btn \{[^}]*min-height: var\(--touch-min\)/s', $css) === 1
    && !str_contains($app, 'btn-alert-extend'));
check('extending an expiry moved into the detail modal',
    str_contains($app, 'extendInventoryExpiry(${item.id})') && str_contains($app, 'function _shouldOfferExtendExpiry('));
check('the product modal has a quantity stepper',
    str_contains($app, 'function _itemDetailConsume(')
    && str_contains($app, 'class="item-detail-stepper"')
    && str_contains($css, '.item-detail-step-value {'));
check('the product modal has one primary, one secondary, neutral support',
    str_contains($app, 'class="item-detail-primary"')
    && str_contains($app, 'class="item-detail-secondary"')
    && str_contains($app, 'class="item-detail-support-btn')
    && !str_contains($app, 'item-detail-action--use')
    && !str_contains($app, 'item-detail-action--recipe'));
check('barcode and timestamps are collapsed, not in the middle of the card',
    str_contains($app, 'class="item-detail-more"')
    && str_contains($css, '.item-detail-more > summary {'));
// Round 14 (audit): the row's ✓ and the modal are the same decision, so they cannot
// land in two different shapes — the old full-page USE form stays for SHELFY only.
check('the row use action opens the product modal',
    preg_match('/async function quickUse\(productId, location\) \{[\s\S]{0,400}showItemDetail\(invId, productId\)/', $app) === 1);
check('consume everything stays in the modal',
    str_contains($app, '_loadUseProduct(') && str_contains($app, 'submitUseAll();')
    && preg_match('/async function itemDetailUseAll\([^)]*\) \{[^}]*showPage/', $app) === 0);
// The scale was a white glyph on a white header with a 6 px dot, and its feedback was a
// toast over the buttons: it is a hardware indicator now.
check('the scale is a pill, not a ghost icon',
    str_contains($html, 'id="scale-status-indicator" class="scale-pill')
    && str_contains($css, '.scale-pill.scale-status-connected {'));
check('the scale shows the live weight and the battery',
    str_contains($app, 'function _scaleRenderPill(')
    && str_contains($html, 'id="scale-pill-weight"')
    && str_contains($html, 'id="scale-pill-battery"'));
check('tapping the scale opens a popover, not a toast',
    str_contains($html, 'id="scale-popover"')
    && str_contains($app, 'function _scaleTogglePopover(')
    && str_contains($app, 'function _scaleTare(')
    && !str_contains($html, 'onclick="_scaleShowInfo()"'));
check('a gateway replay cannot paint a live pill',
    str_contains($app, 'const SCALE_LIVE_MS = 10000;')
    && str_contains($app, 'if (_scaleWeightMsgCount === 1) {')
    && str_contains($app, 'function _scaleResetLiveWindow(')
    && str_contains($app, "pill.classList.toggle('scale-status-waiting'")
    && str_contains($app, '_scaleResetLiveWindow();'));
check('a scale that was never configured stays hidden',
    str_contains($app, 'if (!s.scale_enabled || !s.scale_gateway_url)')
    && str_contains($app, "indicator.style.display = 'none';"));
// The recipes own the nav slot (a household uses them daily); the scanner is still one tap
// away from the dashboard and the product flows, without taking a slot of its own.
check('the recipes have their nav tab back and the scanner is not a slot',
    str_contains($html, 'data-page="recipe"') && !str_contains($html, 'data-page="scan"')
    && str_contains($html, 'data-i18n="nav.recipes"'));
check('scanning a product you already have opens the product modal',
    str_contains($app, 'function _inventoryRowForProduct(')
    && str_contains($app, '_inventoryRowForProduct(currentProduct.id)'));
check('the anomaly banner offers a named action instead of "Giusta"',
    str_contains($app, 'function alignBannerAnomaly(')
    && str_contains($app, "t('dashboard.banner_anomaly_align'")
    && str_contains($app, 'btn-banner btn-banner-use'));
check('no banner hides its actions in a menu',
    !str_contains($css, '.banner-menu-list') && !str_contains($app, '_collapseBannerActions'));
check('the capture sheet follows the emerald hierarchy',
    preg_match('/\.capture-input-row \.btn-accent \{[^}]*background: var\(--brand-primary\)/s', $css) === 1);
check('capture hints are solid pills, not dashed placeholders',
    preg_match('/\.capture-hint \{[^}]*border: 1px solid var\(--surface-border\)/s', $css) === 1
    && !str_contains($css, 'border: 1px dashed var(--surface-border)'));
// ── Round 5: colours that mean something, one card per tab ──────────────────
// Round 13 (photo/palette pass): the strip keeps every action inline, but only the action
// the user is being asked to perform NOW is solid emerald; finishing, discarding and
// correcting stock are neutral secondaries. The pastel tones (sky, pink, azure) made the
// banner look like a different app from the rows underneath.
check('the banner actions are inline: one emerald primary, neutral secondaries',
    str_contains($app, 'actionsEl.innerHTML = btns;')
    && preg_match('/\.alert-banner-actions \.btn-banner-finish,\s*\n\.alert-banner-actions \.btn-banner-throw,[\s\S]{0,700}?background: var\(--surface-bg\);/', $css) === 1
    && preg_match('/\.alert-banner-actions \.btn-banner-ok,\s*\n\.alert-banner-actions \.btn-banner-confirm \{[^}]*background: var\(--brand-primary\)/s', $css) === 1
    && !preg_match('/\.alert-banner-actions \.btn-banner-(finish|throw|edit) \{[^}]*var\(--tone-(danger|info)-soft\)/s', $css));
check('"Dimenticati" is the same card as "In scadenza"',
    preg_match('/\.alert-stale,\s*\[data-theme="dark"\] \.alert-stale \{[^}]*background: var\(--surface-bg\)/s', $css) === 1
    && preg_match('/\.alert-stale h3,\s*\[data-theme="dark"\] \.alert-stale h3 \{ color: var\(--text-light\)/s', $css) === 1);
check('each hub panel keeps its own rail and its own left padding',
    str_contains($css, '.stock-hub #alert-expiring { border-left-color: var(--tone-soon); }')
    && str_contains($css, '.stock-hub .alert-stale    { border-left-color: var(--tone-calm); }')
    && preg_match('/\.stock-hub \.alert-section \{[^}]*padding: 4px 0 0 10px/s', $css) === 1);
check('the capture mic wears an animated border at rest',
    str_contains($css, '@keyframes capture-ring-turn')
    && str_contains($css, '.capture-mic-wrap::before {')
    && str_contains($css, '.capture-mic:hover'));
check('the mic shows a sonar ring while recording',
    str_contains($css, '@keyframes capture-ping') && str_contains($css, '.capture-mic.is-listening {'));
check('the system alert strip is neutral, not an amber card',
    str_contains($css, '.alert-banner.system-alert.banner-anomaly') && str_contains($css, '.system-alert .alert-banner-title'));

// ── Three text levels ──────────────────────────────────────────────────────
check('product title is 15px / 600', preg_match('/\.alert-item-name \{[^}]*font-size: 15px[^}]*font-weight: 600/s', $css) === 1);
check('metadata is 13px muted', preg_match('/\.alert-item-qty,[\s\S]{0,400}\.alert-section-hint[\s\S]{0,400}font-size: 13px/s', $css) === 1);
check('badges are 12px / 600', preg_match('/\.alert-item-badge,[\s\S]{0,400}font-size: 12px[^}]*font-weight: 600/s', $css) === 1);

// ── One relative-date format ───────────────────────────────────────────────
check('the two helpers exist', str_contains($app, 'function _relativeDaysAhead(') && str_contains($app, 'function _relativeDaysAgo('));
check('the expiring badge uses the helper', str_contains($app, 'const badgeText = _relativeDaysAhead(days);'));
check('the expired badge uses the helper', str_contains($app, 'const daysText = _relativeDaysAgo(days);'));
check('the stale label uses the helper', str_contains($app, ': _relativeDaysAgo(days);'));
check('the dashboard no longer mixes the old formats',
    !str_contains($app, "t('expiry.days').replace('{days}', days)")
    && !str_contains($app, "t('dashboard.stale_days').replace('{n}', String(days))"));
foreach (['it', 'en', 'de', 'fr', 'es', 'zh'] as $loc) {
    $doc = json_decode((string)file_get_contents($root . "/translations/{$loc}.json"), true);
    foreach (['in_days_short', 'days_ago_short'] as $key) {
        $val = $doc['expiry'][$key] ?? '';
        check("translations/{$loc}.json has expiry.{$key} with {n}", is_string($val) && str_contains($val, '{n}'));
    }
}

echo "\n{$checks} checks, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
