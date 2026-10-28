#!/usr/bin/env php
<?php
/**
 * Guard test — the credits of the free services, on every surface.
 *
 * EverShelf stands on open data: Open Food Facts (barcodes, categories, photos),
 * Open Prices (community prices) and Open-Meteo (weather + geocoding). This app thanks them
 * in four places, all fed by ONE list (`data/free_apis.json` → generated JS):
 *
 *   - the splash (before any token exists, offline),
 *   - the bottom of the overview,
 *   - the Settings card,
 *   - a dedicated thanks page (Settings → Crediti) with the note on WHERE and WHAT each
 *     service is used for, plus the licence and the logo disclaimer.
 *
 * What it locks: the logo files exist and are real images (no broken-image icon, no remote
 * fetch), the list drives all four surfaces, every service has its "where" sentence in all
 * six locales, and the logos stay attributed.
 *
 * Run: php scripts/test-credits.php
 */

$root = dirname(__DIR__);
$html = (string)file_get_contents($root . '/index.html');
$app  = (string)file_get_contents($root . '/assets/js/app.js');
$data = json_decode((string)file_get_contents($root . '/data/free_apis.json'), true);
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

// ── The list ───────────────────────────────────────────────────────────────
$apis = is_array($data) ? (array)($data['apis'] ?? []) : [];
check('data/free_apis.json lists the services', count($apis) >= 3, (string)count($apis));
check('the data explains the logo attribution', trim((string)($data['logo_note'] ?? '')) !== '');
foreach ($apis as $api) {
    $id = (string)($api['id'] ?? '');
    check("{$id}: has a name, a mark, a licence and an https url",
        trim((string)($api['name'] ?? '')) !== ''
        && trim((string)($api['mark'] ?? '')) !== ''
        && trim((string)($api['licence'] ?? '')) !== ''
        && preg_match('#^https://#', (string)($api['url'] ?? '')) === 1);
}

// ── The logos are real local files ─────────────────────────────────────────
// A source may also ship WITHOUT a file: then it is drawn with its monogram (the guaranteed
// fallback), which is what the national catalogue does until its official mark is vendored.
foreach ($apis as $api) {
    $id   = (string)($api['id'] ?? '');
    $logo = (string)($api['logo'] ?? '');
    if ($logo === '') {
        check("{$id}: no logo file, so it draws its monogram", trim((string)($api['mark'] ?? '')) !== '');
        continue;
    }
    $path = $root . '/' . $logo;
    check("{$id}: the logo is a local asset under assets/img/", str_starts_with($logo, 'assets/img/') && is_file($path), $logo);
    if (!is_file($path)) {
        continue;
    }
    $head = (string)file_get_contents($path, false, null, 0, 8);
    $isSvg = str_ends_with($logo, '.svg') && str_contains((string)file_get_contents($path, false, null, 0, 400), '<svg');
    $isPng = str_ends_with($logo, '.png') && str_starts_with($head, "\x89PNG");
    check("{$id}: the logo really is an image", $isSvg || $isPng, $logo);
    check("{$id}: the logo is not an empty stub", filesize($path) > 800, (string)filesize($path));
}
// No remote logo: a third-party request behind a thank-you is a tracking pixel.
$jsonText = (string)file_get_contents($root . '/data/free_apis.json');

