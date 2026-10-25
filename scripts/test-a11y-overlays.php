#!/usr/bin/env php
<?php
/**
 * Guard test — the overlays a user meets first are announced correctly.
 *
 * Regression history (audit I1/I2): `#toast` carried no `role`/`aria-live`, so every
 * "Salvato" / "Errore" / "Esaurito" message was silent for a screen reader, while the
 * offline banner and the cooking overlay were announced. And the two first-run dialogs
 * (`#setup-wizard`, `#support-modal`) were plain `.modal-overlay` divs: no
 * `role="dialog"`, no `aria-modal`, no accessible name.
 *
 * Run: php scripts/test-a11y-overlays.php
 */

$root = dirname(__DIR__);
$html = (string)file_get_contents($root . '/index.html');
$app  = (string)file_get_contents($root . '/assets/js/app.js');
$css  = (string)file_get_contents($root . '/assets/css/style.css');
$fail = 0;
$checks = 0;

function check(string $label, bool $ok): void
{
    global $fail, $checks;
    $checks++;
    if ($ok) {
        echo "ok    {$label}\n";
    } else {
        $fail++;
        echo "FAIL  {$label}\n";
    }
}

libxml_use_internal_errors(true);
$doc = new DOMDocument();
$doc->loadHTML('<?xml encoding="UTF-8">' . $html);
$xp = new DOMXPath($doc);

// --------------------------------------------------------------------- toasts
$toast = $xp->query("//*[@id='toast']")->item(0);
check('#toast exists', $toast !== null);
if ($toast instanceof DOMElement) {
    check('#toast is announced politely (role=status)', $toast->getAttribute('role') === 'status');
    check('#toast has aria-live=polite', $toast->getAttribute('aria-live') === 'polite');
}

// --------------------------------------------------------------------- dialogs
foreach ([
    'setup-wizard'  => 'setup-wizard-title',
    'support-modal' => 'support-modal-title',
] as $dialogId => $titleId) {
    $dialog = $xp->query("//*[@id='{$dialogId}']")->item(0);
    check("#{$dialogId} exists", $dialog !== null);
    if (!$dialog instanceof DOMElement) {
        continue;
    }
    check("#{$dialogId} is a dialog", $dialog->getAttribute('role') === 'dialog');
    check("#{$dialogId} is modal (aria-modal=true)", $dialog->getAttribute('aria-modal') === 'true');
    check("#{$dialogId} names its title", $dialog->getAttribute('aria-labelledby') === $titleId);
    check("#{$titleId} exists and is a heading",
        $xp->query("//*[@id='{$titleId}']")->length === 1
        && $xp->query("//h2[@id='{$titleId}']")->length === 1);
}

// ----------------------------------------------- the offline banner stays richer
$banner = $xp->query("//*[@id='offline-banner']")->item(0);
check('#offline-banner keeps role=alert + aria-live=assertive',
    $banner instanceof DOMElement
    && $banner->getAttribute('role') === 'alert'
    && $banner->getAttribute('aria-live') === 'assertive');
check('the offline banner tooltip says when the server last answered',
    str_contains($app, "t('error.offline_last_sync', { time: when })")
    && str_contains($app, '_lastServerOkTs = Date.now();'));

// --------------------------------------------------------------------- motion
check('reduced-motion users are respected', str_contains($css, '@media (prefers-reduced-motion: reduce)'));

// --------------------------------------------------------------------- i18n slot
$json = json_decode((string)file_get_contents($root . '/translations/it.json'), true);
foreach (['it', 'en', 'de', 'fr', 'es', 'zh'] as $loc) {
    $doc2 = json_decode((string)file_get_contents($root . "/translations/{$loc}.json"), true);
    $val = $doc2['error']['offline_last_sync'] ?? '';
    check("translations/{$loc}.json has error.offline_last_sync with {time}",
        is_string($val) && str_contains($val, '{time}'));
}

echo "\n{$checks} checks, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
