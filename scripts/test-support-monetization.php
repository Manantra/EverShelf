#!/usr/bin/env php
<?php
/**
 * Regression tests: the optional support layer (donations + banner slots).
 *
 * Regression history: none yet — this file exists because the layer touches four
 * things at once (the `.env` it reads, the API payload the client draws from, the
 * markup the client writes into, and six locales), and a mismatch in any of them
 * fails silently: a missing key prints its raw name, an unknown mode leaves the
 * banners in a state nobody described, a banner markup written through the API
 * would be an injection point.
 *
 * What it locks down:
 *   * the four answers and four providers are exactly the documented ones, and a
 *     junk mode/provider is clamped instead of trusted;
 *   * "ads" is the state while nobody answered, and "donate"/"later" really do turn
 *     the banners off (the household said no to them);
 *   * the donation URL adapts to paypal.me, to a hosted button, and stays untouched
 *     when the button already carries an amount;
 *   * the cost rows are the ones actually paid, and the monthly equivalent adds the
 *     yearly lines at a twelfth;
 *   * every `support.*` key the client uses exists in all six locales;
 *   * `support_qr` is routed, public, and can only render published amounts;
 *   * the banner markup is .env-only: save_settings must not accept it;
 *   * index.html loads the module, sw.js precaches it, .env.example documents every
 *     key the library reads.
 *
 * Run: php scripts/test-support-monetization.php
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

function assert_same($expected, $actual, string $msg): void
{
    global $fail;
    if ($expected !== $actual) {
        echo 'FAIL: ' . $msg . ' (got ' . var_export($actual, true)
            . ', expected ' . var_export($expected, true) . ")\n";
        $fail++;
    } else {
        echo "OK: {$msg}\n";
    }
}

$root = __DIR__ . '/..';
$appJs  = (string)file_get_contents($root . '/assets/js/app.js');
$supJs  = (string)file_get_contents($root . '/assets/js/core/support.js');
$html   = (string)file_get_contents($root . '/index.html');
$sw     = (string)file_get_contents($root . '/sw.js');
$api    = (string)file_get_contents($root . '/api/index.php');
$lib    = (string)file_get_contents($root . '/api/lib/support.php');
$envEx  = (string)file_get_contents($root . '/.env.example');

/** Set .env overrides for one assertion block, then restore. */
function withSupportEnv(array $values, callable $check): void
{
    $previous = loadEnvOverrides(true);
    saveEnvOverrides($values);
    try {
        $check();
    } finally {
        // Restore exactly what was there before this test touched anything.
        clearEnvOverrides(array_keys($values));
        if ($previous) {
            saveEnvOverrides($previous);
        }
    }
}

// ── 1. The vocabulary ────────────────────────────────────────────────────────
assert_same(['donate', 'later'], evershelfSupportModes(),
    'the dialog offers exactly the two documented answers');
assert_same('', evershelfSupportNormalizeMode('nonsense'), 'a junk mode is refused');
assert_same('later', evershelfSupportNormalizeMode(' LATER '), 'a mode is normalised');
// Advertising is parked, so the four banner helpers are not defined any more: that
// is what stops a slot from being lit up by accident. Re-enabling the slots means
// re-adding them here as well (see docs/wiki/Support-and-ads.md).
foreach (['evershelfSupportAdProviders', 'evershelfSupportBannerModes',
    'evershelfSupportAdProvider', 'evershelfSupportBannersAvailable'] as $gone) {
    assert_true(!function_exists($gone), $gone . ' is gone: the banner slots are parked');
}

// ── 2. Advertising is parked: nothing ever lights a slot up ──────────────────
// The banner answers are gone from the dialog and `evershelfSupportAdsEnabled()`
// is hard-wired to false, so not even a stored "ads"/"both" plus an `ADS_PROVIDER`
// plus the old owner switch can put a slot back on screen.
$parked = [];
withSupportEnv(['SUPPORT_MODE' => '', 'SUPPORT_DECIDED' => 'false', 'ADS_PROVIDER' => 'house'],
    function () use (&$parked) {
        $parked['undecided_ads']   = evershelfSupportAdsEnabled();
        $parked['undecided_asked'] = evershelfSupportAnswered();
        $parked['default']         = evershelfSupportDefaultMode();
    });
