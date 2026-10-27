#!/usr/bin/env php
<?php
/**
 * Guard test — the post-opening clock (PAO) and the "opened too long" notice.
 *
 * The audit asked for a deterministic engine instead of an AI guess: one table of
 * macro-families with two thresholds per storage place, no model call, no network.
 *
 *   warn      quality starts to go: "check it"
 *   critical  almost certainly spoiled: "probably gone"
 *
 * The report behind it: a wine open for 16 days in the fridge was shown with the generic
 * "Controlla" because the verdict came from the PRINTED date, which says nothing about
 * what happened after the seal was broken.
 *
 * Run: php scripts/test-opened-shelf-life.php
 */
define('CRON_MODE', true);
require_once __DIR__ . '/../api/bootstrap.php';

$root = dirname(__DIR__);
$json = (string)file_get_contents($root . '/data/opened_shelf_life.json');
$tbl  = json_decode($json, true);
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

// ── The table is DATA, and it is complete ───────────────────────────────────
check('the PAO table is valid JSON', is_array($tbl));
check('the table carries its own provenance note',
    isset($tbl['note']) && str_contains((string)$tbl['note'], 'FoodKeeper'));
$families = is_array($tbl) ? (array)($tbl['families'] ?? []) : [];
check('the table has the macro-families the audit listed', count($families) >= 25, (string)count($families));
$byId = [];
foreach ($families as $f) {
    $byId[(string)($f['id'] ?? '')] = $f;
}
check('no duplicate family id', count($byId) === count($families));
check('every family declares its keywords',
    array_reduce($families, static fn($ok, $f) => $ok && !empty($f['match']), true));
check('every family declares the three storage places with warn <= critical',
    array_reduce($families, static function ($ok, $f) {
        foreach (['frigo', 'dispensa', 'freezer'] as $loc) {
            $spec = $f[$loc] ?? null;
            if (!is_array($spec) || !isset($spec['warn'], $spec['critical'])) return false;
            if ((int)$spec['warn'] > (int)$spec['critical']) return false;
        }
        return $ok;
    }, true));

// The thresholds the audit asked for, verbatim.
$expect = [
    'wine'       => ['frigo' => [4, 10], 'dispensa' => [3, 7]],
    'milk_fresh' => ['frigo' => [3, 6]],
    'sauce'      => ['frigo' => [3, 7]],
    'dairy'      => ['frigo' => [4, 8]],
    'condiment'  => ['frigo' => [30, 60]],
    'biscuits'   => ['dispensa' => [20, 45]],
];
foreach ($expect as $id => $places) {
    foreach ($places as $loc => [$warn, $critical]) {
        $spec = $byId[$id][$loc] ?? [];
        check("{$id} in {$loc}: {$warn} / {$critical} days",
            (int)($spec['warn'] ?? -1) === $warn && (int)($spec['critical'] ?? -1) === $critical,
            json_encode($spec));
    }
}
check('the unknown category falls back to 5 / 10 in the fridge',
    (int)($tbl['defaults']['frigo']['warn'] ?? 0) === 5
    && (int)($tbl['defaults']['frigo']['critical'] ?? 0) === 10);

// ── The matcher: keywords first, category second, defaults last ─────────────
$cases = [
    ['Vino bianco Trebbiano igt Rubicone', 'bevande', 'frigo', 'wine'],
    ['Latte fresco intero', 'latticini', 'frigo', 'milk_fresh'],
    ['Latte Parmalat', 'latticini', 'dispensa', 'milk'],
    ['Latte di cocco', 'bevande', 'dispensa', 'plant_milk'],
    ['Yogurt Greco Milbona', 'latticini', 'frigo', 'dairy'],
    ['Mozzarella di bufala', 'latticini', 'frigo', 'dairy'],
    ['Parmigiano reggiano 24 mesi', 'latticini', 'frigo', 'cheese_hard'],
    ['Maionese Calvé', 'condimenti', 'frigo', 'condiment'],
    ['Passata di pomodoro', 'conserve', 'dispensa', 'sauce'],
    ['Tonno in scatola', 'pesce', 'dispensa', 'canned_fish'],
    ['Salmone fresco', 'pesce', 'frigo', 'fish'],
    ['Prosciutto crudo di Parma', 'carne', 'frigo', 'cured_meat'],
    ['Prosciutto cotto', 'carne', 'frigo', 'cooked_meat'],
    ['Biscotti integrali', 'snack', 'dispensa', 'biscuits'],
    ['Cracker integrali', 'snack', 'dispensa', 'biscuits'],
    ['Patatine fritte', 'snack', 'dispensa', 'biscuits'],
    // Two false positives found on real data: shelled nuts matched the "frutta" keyword of
    // fresh produce, breadcrumbs matched fresh bread (they last months, not days).
    ['Frutta secca noci sgusciate', 'snack', 'dispensa', 'nuts_dried'],
    ['Pane grattugiato', 'altro', 'dispensa', 'breadcrumbs'],
    ['Gnocco', 'pasta', 'freezer', 'fresh_pasta'],
    ['Pasta di semola', 'pasta', 'dispensa', 'dry'],
    ['Gnocchi freschi', 'pasta', 'frigo', 'fresh_pasta'],
    ['Olio extravergine', 'condimenti', 'dispensa', 'oil'],
    ['Sale fino', 'altro', 'dispensa', 'non_perishable'],
    ['Zucchero semolato', 'altro', 'dispensa', 'non_perishable'],
    ['Grappa', 'bevande', 'dispensa', 'spirits'],
];
foreach ($cases as [$name, $cat, $loc, $want]) {
    $got = openedShelfLifeFamily($name, $cat)['id'] ?? '';
    check("family of \"{$name}\" is {$want}", $got === $want, "got {$got}");
}
check('an unknown name falls back to the category',
    (openedShelfLifeFamily('Prodotto strano XYZ', 'latticini')['id'] ?? '') === 'dairy');
