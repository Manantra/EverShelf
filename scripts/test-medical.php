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
check('the one-time question follows the SERVER, not this browser',
    (bool)preg_match('/function _medMaybeAskSetup\(\) \{[\s\S]{0,600}?if \(!_medStatus \|\| _medStatus\.setupDone\) return false;/s', $app)
    && !preg_match('/function _medMaybeAskSetup\(\) \{[\s\S]{0,600}?localStorage\.getItem/s', $app));
check('the medical UI never opens without the accepted notice',
    str_contains($app, 'return !!(_medStatus.enabled && _medStatus.accepted);'));
check('the disclaimer is stored per instance',
    str_contains($api, 'med_disclaimer_accepted') && str_contains($app, 'med_disclaimer_accepted'));
check('the consent button starts disabled', str_contains($app, 'id="med-setup-next" disabled'));
check('the module settings are the only new writable keys',
    preg_match('/evershelfClientSettingsKeys.*?med_enabled.*?medical_locations.*?med_disclaimer_accepted/s', $sec) === 1);
check('all medical actions stay authenticated',
    !str_contains($sec, "'med_symptom_search'") && !str_contains($sec, "'med_classify'")
    && !str_contains($sec, "'med_open'") && !str_contains($sec, "'med_status'"));

// ── 1b. The first-run flow actually stores what it promises ─────────────────
// The keys live in app_settings (instance-level). `_saveSettingToServer()` writes the .env
// through `save_settings` and silently ignores unknown keys, which is why enabling the
// module did nothing: the acceptance and the switch never reached the server.
check('the module state is written through app_settings_save',
    (bool)preg_match("/function _medSaveInstanceSettings[\s\S]{0,300}?api\('app_settings_save'/s", $app));
check('no medical key goes through the .env writer any more',
    !preg_match('/_saveSettingToServer\(\{ *med_/', $app));
check('the medical keys are writable by the client (setup + country)',
    str_contains($sec, "'med_setup_done',") && str_contains($sec, "'med_country',"));
check('the wizard has three steps: question, notice, place',
    str_contains($app, 'function _medSetupRender(step)')
    && (bool)preg_match("/if \(step === 2\)/s", $app)
    && (bool)preg_match("/if \(step === 3\)/s", $app));
check('the notice is long, not a one-liner (five paragraphs in every locale)',
    // Literal t() calls: the i18n audit cannot see a key built at runtime.
    array_reduce([1, 2, 3, 4, 5], fn($c, $i) => $c && str_contains($app, "t('medical.disclaimer_p{$i}')"), true)
    && !str_contains($app, "t('medical.' + k)")
    && array_reduce(['it','en','de','fr','es','zh'], function ($c, $l) use ($root) {
        $d = json_decode((string)file_get_contents($root . "/translations/{$l}.json"), true);
        for ($i = 1; $i <= 5; $i++) {
            if (mb_strlen((string)($d['medical']['disclaimer_p' . $i] ?? '')) < 40) { return false; }
        }
        return $c;
    }, true));
check('the last step requires a location, existing or brand new',
    (bool)preg_match("/id=\"med-setup-location\"/s", $app)
    && (bool)preg_match("/id=\"med-setup-new-location\"/s", $app)
    && str_contains($app, 'medical.location_required_toast'));
check('finishing stores enable + consent + location + setup_done in one save',
    (bool)preg_match('/med_enabled: true,[\s\S]{0,200}?med_disclaimer_accepted: true,[\s\S]{0,200}?medical_locations: \[name\],[\s\S]{0,120}?med_setup_done: true/s', $app));
check('the UI re-reads the server after saving (the switch cannot lie)',
    (bool)preg_match('/await _medSaveInstanceSettings\(\{[\s\S]{0,900}?await _medRefreshStatus\(\);/s', $app));
check('the introduction can be reopened from Settings',
    str_contains($html, 'onclick="_medReopenSetup()"') && str_contains($app, 'function _medReopenSetup'));

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
    (bool)preg_match('/#med-setup-overlay,\s*\n#med-search-overlay \{[^}]*z-index: 300500/s', $css));
check('the medical dialogs use the real modal surface',
    str_contains($html, 'class="modal-content med-search-card"')
    && str_contains($app, 'class="modal-content med-setup-card"'));

// ── 3. A dedicated location, with its own colour ────────────────────────────
check('the household can pick an existing location',
    str_contains($html, 'id="med-location-select"') && str_contains($app, 'function onMedLocationPick'));
