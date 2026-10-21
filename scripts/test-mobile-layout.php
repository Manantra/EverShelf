#!/usr/bin/env php
<?php
/**
 * Regression tests: the list rows stay readable on a phone.
 *
 * The report: "da mobile molte scritte sono troncate o vanno a capo dopo poche lettere".
 * Measured at a 360 px viewport (Chromium, the app's own CSS) before the fix:
 *
 *   CLIP   alert-item-name   w=107  "Formaggio spalmabile Philadelphia, Class…"
 *   CLIP   alert-item-name   w=121  "Macedonia di Verdure"
 *   CLIP   inv-name          w=308  (inventory + product rows)
 *   NARROW shop-row-title-name w=0 lines=43  ← the shopping title collapsed and wrapped
 *                                              one letter per line (the qty + urgency
 *                                              badge + price cell are nowrap siblings)
 *   CLIP   recipe-archive-title w=286
 *
 * The cause was desktop-first geometry: `.alert-item-badges { max-width: 64% }` starved
 * the text column, and `.shop-row-title` is a flex row whose name is `flex: 1` beside
 * nowrap siblings. `assets/css/mobile.css` (layer 4, loaded LAST so the themes cannot
 * beat it) gives the text the full row width and moves badges/quantities under it.
 *
 * This test locks the plumbing (the layer is loaded, cached and carries the rules) —
 * the geometry itself is measured with a Chromium harness (see the commit message).
 *
 * Run: php scripts/test-mobile-layout.php
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

$root = __DIR__ . '/..';
$html = (string)file_get_contents($root . '/index.html');
$sw   = (string)file_get_contents($root . '/sw.js');
$css  = (string)file_get_contents($root . '/assets/css/mobile.css');

// ── The layer is loaded, and last ───────────────────────────────────────────
$posMobile  = strpos($html, 'assets/css/mobile.css');
$posElegant = strpos($html, 'assets/css/elegant.css');
assert_true($posMobile !== false, 'index.html loads assets/css/mobile.css');
assert_true($posMobile > $posElegant, 'mobile.css comes AFTER elegant.css (the themes cannot override it)');
assert_true(str_contains($sw, "BASE + 'assets/css/mobile.css'"), 'sw.js precaches mobile.css');

// ── The rules that fixed the measured failures ─────────────────────────────
$rules = [
    '/\.alert-item\s*\{[^}]*flex-wrap:\s*wrap/'                     => 'alert rows wrap instead of squeezing the text',
    '/\.alert-item-info\s*\{[^}]*flex:\s*1 1 100%/'                 => 'the alert text owns the full line',
    '/\.alert-item-badges\s*\{[^}]*max-width:\s*100%/'              => 'badges/buttons get the full width under it',
    '/\.alert-item-name[\s\S]{0,400}?white-space:\s*normal/'        => 'alert names are not truncated',
    '/\.alert-item-badges \.btn-banner[\s\S]{0,200}?white-space:\s*nowrap/' => 'row buttons never break mid-word',
    '/\.inv-name\s*\{[^}]*white-space:\s*normal/'                   => 'inventory/product names wrap',
    '/\.shop-row-title\s*\{[^}]*flex-wrap:\s*wrap/'                 => 'the shopping title wraps',
    '/\.shop-row-title-name\s*\{[^}]*flex:\s*1 1 100%/'             => 'the shopping name gets the first line',
    '/\.recipe-archive-title\s*\{[^}]*white-space:\s*normal/'       => 'recipe titles wrap',
];
foreach ($rules as $regex => $msg) {
    assert_true((bool)preg_match($regex, $css), $msg);
}

// ── It is geometry only: the themes keep their colours ─────────────────────
assert_true(!preg_match('/(^|\n)\s*(body|\.alert-item|\.shop-row|\.inv-name)[^{]*\{[^}]*(background:|color:)/', $css),
    'mobile.css does not paint colours (themes stay in charge)');
assert_true(str_contains($css, '@media (max-width: 640px)'), 'the block is scoped to phones (≤640px)');
assert_true(str_contains($css, '@media (max-width: 400px)'), 'very narrow phones get their own pass');

if ($fail === 0) {
    echo "\nAll mobile-layout guard tests passed.\n";
}
exit($fail === 0 ? 0 : 1);