foreach (['ads', 'both', 'donate', 'later'] as $storedMode) {
    withSupportEnv(['SUPPORT_MODE' => $storedMode, 'SUPPORT_DECIDED' => 'true',
        'ADS_PROVIDER' => 'house', 'SUPPORT_BANNERS_AVAILABLE' => 'true'],
        function () use (&$parked, $storedMode) {
            $parked[$storedMode] = evershelfSupportAdsEnabled();
        });
}
withSupportEnv(['ADS_PROVIDER' => 'adsense', 'SUPPORT_BANNERS_AVAILABLE' => 'true',
    'SUPPORT_MODE' => 'ads', 'SUPPORT_DECIDED' => 'true'], function () use (&$parked) {
    $parked['adsense_too'] = evershelfSupportAdsEnabled();
});
assert_same(
    ['undecided_ads' => false, 'undecided_asked' => false, 'default' => 'later',
        'ads' => false, 'both' => false, 'donate' => false, 'later' => false,
        'adsense_too' => false],
    $parked,
    'no answer, no .env switch and no provider can put a banner back on screen'
);

// The payload still states `ads_enabled`, and always as false: an older client
// cached in a kiosk WebView must be told that nothing is rendered, rather than
// having to guess. Section 5 checks that no other banner field travels at all.
withSupportEnv(['ADS_PROVIDER' => 'adsense', 'SUPPORT_BANNERS_AVAILABLE' => 'true',
    'SUPPORT_MODE' => 'ads', 'SUPPORT_DECIDED' => 'true'], function () use (&$parked) {
    $parked['payload_ads_enabled'] = evershelfSupportPayload()['ads_enabled'];
});
assert_same(false, $parked['payload_ads_enabled'],
    'the payload states that no slot is rendered, whatever is stored');

// Only the two offered answers survive the save-time clamp: anything else — the
// parked banner answers included — is stored as "later", so the state can never
// claim a choice the app does not apply.
$clamped = [];
withSupportEnv(['SUPPORT_BANNERS_AVAILABLE' => 'true', 'ADS_PROVIDER' => 'house'], function () use (&$clamped) {
    $clamped['ads'] = evershelfSupportClampMode('ads');
    $clamped['both'] = evershelfSupportClampMode('both');
    $clamped['donate'] = evershelfSupportClampMode('donate');
    $clamped['later'] = evershelfSupportClampMode('later');
    $clamped['junk'] = evershelfSupportClampMode('DROP TABLE');
});
assert_same(
    ['ads' => 'later', 'both' => 'later', 'donate' => 'donate', 'later' => 'later', 'junk' => 'later'],
    $clamped,
    'a parked or unknown answer is stored as "later" instead of a choice the app would not apply'
);

// ── 3. The donation link ─────────────────────────────────────────────────────
$urls = [];
withSupportEnv([
    'SUPPORT_PAYPAL_URL' => 'https://paypal.me/evershelf',
    'SUPPORT_PAYPAL_CURRENCY' => 'EUR',
], function () use (&$urls) {
    $urls['me_plain'] = evershelfSupportDonateUrl();
    $urls['me_amount'] = evershelfSupportDonateUrl(10);
});
withSupportEnv([
    'SUPPORT_PAYPAL_URL' => 'https://www.paypal.com/cgi-bin/webscr?cmd=_donations&business=me%40example.com',
    'SUPPORT_PAYPAL_CURRENCY' => 'USD',
], function () use (&$urls) {
    // The legacy donation form does honour a pre-filled amount.
    $urls['form_amount'] = evershelfSupportDonateUrl(2.5);
});
withSupportEnv([
    'SUPPORT_PAYPAL_URL' => 'https://www.paypal.com/cgi-bin/webscr?cmd=_donations&amount=7',
], function () use (&$urls) {
    // One that already carries an amount must not get a second one.
    $urls['form_fixed'] = evershelfSupportDonateUrl(25);
});
withSupportEnv(['SUPPORT_PAYPAL_URL' => ''], function () use (&$urls) {
    $urls['unconfigured'] = evershelfSupportDonateUrl(10);
});
assert_same(
    [
        'me_plain' => 'https://paypal.me/evershelf',
        'me_amount' => 'https://paypal.me/evershelf/10EUR',
        'form_amount' => 'https://www.paypal.com/cgi-bin/webscr?cmd=_donations&business=me%40example.com&amount=2.5&currency_code=USD',
        'form_fixed' => 'https://www.paypal.com/cgi-bin/webscr?cmd=_donations&amount=7',
        'unconfigured' => '',
    ],
    $urls,
    'the donation link adapts to paypal.me and to the legacy form, and stays empty when unset'
);

