#!/usr/bin/env php
<?php
/**
 * Guard test — the stock hub (three lists, one segmented control).
 *
 * Regression history (UI audit, blocks 5-7): "in scadenza", "aperti" and
 * "dimenticati" were three stacked lists. The page height changed with the data,
 * the same product could appear in two lists, and finding "what should I eat"
 * meant scrolling past everything. They are three tabs of one block now, and
 * `_syncStockHub()` is the only place that decides what exists.
 *
 * Run: php scripts/test-stock-hub.php
 */

$root = dirname(__DIR__);
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

/** Pull a top-level function out of app.js by matching braces. */
function extract_fn(string $src, string $name): string
{
    $start = strpos($src, 'function ' . $name . '(');
    if ($start === false) {
        return '';
    }
    $open = strpos($src, '{', $start);
    if ($open === false) {
        return '';
    }
    $depth = 0;
    for ($i = $open, $len = strlen($src); $i < $len; $i++) {
        if ($src[$i] === '{') {
            $depth++;
        } elseif ($src[$i] === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($src, $start, $i - $start + 1);
            }
        }
    }
    return '';
}

// ------------------------------------------------------------------- markup
libxml_use_internal_errors(true);
$doc = new DOMDocument();
$doc->loadHTML('<?xml encoding="UTF-8">' . $html);
$xp = new DOMXPath($doc);
check('one stock hub exists', $xp->query("//*[@id='stock-hub']")->length === 1);
check('the hub starts hidden', $xp->query("//*[@id='stock-hub'][@hidden]")->length === 1);
foreach (['alert-expiring', 'alert-opened', 'alert-stale'] as $panel) {
    check("{$panel} is a panel of the hub",
        $xp->query("//*[@id='stock-hub']//*[@id='{$panel}'][@data-stock-panel]")->length === 1);
}
check('three tabs, one per list', $xp->query("//*[@id='stock-hub-tabs']//button[@data-stock]")->length === 3);
check('the tabs are a tablist', $xp->query("//*[@id='stock-hub-tabs'][@role='tablist']")->length === 1);
check('the rows keep their menu button (the six micro-buttons problem)',
    str_contains($app, 'class="alert-item-menu"')
    && str_contains($app, 'function _rowMenuButtons(item)'));

// ----------------------------------------------------------------- functions
$counts = extract_fn($app, '_stockHubCounts');
$sync = extract_fn($app, '_syncStockHub');
$select = extract_fn($app, '_stockHubSelect');
check('_stockHubCounts() is extractable', $counts !== '');
check('_syncStockHub() is extractable', $sync !== '');
check('_stockHubSelect() is extractable', $select !== '');
check('the dashboard repaints the hub after every list', substr_count($app, '_syncStockHub();') >= 4);
check('the async stale loader re-applies the tab selection (it used to escape the hub)',
    str_contains($app, "_bindAlertItemsHoldOpen(list);\n        // This loader is async and finishes after the tab selection: re-apply it, or"));

$harness = "let _stockHubTab = 'expiring';\n\n" . $counts . "\n\n" . $sync . "\n\n" . $select . <<<'JS'
const els = {};
function mk(id) {
    els[id] = { id, hidden: false, textContent: '', dataset: {}, classList: { toggle() {} }, setAttribute() {} };
    return els[id];
}
let COUNTS = { expiring: 0, opened: 0, stale: 0 };
let PANELS = [];
function panel(key, rows) {
    const el = { dataset: { stockPanel: key }, style: { display: 'none' }, querySelectorAll: () => ({ length: rows }) };
    PANELS.push(el);
    return el;
}
const document = {
    getElementById: (id) => els[id] || null,
    querySelector: (sel) => {
        const m = sel.match(/data-stock="(\w+)"/);
        return m ? (els['tab_' + m[1]] || null) : null;
    },
    querySelectorAll: (sel) => {
        if (sel === '#expiring-list .alert-item') return { length: COUNTS.expiring };
        if (sel === '#opened-list .alert-item') return { length: COUNTS.opened };
        if (sel === '#stale-list .alert-item') return { length: COUNTS.stale };
        if (sel === '#stock-hub .stock-tab') return [els.tab_expiring, els.tab_opened, els.tab_stale];
        if (sel === '#stock-hub [data-stock-panel]') return PANELS;
        return [];
    },
};

function scenario(counts) {
    for (const k of Object.keys(els)) delete els[k];
    PANELS = [];
    mk('stock-hub');
    mk('stock-tab-count-expiring'); mk('stock-tab-count-opened'); mk('stock-tab-count-stale');
    mk('tab_expiring'); mk('tab_opened'); mk('tab_stale');
    const panels = [panel('expiring', counts.expiring), panel('opened', counts.opened), panel('stale', counts.stale)];
    COUNTS = counts;
    _syncStockHub();
    return {
        hub_hidden: els['stock-hub'].hidden,
        tabs_hidden: ['expiring', 'opened', 'stale'].map(k => !!els['tab_' + k].hidden),
        counts: ['expiring', 'opened', 'stale'].map(k => String(els['stock-tab-count-' + k].textContent)),
        shown: panels.map(p => p.style.display !== 'none'),
    };
}

const out = {
    empty: scenario({ expiring: 0, opened: 0, stale: 0 }),
    only_opened: scenario({ expiring: 0, opened: 4, stale: 0 }),
    all: scenario({ expiring: 2, opened: 3, stale: 1 }),
};
console.log(JSON.stringify(out));
JS;

$tmp = sys_get_temp_dir() . '/stock_hub_' . getmypid() . '.js';
file_put_contents($tmp, $harness);
$raw = shell_exec('node ' . escapeshellarg($tmp) . ' 2>/dev/null');
@unlink($tmp);
$out = json_decode(trim((string)$raw), true);
check('the harness runs in node', is_array($out), (string)$raw);
if (is_array($out)) {
    check('an empty pantry hides the whole hub', ($out['empty']['hub_hidden'] ?? null) === true);
    check('only the filled tab is offered', ($out['only_opened']['tabs_hidden'] ?? []) === [true, false, true]);
    check('the counts are shown next to the tab names', ($out['all']['counts'] ?? []) === ['2', '3', '1']);
    check('exactly one panel is visible', count(array_filter($out['all']['shown'] ?? [], fn($v) => $v === true)) === 1);
    check('the first filled tab wins when the previous one is empty',
        ($out['only_opened']['shown'] ?? []) === [false, true, false]);
}

// --------------------------------------------------------------------- i18n
foreach (['dashboard.tab_expiring', 'dashboard.tab_opened', 'dashboard.tab_stale', 'dashboard.row_actions'] as $key) {
    foreach (['it', 'en', 'de', 'fr', 'es', 'zh'] as $loc) {
        $data = json_decode((string)file_get_contents($root . "/translations/{$loc}.json"), true);
        $node = $data;
        foreach (explode('.', $key) as $step) {
            $node = is_array($node) ? ($node[$step] ?? null) : null;
        }
        check("translations/{$loc}.json has {$key}", is_string($node) && trim($node) !== '');
    }
}

echo "\n{$checks} checks, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
