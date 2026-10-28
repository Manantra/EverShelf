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
$libMeds = (string)file_get_contents($root . '/api/lib/medicines.php');
// The medicine scanner is split: a country-neutral core + one module per country.
$libIt  = (string)file_get_contents($root . '/api/lib/med/italy.php');
$medCoreJs = (string)file_get_contents($root . '/assets/js/core/med.js');
$medItJs   = (string)file_get_contents($root . '/assets/js/med/italy.js');
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
check('Italy carries the AIC as its ONLY national medicine code', // the pack has no drug barcode
    count($ccs['IT']['codes'] ?? []) === 1
    && ($ccs['IT']['codes'][0]['type'] ?? '') === 'aic');
check('the Italian schema never claims an EAN or a Pharmacode as a drug code',
    !array_filter($ccs['IT']['codes'] ?? [], fn($c) => in_array($c['type'] ?? '', ['ean13', 'pharmacode'], true))
    && ($ccs['IT']['gs1'][0] ?? []) === ['800', '839']);
check('the packaging codes are listed as IGNORED, with the action to take instead',
    array_reduce($ccs['IT']['ignored'] ?? [], fn($c, $def) => $c
        && in_array($def['type'] ?? '', ['pharmacode', 'laetus'], true)
        && ($def['action'] ?? '') === 'ocr'
        && !empty($def['pattern']), true)
    && count($ccs['IT']['ignored'] ?? []) >= 2);
check('the printed patterns the OCR must look for are DATA (the A-form ONLY)',
    count($ccs['IT']['ocr']['patterns'] ?? []) === 1
    && str_starts_with((string)($ccs['IT']['ocr']['patterns'][0] ?? ''), 'A[')
    && str_contains((string)($ccs['IT']['ocr']['patterns'][0] ?? ''), '\\d{9}')
    // A bare 9-digit run is the pack serial under the SSN barcode: never a pattern.
    && !preg_match('/(?<!A\[)[^A]\[\(\?<!/', (string)($ccs['IT']['ocr']['patterns'][0] ?? ''))
    && ($ccs['IT']['ocr']['sample'] ?? '') === 'A000000000'
    && (int)($ccs['IT']['ocr']['frame']['width_pct'] ?? 0) > 40);
check('the FMD DataMatrix carries the AIC in AI 710',
    ($ccs['IT']['fmd_national_ai'] ?? '') === '710' && ($ccs['IT']['fmd'] ?? false) === true);
check('the catalogue source is declared as DATA (AIFA open data)', // no unknown drug data invented
    ($ccs['IT']['dataset']['id'] ?? '') === 'aifa'
    && str_contains((string)($ccs['IT']['dataset']['importer'] ?? ''), 'import-medicines.php'));
check('the Italian rule lives in its own MODULE, never in the core',
    ($ccs['IT']['module'] ?? '') === 'italy'
    && (medicalCountryModule('IT')['local_code'] ?? '') === 'medicalItalyLocalCode'
    && !str_contains($lib, 'medicalItaly')
    // The AIC pattern and the AI-710 interpretation are NOT in the core (AI 71x parsing is GS1,
    // a standard: the core exposes `ais['71']` and the module decides what it means).
    && !str_contains($lib, '/^A(\d{9})$/')
    && !str_contains($lib, '^0*(\d{9})$')
    && str_contains($libIt, '/^A(\d{9})$/')
    && str_contains($libIt, '^0*(\d{9})$')
    && str_contains($libIt, 'medicalItalyColumnAliases'));
check('the core exposes the country module hook and the GS1 walker',
    str_contains($lib, 'function medicalCountryModule(')
    && str_contains($lib, 'function medicalLocalCode(')
    && str_contains($lib, 'function medicalGs1Parse('));
check('a country without a module still gets its plain patterns (PZN stays generic)',
    medicalLocalCode('09876543', 'DE') === null
    && medicalDetectCode('09876543', 'DE')['type'] === 'pzn');

// The detector: high confidence where the schema is complete, nothing where it is not.
$it = medicalDetectCode('8012345678901', 'IT');
$itPharma = medicalDetectCode('123456', 'IT');
$de = medicalDetectCode('09876543', 'DE');
$fr = medicalDetectCode('3400930000000', 'FR');
$zz = medicalDetectCode('8012345678901', 'ZZ');
check('an Italian EAN is NOT a medicine code any more (it goes to the food catalogues)',
    $it['detected'] === false && $it['ignored'] === false);
check('an Italian Pharmacode/Laetus is thrown away, not claimed',
    $itPharma['detected'] === false && $itPharma['ignored'] === true
    && $itPharma['label_key'] === 'medical.code_line');
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
    str_contains($medCoreJs, "schema.status === 'none'") && str_contains($app, 'unsupportedNoteKey'));

// The shipped JS detector must agree with the PHP one (two ports, one rule), and it must be
// SPLIT the same way: the country-neutral core + the Italy module.
$jsFns = [];
foreach (['_medRegisterCountryModule(slug, module)', '_medCountryModule()', '_medCompactCode(code)',
          '_medGs1Parse(read)', '_medLocalCode(code)', '_medCodeIgnored(code)',
          '_medIgnoredLabelKey(code)', '_medDetectCode(code)', '_medCodeMatches(def, code, digits)'] as $sig) {
    $pat = '/function ' . preg_quote($sig, '/') . ' \{.*?\n\}/s';
    if (preg_match($pat, $medCoreJs, $m)) {
        $jsFns[$sig] = $m[0];
    }
}
$itFn = '';
if (preg_match('/function _medItalyAicFromCode\(code\) \{.*?\n\}/s', $medItJs, $mIt)) {
    $itFn = $mIt[0];
}
check('the shipped code detector is extractable from core + module',
    count($jsFns) === 9 && $itFn !== '', implode(', ', array_keys($jsFns)));
check('the browser core has no national rule either (the AIC lives in the module)',
    str_contains($medCoreJs, 'EVERSHELF_MED_MODULES')
    && !str_contains($medCoreJs, 'AIC')
    && !preg_match('/\^A\(/', $medCoreJs)
    && str_contains($medItJs, "_medRegisterCountryModule('italy'")
    && str_contains($medItJs, '/^A(\d{9})$/'));
check('both files are loaded by the app shell and by the service worker',
    str_contains($html, 'assets/js/core/med.js')
    && str_contains($html, 'assets/js/med/italy.js')
    && str_contains((string)file_get_contents($root . '/sw.js'), "assets/js/core/med.js")
    && str_contains((string)file_get_contents($root . '/sw.js'), "assets/js/med/italy.js"));
