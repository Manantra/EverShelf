#!/usr/bin/env php
<?php
/**
 * Guard test — EverShelf Med, the opt-in medicine cabinet (Phase 1).
 *
 * The rules this module must never break:
 *   1. OFF by default, asked once. A household that only counts tomatoes must not see a
 *      medicine string, and the first-run question is a decision ("Non ora"), not a nag.
 *   2. No medicine UI without the accepted notice (stored per instance, not per browser).
 *   3. A DEDICATED location, painted differently: the module lives where medicines live.
 *   4. Food and medicines stay apart: `for_recipe=1` never returns a medical row, an expired
 *      box gets "Smaltito" (special waste), and an expiring one keeps its place with a tint.
 *   5. The symptom lookup is DETERMINISTIC and OFFLINE over a CLOSED taxonomy (8 household
 *      categories, data file), with no AI and no dosage. The scanner recognises a medicine
 *      code instead of dead-ending on "not found".
 *
 * Run: php scripts/test-medical.php
 */
define('CRON_MODE', true);
require_once __DIR__ . '/../api/bootstrap.php';

$root   = dirname(__DIR__);
$api    = (string)file_get_contents($root . '/api/index.php');
$app    = (string)file_get_contents($root . '/assets/js/app.js');
$html   = (string)file_get_contents($root . '/index.html');
$lib    = (string)file_get_contents($root . '/api/lib/medical.php');
$css    = (string)file_get_contents($root . '/assets/css/surfaces.css');
$sec    = (string)file_get_contents($root . '/api/lib/security.php');
$db     = (string)file_get_contents($root . '/api/database.php');
$cats   = json_decode((string)file_get_contents($root . '/data/medical_categories.json'), true);
$fail   = 0;
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

$langs = ['it', 'en', 'de', 'fr', 'es', 'zh'];

// ── 1. Opt-in, and the choice is remembered ─────────────────────────────────
check('the module is off unless MED_ENABLED / the Settings toggle says so',
    str_contains($lib, "env('MED_ENABLED', 'false')") && str_contains($lib, 'med_enabled'));
check('the first run is asked once and "Non ora" is a decision',
    (bool)preg_match('/function _medSetupLater.*?evershelf_med_asked.*?med_setup_done/s', $app));
check('the one-time question is never shown again once answered',
    (bool)preg_match("/if \\(localStorage\\.getItem\\('evershelf_med_asked'\\) === '1'\\) return false;/", $app));
check('the medical UI never opens without the accepted notice',
    str_contains($app, 'return !!(_medStatus.enabled && _medStatus.accepted);'));
check('the disclaimer is stored per instance',
    str_contains($api, 'med_disclaimer_accepted') && str_contains($app, 'med_disclaimer_accepted'));
check('the accept button starts disabled', str_contains($app, 'id="med-disclaimer-ok" disabled'));
check('the module settings are the only new writable keys',
    preg_match('/evershelfClientSettingsKeys.*?med_enabled.*?medical_locations.*?med_disclaimer_accepted/s', $sec) === 1);
check('all medical actions stay authenticated',
    !str_contains($sec, "'med_symptom_search'") && !str_contains($sec, "'med_classify'")
    && !str_contains($sec, "'med_open'") && !str_contains($sec, "'med_status'"));

// ── 2. Its own settings SECTION, like "Cucina" ──────────────────────────────
check('the settings strip has a medical group',
    str_contains($app, "const SETTINGS_GROUPS = ['app', 'cooking', 'medical', 'alerts', 'system'];"));
check('the group button exists in the markup',
    str_contains($html, 'data-group="medical"') && str_contains($html, 'settings.group_medical'));
check('the panel is a real settings section with its own id',
    str_contains($html, 'id="tab-medical"') && str_contains($html, 'data-tab="tab-medical"'));
check('the module card lives in that section',
    (bool)preg_match('/id="tab-medical".*?id="med-module-card"/s', $html));
check('the card is no longer inside the Health panel',
    !preg_match('/id="tab-health".*?id="med-module-card"/s', $html));