check('the household can create the location from the module',
    str_contains($html, 'id="med-new-location-input"') && str_contains($app, 'function onMedLocationCreate'));
check('creating a location persists it through the settings path',
    (bool)preg_match("/api\\('save_settings', \\{\\}, 'POST', \\{ custom_locations:/s", $app));
check('the medical location is marked instance-wide and closes the first run',
    (bool)preg_match('/_medSaveInstanceSettings\\(\\{ medical_locations: clean, med_setup_done: clean\\.length > 0 \\}\\)/s', $app));
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

// ── 4c. Barcode schemas per country (data, with sources) ────────────────────
$schemas = json_decode((string)file_get_contents($root . '/data/medical_code_schemas.json'), true);
$ccs = is_array($schemas['countries'] ?? null) ? $schemas['countries'] : [];
check('the per-country schemas are a data file with sources', count($ccs) >= 15 && !empty($schemas['sources']));
check('no schema claims a drug catalogue (codes only, no data)',
    !preg_match('/"catalogue"\s*:/', (string)file_get_contents($root . '/data/medical_code_schemas.json')));
check('Italy and Germany are complete, the rest is honest about being partial',
    ($ccs['IT']['status'] ?? '') === 'full' && ($ccs['DE']['status'] ?? '') === 'full'
    && ($ccs['FR']['status'] ?? '') === 'partial');
check('Italy carries the SSN label (Pharmacode) and the 800-839 GS1 range',
    (bool)array_filter($ccs['IT']['codes'] ?? [], fn($c) => ($c['type'] ?? '') === 'pharmacode')
    && ($ccs['IT']['gs1'][0] ?? []) === ['800', '839']);
// The detector: high confidence where the schema is complete, nothing where it is not.
$it = medicalDetectCode('8012345678901', 'IT');
$itPharma = medicalDetectCode('123456', 'IT');
$de = medicalDetectCode('09876543', 'DE');
$fr = medicalDetectCode('3400930000000', 'FR');
$zz = medicalDetectCode('8012345678901', 'ZZ');
check('an Italian EAN in the 80… range is recognised as a medicine code',
    $it['detected'] === true && $it['confidence'] === 'high' && $it['type'] === 'ean13');
check('an Italian SSN Pharmacode is recognised as a medicine code',
    $itPharma['detected'] === true && $itPharma['confidence'] === 'high' && $itPharma['type'] === 'pharmacode');
check('a German PZN (8 digits) is recognised', $de['detected'] === true && $de['type'] === 'pzn');
check('a partial country only says "probable"', $fr['detected'] === true && $fr['confidence'] === 'probable');
check('a country with no schema claims nothing at all',
    $zz['detected'] === false && $zz['confidence'] === 'none');
check('med_status answers with country, countries and schema',
    substr_count($api, "'countries' => ") >= 1 && substr_count($api, "'schema' => ") >= 1
    && (bool)preg_match('/country. => .country,/s', $api));
check('the country lives in app_settings, not in the .env writer',
    str_contains($sec, "'med_country',") && (bool)preg_match('/function medicalActiveCountry[\s\S]{0,700}?med_country/s', $lib));
check('the settings card has the country picker', str_contains($html, 'id="med-country-select"')
    && str_contains($app, 'function _medRenderCountryPicker'));
check('with no schema the scanner says so instead of pretending',
    str_contains($app, "_medStatus.schema.status === 'none'") && str_contains($app, 'unsupportedNoteKey'));

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

// ── 6b. The product form closes the gap ─────────────────────────────────────
// A scanned medicine used to become a normal product to re-classify later. The form now has
// the toggle, the closed chips and the PAO in place, and the add step prefers the cabinet.
check('the product form carries the medicine toggle, hidden until the module is on',
    str_contains($html, 'id="pf-medical-group"') && str_contains($html, 'id="pf-medical-group" hidden')
    && str_contains($html, 'id="pf-is-medical"'));
check('the toggle shows the closed category chips and the PAO, never a free text tag',
    str_contains($html, 'id="pf-med-cats"') && str_contains($html, 'id="pf-med-pao"')
    && !preg_match('/id="pf-med-cats"[^>]*contenteditable/', $html));
check('saving the form stores the medical fields through med_classify',
    (bool)preg_match("/const medPayload = _pfMedicalPayload\(\);[\s\S]{0,400}?api\('med_classify', \{ product_id: result\.id/s", $app));