$amounts = [];
withSupportEnv(['SUPPORT_PAYPAL_AMOUNTS' => '5, 10 ,25,0,-3,junk'], function () use (&$amounts) {
    $amounts = evershelfSupportAmounts();
});
assert_same([5.0, 10.0, 25.0], $amounts, 'only positive amounts survive the parsing');

// A hosted donate button ignores `amount=` (PayPal shows its own presets + an
// editable field), so no amount may be appended there and the client is told not
// to offer amount buttons: a chip would promise a figure PayPal never receives.
$hosted = [];
withSupportEnv([
    'SUPPORT_PAYPAL_URL' => 'https://www.paypal.com/donate/?hosted_button_id=PWFJDMB8ME59L',
    'SUPPORT_PAYPAL_AMOUNTS' => '5,10,25',
], function () use (&$hosted) {
    $hosted['supported'] = evershelfSupportAmountsSupported();
    $hosted['url_plain'] = evershelfSupportDonateUrl();
    $hosted['url_amount'] = evershelfSupportDonateUrl(25);
});
assert_same(false, $hosted['supported'], 'a hosted donate button cannot preset an amount');
assert_same(
    'https://www.paypal.com/donate/?hosted_button_id=PWFJDMB8ME59L',
    $hosted['url_plain'],
    'the hosted button link is handed over untouched'
);
assert_same($hosted['url_plain'], $hosted['url_amount'],
    'asking for an amount on a hosted button does not fake a query parameter');

// ── 4. The costs it discloses ────────────────────────────────────────────────
$costs = [];
withSupportEnv([
    'SUPPORT_COST_CURRENCY' => 'EUR',
    'SUPPORT_COST_HOSTING' => '6',
    'SUPPORT_COST_DOMAIN' => '12',
    'SUPPORT_COST_DEVACCOUNT' => '0',
    'SUPPORT_COST_API' => '',
], function () use (&$costs) {
    $costs = evershelfSupportCosts();
});
assert_same([
    ['key' => 'hosting', 'amount' => 6.0, 'period' => 'month'],
    ['key' => 'domain', 'amount' => 12.0, 'period' => 'year'],
], $costs['rows'], 'a cost line left at zero is not disclosed');
assert_same(7.0, $costs['monthly'], 'the monthly equivalent adds the yearly line at a twelfth');
assert_same('EUR', $costs['currency'], 'the cost currency comes from .env');

// ── 5. The payload the client draws from ─────────────────────────────────────
$payload = [];
withSupportEnv([
    'SUPPORT_MODE' => 'donate',
    'SUPPORT_DECIDED' => 'true',
    'SUPPORT_PAYPAL_URL' => 'https://paypal.me/evershelf',
    // Parked keys, set on purpose: they must not change a thing.
    'SUPPORT_BANNERS_AVAILABLE' => 'true',
    'ADS_PROVIDER' => 'adsense',
    'ADSENSE_CLIENT' => 'ca-pub-1234567890123456',
    'ADSENSE_SLOT_APP' => '1112223333',
    'ADSENSE_SLOT_SCREENSAVER' => '4445556666',
    'ADS_CUSTOM_APP_HTML' => '<div>house</div>',
], function () use (&$payload) {
    $payload = evershelfSupportPayload();
});
assert_same('donate', $payload['mode'], 'the payload reports the stored mode');
assert_same(true, $payload['answered'], 'the payload reports that the question was answered');
assert_same('later', $payload['default_mode'], 'the default answer is "later"');
assert_same(false, $payload['ads_enabled'], 'the payload states that nothing is rendered');
assert_same([], array_values(array_filter(array_keys($payload),
    static fn($k) => $k !== 'ads_enabled' && (bool)preg_match('/provider|banner|adsense|custom/i', $k))),
    'the payload exposes no banner, provider or AdSense field any more');
assert_same('https://paypal.me/evershelf', $payload['paypal_url'], 'the donation link travels to the client');
assert_true(isset($payload['costs']['rows'], $payload['amounts']),
    'the payload carries costs and amounts');
assert_true(!isset($payload['prompt_min_days'], $payload['prompt_max_days']),
    'the payload carries no reminder schedule: an answer is final');
assert_true(!str_contains((string)json_encode($payload), '<div>house</div>'),
    'the parked custom banner markup never reaches the client');