// ── A logo must be readable where it is shown ──────────────────────────────
// The splash paints a 22 px box, the chips a 28 px one: a HORIZONTAL wordmark (7:1) becomes
// a sliver there — which is exactly why three logos "were not there". The real aspect ratio
// of every file decides the flags, so a future logo cannot slip into the splash unreadable.
$shapeErrors = [];
foreach ($apis as $api) {
    $id   = (string)($api['id'] ?? '');
    $file = $root . '/' . (string)($api['logo'] ?? '');
    if (!is_file($file)) {
        continue;
    }
    $w = 0;
    $h = 0;
    if (str_ends_with($file, '.svg')) {
        $head = (string)file_get_contents($file, false, null, 0, 1500);
        if (preg_match('/width="(\d+)"/', $head, $mw) && preg_match('/height="(\d+)"/', $head, $mh)) {
            $w = (int)$mw[1];
            $h = (int)$mh[1];
        }
    } else {
        $head = (string)file_get_contents($file, false, null, 0, 33);
        // PNG IHDR: width at byte 16, height at byte 20, both big-endian uint32.
        $dim  = unpack('Nw/Nh', substr($head, 16, 8));
        $w = (int)($dim['w'] ?? 0);
        $h = (int)($dim['h'] ?? 0);
    }
    if ($w <= 0 || $h <= 0) {
        $shapeErrors[] = "{$id}: image size unreadable";
        continue;
    }
    $isWordmark = ($w / $h) > 2;
    $markedWide   = ($api['wide'] ?? false) === true;
    $markedSplash = ($api['splash'] ?? true) !== false;
    if ($isWordmark && (!$markedWide || $markedSplash)) {
        $shapeErrors[] = "{$id}: {$w}x{$h} is a wordmark but not marked wide + splash:false";
    }
    if (!$isWordmark && $markedWide) {
        $shapeErrors[] = "{$id}: {$w}x{$h} is square but marked wide";
    }
}
check('every wordmark logo is marked wide and kept off the splash', $shapeErrors === [], implode(', ', $shapeErrors));
check('the generated file and the API carry both flags',
    str_contains((string)file_get_contents($root . '/assets/js/free_apis.generated.js'), '"wide":true')
    && str_contains((string)file_get_contents($root . '/api/index.php'), "'wide'    => !empty(\$api['wide'])")
    && str_contains((string)file_get_contents($root . '/scripts/gen-free-apis-js.php'), "'splash'  => !array_key_exists('splash', \$api)"));
check('the logo helper gives a wordmark a wide box',
    str_contains($app, "const wide = (api && api.wide === true) ? ' is-wide' : '';")
    && str_contains((string)file_get_contents($root . '/assets/css/surfaces.css'), '.free-api-logo.is-wide'));

// ── Four surfaces, one list ────────────────────────────────────────────────
check('the splash paints the credits', str_contains($html, 'id="preloader-apis"')
    && str_contains($app, 'function _renderPreloaderApis(')
    && str_contains($app, '_renderPreloaderApis();'));
check('the splash uses the official logo helper',
    str_contains($app, "_freeApiLogoHtml(api, 'preloader-api-logo')"));
// The splash prints the MARKS ONLY: the names wrapped onto three lines on a phone, and the
// splash is not a menu — the names, the licences and the why/where live in Settings.
preg_match('/function _renderPreloaderApis\(\)[\s\S]*?\n\}/', $app, $splashFn);
$splashBody = $splashFn[0] ?? '';
check('the splash body is extractable', $splashBody !== '');
check('the splash never prints the service names', !str_contains($splashBody, 'preloader-api-name'));
check('the dead name style is gone from the stylesheet',
    !str_contains((string)file_get_contents($root . '/assets/css/style.css'), '.preloader-api-name'));
// The button the household looks for when it asks what leaves the house: it sits in the
// free-API card, immediately above the support card (and its buttons).
$creditsCardAt = strpos($html, 'id="settings-free-apis-card"');
$supportCardAt = strpos($html, 'id="settings-support-card"');
check('the free-API card opens the "why/where" dialog above the support card',
    str_contains($html, 'onclick="_openFreeApisDialog()"')
    && str_contains($app, 'function _openFreeApisDialog(')
    && $creditsCardAt !== false && $supportCardAt !== false && $creditsCardAt < $supportCardAt);
check('the dialog answers "why" and "in which context" from the same credited list',
    str_contains($app, 'credits.why_usage') && str_contains($app, 'credits.why_where')
    && str_contains($app, '_freeApiUsage(api)') && str_contains($app, '_freeApiWhere(api)')
    && str_contains($app, "ov.id = 'free-apis-overlay'")
    && str_contains($app, "'free-apis-overlay': () => _closeFreeApisDialog()"));
// The overview does NOT carry the credits any more: on a phone that footer took a third of
// the screen right above the nav bar, stealing space from the food lists (audit, round 14).
// The splash shows the marks at boot, Settings keeps the card and the dedicated page.
check('the overview stays free of the credits footer', !str_contains($html, 'app-footer-dashboard'));
check('the Settings card carries them too', str_contains($html, 'id="settings-free-apis"')
    && str_contains($app, "document.getElementById('settings-free-apis')"));
check('the dedicated thanks page exists (tab + panel)',
    str_contains($html, 'data-tab="tab-credits"')
    && str_contains($html, 'id="tab-credits"')
    && str_contains($html, 'id="settings-free-apis-page"')
    && str_contains($app, "document.getElementById('settings-free-apis-page')"));