if (count($jsFns) === 9 && $itFn !== '') {
    $probes = ['A040313076', 'A034548040', 'A012745182', '014058142', 'a 040 313 076',
        '123456', '16G87N', '999999', '8012345678901', 'LOT2024ABC', '8001234567890',
        "01080123456789011727043010ABC123\x1d710040313076"];
    $detectHarness = 'let _medStatus = ' . json_encode(['schema' => $ccs['IT'] ?? []]) . ";\n"
        . $medCoreJs . "\n" . $medItJs . "\n"
        . 'const probes = ' . json_encode($probes) . ";\n"
        . 'console.log(JSON.stringify(probes.map(c => { const d = _medDetectCode(c);'
        . ' return [c, d.detected, d.type, d.ignored, d.aic]; })));';
    $detectTmp = sys_get_temp_dir() . '/med_detect_' . getmypid() . '.js';
    file_put_contents($detectTmp, $detectHarness);
    $detectRaw = shell_exec('node ' . escapeshellarg($detectTmp) . ' 2>&1');
    @unlink($detectTmp);
    $detectOut = json_decode(trim((string)$detectRaw), true);
    check('the JS detector harness runs in node', is_array($detectOut), (string)$detectRaw);
    if (is_array($detectOut)) {
        $js = [];
        foreach ($detectOut as $row) {
            $js[(string)$row[0]] = [(bool)$row[1], (string)$row[2], (bool)$row[3], (string)($row[4] ?? '')];
        }
        $mismatch = [];
        foreach ($probes as $probe) {
            $php = medicalDetectCode($probe, 'IT');
            $want = [(bool)$php['detected'], (string)$php['type'], (bool)$php['ignored'], (string)($php['aic'] ?? '')];
            if (($js[$probe] ?? null) !== $want) {
                $mismatch[] = $probe . ' php=' . json_encode($want) . ' js=' . json_encode($js[$probe] ?? null);
            }
        }
        check('the browser and the server agree on every probed code', $mismatch === [], implode(' | ', $mismatch));
        check('the probe list covers the lot code, the printed AIC, the pack serial, the FMD read and an EAN',
            ($js['16G87N'][2] ?? false) === true
            && ($js['A040313076'][1] ?? '') === 'aic'
            && ($js['014058142'][0] ?? true) === false
            && ($js["01080123456789011727043010ABC123\x1d710040313076"][1] ?? '') === 'aic'
            && ($js['8012345678901'][0] ?? true) === false,
            json_encode($js));
    }
}

// ── 4d. The code itself: the AIC (printed, or AI 710), never a packaging code ──
// The report's photo: the box prints `A040313076` and a Pharmacode/Laetus label, and the 2D
// DataMatrix carries a lot/serial (`16G87N`). Only the AIC identifies the drug, so only the
// AIC (in its three real forms) is a medicine code; the packaging codes are thrown away and
// the label is OCR'd instead.
$badPatterns = [];
foreach ($ccs as $cc => $data) {
    foreach (($data['codes'] ?? []) as $codeDef) {
        $type = (string)($codeDef['type'] ?? '');
        if (in_array($type, ['ean13', 'pzn_in_ean'], true)) {
            continue;
        }
        if (!is_string($codeDef['pattern'] ?? null) || trim((string)$codeDef['pattern']) === '') {
            $badPatterns[] = $cc . ':' . $type;
        }
    }
}
check('every national code definition carries a whole-code pattern',
    $badPatterns === [], implode(', ', $badPatterns));
$itAic = [];
foreach (($ccs['IT']['codes'] ?? []) as $codeDef) {
    if (($codeDef['type'] ?? '') === 'aic') {
        $itAic = $codeDef;
    }
}
check('the AIC is declared in its PRINTED form only (A + 9 digits)',
    ($itAic['pattern'] ?? '') === '^A\\d{9}$' && (int)($itAic['digits'] ?? 0) === 9);
$itLot = medicalDetectCode('16G87N', 'IT');
check('the lot/serial fragment of a medicine box is NOT a medicine code',
    $itLot['detected'] === false && $itLot['confidence'] === 'none' && $itLot['ignored'] === true,
    json_encode($itLot));
check('the AIC is recognised as the box prints it (A + 9 digits)',
    medicalDetectCode('A040313076', 'IT')['type'] === 'aic'
    && medicalDetectCode('A034548040', 'IT')['type'] === 'aic'
    && medicalDetectCode('A040313076', 'IT')['aic'] === '040313076');
check('a bare 9-digit read is NOT an AIC (it is the pack serial)',
    // `014058142` is what the second Code 39 of an SSN label carries: accepting it stored a
    // serial as if it were a medicine. The printed block always has the A.
    medicalDetectCode('014058142', 'IT')['detected'] === false
    && medicalDetectCode('040313076', 'IT')['detected'] === false
    && medicalItalyAicFromCode('014058142') === null);
check('the spacing and the case of a photographed label do not matter',
    medicalItalyAicFromCode('a 040 313 076') === '040313076'
    && medicalItalyAicFromCode('A-040313076') === '040313076'
    && medicalItalyAicFromCode('16G87N') === null
    && medicalItalyAicFromCode('014058142') === null);
$fmdRead = "01080123456789011727043010ABC123\x1d710040313076";
$gs1 = medicalGs1Parse($fmdRead);
$fmd = ['aic' => medicalItalyAicFromCode($fmdRead)] + $gs1;
check('the FMD DataMatrix is parsed: GTIN, expiry, batch and the AIC in AI 710',
    $fmd['fmd'] === true && $fmd['gtin'] === '08012345678901'
    && $fmd['expiry'] === '270430' && $fmd['batch'] === 'ABC123'
    && $fmd['aic'] === '040313076',
    json_encode($fmd));
check('a DataMatrix read is answered as the AIC, not as its lot',
    medicalDetectCode($fmdRead, 'IT')['type'] === 'aic'
    && medicalDetectCode($fmdRead, 'IT')['aic'] === '040313076'
    && medicalItalyAicFromCode($fmdRead) === '040313076');
check('a code above the Pharmacode maximum is not claimed either',
    medicalDetectCode('999999', 'IT')['detected'] === false);
check('a letter-carrying code never reaches a food catalogue',
    str_contains($app, 'if (/[A-Z]/.test(raw)) return false;'));
check('in Italy the medicine panel runs NO barcode engine at all',
    // The household's words: "it must not read the barcode". The flag is data
    // (`scan.barcodes: false` for IT) and it gates the native detector, ZBar, the crop pass,
    // the digit OCR, Quagga and the confirm path.
    (bool)preg_match('/function _medScanBarcodes\(\)[\s\S]{0,400}?scan\.barcodes/s', $app)
    && (bool)preg_match('/const decodeBarcodes = !_scanModeIsMedical\(\) \|\| _medScanBarcodes\(\);/s', $app)
    && (bool)preg_match('/if \(decodeBarcodes && _detectorNative\)/s', $app)
    && (bool)preg_match('/const useZbar = decodeBarcodes &&/s', $app)
    && (bool)preg_match('/if \(_scanModeIsMedical\(\) && !_medScanBarcodes\(\)\) \{\s*startUnifiedScanner\(videoEl\);\s*return;/s', $app)
    && (bool)preg_match('/if \(_scanModeIsMedical\(\) && !_medScanBarcodes\(\)\) return;/s', $app)
    && (bool)preg_match('/function _tryConfirmBarcode\(code, format, method, geo = null\) \{[\s\S]{0,200}?if \(_scanModeIsMedical\(\) && !_medScanBarcodes\(\)\) return false;/s', $app));
check('the hint is ONE short line over the viewfinder (it was a white card)',
    // The prose explaining that barcodes are not read lives in the docs and in the country schema:
    // on the scan page it ate three lines of white box above the camera.
    (function () use ($root, $css) {
        $it = json_decode((string)file_get_contents($root . '/translations/it.json'), true);
        $hint = (string)($it['medical']['scan_hint'] ?? '');
        return str_contains($hint, 'A040313076')
            && mb_strlen($hint) <= 90
            && (bool)preg_match('/\.scan-med-hint \{[^}]*background: none;/s', $css)
            && (bool)preg_match('/\.scan-med-hint \{[^}]*border: 0;/s', $css);
    })());