check('the add step defaults to the medical location for a medicine',
    // The value is resolved to a pantry KEY (the stored marking may be the typed label) and
    // recorded as decided, so no history/AI hint can move a medicine out of its cabinet.
    (bool)preg_match('/const autoLoc = resolveLocationKey\(\(_isMedicalRow\(currentProduct\) && medLoc\) \? medLoc : guessLocation\(currentProduct\)\) \|\| \'dispensa\';/s', $app)
    && (bool)preg_match('/function _medArmProductForm\(code = \'\', labelKey = \'\'\)[\s\S]{0,900}?addLoc\.value = resolveLocationKey\(loc\);[\s\S]{0,200}?addLoc\.dataset\.userPicked = \'1\';/s', $app));
check('editing a product shows its own medical fields',
    (bool)preg_match("/t\('product\.title_edit'\);\n    _syncProductMedicalUi\(currentProduct\)/", $app));

// ── 7. Scanner ──────────────────────────────────────────────────────────────
check('a medicine code is not an error: the schema decides, the manual entry opens',
    str_contains($app, 'const det = _medDetectCode(code);')
    && str_contains($app, "det.confidence === 'high'")
    && str_contains($app, '_medStartManualEntry(code, true, det.label_key);'));

// The EAN case: Italian boxes carry BOTH the SSN label (a Pharmacode) and an EAN-13, and no
// free catalogue knows either. The app must ask, in every country, instead of dead-ending.
check('an unknown EAN is not a dead end: the household is asked',
    str_contains($app, 'function _medOfferMedicalFallback(code) {')
    && str_contains($app, '_medOfferMedicalFallback(code);')
    && str_contains($app, 'medical.fallback_yes')
    && str_contains($app, 'medical.no_catalogue'));

// The Italian box from the bug report: AIC `A017837016` (A + 9 digits, Code 39), Pharmacode
// 3–7 digits. Neither is an EAN, so neither may be dropped by the EAN length floor nor sent
// to five food catalogues before the medicine entry opens.
check('a national medicine code is answered before any catalogue request',
    (bool)preg_match('/function _medNationalCode\(code\)[\s\S]{0,700}?return clean \? \{ code: clean, type: det\.type, label_key: det\.label_key \|\| \'\' \} : null;/s', $app)
    && str_contains($app, 'const national = _medOn() ? _medNationalCode(barcode) : null;')
    && (bool)preg_match('/if \(national && !_localRowForBarcode\(national\.code\)\) \{[\s\S]{0,220}?_medStartManualEntry\(national\.code, true, national\.label_key\);/s', $app));
check('the EAN length floor no longer drops a Pharmacode or an AIC',
    (bool)preg_match('/const national = _medOn\(\) \? _medNationalCode\(raw\) : null;\s*if \(digits\.length < 8 && !national\) return false;/s', $app));
check('the pack code keeps its letters (the A of the AIC)',
    str_contains($app, "replace(/[^0-9A-Z]/g, '')")
    && str_contains($app, 'const scanCode = national ? national.code : digits;')
    && (bool)preg_match('/_finalizeBarcode\(scanCode, method\);/s', $app));
check('a medicine code is not pre-fetched from the food catalogues',
    (bool)preg_match('/if \(!national\) _scheduleSpeculativeBarcodePrefetch\(digits\);/s', $app));
check('a medicine already in the pantry opens its own row, not the blank form',
    str_contains($app, 'function _localRowForBarcode(')
    && str_contains($app, 'if (national && !_localRowForBarcode(national.code))'));
check('the country schema falls back to the UI language (no APP_LANG needed)',
    str_contains($app, "api('med_status', { lang: _currentLang || '' })")
    && (bool)preg_match('/function medicalActiveCountry\(\?PDO \$db = null, string \$uiLang = .{0,10}\)/s', $lib)
    && str_contains($api, "medicalActiveCountry(\$db, (string)(\$_GET['lang'] ?? ''))"));
check('the answer routes to the right form (medicine arms the toggle, food does not)',
    (bool)preg_match('/function _medFallbackMedicine\(\)[\s\S]{0,200}?_medStartManualEntry\(code, true\)/s', $app)
    && (bool)preg_match('/function _medFallbackFood\(\)[\s\S]{0,200}?startManualEntry\(code\)/s', $app)
    && (bool)preg_match('/function _medArmProductForm\(code = \'\', labelKey = \'\'\)[\s\S]{0,300}?_medFormSetMode\(true, \{ code: String\(code \|\| \'\'\), label_key: String\(labelKey \|\| \'\'\) \}\);/s', $app));