check('the page can be opened from the card', str_contains($app, 'function _openCreditsPage(')
    && str_contains($html, 'onclick="_openCreditsPage()"'));
check('the logos are painted by one helper with a monogram fallback',
    str_contains($app, 'function _freeApiLogoHtml(')
    && str_contains($app, "classList.add('is-fallback')")
    && str_contains($app, 'EVERSHELF_FREE_APIS'));
check('the generated list and the logos are in the service-worker shell',
    str_contains((string)file_get_contents($root . '/sw.js'), 'free_apis.generated.js')
    && str_contains((string)file_get_contents($root . '/sw.js'), 'assets/img/apis/open-food-facts.svg'));

// ── Every service has its note, and the note is translated ────────────────
preg_match('/function _freeApiWhere\(api\) \{[\s\S]*?\n\}/', $app, $whereFn);
$whereBody = $whereFn[0] ?? '';
check('_freeApiWhere() is extractable', $whereBody !== '');
foreach ($apis as $api) {
    $id = (string)($api['id'] ?? '');
    check("{$id}: the page says where we use it", str_contains($whereBody, "case '{$id}':"));
}

foreach (['it', 'en', 'de', 'fr', 'es', 'zh'] as $loc) {
    $doc = json_decode((string)file_get_contents($root . "/translations/{$loc}.json"), true);
    $credits = (array)($doc['credits'] ?? []);
    check("translations/{$loc}.json has the credits page copy",
        trim((string)($credits['page_title'] ?? '')) !== ''
        && trim((string)($credits['page_lead'] ?? '')) !== ''
        && trim((string)($credits['page_note'] ?? '')) !== ''
        && trim((string)($credits['open_page'] ?? '')) !== ''
        && trim((string)($credits['back'] ?? '')) !== '');
    check("translations/{$loc}.json keeps the logo disclaimer",
        trim((string)($credits['logo_note'] ?? '')) !== '');
    check("translations/{$loc}.json answers which API, why and where",
        trim((string)($credits['why_button'] ?? '')) !== ''
        && trim((string)($credits['why_title'] ?? '')) !== ''
        && trim((string)($credits['why_lead'] ?? '')) !== ''
        && trim((string)($credits['why_usage'] ?? '')) !== ''
        && trim((string)($credits['why_where'] ?? '')) !== '');
    check("translations/{$loc}.json names the settings tab",
        trim((string)($doc['settings']['tab_credits'] ?? '')) !== '');
    foreach ($apis as $api) {
        $id = (string)($api['id'] ?? '');
        check("translations/{$loc}.json has credits.{$id} and _where",
            trim((string)($credits['api_' . $id] ?? '')) !== ''
            && trim((string)($credits['api_' . $id . '_where'] ?? '')) !== '');
    }
}

// The sources behind the data files are credited where the APIs are (and never called at
// runtime): GS1, Pharmacode/Laetus, PZN, the EU FMD regulation, AIFA as Phase 2.
$sources = is_array($data['sources'] ?? null) ? $data['sources'] : [];
check('data/free_apis.json lists the reference sources', count($sources) >= 4, (string)count($sources));
$srcMissing = [];
foreach ($sources as $src) {
    $key = (string)($src['use_key'] ?? '');
    if ($key === '') { $srcMissing[] = (string)($src['id'] ?? '?') . ': no use_key'; continue; }
    [$group, $name] = array_pad(explode('.', $key, 2), 2, '');
    foreach (['it', 'en', 'de', 'fr', 'es', 'zh'] as $lang) {
        $dict = json_decode((string)file_get_contents($root . "/translations/{$lang}.json"), true);
        if (!isset($dict[$group][$name]) || $dict[$group][$name] === '') {
            $srcMissing[] = "{$lang}: {$key}";
        }
    }
    $logo = (string)($src['logo'] ?? '');
    if ($logo !== '' && !str_starts_with($logo, 'assets/img/')) {
        $srcMissing[] = (string)($src['id'] ?? '?') . ': remote logo';
    }
}
check('every source has a translated sentence in six locales and no remote logo', $srcMissing === [], implode(', ', array_slice($srcMissing, 0, 5)));
check('the credits page renders the sources and the medicines row',
    str_contains($app, 'function _dataSourcesHtml')
    && str_contains($app, '_dataSourcesHtml(sources)')
    && str_contains($app, "credits.med_no_api")
    && str_contains($app, 'EVERSHELF_DATA_SOURCES'));