check('the medicine panel never takes the drug number from a barcode',
    // Only the FMD DataMatrix is unambiguous (AI 710); every other Code 39 on the SSN label
    // — the AIC on top AND the pack serial at the bottom — has the same shape as an AIC, so
    // none of them is accepted: the printed block is read instead. Asserted on the scan-loop
    // branch itself (the OBDetected one below still asks _medNationalCode, by design).
    (function () use ($app) {
        $anchor = "    if (_scanModeIsMedical()) {\n        const gs1 = _medGs1Parse(raw);";
        $from = strpos($app, $anchor);
        if ($from === false) { return false; }
        $to = strpos($app, '    // Food panel: a drug code or a packaging code belongs', $from);
        if ($to === false) { return false; }
        $branch = substr($app, $from, $to - $from);
        return str_contains($branch, "gs1.ais['71']")
            && str_contains($branch, '_medOcrKick();')
            && str_contains($branch, "t('medical.scan_code_ignored')")
            && !str_contains($branch, '_medNationalCode(')
            && !str_contains($branch, '_finalizeBarcode(raw,');
    })());
check('the guide rectangle is the OCR field of view (and says what to frame)',
    str_contains($html, 'id="scan-med-frame"')
    && str_contains($html, 'id="scan-med-frame-sample"')
    && str_contains($app, 'function _medGuideRect(')
    && (bool)preg_match('/const guide = _medGuideRect\(videoEl\);\s*const wide = guide \? _medGuideRect\(videoEl, 1\.5\) : null;/s', $app)
    && (bool)preg_match('/passes\\.push\\(\\{\\s*region: guide, psm: /s', $app)
    && (bool)preg_match('/const canvas = pass\.binarize[\s\S]{0,200}?_captureMedLabelFrame\(videoEl, pass\.region, true\)/s', $app)
    && (bool)preg_match('/function _setScanMode\(mode\)[\s\S]{0,1600}?sample\.textContent = _medOcrSample\(\);/s', $app));
check('the barcode-hunting chrome is gone from the medicine panel',
    str_contains($css, '#page-scan.is-medical-scan .scanner-line')
    && str_contains($css, '#page-scan.is-medical-scan .scan-ai-cloud')
    && str_contains($css, '#page-scan.is-medical-scan .scan-guide-frame')
    && (bool)preg_match('/\.scan-med-frame-sample \{[^}]*font-family: ui-monospace/s', $css));
check('the hint tells the household WHICH code to frame, in six locales',
    array_reduce(['it','en','de','fr','es','zh'], function ($c, $l) use ($root) {
        $d = json_decode((string)file_get_contents($root . "/translations/{$l}.json"), true);
        $hint = (string)($d['medical']['scan_hint'] ?? '');
        return $c && str_contains($hint, 'A040313076') && mb_strlen($hint) > 30 && mb_strlen($hint) <= 110;
    }, true));
check('the AIC read by the OCR is stored as the box prints it (A + 9 digits)',
    str_contains($app, 'const aic = String(det.aic || (local && local.aic)')
    && str_contains($app, "(aic ? ('A' + aic) :")
    && (bool)preg_match("/_finalizeBarcode\('A' \+ code, 'OCR'\);/s", $app)
    && (bool)preg_match('/_finalizeBarcode\(local\.code, method\);\s*return true;/s', $app));
check('a medicine code is not pre-fetched from the food catalogues',
    // The handover happens before the food pipeline, so the prefetch only ever sees products.
    (bool)preg_match('/if \(_medOn\(\) && \(_medNationalCode\(rawCode\) \|\| _medCodeIgnored\(rawCode\)\)\) \{[\s\S]{0,300}?_medShowCrossHint\(\);/s', $app)
    && !str_contains($app, 'if (!national) _scheduleSpeculativeBarcodePrefetch'));
check('a medicine already in the pantry opens its own row, not the blank form',
    str_contains($app, 'function _localRowForBarcode(')
    && str_contains($app, 'if (national && !_localRowForBarcode(national.code))'));
check('the country schema falls back to the UI language (no APP_LANG needed)',
    str_contains($app, "api('med_status', { lang: _currentLang || '' })")
    && (bool)preg_match('/function medicalActiveCountry\(\?PDO \$db = null, string \$uiLang = .{0,10}\)/s', $lib)
    && str_contains($api, "medicalActiveCountry(\$db, (string)(\$_GET['lang'] ?? ''))"));
check('the two panels route the form (no "is it a medicine?" dialog any more)',
    str_contains($app, 'function _medManualFromScan()')
    && !str_contains($app, 'function _medOfferMedicalFallback(')
    && !str_contains($app, 'medical.fallback_yes')
    && (bool)preg_match('/function _medArmProductForm\(code = \'\', labelKey = \'\'\)[\s\S]{0,300}?_medFormSetMode\(true, \{ code: String\(code \|\| \'\'\), label_key: String\(labelKey \|\| \'\'\) \}\);/s', $app));

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

// ── 7. The scanner: TWO PANELS, never mixed ─────────────────────────────────
// The household's rule: in a food place the panel scans food; opening the medicine cabinet
// switches the panel to medicines. No screen ever answers with the other world's codes.
check('the panel follows the PLACE the pantry is showing',
    str_contains($html, 'id="scan-title"')
    && (bool)preg_match('/function _scanModeForLocation\(loc\)[\s\S]{0,600}?medical_locations/s', $app)
    && (bool)preg_match("/case 'scan':[\s\S]{0,900}?_setScanMode\(_scanModeForLocation\(currentLocation\)\);/s", $app));
check('the medicine panel is a different screen, not a corner of the food one',
    str_contains($css, '#page-scan.is-medical-scan #scan-ai-manual-btn')
    && str_contains($css, '#page-scan.is-medical-scan .scan-quick-rail')
    && str_contains($css, '#page-scan.is-medical-scan #scan-search-btn')
    && (bool)preg_match('/function _setScanMode\(mode\)[\s\S]{0,1600}?is-medical-scan/s', $app)
    && (bool)preg_match('/function _setScanMode\(mode\)[\s\S]{0,2000}?medical\.scan_title/s', $app));
check('the medicine actions exist only in the medicine panel',
    str_contains($html, 'id="scan-med-ocr-btn"')
    && str_contains($html, 'id="scan-med-manual-btn"')
    && (bool)preg_match("/const medManual = document\.getElementById\('scan-med-manual-btn'\);\s*if \(medManual\) medManual\.hidden = !medical;/s", $app)
    && str_contains($app, "_medStartManualEntry('', true, '');"));
check('the medicine panel refuses an EAN instead of looking it up',
    // The scan loop never finalises an EAN there (it kicks the label OCR); the defensive branch
    // in onBarcodeDetected is what says it out loud.
    str_contains($app, "t('medical.scan_not_medicine')"));
check('the food panel never opens the medicine form: it points at the right one',
    (bool)preg_match('/if \(_medOn\(\) && \(_medNationalCode\(rawCode\) \|\| _medCodeIgnored\(rawCode\)\)\) \{[\s\S]{0,300}?_medShowCrossHint\(\);/s', $app)
    && str_contains($html, 'id="scan-med-cross"')
    && str_contains($app, 'function _medGoToCabinetScan()')
    && !str_contains($app, 'function _medOfferMedicalFallback('));
check('shopping is a food flow and never runs on the medicine panel',
    (bool)preg_match("/async function startSpesaMode\(\) \{[\s\S]{0,300}?_setScanMode\('food'\);/s", $app));
