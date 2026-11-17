#!/usr/bin/env php
<?php
/**
 * The everyday quantity action comes before the dangerous, separately
 * confirmed full-depletion alternative on the real Use page.
 * In all locales internal 'conf' never appears as copy on that page.
 */
$root = dirname(__DIR__);
$html = (string)file_get_contents($root . '/index.html');
$js   = (string)file_get_contents($root . '/assets/js/app.js');
$css  = (string)file_get_contents($root . '/assets/css/style.css');
$fails = 0;
$check = static function (bool $ok, string $label) use (&$fails): void {
    echo ($ok ? 'PASS: ' : 'FAIL: ') . $label . PHP_EOL;
    if (!$ok) ++$fails;
};
$start = strpos($html, '<section class="page" id="page-use">');
$end = $start === false ? false : strpos($html, '<!-- ===== MANUAL / EDIT PRODUCT FORM ===== -->', $start);
$use = ($start !== false && $end !== false) ? substr($html, $start, $end - $start) : '';
$unitPos = strpos($use, 'id="use-unit-switch"');
$amountPos = strpos($use, 'id="use-quantity"');
$normalPos = strpos($use, 'id="btn-use-submit"');
$allPos = strpos($use, 'onclick="submitUseAll()"');
$check($unitPos !== false && $amountPos !== false && $normalPos !== false && $allPos !== false
    && $unitPos < $amountPos && $amountPos < $normalPos && $normalPos < $allPos,
    'Choose unit -> enter amount -> use amount -> separate use-all action');
$check(substr_count($use, 'onclick="submitUseAll()"') === 1
    && str_contains($use, 'class="use-all-option"'),
    'Single, separately grouped use-all action, not inside partial inputs');
$check(str_contains($use, 'type="submit" id="btn-use-submit"')
    && str_contains($use, 'type="button" class="btn btn-large btn-danger full-width use-all-btn"'),
    'Normal action submits form; all-stock action is separate button');
$check(str_contains($js, "document.getElementById('btn-use-submit').before(confFracDiv);")
    && substr_count($js, "document.getElementById('btn-use-submit').before(fracDiv);") === 2,
    'All fraction shortcuts render before the primary quantity submit');
$check(str_contains($js, 'function submitUseAll()')
    && str_contains($js, '_showUseAllSlideConfirm(name, qtyStr, _doSubmitUseAll);'),
    'Full-stock action retains mandatory slide confirmation');
$check(str_contains($js, '_renderUseStockSummary(items)')
    && !str_contains($js, 'confDisplay} conf'),
    'Use stock has a single localized overview rather than raw conf');
$check(str_contains($css, '.use-all-option') && str_contains($css, 'border-top: 1px solid var(--border);'),
    'Destructive alternative has visible separation');
foreach (['de', 'en', 'it', 'es', 'fr', 'zh'] as $locale) {
    $dict = json_decode((string)file_get_contents("$root/translations/$locale.json"), true);
    $hint = $dict['use']['partial_hint'] ?? '';
    $label = $dict['use']['use_all'] ?? '';
    $check(is_string($hint) && trim($hint) !== '' && is_string($label) && trim($label) !== '',
        "$locale: quantity and full-stock labels exist");
}
$de = json_decode((string)file_get_contents("$root/translations/de.json"), true);
$check(($de['units']['conf'] ?? null) === 'Packung'
    && ($de['units']['boxes'] ?? null) === 'Packungen',
    'German package unit displays Packung/Packungen rather than Pkg/conf');
$check(!str_starts_with($de['use']['partial_hint'], 'Oder')
    && !str_contains($de['use']['use_all'], '🗑️'),
    'German quantity hint is direct and consumption is not labelled as disposal');
if ($fails) {
    echo "$fails Use-page layout regressions failed." . PHP_EOL;
    exit(1);
}
echo 'All Use-page layout/i18n regressions passed.' . PHP_EOL;