check('the generated file carries the sources (splash-safe, offline)',
    str_contains((string)file_get_contents($root . '/assets/js/free_apis.generated.js'), 'EVERSHELF_DATA_SOURCES'));

// ── A source that exists only in some configurations ───────────────────────
// The national medicine catalogue is not a service everybody uses: it is real only for an
// instance whose module is ON and whose country module is Italy. Its logo must be on the
// splash AND in the thanks page there, and ABSENT everywhere else — a credit list that shows
// a source the app does not use is advertising, not attribution.
$byId = [];
foreach ($apis as $api) { $byId[(string)($api['id'] ?? '')] = $api; }
$aifa = $byId['aifa'] ?? [];
check('the national catalogue is a declared source with a "when" gate',
    ($aifa['when']['medical'] ?? false) === true
    && in_array('IT', (array)($aifa['when']['country'] ?? []), true)
    && ($aifa['splash'] ?? true) !== false);
check('it is the only conditional source (the others are always on)',
    array_reduce($apis, fn($c, $api) => $c && ((string)($api['id'] ?? '') === 'aifa' || empty($api['when'])), true));
check('the generator carries the gate into the generated file',
    str_contains((string)file_get_contents($root . '/scripts/gen-free-apis-js.php'), "'when'    => _freeApiWhen(")
    && str_contains((string)file_get_contents($root . '/assets/js/free_apis.generated.js'), '"when":{"medical":true,"country":["IT"]}'));
check('the splash paints only the sources in use (and repaints when the state arrives)',
    (bool)preg_match('/function _renderPreloaderApis\(\)[\s\S]{0,900}?_activeFreeApis\(/s', $app)
    && str_contains($app, 'el.dataset.sig === sig')
    && (bool)preg_match('/function _medRefreshStatus\(\)[\s\S]{0,2600}?_syncCreditSources\(\);/s', $app));
check('the thanks page and the why/where dialog use the same filter',
    (bool)preg_match('/function _loadFreeApis\(\)[\s\S]{0,2200}?const active = _activeFreeApis\(_freeApisCache\);/s', $app)
    && (bool)preg_match('/function _openFreeApisDialog\(\)[\s\S]{0,900}?_activeFreeApis\(list\)/s', $app));
check('the gate reads the module state and the active country',
    (bool)preg_match('/function _freeApiActive\(api\)[\s\S]{0,1400}?when\.medical === true && !_medOn\(\)/s', $app)
    && (bool)preg_match('/function _freeApiActive\(api\)[\s\S]{0,1700}?_medStatus\.country/s', $app));
check('the last known set is remembered for the splash before the token',
    str_contains($app, "localStorage.setItem('evershelf_credits_seen_v1'")
    && (bool)preg_match('/function _freeApiActive\(api\)[\s\S]{0,900}?_creditsSeenIds\(\)/s', $app));