check('the notice puts the pantry on the cabinet and opens the medicine panel at once',
    (bool)preg_match("/function _medGoToCabinetScan\(\)[\s\S]{0,500}?filterLocation\(slug\);\s*showPage\('scan'\);/s", $app));
check('a drug code is not an error: the panel decides, the medicine entry opens',
    (bool)preg_match('/if \(national && !_localRowForBarcode\(national\.code\)\) \{[\s\S]{0,220}?_medStartManualEntry\(national\.code, true, national\.label_key\);/s', $app));
check('a packaging code is never stored as the product barcode',
    (bool)preg_match("/_medStartManualEntry\('', true, 'medical\.code_line'\);/s", $app)
    && !preg_match('/_medOcrFallback\(code[\s\S]{0,1200}?pf-barcode[\s\S]{0,80}?value = /s', $app));


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

// The EDIT screen is a move too: "when a food's screen opens I must not be able to put it in
// the medicine cabinet, and a medicine must not go into a food place — do not show the button
// at all". The position group is built from `_locationsForEdit()`, and a product with a single
// legal place hides the group entirely instead of drawing one dead button.
check('the edit screens build their places from the domain helper, not from every location',
    substr_count($app, 'const editLocs = _locationsForEdit(_editDomainIsMedical(item), item.location);') === 2
    && substr_count($app, '${editLocs.map(([k, v]) =>') === 2
    && !preg_match("/inventory\\.label_position[\\s\\S]{0,400}?Object\\.entries\\(LOCATIONS\\)/s", $app));
check('a position with nothing to choose is removed from the form (and [hidden] really hides)',
    str_contains($app, "editLocs.length > 1 ? '' : ' hidden'")
    && str_contains($app, 'function _locationsForEdit(isMedical, currentLoc)')
    && str_contains((string)file_get_contents($root . '/assets/css/style.css'), '.form-group[hidden] { display: none; }'));
$editLocsFn = '';
if (preg_match('/function _locationsForEdit\(isMedical, currentLoc\) \{.*?\n\}/s', $app, $mLocs)) {
    $editLocsFn = $mLocs[0];
}
check('_locationsForEdit() is extractable', $editLocsFn !== '');
if ($editLocsFn !== '') {
    $domainFn = '';
    if (preg_match('/function _editDomainIsMedical\(item\) \{.*?\n\}/s', $app, $mDom)) {
        $domainFn = $mDom[0];
    }
    check('_editDomainIsMedical() is extractable', $domainFn !== '');
    $locsHarness = $domainFn . "\n" . $editLocsFn . <<<'JS'
const LOCATIONS = {
    dispensa: { icon: '🗄️', label: 'Dispensa' },
    custom_armadietto: { icon: '💊', label: 'Armadietto' },
    frigo: { icon: '🧊', label: 'Frigo' },
    freezer: { icon: '❄️', label: 'Freezer' },
};
const isMedicalLocation = (key) => key === 'custom_armadietto';
const normalizeLocationKey = (k) => (String(k || '') === 'altro' ? 'dispensa' : String(k || ''));
const _isMedicalRow = (item) => isMedicalLocation(item && item.location);
const keysFor = (item) => _locationsForEdit(_editDomainIsMedical(item), item.location).map(([k]) => k);
console.log(JSON.stringify({
    food: _locationsForEdit(false, 'frigo').map(([k]) => k),
    food_none: _locationsForEdit(false, '').map(([k]) => k),
    medicine: _locationsForEdit(true, 'custom_armadietto').map(([k]) => k),
    medicine_legacy: _locationsForEdit(true, 'frigo').map(([k]) => k),
    legacy_key: _locationsForEdit(false, 'altro').map(([k]) => k),
    food_flagged_in_cabinet: keysFor({ is_medical: 0, location: 'custom_armadietto' }),
    medicine_flagged_in_fridge: keysFor({ is_medical: 1, location: 'frigo' }),
    no_flag_in_cabinet: keysFor({ location: 'custom_armadietto' }),
}));
JS;
    $locsTmp = sys_get_temp_dir() . '/med_locs_' . getmypid() . '.js';
    file_put_contents($locsTmp, $locsHarness);
    $locsRaw = shell_exec('node ' . escapeshellarg($locsTmp) . ' 2>&1');
    @unlink($locsTmp);
    $locs = json_decode(trim((string)$locsRaw), true);
    check('the edit-places harness runs in node', is_array($locs), (string)$locsRaw);
    if (is_array($locs)) {
        check('food never gets the cabinet button',
            ($locs['food'] ?? []) === ['dispensa', 'frigo', 'freezer']
            && !in_array('custom_armadietto', ($locs['food_none'] ?? []), true));
        check('a medicine gets ONLY its cabinet (one place: no pointless choice)',
            ($locs['medicine'] ?? []) === ['custom_armadietto']);
        check('a legacy medicine outside the cabinet keeps its place and gains the cabinet',
            ($locs['medicine_legacy'] ?? []) === ['custom_armadietto', 'frigo']);
        check('the legacy "altro" key still reads as the pantry',
            ($locs['legacy_key'] ?? []) === ['dispensa', 'frigo', 'freezer']);
        check('a food flagged as food keeps a way OUT of the cabinet',
            ($locs['food_flagged_in_cabinet'] ?? []) === ['dispensa', 'custom_armadietto', 'frigo', 'freezer']);
        check('a flagged medicine only ever gets the cabinet (+ where it already is)',
            ($locs['medicine_flagged_in_fridge'] ?? []) === ['custom_armadietto', 'frigo']);
        check('a row with no flag is judged by the place it sits in',
            ($locs['no_flag_in_cabinet'] ?? []) === ['custom_armadietto']);
    }
}
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
check('each panel hides the other one (no silent cross-over)',
    str_contains($css, '#page-scan.is-medical-scan #scan-manual-btn')
    && str_contains($app, 'if (!medical) _medHideCrossHint();')
    && (bool)preg_match("/function _setScanMode\(mode\)[\s\S]{0,3400}?actionBar\.classList\.toggle\('has-med'/s", $app));
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
check('EAN-13/EAN-8 stay on the food path (no medicine heuristic claims them)', // Italy: AIC only
    !str_contains($app, 'function _looksLikeMedicineCode(')
    && (bool)preg_match('/isEan && \(digits\.length === 13 \|\| digits\.length === 8\)/s', $app));

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
check('all six locales carry the same 174 medical keys', $aligned && count($base) === 174);
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
// ── 10. The AIFA catalogue (local import) + the printed-AIC OCR ─────────────
// Italy: the AIC is PRINTED on the box, so the scan must read the label. The number is worth
// reading only if it can name the drug, and no free REST API exists for Italian medicines —
// so the catalogue is the AIFA open data imported ONCE into our own SQLite table.
check('the catalogue lookup is one authenticated action (never a public one)',
    str_contains($api, "case 'med_lookup':")
    && !str_contains($sec, "'med_lookup'")
    && (bool)preg_match('/function medLookupAction\(PDO \$db\)[\s\S]{0,1200}?medicineFindByAic\(\$db/s', $api)
    && (bool)preg_match('/function medLookupAction\(PDO \$db\)[\s\S]{0,3000}?medicineSearch\(\$db/s', $api));
check('the lookup never leaves the server (no HTTP call, no key)',
    !preg_match('/function medLookupAction\(PDO \$db\)[\s\S]{0,2500}?(curl_|file_get_contents\(\s*[\'"]https?)/s', $api));
