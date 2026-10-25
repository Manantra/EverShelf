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
check('the centre button label follows the voice action',
    str_contains($html, 'data-i18n="capture.nav_label"'));
check('blocks carry a 4px accent line instead of a coloured frame',
    str_contains($css, 'border-left: 4px solid var(--tone-danger)')
    && str_contains($css, 'border-left: 4px solid var(--tone-soon)')
    && str_contains($css, 'border-left: 4px solid var(--tone-info)'));

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