// ── 7b. Adding a medicine is not adding food ────────────────────────────────
// The report: "it says the code is recognised but fills neither name, brand nor category,
// then the whole screen still talks about food, it asks me whether it is a medicine (of
// course it is, you detected it!) and the family suggests Spaghetti".
check('the medicine form has its own banner (code + no-catalogue note)',
    str_contains($html, 'id="pf-med-banner"')
    && str_contains($html, 'id="pf-med-banner-code"')
    && str_contains($html, 'data-i18n="medical.form_no_catalogue"'));
check('the food-only fields are marked, and hidden in medicine mode',
    substr_count($html, 'pf-food-only') >= 6
    && str_contains((string)file_get_contents($root . '/assets/css/surfaces.css'),
        '#page-product-form.is-medicine-form .pf-food-only { display: none !important; }'));
check('the "is it a medicine?" switch is not asked when the code proved it',
    str_contains($html, 'id="pf-med-toggle-row"')
    && str_contains((string)file_get_contents($root . '/assets/css/surfaces.css'),
        '#page-product-form.is-medicine-form #pf-med-toggle-row'));
check('the wording becomes medical (name, maker, box code), not food',
    (bool)preg_match('/function _medFormSetMode\(on, info = \{\}\)[\s\S]{0,2600}?nameLabel\.textContent = on \? t\(\'medical\.form_name_label\'\)/s', $app)
    && (bool)preg_match('/function _medFormSetMode\(on, info = \{\}\)[\s\S]{0,3200}?t\(\'medical\.form_brand_label\'\)/s', $app)
    && (bool)preg_match('/function _medFormSetMode\(on, info = \{\}\)[\s\S]{0,3600}?t\(\'medical\.form_code_label\'\)/s', $app));
check('a medicine is stored as a box in "altro", never in a food category',
    (bool)preg_match('/const isMedicine = _medFormIsMedicine\(\);[\s\S]{0,500}?category: isMedicine \? \'altro\' :/s', $app)
    && (bool)preg_match('/unit: isMedicine \? \'pz\' : pfUnit,/s', $app));
check('the closed medical category is required (it is the category here)',
    (bool)preg_match("/if \(isMedicine && !document\.querySelector\('#pf-med-cats input:checked'\)\) \{[\s\S]{0,200}?t\('medical\.pick_category'\)/s", $app));
check('the food form comes back for a normal product and when editing',
    (bool)preg_match('/_syncProductMedicalUi\(null\);\s*\/\/[^\n]*\n\s*\/\/[^\n]*\n\s*_medFormSetMode\(false\);/s', $app)
    && (bool)preg_match('/function editProductFromAction\(\) \{[\s\S]{0,400}?_medFormSetMode\(false\);/s', $app));
check('the add step drops the vacuum option for a medicine',
    (bool)preg_match("/const vacuumGroup = document\.getElementById\('add-vacuum-group'\);\s*if \(vacuumGroup\) vacuumGroup\.style\.display = _isMedicalRow\(currentProduct\) \? 'none' : '';/s", $app));
// ── 7c. Food and medicines never share a place ──────────────────────────────
// "Food cannot go into the medicine location, and a medicine cannot go anywhere else."
// The client hides the wrong places; the server refuses them on EVERY door (add, move,
// import) and moves the stock of a product that BECOMES a medicine into the cabinet.
$apiSrc2 = (string)file_get_contents($root . '/api/index.php');
check('the server knows the two refusals and the missing cabinet',
    str_contains($apiSrc2, 'function inventoryLocationDomainError(PDO $db, bool $isMedical, string $location): ?string')
    && str_contains($apiSrc2, "'error.food_in_cabinet'")
    && str_contains($apiSrc2, "'error.medicine_needs_cabinet'")
    && str_contains($apiSrc2, "'error.medicine_location_missing'"));