check('the answer says where the name came from and that it is not advice',
    str_contains($api, "'dataset_key' => 'medical.dataset_aifa'")
    && str_contains($api, "'advice_key' => 'medical.no_dosage'"));
check('med_status tells the client how many medicines are loaded',
    str_contains($api, "'dataset' => medicinesDatasetInfo(\$db)"));
check('the catalogue table is created by initializeDB (no manual migration)',
    str_contains($db, 'medicinesEnsureTable($db)')
    && str_contains($libMeds, 'CREATE TABLE IF NOT EXISTS medicines'));

// The importer, on an in-memory database (never data/evershelf.db).
$memDb = new PDO('sqlite::memory:');
$memDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
medicinesEnsureTable($memDb);
$importOne = medicinesImportFile($memDb, $root . '/scripts/fixtures/aifa_sample.csv', [], medicalItalyColumnAliases());
check('the AIFA CSV import fills the table', $importOne['ok'] === true && $importOne['saved'] === 4,
    json_encode($importOne));
check('the header map is by NAME, not by position (AIFA renames columns)',
    ($importOne['columns']['aic'] ?? '') === 'CODICE_AIC'
    && ($importOne['columns']['ingredient'] ?? '') === 'PRINCIPIO_ATTIVO'
    && ($importOne['columns']['company'] ?? '') === 'TITOLARE');
$importTwo = medicinesImportFile($memDb, $root . '/scripts/fixtures/aifa_sample.csv', [], medicalItalyColumnAliases());
$infoMem = medicinesDatasetInfo($memDb);
check('importing twice updates instead of duplicating (AIC is the key)',
    $importTwo['ok'] === true && (int)$infoMem['count'] === 4 && $infoMem['loaded'] === true,
    json_encode($infoMem));
check('the AIC is found with or without the printed leading A',
    (medicineFindByAic($memDb, 'A900000001')['name'] ?? '') === 'FARMACO DEMO ALFA 500 mg compresse'
    && (medicineFindByAic($memDb, '900000001')['name'] ?? '') === 'FARMACO DEMO ALFA 500 mg compresse');
check('the search requires EVERY token (a wrong second word is not a match)',
    count(medicineSearch($memDb, 'farmaco demo alfa')) === 2
    && medicineSearch($memDb, 'demo alfa 1000') === []);
$xmlImport = medicinesImportFile($memDb, $root . '/scripts/fixtures/aifa_sample.xml', [], medicalItalyColumnAliases());
check('the XML export imports too (same column names, same result)',
    $xmlImport['ok'] === true && $xmlImport['format'] === 'xml' && $xmlImport['saved'] === 2,
    json_encode($xmlImport));
$noAicFile = sys_get_temp_dir() . '/med_no_aic_' . getmypid() . '.csv';
file_put_contents($noAicFile, "NAME;NOTE\nFoo;bar\n");
$refused = medicinesImportFile($memDb, $noAicFile, [], medicalItalyColumnAliases());
@unlink($noAicFile);
check('a file without an AIC column is refused, not imported as garbage',
    $refused['ok'] === false && ($refused['error'] ?? '') === 'missing_columns');

// The client: the card, the button, and the OCR of the label.
check('the catalogue FILLS the form: name, company, notes and the closed category',
    str_contains($html, 'id="pf-med-match"')
    && (bool)preg_match('/function _medShowCatalogueMatch\(code\)[\s\S]{0,4200}?_medUseMatch\(\$\{i\}\)/s', $app)
    && (bool)preg_match("/function _medFillFromCatalogue\(row, options = \{\}\)[\s\S]{0,1400}?set\('pf-name'/s", $app)
    && (bool)preg_match("/set\('pf-brand', String\\(row\\.company\\)/s", $app)
    && (bool)preg_match("/set\('pf-notes', details\\.join\\(/s", $app)
    && str_contains($app, 'function _medCategoryFromAtc(atc)')
    && (bool)preg_match('/function _medUseMatch\(index\)[\s\S]{0,500}?_medFillFromCatalogue\(row, \{ force: true \}\)/s', $app)
    // A typed value is never overwritten: only empty fields (or fields WE filled) are written.
    && (bool)preg_match('/if \(!options\.force && current && current !== mine\) return;/s', $app)
    && (bool)preg_match('/_medFillFromCatalogue\(exactRow\);\s*host\.innerHTML = `/s', $app));
check('the NAME is read off the box when the catalogue cannot name it',
    // An empty catalogue used to mean an empty form. The label prints its own name above the
    // AIC, and the camera is still live at that moment: one extra pass, one plausible line.
    str_contains($app, 'async function _medReadLabelName()')
    && (bool)preg_match('/function _medPickNameLine\(page\)[\s\S]{0,1200}?bestScore/s', $app)
    && (bool)preg_match('/_medPendingLabelName = await _medReadLabelName\(\)/s', $app)
    && (bool)preg_match("/function _medLabelNameFill\(\)[\s\S]{0,700}?medical\.match_name_from_label/s", $app)
    // Only when nothing name-like was typed/filled by the catalogue.
    && (bool)preg_match('/if \\(field && !String\\(field\\.value \\|\\| .{2}\\)\\.trim\\(\\)\\)/s', $app)
    && array_reduce(['it','en','de','fr','es','zh'], function ($c, $l) use ($med) {
        return $c && str_contains((string)($med[$l]['match_name_from_label'] ?? ''), '{name}');
    }, true));
$medLib = (string)file_get_contents($root . '/api/lib/medicines.php');
$italyMod = (string)file_get_contents($root . '/api/lib/med/italy.php');
check('the catalogue downloads and refreshes itself (no ssh, no manual step)',
    // The module being ON is the consent: the free source the country declares is fetched, and
    // refreshed monthly. A failed download never empties what is already there (upsert).
    (bool)preg_match("/'dataset_sync' => \[/s", $italyMod)
    && str_contains($italyMod, "'urls' => ['https://drive.aifa.gov.it/farmaci/confezioni_fornitura.csv']")
    && str_contains($italyMod, "'max_bytes'")
    && str_contains($apiSrc2, "case 'med_catalog_sync':")
    && (bool)preg_match('/function medCatalogSyncAction\(PDO \$db\)[\s\S]{0,2600}?medicinesSyncSources\(\$db, \$sync/s', $apiSrc2)
    && str_contains($apiSrc2, "'event' => 'med_catalog_sync'")
    && (bool)preg_match('/function medicinesSyncSources\(PDO \$db[\s\S]{0,4000}?medicinesDownloadToFile\(/s', $medLib)
    && (bool)preg_match('/function medicinesDownloadToFile[\s\S]{0,1600}?CURLOPT_FILE/s', $medLib)
    // The client: automatic (once a day, when the module is on) + the manual nudge.
    && (bool)preg_match('/function _medMaybeAutoSyncCatalog\(\)[\s\S]{0,1400}?_MED_CATALOG_STALE_DAYS/s', $app)
    && (bool)preg_match('/_applyMedUi\(\);\s*\/\/ A conditional credit source[\s\S]{0,400}?_medMaybeAutoSyncCatalog\(\)/s', $app)
    && (bool)preg_match('/async function _medSyncCatalog\(options = \{\}\)[\s\S]{0,900}?med_catalog_sync/s', $app)
    && str_contains($html, 'id="med-dataset-sync"')
    // And the cron keeps it fresh even if nobody opens the settings.
    && (bool)preg_match('/if \(medicalEnabled\(\$db\)\)[\s\S]{0,1200}?medicinesSyncSources/s', (string)file_get_contents($root . '/api/cron_smart_shopping.php')));