check('an unknown name and category falls back to `other`',
    (openedShelfLifeFamily('Prodotto strano XYZ', '')['id'] ?? '') === 'other');


// ── The state machine ──────────────────────────────────────────────────────
check('3 days with 4/10 is "ok"', openedShelfLifeState(3, 4, 10) === 'ok');
check('4 days with 4/10 is "warn" (the day the warning starts)', openedShelfLifeState(4, 4, 10) === 'warn');
check('9 days with 4/10 is still "warn"', openedShelfLifeState(9, 4, 10) === 'warn');
check('10 days with 4/10 is "critical"', openedShelfLifeState(10, 4, 10) === 'critical');
// The case from the audit: wine open for 16 days in the fridge.
$wine = openedShelfLifeThresholds('Vino bianco Trebbiano igt Rubicone', 'bevande', 'frigo');
check('the audit wine parses as frigo 4 / 10',
    $wine['warn'] === 4 && $wine['critical'] === 10 && $wine['family'] === 'wine', json_encode($wine));
check('the wine open 16 days is CRITICAL, not a generic "Controlla"',
    openedShelfLifeState(16, $wine['warn'], $wine['critical']) === 'critical');

// ── One summary per opened row (server side) ───────────────────────────────
$item = [
    'id' => 1,
    'name' => 'Vino bianco Trebbiano igt Rubicone',
    'category' => 'bevande',
    'location' => 'frigo',
    'opened_at' => date('Y-m-d', strtotime('-16 days')) . ' 20:00:00',
];
$pao = openedShelfLifeSummary($item);
check('the summary counts the days since opening', is_array($pao) && $pao['days_open'] === 16, json_encode($pao));
check('the summary carries the state and the score',
    is_array($pao) && $pao['state'] === 'critical' && abs(($pao['score'] ?? 0) - 1.6) < 0.01, json_encode($pao));
check('a sealed row has no post-opening clock', openedShelfLifeSummary(['name' => 'Latte']) === null);
check('a custom location is treated as the pantry',
    openedShelfLifeLocationKey('cantina') === 'dispensa' && openedShelfLifeLocationKey('frigo') === 'frigo');

// ── Wiring: the server ships it, the client acts on it ─────────────────────
$api = (string)file_get_contents($root . '/api/index.php');
check('getStats attaches the PAO clock to every opened row',
    str_contains($api, "\$item['pao'] = openedShelfLifeSummary(\$item);"));
check('a still-sealed split row carries no PAO',
    str_contains($api, "\$si['pao'] = null;"));
check('the client judges an opened row by the PAO clock',
    str_contains($app, 'function _openedPao(')
    && preg_match('/function shouldShowExpiredBanner\(item\) \{[\s\S]{0,400}_openedPao\(item\)/', $app) === 1);
check('an opened row outranks the rest of the queue',
    str_contains($app, "if (pao.state === 'critical') return 3000 + score;")
    && str_contains($app, "if (pao.state === 'warn') return 1500 + score;"));
check('the opened notice has its own layout with the two fast answers',
    str_contains($app, "type === 'opened'")
    && str_contains($app, 'banner-opened')
    && str_contains($app, 'onclick="bannerThrowAway()"')
    && str_contains($app, 'onclick="bannerUseInRecipe()"')
    && str_contains($app, 'id="alert-banner-menu"'));
check('the secondary actions stay one tap away, not hidden',
    str_contains($app, 'function _bannerMenuToggle(')
    && str_contains($app, 'onclick="bannerFinishAll()"')
    && str_contains($app, 'onclick="bannerStillGood()"'));
check('"è ancora buono" snoozes instead of deleting the warning',
    str_contains($app, 'function bannerStillGood(') && str_contains($app, '_openedSnooze(item)'));
check('the notice offers the other opened rows with a link to the tab',
    str_contains($app, 'function _openedQueueSiblings(')
    && str_contains($app, 'function _showOpenedTab(')
    && str_contains($app, "t('dashboard.banner_opened_more'"));

// ── The copy exists in all six locales ─────────────────────────────────────
$keys = ['banner_opened_since', 'banner_opened_limit', 'banner_opened_limit_none',
    'banner_opened_verdict_warn', 'banner_opened_verdict_critical',
    'banner_opened_action_recipe', 'banner_opened_action_still_good',
    'banner_opened_more', 'banner_opened_snoozed', 'banner_opened_more_actions'];
foreach (['it', 'en', 'de', 'fr', 'es', 'zh'] as $loc) {
    $doc = json_decode((string)file_get_contents($root . "/translations/{$loc}.json"), true);
    foreach ($keys as $key) {
        $val = $doc['dashboard'][$key] ?? '';
        check("translations/{$loc}.json has dashboard.{$key}", is_string($val) && trim($val) !== '');
    }
    // The placeholders the renderer fills must exist in the sentence.
    $since = (string)($doc['dashboard']['banner_opened_since'] ?? '');
    $more = (string)($doc['dashboard']['banner_opened_more'] ?? '');
    $limit = (string)($doc['dashboard']['banner_opened_limit'] ?? '');
    check("translations/{$loc}.json keeps the placeholders",
        str_contains($since, '{days}') && str_contains($since, '{location}')
        && str_contains($more, '{n}')
        && str_contains($limit, '{warn}') && str_contains($limit, '{critical}'));
}

echo "\n{$checks} checks, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);