// The shipped default: nothing answered, no banner anywhere.
$payloadOff = [];
withSupportEnv(['SUPPORT_MODE' => '', 'SUPPORT_DECIDED' => 'false', 'ADS_PROVIDER' => 'house'],
    function () use (&$payloadOff) {
        $payloadOff = evershelfSupportPayload();
    });
assert_same('later', $payloadOff['default_mode'], 'the default answer is "later"');
assert_same(false, $payloadOff['ads_enabled'], 'an undecided install renders no banner');

// ── 6. Every string exists in all six locales ────────────────────────────────
$locales = ['it', 'en', 'de', 'fr', 'es', 'zh'];
$flatten = function (array $node, string $prefix = '') use (&$flatten): array {
    $out = [];
    foreach ($node as $key => $value) {
        $path = $prefix === '' ? (string)$key : $prefix . '.' . $key;
        if (is_array($value)) {
            $out += $flatten($value, $path);
        } else {
            $out[$path] = (string)$value;
        }
    }
    return $out;
};

// The literal t('support.…') calls in the module + the data-i18n attributes in the
// markup: the audit's own regex, so a helper call cannot hide a key from us.
$used = [];
preg_match_all('/\bt\(\s*[\'"](support\.[A-Za-z0-9_.]+)[\'"]/', $supJs, $m1);
preg_match_all('/\bt\(\s*[\'"](support\.[A-Za-z0-9_.]+)[\'"]/', $appJs, $m2);
preg_match_all('/data-i18n(?:-title|-aria|-placeholder)?="(support\.[A-Za-z0-9_.]+)"/', $html, $m3);
foreach (array_merge($m1[1], $m2[1], $m3[1]) as $key) {
    $used[$key] = true;
}
$used = array_keys($used);
sort($used);
assert_true(count($used) >= 20, 'the support layer uses its own string namespace (' . count($used) . ' keys)');

$missing = [];
foreach ($locales as $locale) {
    $strings = $flatten(json_decode((string)file_get_contents($root . '/translations/' . $locale . '.json'), true) ?: []);
    foreach ($used as $key) {
        if (!isset($strings[$key]) || trim($strings[$key]) === '') {
            $missing[] = $locale . ':' . $key;
        }
    }
}
assert_same([], $missing, 'every support key exists in all six locales');

// The four answer cards and the four cost rows are rendered through label maps:
// a key that map points at must exist too (it is a literal t(), but in another file).
$labels = [];
preg_match_all('/t\(\s*[\'"](support\.(?:option|cost|status)_[A-Za-z0-9_]+)[\'"]/', $supJs, $m4);
foreach ($m4[1] as $key) {
    $labels[$key] = true;
}
assert_true(count($labels) >= 10, 'the option, cost and status labels are all literal keys');

// ── 8. The wiring ────────────────────────────────────────────────────────────
$security = (string)file_get_contents($root . '/api/lib/security.php');

/**
 * The browser evaluates core/*.js and app.js in ONE script scope, so a name
 * declared twice across them is a fatal SyntaxError that kills the whole file —
 * exactly how a `let _supportDonateUrl` next to a `function _supportDonateUrl()`
 * once broke the app ("Identifier … has already been declared"). `node -c` checks
 * one file at a time and cannot see it, so it is checked here: every top-level
 * declaration is collected (column 0, the way a classic script is evaluated) and
 * a name may only be lexical in one single file.
 */
$scripts = [
    'assets/js/core/dom.js'     => (string)file_get_contents($root . '/assets/js/core/dom.js'),
    'assets/js/core/auth.js'    => (string)file_get_contents($root . '/assets/js/core/auth.js'),
    'assets/js/core/support.js' => $supJs,
    'assets/js/app.js'          => $appJs,
];
$lexical   = [];
$functions = [];
$vars      = [];
foreach ($scripts as $file => $code) {
    preg_match_all('/^(?:let|const|class)\s+([A-Za-z_$][A-Za-z0-9_$]*)/m', $code, $m);
    foreach ($m[1] as $name) {
        $lexical[$name][] = $file;
    }
    preg_match_all('/^(?:async\s+)?function\s+([A-Za-z_$][A-Za-z0-9_$]*)/m', $code, $m);
    foreach ($m[1] as $name) {
        $functions[$name][] = $file;
    }
    preg_match_all('/^var\s+([A-Za-z_$][A-Za-z0-9_$]*)/m', $code, $m);
    foreach ($m[1] as $name) {
        $vars[$name][] = $file;
    }
}
$collisions = [];
foreach ($lexical as $name => $files) {
    if (count($files) > 1) {
        $collisions[] = $name . ' declared with let/const/class in ' . implode(' + ', $files);
    }
}
foreach ($lexical as $name => $files) {
    if (isset($functions[$name])) {
        $collisions[] = $name . ' is both lexical and a function declaration (' . implode(' + ', array_merge($files, $functions[$name])) . ')';
    }
    if (isset($vars[$name])) {
        $collisions[] = $name . ' is both lexical and a var (' . implode(' + ', array_merge($files, $vars[$name])) . ')';
    }
}
assert_same([], array_values(array_unique($collisions)),
    'no name is declared twice across dom/auth/support/app (one script scope)');