check('a new medicine asks the catalogue as soon as the form opens',
    (bool)preg_match('/function _medStartManualEntry\(barcode, medical = true, labelKey = \'\'\)[\s\S]{0,400}?_medShowCatalogueMatch\(barcode\);/s', $app));
check('Settings shows how many medicines are loaded (or that none are)',
    str_contains($html, 'id="med-dataset-block"') && str_contains($html, 'id="med-dataset-hint"')
    && str_contains($app, 'function _renderMedDataset()')
    && str_contains($app, 'medical.dataset_loaded') && str_contains($app, 'medical.dataset_empty'));
check('the OCR of the label uses ONE engine in two modes (no second worker)',
    (bool)preg_match('/async function _ensureTesseractWorker\(mode = \'digits\'\)/s', $app)
    && str_contains($app, "tessedit_pageseg_mode: '6'")
    && str_contains($app, "tessedit_pageseg_mode: '7'")
    && substr_count($app, 'Tesseract.createWorker(') === 1);
check('in the medicine panel the LABEL is the source, not the fallback',
    str_contains($app, 'const _MED_OCR_FIRST_MS = 600;')
    && str_contains($app, 'const _MED_OCR_INTERVAL_MS = 2000;')
    && str_contains($app, 'const _MED_OCR_MAX_ATTEMPTS = 45;')
    // The loop reads the label in the medicine panel only (the food panel never OCRs a box),
    // it starts on the first frames, and a hit opens the medicine form with the AIC.
    && (bool)preg_match('/if \(_scanModeIsMedical\(\) && _medOcrPatterns\(\)\.length[\s\S]{0,900}?_medReadLabelOcr\(\{ silent: true \}\)/s', $app)
    && (bool)preg_match('/_medReadLabelOcr\(\{ silent: true \}\)[\s\S]{0,900}?_medConfirmRead\(reading\.aic\);/s', $app)
    && str_contains($app, 'async function _medReadLabelOcr('));
check('the medicine panel says what it is doing (it reads a label, not a barcode)',
    (bool)preg_match("/_setScanStatus\(_scanModeIsMedical\(\) \? t\('medical\.ocr_reading'\)/s", $app));
check('no box is painted over the camera: the digits in the frame are the feedback',
    // Asked twice by the household, and right: a white card, then a dark one, both sat on top of
    // the label being aimed at. The guide frame fills in instead — and the trace stays in logs.
    !str_contains($html, 'scan-med-read')
    && !str_contains($app, 'scan-med-read')
    && !str_contains($css, '.scan-med-read')
    && !str_contains($app, 'function _medOcrFeedback(')
    && (bool)preg_match('/\/\/ The frame is the only feedback[\s\S]{0,120}?_medPreviewSample\(page, aic\);/s', $app)
    && str_contains($css, '.scan-med-frame-sample.is-read'));
check('the reader makes the printed block readable (binarize, upscale, three passes)',
    // A webcam's contrast is what makes a clean AIC line come back as "A0l2345678": the crop is
    // binarized and blown up, and the block is retried with a wider page segmentation and a
    // wider band before giving up.
    (bool)preg_match('/function _binarizeScanCanvas\(canvas\)[\s\S]{0,1200}?putImageData/s', $app)
    && str_contains($app, 'if (binarize) _binarizeScanCanvas(canvas);')
    && (bool)preg_match("/if \(mode === 'aic'\)[\s\S]{0,300}?tessedit_char_whitelist: '0123456789A'/s", $app)
    && str_contains($app, "passes.push({ region: guide, psm: '7', label: 'frame' });")
    && str_contains($app, "passes.push({ region: guide, psm: '7', label: 'frame-ink', binarize: true });")
    && str_contains($app, "label: 'wide', binarize: true"));
check('the OCR button exists on the scan page and only for the module',
    str_contains($html, 'id="scan-med-ocr-btn"')
    && str_contains($app, '_medOcrFromScan()')
    && (bool)preg_match('/if \(ocrBtn\) ocrBtn\.hidden = !readable;/s', $app));
// The printed-code reader must find the AIC in a real page of OCR text (both ports agree).
if (count($jsFns) === 9 && $itFn !== '') {
    $pages = [
        "TACHIPIRINA 500 mg compresse\nA 040 313 076\nMinistero della Salute",
        "IBUPROFENE 200 mg\n16G87N\nLotto 123",
        "codice 014058142\n666000000\nA014058142",
        "NOME Commerciale\nA040313076\nTITOLARE A.I.C.",
        // A real camera reads OCR-B with the tracking printed into the line: every digit comes
        // back with a space around it, and a thin 1 arrives as `l`.
        "A 0 1 2 3 4 5 6 7 8\nTITOLARE A.I.C.",
        "A0l2345678\nTITOLARE A.I.C.",
    ];
    $ocrHarness = 'let _medStatus = ' . json_encode(['schema' => $ccs['IT'] ?? []]) . ";\n"
        . $medCoreJs . "\n" . $medItJs . "\n"
        . 'const patterns = ' . json_encode($ccs['IT']['ocr']['patterns'] ?? []) . ";\n"
        . 'const pages = ' . json_encode($pages) . ";\n"
        . 'console.log(JSON.stringify(pages.map(p => _medOcrFindAic(p, patterns))));';
    $ocrTmp = sys_get_temp_dir() . '/med_ocr_' . getmypid() . '.js';
    file_put_contents($ocrTmp, $ocrHarness);
    $ocrRaw = shell_exec('node ' . escapeshellarg($ocrTmp) . ' 2>&1');
    @unlink($ocrTmp);
    $ocrOut = json_decode(trim((string)$ocrRaw), true);
    check('the OCR harness runs in node', is_array($ocrOut), (string)$ocrRaw);
    if (is_array($ocrOut)) {
        check('the printed block is read with its spacing (A 040 313 076)',
            ($ocrOut[0] ?? null) === '040313076' && ($ocrOut[3] ?? null) === '040313076',
            json_encode($ocrOut));
        check('a lot/serial page yields no AIC (no loose 9-digit fallback)',
            array_key_exists(1, $ocrOut) && $ocrOut[1] === null, json_encode($ocrOut));
        check('a letter-spaced AIC line is read (A 0 1 2 3 4 5 6 7 8)',
            ($ocrOut[4] ?? null) === '012345678', json_encode($ocrOut));
        check('the classic OCR confusions are repaired inside an A-code only (A0l2345678)',
            ($ocrOut[5] ?? null) === '012345678', json_encode($ocrOut));
    }
}





// ── 11. The country schema is DATA a contributor can extend ─────────────────
// The Italian rule is Italy's, not the app's: every country declares its own standard in
// data/medical_code_schemas.json, and the scanner is BLOCKED with a dialog where the schema
// does not exist (or declares nothing to read).
check('the schema is a versioned, documented data file',
    (int)($schemas['version'] ?? 0) >= 3
    && !empty($schemas['contributing']['doc'])
    && !empty($schemas['contributing']['test'])
    && is_file($root . '/' . (string)$schemas['contributing']['doc']));
check('the contribution guide explains the per-country situation',
    ($md = (string)file_get_contents($root . '/' . (string)$schemas['contributing']['doc'])) !== ''
    && str_contains($md, 'blocked')
    && str_contains($md, 'A040313076')
    && str_contains($md, 'PZN')
    && str_contains($md, 'NDC')
    && str_contains($md, 'How to add your country'));