// ── 2b. Dialogs use a class the stylesheets know ────────────────────────────
// A dialog built with a class that nobody styles is invisible in every other check: the
// medicine cabinet first-run question shipped with `modal-card` (the app paints
// `.modal-content`) and its text floated over the dashboard with no surface behind it.
$cssAll = '';
foreach (glob($root . '/assets/css/*.css') as $cssFile) { $cssAll .= (string)file_get_contents($cssFile); }
$unknown = [];
foreach (['index.html' => $html, 'assets/js/app.js' => $app] as $src => $body) {
    if (preg_match_all('/class="([^"]*)"/', $body, $m)) {
        foreach ($m[1] as $classes) {
            foreach (preg_split('/\s+/', $classes) as $token) {
                if (str_starts_with($token, 'modal-') && !str_contains($cssAll, '.' . $token)) {
                    $unknown[$src . ':' . $token] = true;
                }
            }
        }
    }
}
check('every modal-* class used in the UI exists in the stylesheets', $unknown === [], implode(', ', array_keys($unknown)));
check('the medical dialogs are drawn above the splash and the network overlay',
    (bool)preg_match('/#med-setup-overlay,\s*\n#med-disclaimer-overlay,\s*\n#med-search-overlay \{[^}]*z-index: 300500/s', $css));
check('the three medical dialogs use the real modal surface',
    str_contains($html, 'class="modal-content med-search-card"')
    && str_contains($app, 'class="modal-content med-disclaimer-card"')
    && str_contains($app, 'class="modal-content med-setup-card"'));

// ── 3. A dedicated location, with its own colour ────────────────────────────
check('the household can pick an existing location',
    str_contains($html, 'id="med-location-select"') && str_contains($app, 'function onMedLocationPick'));
check('the household can create the location from the module',
    str_contains($html, 'id="med-new-location-input"') && str_contains($app, 'function onMedLocationCreate'));
check('creating a location persists it through the settings path',
    (bool)preg_match("/api\\('save_settings', \\{\\}, 'POST', \\{ custom_locations:/s", $app));
check('the medical location is marked instance-wide and closes the first run',
    (bool)preg_match('/_saveSettingToServer\\(\\{ medical_locations: clean, med_setup_done: clean\\.length > 0 \\}\\)/s', $app));
check('a medical row is tinted, not recoloured',
    str_contains($css, '.alert-item.is-medical') && str_contains($app, 'is-medical'));
check('the tint is driven by the flag OR the marked location',
    (bool)preg_match('/function _isMedicalRow.*?is_medical.*?medical_locations/s', $app));

// ── 4. Domains separated, expiries included ─────────────────────────────────
check('the recipe flow excludes medical rows server-side',
    (bool)preg_match('/for_recipe.*?medicalEnabled\\(\\$db\\).*?is_medical, 0\\) = 0/s', $api));
check('an expiring medicine keeps its place in the expiry list',
    (bool)preg_match("/alert-item alert-item-clickable\\$\\{_isMedicalRow\\(item\\) \\? ' is-medical'/s", $app));
check('expired medicines leave the food block for their own one',
    str_contains($app, 'const medicalExpired = _medOn() ? visibleExpiredAll.filter(_isMedicalRow) : [];'));
check('the medical block offers "Smaltito", never the recipe action',
    (bool)preg_match('/function _renderMedicalBlock.*?medical\\.discard/s', $app)
    && !preg_match('/function _renderMedicalBlock.*?_rowMenuButtons/s', $app));
check('the medical block is hidden when the module is off',
    (bool)preg_match("/function _renderMedicalBlock.*?if \\(!_medOn\\(\\).*?section\\.style\\.display = 'none'/s", $app));

// ── 5. Taxonomy: CLOSED, data-only, six locales ─────────────────────────────
$slugs = array_map(fn($c) => $c['slug'] ?? '', $cats['categories'] ?? []);
check('the taxonomy is exactly the 8 household categories',
    $slugs === ['pain', 'stomach', 'cold', 'allergy', 'firstaid', 'eyes', 'muscles', 'supplements']);
$complete = true;
foreach (($cats['categories'] ?? []) as $c) {
    foreach ($langs as $l) {
        if (empty($c['symptoms'][$l]) || !is_array($c['symptoms'][$l])) { $complete = false; }
    }
    if (empty($c['label_key']) || !str_starts_with((string)$c['label_key'], 'medical.cat_')) { $complete = false; }
}
check('every category speaks all six locales and points at an i18n label', $complete);
check('the only valid tags are the ones in the file',
    (bool)preg_match('/function medicalCategorySlugs.*?medicalCategories/s', $lib));