check('every door asks: the add, the move and the import',
    (bool)preg_match('/function addToInventory\(PDO \$db\): void[\s\S]{0,6000}?inventoryLocationDomainError\(\$db, \$isMedicalProduct, \(string\)\$location\)/s', $apiSrc2)
    && (bool)preg_match('/function updateInventory\(PDO \$db\): void[\s\S]{0,4000}?inventoryLocationDomainError\(\$db, \$isMedicalRow, \(string\)\$input\[.location.\]\)/s', $apiSrc2)
    && (bool)preg_match('/inventoryLocationDomainError\(\$db, !empty\(\$row\[.data.\]\[.is_medical.\]\)/s', $apiSrc2));
check('a product that becomes a medicine moves its stock into the cabinet',
    (bool)preg_match('/function medClassifyAction\(PDO \$db\): void[\s\S]{0,4000}?medicalStockMoveToCabinet\(\$db, \$productId\)/s', $apiSrc2));
check('the marked place is read in both stored forms (typed label or slug)',
    (bool)preg_match('/function medicalLocationKeys\(PDO \$db\): array[\s\S]{0,1400}?inventorySlugifyCustomLocation/s', $apiSrc2));
check('the cabinet is never discovered by opening a second connection',
    // getDB() builds a NEW PDO and runs the migrations on it: a second connection left open
    // made the next UPDATE of the same request answer "database is locked".
    (bool)preg_match('/function inventoryOrphanCustomLocations\(\?PDO \$db = null\): array/s', $apiSrc2)
    && !preg_match('/function inventoryOrphanCustomLocations\(\?PDO \$db = null\): array[\s\S]{0,900}?getDB\(\)/s', $apiSrc2));
check('the add asks the allow-list with the request connection',
    (bool)preg_match('/function addToInventory\(PDO \$db\): void[\s\S]{0,6000}?\$validLocations = validInventoryLocations\(\$db\);/s', $apiSrc2));
check('the client offers only the right places',
    (bool)preg_match('/function _renderLocationSelector\(sel, hiddenId, clickFn, currentVal, kind = .any.\)/s', $app)
    && str_contains($app, "_renderLocationSelector('#page-add .location-selector', 'add-location', 'selectLocation', autoLoc, _isMedicalRow(currentProduct) ? 'medical' : 'food')")
    && (bool)preg_match('/function _locationsForDomain\(isMedical, fromLoc = ..\)/s', $app)
    && substr_count($app, '_locationsForDomain(') >= 3);
check('the move pickers follow the domain (both of them)',
    str_contains($app, 'const otherLocs = _locationsForDomain(_isMedicalRow(product), fromLoc);')
    && str_contains($app, 'const otherLocs = _locationsForDomain(false, fromLoc);'));
check('the three refusals are translated in six locales', array_reduce(['it','en','de','fr','es','zh'], function ($c, $l) use ($root) {
    $d = json_decode((string)file_get_contents($root . "/translations/{$l}.json"), true);
    foreach (['food_in_cabinet', 'medicine_needs_cabinet', 'medicine_location_missing'] as $k) {
        if (trim((string)($d['error'][$k] ?? '')) === '') { return false; }
    }
    return $c;
}, true));

check('the medical form copy exists in all six locales', array_reduce(['it','en','de','fr','es','zh'], function ($c, $l) use ($root) {
    $d = json_decode((string)file_get_contents($root . "/translations/{$l}.json"), true);
    $m = $d['medical'] ?? [];
    foreach (['form_title','form_detected','form_no_catalogue','form_name_label','form_name_placeholder',
              'form_brand_label','form_brand_placeholder','form_code_label','pick_category'] as $k) {
        if (trim((string)($m[$k] ?? '')) === '') { return false; }
    }
    return $c;
}, true));