check('every country declares whether it can be scanned',
    array_reduce($ccs, fn($c, $b) => $c && array_key_exists('supported', (array)($b['scan'] ?? [])), true));
check('Italy is the PRINTED case, France the BARCODE case',
    ($ccs['IT']['scan']['national_code'] ?? '') === 'printed'
    && ($ccs['IT']['scan']['ocr'] ?? false) === true
    && ($ccs['FR']['scan']['national_code'] ?? '') === 'barcode'
    && ($ccs['FR']['scan']['ocr'] ?? true) === false);
check('Italy decodes NO barcode in the medicine panel; DE and US keep theirs',
    ($ccs['IT']['scan']['barcodes'] ?? true) === false
    && ($ccs['DE']['scan']['barcodes'] ?? false) === true
    && ($ccs['US']['scan']['barcodes'] ?? false) === true);
check('Germany and the US print their number too, with their own sample',
    ($ccs['DE']['ocr']['sample'] ?? '') === 'PZN 00000000'
    && str_contains((string)($ccs['DE']['ocr']['patterns'][0] ?? ''), 'PZN')
    && ($ccs['US']['ocr']['sample'] ?? '') === '00000-0000-00'
    && str_contains((string)($ccs['US']['ocr']['patterns'][0] ?? ''), 'NDC'));
check('the catalogue sources tell open from licensed',
    ($ccs['IT']['dataset']['open'] ?? false) === true
    && str_contains((string)($ccs['IT']['dataset']['importer'] ?? ''), '--country=IT')
    && ($ccs['DE']['dataset']['open'] ?? true) === false
    && !empty($ccs['DE']['dataset']['note_key'])
    && ($ccs['US']['dataset']['open'] ?? false) === true
    && str_contains((string)($ccs['US']['dataset']['importer'] ?? ''), '--country=US'));
check('Germany and the US have a module for their catalogue columns',
    (medicalCountryModule('DE')['dataset_columns'] ?? '') === 'medicalGermanyColumnAliases'
    && (medicalCountryModule('US')['dataset_columns'] ?? '') === 'medicalUsaColumnAliases'
    && medicalLocalCode('12345678', 'DE') === null  // a plain PZN needs no module code
    && medicalDetectCode('12345678', 'DE')['type'] === 'pzn');
check('a country with no schema is NOT scannable (the UI blocks it)',
    medicalScanSupported('IT') === true
    && medicalScanSupported('US') === true
    && medicalScanSupported('ZZ') === false);
check('med_status hands the block decision and the guide to the client',
    str_contains($api, "'scan_supported' => medicalScanSupported(\$country)")
    && str_contains($api, "'contrib_doc' =>")
    && (bool)preg_match('/function _medScanSupported\(\)[\s\S]{0,900}?_medStatus\.scanSupported/s', $app)
    && (bool)preg_match('/function _scanModeIsMedical\(\)[\s\S]{0,400}?_medScanSupported\(\)/s', $app));
check('the blocked dialog says it, offers the manual entry and the guide',
    (bool)preg_match('/function _medBlockedPopup\(\)[\s\S]{0,1800}?medical\.scan_unsupported_title/s', $app)
    && str_contains($app, "'med-blocked-overlay': () => _medCloseBlocked(true)")
    && (bool)preg_match("/function _medBlockedManual\(\)[\s\S]{0,200}?_medStartManualEntry\('', true, ''\);/s", $app)
    && array_reduce(['it','en','de','fr','es','zh'], function ($c, $l) use ($root) {
        $d = json_decode((string)file_get_contents($root . "/translations/{$l}.json"), true);
        return $c && mb_strlen((string)($d['medical']['scan_unsupported_title'] ?? '')) >= 4
            && mb_strlen((string)($d['medical']['scan_unsupported_body'] ?? '')) > 40
            && mb_strlen((string)($d['medical']['scan_unsupported_contrib'] ?? '')) > 30;
    }, true));
check('the hint above the camera follows the country',
    (bool)preg_match('/function _medScanHintText\(\)[\s\S]{0,900}?medical\.scan_hint_pzn/s', $app)
    && (bool)preg_match('/function _medScanHintText\(\)[\s\S]{0,1400}?medical\.scan_hint_ndc/s', $app)
    && (bool)preg_match('/function _setScanMode\(mode\)[\s\S]{0,1800}?hint\.textContent = medical \? _medScanHintText\(\)/s', $app));
check('the panel hides the reader where it cannot read',
    (bool)preg_match('/const readable = medical && _medScanSupported\(\) && _medOcrPatterns\(\)\.length > 0;/s', $app)
    && (bool)preg_match('/if \(medical && !_medScanSupported\(\)\) _medBlockedPopup\(\);/s', $app));

// ── 12. The catalogue import is country-driven (CSV/XML/JSON) ─────────────
// The national number is the key, whatever its length: an AIC is 9 digits, an NDC 10-11.
check('the catalogue key is the national number, not an Italian shape',
    medicineNormalizeKey('A040313076') === '040313076'
    && medicineNormalizeKey('12345-678-90') === '1234567890'
    && medicineNormalizeKey('PZN 12345678') === 'PZN12345678');
$usDb = new PDO('sqlite::memory:');
$usDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
medicinesEnsureTable($usDb);
$usModule = medicalCountryModule('US');
$usAliases = (array)call_user_func($usModule['dataset_columns']);
$usImport = medicinesImportFile($usDb, $root . '/scripts/fixtures/openfda_ndc_sample.json', [], $usAliases);
check('the openFDA JSON import fills the table',
    $usImport['ok'] === true && $usImport['format'] === 'json' && $usImport['saved'] === 2,
    json_encode($usImport));
$usRow = medicineFindByAic($usDb, '00000-0001-01');
check('the NDC names the box (brand, generic, labeler) without mixing the columns',
    ($usRow['name'] ?? '') === 'DEMO DRUG ALPHA'
    && ($usRow['ingredient'] ?? '') === 'demo ingredient alpha'
    && ($usRow['company'] ?? '') === 'Demo Pharma LLC',
    json_encode($usRow));
// ── 13. A doubtful read is a QUESTION, not a silent acceptance ─────────────
// AIC 040313076 read as 040313072 is one digit: the reader cannot notice, so the household
// confirms among the real codes closest to what the camera saw.
$itDb = new PDO('sqlite::memory:');
$itDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
medicinesEnsureTable($itDb);
foreach ([
    ['aic' => '040313076', 'name' => 'DEMO FARMACO 500 MG'],
    ['aic' => '040313072', 'name' => 'DEMO FARMACO 250 MG'],
    ['aic' => '040313080', 'name' => 'DEMO FARMACO 1000 MG'],
    ['aic' => '040199999', 'name' => 'ALTRO FARMACO'],
] as $row) {
    medicineUpsert($itDb, $row);
}
$near = medicineFindNeighbours($itDb, '0403130', 10);
check('the catalogue answers with the REAL codes next to a read',
    count($near) === 3
    && array_reduce($near, fn($c, $r) => $c && str_starts_with((string)$r['aic'], '0403130'), true),
    json_encode(array_column($near, 'aic')));
check('the distance orders the candidates (a wrong digit is one edit away)',
    medicineLevenshtein('040313072', '040313076') === 1
    && medicineLevenshtein('040313072', '040313072') === 0
    && medicineLevenshtein('040313072', '040199999') === 6
    && medicineFindNeighbours($itDb, '040', 10) === []);