// The gate must actually DO it: a harness with a fake `_medStatus` and a fake localStorage.
$gateFn = '';
foreach (['_freeApiActive(api)', '_creditsSeenIds()'] as $sig) {
    if (preg_match('/function ' . preg_quote($sig, '/') . ' \{.*?\n\}/s', $app, $mFn)) {
        $gateFn .= $mFn[0] . "\n";
    }
}
check('the source gate is extractable', $gateFn !== '');
if ($gateFn !== '') {
    $gateHarness = "const __store = {};\n"
        . "globalThis.localStorage = { getItem: k => (k in __store ? __store[k] : null), setItem: (k, v) => { __store[k] = String(v); } };\n"
        . "let _medStatus = { schema: null, enabled: false, accepted: false, country: '' };\n"
        . "function _medOn() { return !!(_medStatus.enabled && _medStatus.accepted); }\n"
        . $gateFn
        . "const aifa = { id: 'aifa', when: { medical: true, country: ['IT'] } };\n"
        . "const off = { id: 'off' };\n"
        . "const out = [];\n"
        . "out.push(['unknown state, never seen', _freeApiActive(aifa)]);\n"
        . "localStorage.setItem('evershelf_credits_seen_v1', JSON.stringify(['aifa']));\n"
        . "out.push(['unknown state, seen last boot', _freeApiActive(aifa)]);\n"
        . "_medStatus = { schema: {}, enabled: false, accepted: false, country: 'IT' };\n"
        . "out.push(['module off', _freeApiActive(aifa)]);\n"
        . "_medStatus = { schema: {}, enabled: true, accepted: true, country: 'DE' };\n"
        . "out.push(['module on, another country', _freeApiActive(aifa)]);\n"
        . "_medStatus = { schema: {}, enabled: true, accepted: true, country: 'IT' };\n"
        . "out.push(['module on, Italy', _freeApiActive(aifa)]);\n"
        . "out.push(['unconditional source always on', _freeApiActive(off)]);\n"
        . "console.log(JSON.stringify(out));";
    $gateTmp = sys_get_temp_dir() . '/credits_gate_' . getmypid() . '.js';
    file_put_contents($gateTmp, $gateHarness);
    $gateRaw = shell_exec('node ' . escapeshellarg($gateTmp) . ' 2>&1');
    @unlink($gateTmp);
    $gateOut = json_decode(trim((string)$gateRaw), true);
    check('the gate harness runs in node', is_array($gateOut), (string)$gateRaw);
    if (is_array($gateOut)) {
        $got = [];
        foreach ($gateOut as $row) { $got[(string)$row[0]] = (bool)$row[1]; }
        check('the catalogue follows the module state and the country (not a fixed list)',
            ($got['unknown state, never seen'] ?? true) === false
            && ($got['unknown state, seen last boot'] ?? false) === true
            && ($got['module off'] ?? true) === false
            && ($got['module on, another country'] ?? true) === false
            && ($got['module on, Italy'] ?? false) === true
            && ($got['unconditional source always on'] ?? false) === true,
            json_encode($got));
    }
}

check('the catalogue has its own why/where sentence in six locales',
    array_reduce(['it','en','de','fr','es','zh'], function ($c, $l) use ($root) {
        $d = json_decode((string)file_get_contents($root . "/translations/{$l}.json"), true);
        return $c && mb_strlen(trim((string)($d['credits']['api_aifa'] ?? ''))) > 40
            && mb_strlen(trim((string)($d['credits']['api_aifa_where'] ?? ''))) > 40;
    }, true));



// ── Every barcode source the API really queries is credited ─────────────────
// The complaint that started this: Open Products/Beauty/Pet Food Facts were queried by the
// resolver and missing from the credits. A source that answers a scan must be in the list.
$resolver = (string)file_get_contents($root . '/api/index.php');
preg_match_all('#https?://([a-z0-9.\-]+\.(?:openfoodfacts|openproductsfacts|openbeautyfacts|openpetfoodfacts)\.org)#i', $resolver, $m);
$queried = array_values(array_unique(array_map('strtolower', $m[1] ?? [])));
$creditedHosts = [];
foreach ($apis as $api) {
    $h = parse_url((string)($api['url'] ?? ''), PHP_URL_HOST);
    if (is_string($h) && $h !== '') { $creditedHosts[] = strtolower($h); }
}
$uncredited = [];
foreach ($queried as $h) {
    // A country mirror (it.openfoodfacts.org) and the world host are the SAME credited
    // service: compare by domain, not by label-by-label equality.
    // Compare the registrable domain: it.openfoodfacts.org and world.openfoodfacts.org are
    // the same credited service.
    $base = implode('.', array_slice(explode('.', $h), -2));
    $covered = false;
    foreach ($creditedHosts as $credited) {
        if ($h === $credited || $base === implode('.', array_slice(explode('.', $credited), -2))) {
            $covered = true;
            break;
        }
    }
    if (!$covered) { $uncredited[] = $h; }
}
check('every barcode source the API queries is in the credits (' . count($queried) . ' hosts)',
    $queried !== [] && $uncredited === [], implode(', ', $uncredited));
// The commercial trial endpoint must never be in the default chain: it is opt-in.
check('the UPCitemdb trial lookup is opt-in, not a silent default',
    str_contains($resolver, 'UPC_LOOKUP_ENABLED')
    && !preg_match("/\$priority = \[[^\]]*'upc'/s", $resolver)
    && str_contains((string)file_get_contents($root . '/.env.example'), 'UPC_LOOKUP_ENABLED=false'));

echo "\n{$checks} checks, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);

check('no logo points at a remote URL', preg_match('/"logo"\s*:\s*"https?:/', $jsonText) === 0);