// The module and app.js call each other: the contract has to hold both ways.
$weakHelpers = [];
preg_match_all('/\b(_[A-Za-z][A-Za-z0-9_]*)\s*\(/', $supJs, $mCalled);
foreach (array_unique($mCalled[1]) as $helper) {
    if (!preg_match('/(?:function|let|const|var)\s+' . preg_quote($helper, '/') . '\b/', implode("\n", $scripts))) {
        $weakHelpers[] = $helper;
    }
}
assert_same([], $weakHelpers, 'every app helper the module calls exists');

$missingContract = [];
foreach (['_renderAdSlots' => '_initApp and the screensaver', '_maybeShowSupportPrompt' => '_initApp and _finishSetup',
    '_renderSupportOptions' => 'loadSettingsUI'] as $fn => $from) {
    if (!str_contains($supJs, 'function ' . $fn)) {
        $missingContract[] = $fn . ' (needed by ' . $from . ')';
    }
}
assert_same([], $missingContract, 'app.js calls only functions the module defines');

// One asset stamp everywhere: a mixed ?v= pair is how a stale-but-cached module
// gets loaded next to a fresh app.js.
preg_match_all('/\?v=([0-9]{8}[a-z])/', $html, $mStamps);
assert_same(1, count(array_unique($mStamps[1])),
    'index.html uses a single asset stamp (' . implode(', ', array_unique($mStamps[1])) . ')');

assert_true(str_contains($api, "case 'support_qr':"), 'support_qr is routed in the API switch');
assert_true(str_contains($security, "'support_qr',"), 'support_qr is allowed without an API token (the kiosk needs it before pairing)');
assert_true(str_contains($api, "'support'                     => evershelfSupportPayload(),"),
    'get_settings hands the support payload to the client');
assert_true(str_contains($api, "'support_mode'          => 'SUPPORT_MODE',"),
    'save_settings accepts the answer (support_mode)');
assert_true(str_contains($api, "'support_decided' => 'SUPPORT_DECIDED',"),
    'save_settings accepts the answered flag (support_decided)');
assert_true(str_contains($api, "evershelfSupportClampMode((string)\$envVars['SUPPORT_MODE'])"),
    'an unknown or currently unavailable mode written through the API is clamped');
assert_true(str_contains($api, "'event' => 'support_decision',"),
    'the answer is logged with a stable event key');
// Comments still name the parked keys (they are the re-enabling checklist), so the
// check looks at the live code only.
$apiLive = implode("\n", array_filter(explode("\n", $api),
    static fn($l) => !preg_match('#^\s*(?://|\*|/\*)#', $l)));
assert_true(!str_contains($apiLive, "'ads_provider'") && !str_contains($apiLive, "'adsense_client'"),
    'save_settings has no banner mapping any more (the keys are parked)');
assert_true(!str_contains($apiLive, 'evershelfSupportAdProviders()'),
    'the API never validates a banner provider any more');
assert_true(str_contains($api, 'evershelfSupportAmounts()'),
    'support_qr only renders the amounts the owner published');
assert_true(str_contains($api, "require_once __DIR__ . '/lib/qr.php';"),
    'the QR encoder is loaded lazily, only by the QR action');

assert_true(str_contains($html, 'assets/js/core/support.js?v='), 'index.html loads the support module');
assert_true(strpos($html, 'core/support.js') < strpos($html, 'assets/js/app.js'), 'the module loads before app.js');
assert_true(str_contains($sw, "BASE + 'assets/js/core/support.js',"), 'the service worker precaches the module');
foreach (['id="support-modal"', 'id="support-options"', 'id="support-costs"', 'id="support-donate"',
    'id="support-qr"', 'id="support-amounts"', 'id="settings-support-options"',
    'id="settings-support-status"', 'id="btn-support-donate"'] as $needle) {
    assert_true(str_contains($html, $needle), "index.html carries {$needle}");
}
assert_true(str_contains($appJs, '_maybeShowSupportPrompt();'),
    'the startup sequence asks the support question');