check('the matcher is local: no AI call, no network',
    !preg_match("/callGemini|curl_|file_get_contents\\([\\'\"]http/s", $lib));
check('the longest phrase wins ("mal di testa" before "testa")',
    (bool)preg_match('/uksort\(\$phrases.*?mb_strlen/s', $lib));
check('"ho un forte mal di testa" resolves to the pain category',
    (medicalMatchSymptom('ho un forte mal di testa')[0]['slug'] ?? '') === 'pain');
check('"mi brucia lo stomaco" resolves to the stomach category',
    (medicalMatchSymptom('mi brucia lo stomaco')[0]['slug'] ?? '') === 'stomach');
check('an English symptom works too', (medicalMatchSymptom('I have a headache')[0]['slug'] ?? '') === 'pain');
check('an unknown sentence resolves to nothing (no guessing)',
    medicalMatchSymptom('devo comprare il pane') === []);
check('the search reads products.med_tags (Phase 1) and falls back to ATC (Phase 2)',
    (bool)preg_match('/function medSymptomSearchAction.*?medicalTagsOf\(\$row\).*?array_intersect\(\$tags, \$slugs\)/s', $api));

// ── 6. Classification + PAO ─────────────────────────────────────────────────
check('med_classify writes only a slug from the closed list',
    (bool)preg_match('/function medClassifyAction.*?in_array\(\$t, \$valid, true\)/s', $api));
check('classifying flags the product as medical',
    (bool)preg_match('/UPDATE products SET is_medical = 1, med_tags = \?, med_atc = \?, med_ingredient = \?, med_pao_days = \?/s', $api));
check('the schema adds the Phase-1 columns',
    str_contains($db, 'ADD COLUMN med_tags') && str_contains($db, 'ADD COLUMN med_pao_days'));
check('"Segna aperto" replays the operational expiry from the PAO number',
    (bool)preg_match("/med_open.*?expiry_date = date\('now', \?\).*?expiry_user_set = 1/s", $api));
check('the classification UI is a closed multi-select, never a text field',
    (bool)preg_match('/function _renderMedClassify.*?med-cat-chips.*?type="checkbox"/s', $app));
check('every box has its own "Segna aperto"', str_contains($app, 'function _medMarkOpened'));

// ── 7. Scanner ──────────────────────────────────────────────────────────────
check('a medicine code is not an error: the manual entry opens prefilled',
    (bool)preg_match('/_medStatus\.enabled && _looksLikeMedicineCode\(code\).*?_medStartManualEntry\(code\)/s', $app));
check('EAN-13/EAN-8 stay on the food path',
    (bool)preg_match('/function _looksLikeMedicineCode.*?d\{13\}.*?return false/s', $app));

// ── 8. Safety copy: no dosage, all six locales ──────────────────────────────
$med = [];
foreach ($langs as $l) {
    $data = json_decode((string)file_get_contents($root . "/translations/{$l}.json"), true);
    $med[$l] = $data['medical'] ?? [];
}
$base = array_keys($med['it']);
sort($base);
$aligned = true;
foreach ($langs as $l) {
    $keys = array_keys($med[$l]);
    sort($keys);
    if ($keys !== $base) { $aligned = false; }
}
check('all six locales carry the same 66 medical keys', $aligned && count($base) === 66);
check('the category labels PHP returns exist in all six locales',
    array_reduce($cats['categories'], function ($carry, $c) use ($med, $langs) {
        $key = substr((string)$c['label_key'], strlen('medical.'));
        foreach ($langs as $l) {
            if (!isset($med[$l][$key])) { return false; }
        }
        return $carry;
    }, true));
$dose = '/\b\d+\s*(mg|ml|mcg|compresse|gocce|pastiglie|capsule)\b/iu';
$hasDose = array_reduce($langs, fn($c, $l) => $c
    || preg_match($dose, $med[$l]['disclaimer'] ?? '')
    || preg_match($dose, $med[$l]['no_dosage'] ?? '')
    || preg_match($dose, $med[$l]['setup_why'] ?? ''), false);
check('the medical safety copy never states a dose', !$hasDose);
check('the search answer always carries the disclaimer key',
    str_contains($api, "'disclaimer_key' => 'medical.disclaimer'"));

echo "\n" . ($fail === 0 ? 'PASS' : 'FAIL') . " — {$checks} checks, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);