// The shipped switch, run in node against a fake form: the wording and the class really
// change, and going back restores the food form.
$setModeFn = '';
if (preg_match('/function _medFormSetMode\(on, info = \{\}\) \{.*?\n\}/s', $app, $m1)) { $setModeFn = $m1[0]; }
$isMedFn = '';
if (preg_match('/function _medFormIsMedicine\(\) \{.*?\n\}/s', $app, $m2)) { $isMedFn = $m2[0]; }
check('the medicine-mode switch is extractable', $setModeFn !== '' && $isMedFn !== '');
if ($setModeFn !== '' && $isMedFn !== '') {
    $modeHarness = <<<'JS'
const els = {};
function mk(id) {
    els[id] = { id, hidden: true, textContent: '', placeholder: '', checked: false, dataset: {}, attrs: {},
        setAttribute(k, v) { this.attrs[k] = v; },
        classList: { _c: new Set(), toggle(c, on) { on ? this._c.add(c) : this._c.delete(c); }, contains(c) { return this._c.has(c); } } };
    return els[id];
}
['page-product-form','pf-med-banner','pf-med-banner-code','pf-is-medical','pf-medical-box','pf-medical-group',
 'pf-name-label','pf-brand-label','pf-barcode-label','pf-name','pf-brand','pf-notes','product-form-title'].forEach(mk);
const document = { getElementById: (id) => els[id] || null };
const t = (k) => k;
const _medOn = () => true;
const _pfRenderMedCats = () => {};
JS;
    $modeHarness .= "\n" . $setModeFn . "\n" . $isMedFn . <<<'JS'

_medFormSetMode(true, { code: 'A017837016', label_key: 'medical.code_aic' });
const on = {
    medicine: _medFormIsMedicine(),
    banner: !els['pf-med-banner'].hidden,
    code: els['pf-med-banner-code'].textContent,
    toggle: els['pf-is-medical'].checked,
    box: !els['pf-medical-box'].hidden,
    name: els['pf-name-label'].textContent,
    brand: els['pf-brand-label'].textContent,
    barcode: els['pf-barcode-label'].textContent,
    title: els['product-form-title'].textContent,
    notes: els['pf-notes'].placeholder,
};
_medFormSetMode(false);
const off = {
    medicine: _medFormIsMedicine(),
    banner: !els['pf-med-banner'].hidden,
    name: els['pf-name-label'].textContent,
    title: els['product-form-title'].textContent,
};
console.log(JSON.stringify({ on, off }));
JS;
    $modeTmp = sys_get_temp_dir() . '/med_form_' . getmypid() . '.js';
    file_put_contents($modeTmp, $modeHarness);
    $modeRaw = shell_exec('node ' . escapeshellarg($modeTmp) . ' 2>&1');
    @unlink($modeTmp);
    $mode = json_decode(trim((string)$modeRaw), true);
    check('the medicine-mode harness runs in node', is_array($mode), (string)$modeRaw);
    if (is_array($mode)) {
        check('a recognised code switches the form to medicine mode',
            ($mode['on']['medicine'] ?? false) === true
            && ($mode['on']['banner'] ?? false) === true
            && ($mode['on']['code'] ?? '') === 'medical.code_aic · A017837016'
            && ($mode['on']['toggle'] ?? false) === true
            && ($mode['on']['box'] ?? false) === true,
            json_encode($mode['on'] ?? []));
        check('the wording is medical, not food',
            ($mode['on']['name'] ?? '') === 'medical.form_name_label'
            && ($mode['on']['brand'] ?? '') === 'medical.form_brand_label'
            && ($mode['on']['barcode'] ?? '') === 'medical.form_code_label'
            && ($mode['on']['notes'] ?? '') === 'medical.form_notes_placeholder'
            && ($mode['on']['title'] ?? '') === 'medical.form_title',
            json_encode($mode['on'] ?? []));
        check('going back restores the food form',
            ($mode['off']['medicine'] ?? true) === false
            && ($mode['off']['banner'] ?? true) === false
            && ($mode['off']['name'] ?? '') === 'product.name_label',
            json_encode($mode['off'] ?? []));
    }
}
check('closing the question resumes the camera (no silent no-op)',
    (bool)preg_match("/'med-fallback-overlay': \(\) => _medCloseFallback\(true\)/s", $app));
check('the module says it queries no catalogue (so no country is promised)',
    str_contains($html, 'data-i18n="medical.no_catalogue"')
    && array_reduce(['it','en','de','fr','es','zh'], function ($c, $l) use ($root) {
        $d = json_decode((string)file_get_contents($root . "/translations/{$l}.json"), true);
        return $c && mb_strlen((string)($d['medical']['no_catalogue'] ?? '')) > 40;
    }, true));
check('the credit list states what no free API covers (medicines)',
    (bool)preg_match('/credits-note[\s\S]{0,200}?credits\.medicines_note/s', $app)
    && array_reduce(['it','en','de','fr','es','zh'], function ($c, $l) use ($root) {
        $d = json_decode((string)file_get_contents($root . "/translations/{$l}.json"), true);
        return $c && mb_strlen((string)($d['credits']['medicines_note'] ?? '')) > 40;
    }, true));
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
check('all six locales carry the same 116 medical keys', $aligned && count($base) === 116);
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