$liveAdCalls = 0;
foreach (explode("
", $appJs) as $line) {
    if (str_contains($line, '_renderAdSlots') && !preg_match('#^\s*(?://|\*|/\*)#', $line)) {
        $liveAdCalls++;
    }
}
assert_same(0, $liveAdCalls, 'no startup path renders a banner slot any more (ads are parked)');
assert_true(str_contains($supJs, "if (_demoMode) return;"), 'a demo install is never asked');
assert_true(str_contains($supJs, 'if (_supportAnswered()) return;'),
    'an answered install is never asked again: the answer stands until Settings change it');
assert_true(!str_contains($supJs, 'Math.random()') && !str_contains($supJs, 'localStorage'),
    'there is no reminder schedule and no client-side state to drift');
// The hooks the parked banner answers needed are still there (so the checklist in
// the wiki can restore them), but they are inert: nothing is ever disabled, no card
// is greyed out and `_renderAdSlots()` fills nothing.
assert_true(str_contains($supJs, 'function _supportModeDisabled'), 'the module keeps the disabled-answer hook');
assert_true(str_contains($supJs, "function _supportModeDisabled() {
    return false;"),
    'the disabled-answer hook is inert while the slots are parked');
assert_true(str_contains($supJs, "if (_supportModeDisabled(mode)) return;"),
    'a stale request for a parked answer is refused anyway');
assert_true(str_contains($supJs, "function _renderAdSlots() {
    // Intentionally empty"),
    'the slot renderer survives as a no-op (a cached kiosk client cannot throw)');
$liveOnlineListeners = 0;
foreach (explode("
", $supJs) as $line) {
    if (str_contains($line, "window.addEventListener('online'") && !preg_match('#^\s*(?://|\*|/\*)#', $line)) {
        $liveOnlineListeners++;
    }
}
assert_same(0, $liveOnlineListeners,
    'the module no longer watches the connection for banner slots');
// A donation answer narrows the dialog down to the QR + thank-you, and the two
// banner slots are gone from the DOM.
assert_true(str_contains($supJs, 'function _supportDonationFocus'), 'the module knows when the answer is a donation');
assert_true(str_contains($supJs, 'function _supportRevealOptions'), 'the household can still change the answer');
assert_true(str_contains($html, 'id="support-thanks"') && str_contains($html, 'id="support-change"'),
    'the dialog carries the thank-you view and the way back');
preg_match_all('/<!--(.*?)-->/s', $html, $mComments);
$commentedMarkup = implode("
", $mComments[1]);
assert_true(
    str_contains($commentedMarkup, 'id="ad-slot-app"') && str_contains($commentedMarkup, 'id="ad-slot-screensaver"')
    && substr_count($html, 'id="ad-slot-app"') === 1 && substr_count($html, 'id="ad-slot-screensaver"') === 1,
    'the two banner slots stay commented out of the DOM (present, but never live)'
);
foreach (['support.thanks_title', 'support.thanks_text', 'support.change_choice'] as $thanksKey) {
    assert_true(in_array($thanksKey, $used, true), $thanksKey . ' is used by the dialog');
}
assert_true(str_contains($html, 'onclick="_supportRevealOptions()"'), 'the way back is wired to the module');
assert_true(str_contains($html, 'width="240" height="240"'), 'the QR is rendered large');
$styleCss = (string)file_get_contents($root . '/assets/css/style.css');
assert_true(str_contains($styleCss, '.support-donate.is-focused'), 'the focused donation view is styled');
assert_true(str_contains($styleCss, '.support-option:disabled'), 'a switched-off answer is visibly disabled');

// Every .env key the library reads must be documented in .env.example.
preg_match_all("/env\(\s*'([A-Z0-9_]+)'/", $lib, $m5);
$undocumented = [];
foreach (array_unique($m5[1]) as $envKey) {
    if (!str_contains($envEx, $envKey . '=')) {
        $undocumented[] = $envKey;
    }
}
assert_same([], $undocumented, 'every .env key the support layer reads is documented in .env.example');

echo "\n" . ($fail === 0 ? "All support layer tests passed.\n" : "{$fail} failure(s).\n");
exit($fail === 0 ? 0 : 1);