$apiSrc = (string)file_get_contents($root . '/api/index.php');
check('med_lookup can answer with the neighbours (`near=1`) and says whether the read exists',
    (bool)preg_match('/function medLookupAction\\(PDO \\$db\\)[\\s\\S]{0,3000}?if \\(\\$near && \\$aic !== \\x27\\x27\\)/s', $apiSrc)
    && str_contains($apiSrc, "'exact' => \$exact,")
    && str_contains($apiSrc, "medicineLevenshtein(\$code, \$rowAic)"));
check('nothing is glued over the camera (no card, no read box)',
    // Asked twice by the household, and right: a white card, then a dark one, both sat on top of
    // the label being aimed at. The guide frame fills in instead; the form does the correcting.
    !str_contains($html, 'scan-med-choices')
    && !str_contains($html, 'scan-med-read')
    && !str_contains($app, 'function _medRenderChoices(')
    && !str_contains($app, 'function _medOcrFeedback(')
    && !str_contains($css, '.scan-med-choices')
    && !str_contains($css, '.scan-med-read')
    && str_contains($app, 'function _medNeighboursHtml(rows)')
    && str_contains($app, 'function _medUseCode(code)')
    && str_contains($css, '.med-neighbour-row'));
check('a read is VERIFIED against the catalogue before anything opens',
    // `A040313076` read as `...72`: the formulary has no such medicine, so the scanner keeps
    // reading instead of handing the household the wrong box. With no catalogue loaded there is
    // nothing to verify against, and the read is taken (the label name fills the form).
    str_contains($app, 'async function _medConfirmRead(read)')
    && substr_count($app, '_medConfirmRead(reading.aic)') === 3
    && !str_contains($app, '_medAskAic(')
    && str_contains($app, 'const _medRejectedReads = new Set();')
    && (bool)preg_match("/if \\(loaded && !exact\\) \\{[\\s\\S]{0,400}?_medRejectedReads\\.add\\(code\\);\\s*_medLastRejectedAt = Date\\.now\\(\\);/s", $app)
    && (bool)preg_match("/function _medConfirmRead\\(read\\)[\\s\\S]{0,2600}?_finalizeBarcode\\('A' \\+ code, 'OCR'\\);/s", $app)
    && (bool)preg_match('/_medNeighbourCodes = loaded \\? _medChoiceCandidates\(code, rows, 4\)/s', $app)
    && (bool)preg_match('/_medNeighboursHtml\\(rows\\)/s', $app));
check('a doubtful read is correctable where the name is (not over the camera)',
    // The read opens the form; the numbers one edit away are chips on the catalogue card, so a
    // wrong digit is one tap from the right box — and nothing covers the viewfinder.
    (bool)preg_match("/function _medNeighboursHtml\(rows\)[\s\S]{0,900}?medical\\.choice_title_inline/s", $app)
    && (bool)preg_match('/function _medRenderNeighbours\(\)[\s\S]{0,700}?med-neighbour-row/s', $app)
    && (bool)preg_match("/function _medUseCode\\(code\\)[\\s\\S]{0,500}?medical\\.choice_switched/s", $app)
    && (bool)preg_match('/if \\(!rows\\.length\\) \\{[\\s\\S]{0,600}?_medLabelNameFill\(\) \+\s*_medNeighboursHtml\(rows\)/s', $app)
    && (bool)preg_match('/_medMatchResults = \\[\\];\\s*_medShowCatalogueMatch\\(value\\);/s', $app)
    && array_reduce(['it','en','de','fr','es','zh'], function ($c, $l) use ($med) {
        return $c && isset($med[$l]['choice_title_inline'], $med[$l]['choice_switched']);
    }, true));
check('the reader says WHY it cannot read (engine, geometry, frames)',
    // "It does not read" had three indistinguishable causes: no engine, a crop of 30 px, or a
    // box outside the rectangle. Each one is now reported where the household and I can see it.
    str_contains($app, "t('medical.ocr_engine_error')")
    && str_contains($app, 'function _medOcrGeometryNote(videoEl, region)')
    && (bool)preg_match('/GEOM frame [^`]{0,12}crop/s', $app)
    && str_contains($app, "label: 'whole-frame'")
    // A 640x480 stream is an unreadable crop: the medicine panel asks for more pixels.
    && (bool)preg_match('/if \(medical\) _medBoostResolution\(\)/s', $app)
    && (bool)preg_match('/async function _medBoostResolution\(\)[\s\S]{0,1600}?applyConstraints/s', $app)
    && (bool)preg_match("/if \(now - _medFullPassAt > 6000\)/s", $app)
    // The geometry stays in the log: the household asked for no boxes on the camera.
    && !str_contains($css, '.scan-med-read-geom')
    && array_reduce(['it','en','de','fr','es','zh'], function ($c, $l) use ($med) {
        return $c && mb_strlen((string)($med[$l]['ocr_engine_error'] ?? '')) > 20;
    }, true));
check('the guide frame fills with the digits already recognised',
    str_contains($app, 'function _medPreviewSample(raw, aic)')
    && (bool)preg_match("/el\\.textContent = digits\\.length >= 3/s", $app)
    && (bool)preg_match('/_medPreviewSample\\(page, aic\\);/s', $app)
    && str_contains($css, '.scan-med-frame-sample.is-read'));
check('the catalogue card says where the name came from (and the rest of the row)',
    str_contains($app, "t('medical.match_source')")
    && str_contains($app, "row.atc ? t('medical.match_atc')")
    && str_contains($app, "row.fascia ? t('medical.match_fascia')")
    && str_contains($app, 'med-match-source'));
check('the free catalogue can be loaded from Settings (no SSH, no API call)',
    str_contains($apiSrc, "case 'med_catalog_import':")
    && (bool)preg_match('/function medCatalogImportAction\(PDO \$db\)[\s\S]{0,3000}?medicinesImportFile\(\$db, \$path/s', $apiSrc)
    && str_contains($apiSrc, "'event' => 'med_catalog_import'")
    && str_contains($html, 'id="med-dataset-import-wrap"')
    && str_contains($html, 'id="med-dataset-file"')
    && str_contains($app, 'async function _medImportCatalogFile(input)')
    && (bool)preg_match('/_medImportCatalogFile\(input\)[\s\S]{0,1200}?med_catalog_import/s', $app));
// The candidate ranking runs in node, on the app's own code.
$candJs = 'let _medStatus = ' . json_encode(['schema' => $ccs['IT'] ?? []]) . ";\n" . $medCoreJs . "\n" . $medItJs . "\nconsole.log(JSON.stringify(_medChoiceCandidates('040313072', [{aic:'040313076'},{aic:'040313072'},{aic:'040399999'}]).map(c => c.aic + ':' + c.distance)));";
$candTmp = sys_get_temp_dir() . '/med_cand_' . getmypid() . '.js';
file_put_contents($candTmp, $candJs);
$candRaw = trim((string)shell_exec('node ' . escapeshellarg($candTmp) . ' 2>&1'));
@unlink($candTmp);
check('the candidates are ranked by distance, the read first',
    $candRaw === '["040313072:0","040313076:1","040399999:5"]', $candRaw);

check('a catalogue export needs the country module to be mapped',
    medicinesImportFile($usDb, $root . '/scripts/fixtures/openfda_ndc_sample.json', [])['error'] === 'missing_columns');

echo "\n" . ($fail === 0 ? 'PASS' : 'FAIL') . " — {$checks} checks, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);

