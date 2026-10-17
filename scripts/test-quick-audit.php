<?php
/**
 * Guard test — quick audit mode (#285).
 *
 * The stocktake is client-only, so two halves again:
 *  - functional: the "almost out" quantity is computed by the very function the
 *    UI calls (extracted from assets/js/audit.js and run in node), per unit;
 *  - wiring: the entry point exists and can be switched off, the answers go to
 *    `inventory_update` (which does accept a quantity), the writes are batched,
 *    "there is" writes nothing, the summary counts are drawn, and all six
 *    locales carry the strings — with no emoji inside a translated label.
 *
 * Run: php scripts/test-quick-audit.php
 */

$root = dirname(__DIR__);
$fail = 0;
$checks = 0;

function check(string $label, bool $ok): void
{
    global $fail, $checks;
    $checks++;
    if ($ok) {
        echo "ok    $label\n";
    } else {
        $fail++;
        echo "FAIL  $label\n";
    }
}

$audit = (string)file_get_contents($root . '/assets/js/audit.js');
$app = (string)file_get_contents($root . '/assets/js/app.js');
$api = (string)file_get_contents($root . '/api/index.php');
$html = (string)file_get_contents($root . '/index.html');
$sw = (string)file_get_contents($root . '/sw.js');

// ------------------------------------------------------------- functional
preg_match('/function _auditAlmostOutQty\(item\) \{.*?\n\}/s', $audit, $m);
check('the almost-out rule is extractable', !empty($m[0]));
$result = [];
if (!empty($m[0])) {
    $tmp = sys_get_temp_dir() . '/audit_qty_' . getmypid() . '.js';
    file_put_contents($tmp, $m[0] . "\n" . <<<'JS'
const cases = [
    [{ unit: 'pz' }, 1],
    [{ unit: 'conf' }, 0.5],
    [{ unit: 'g' }, 100],
    [{ unit: 'ml' }, 100],
    [{ unit: 'kg' }, 0.1],
    [{ unit: 'l' }, 0.1],
    [{}, 1],
];
console.log(JSON.stringify(cases.map(([item, want]) => [_auditAlmostOutQty(item), want, _auditAlmostOutQty(item) === want])));
JS
    );
    $out = shell_exec('node ' . escapeshellarg($tmp) . ' 2>/dev/null');
    $result = json_decode(trim((string)$out), true) ?: [];
    @unlink($tmp);
}
check('the rule computes a value for every unit', count($result) === 7);
foreach ($result as $row) {
    check('almost out = ' . $row[0] . ' (expected ' . $row[1] . ')', $row[2] === true);
}

// ---------------------------------------------------------------- wiring
check('the pantry page has the entry point', strpos($html, 'id="inventory-stocktake-btn"') !== false);
check('the overlay exists', strpos($html, 'id="audit-overlay"') !== false);
check('the module is loaded', strpos($html, 'assets/js/audit.js') !== false);
check('the service worker precaches the module', strpos($sw, 'assets/js/audit.js') !== false);

check('a stocktake opens on a scope picker', strpos($audit, 'function _auditBegin(location)') !== false);
check('one card at a time', strpos($audit, '_audit.items[_audit.index]') !== false);
check('three answers', substr_count($audit, 'onclick="_auditAnswer(') === 3
    || (strpos($audit, "_auditAnswer('there')") !== false
        && strpos($audit, "_auditAnswer('almost')") !== false
        && strpos($audit, "_auditAnswer('finished')") !== false));
check('skip is the fourth, quiet action', strpos($audit, "_auditAnswer('skip')") !== false);
check('"there is" writes nothing', strpos($audit, "} else if (kind === 'there') {") !== false);
check('the answers are batched', strpos($audit, '_audit.queue.length >= _auditBatchSize') !== false);
check('a flush sends inventory_update with a quantity',
    strpos($audit, "api('inventory_update', {}, 'POST', { id: row.id, quantity: row.quantity })") !== false);
check('the backend accepts a quantity update',
    strpos($api, 'if (isset($input[\'quantity\'])) { $fields[] = "quantity = ?";') !== false);
check('the summary counts every answer', strpos($audit, 'audit.summary_line') !== false);
check('an unsaved change is reported', strpos($audit, 'state.errors') !== false);

check('the option is a setting', strpos($api, "'audit_mode_enabled'") !== false);
check('the batch size is a setting', strpos($api, "'audit_mode_batch_size'") !== false);
check('both live in .env', strpos($api, "'AUDIT_MODE_ENABLED'") !== false && strpos($api, "'AUDIT_MODE_BATCH_SIZE'") !== false);
check('the settings page has the toggle', strpos($html, 'id="setting-audit-mode-enabled"') !== false);
check('the settings page has the batch size', strpos($html, 'id="setting-audit-batch-size"') !== false);
check('the client reads the option', strpos($app, '_auditSetBatchSize') !== false);
check('the client sends the option', strpos($app, 'audit_mode_enabled:') !== false);
check('the .env example documents it',
    strpos((string)file_get_contents($root . '/.env.example'), 'AUDIT_MODE_ENABLED') !== false);

// ------------------------------------------------------------------ i18n
$keys = ['start', 'title', 'scope_hint', 'scope_all', 'question', 'there_is', 'almost_out',
         'finished', 'skip', 'close', 'empty', 'summary_title', 'summary_line',
         'summary_errors', 'done'];
$emoji = '/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}]/u';
foreach (['it', 'en', 'de', 'fr', 'es', 'zh'] as $lang) {
    $data = json_decode((string)file_get_contents($root . '/translations/' . $lang . '.json'), true);
    $missing = [];
    foreach ($keys as $key) {
        if (!isset($data['audit'][$key]) || !is_string($data['audit'][$key]) || $data['audit'][$key] === '') {
            $missing[] = 'audit.' . $key;
        }
    }
    $settings = $data['settings']['product_rules'] ?? [];
    foreach (['audit_label', 'audit_hint', 'audit_batch_label'] as $key) {
        if (!isset($settings[$key]) || $settings[$key] === '') {
            $missing[] = 'settings.product_rules.' . $key;
        }
    }
    check("$lang has every stocktake string" . ($missing ? ' (' . implode(', ', $missing) . ')' : ''), !$missing);

    // The icons are markup, never part of the label: an emoji in a translation is
    // the "label prints its own icon twice" bug scripts/test-i18n-icons.php exists for.
    $withEmoji = [];
    foreach (['there_is', 'almost_out', 'finished', 'skip', 'question'] as $key) {
        if (preg_match($emoji, (string)($data['audit'][$key] ?? ''))) {
            $withEmoji[] = $key;
        }
    }
    check("$lang keeps the icons out of the labels" . ($withEmoji ? ' (' . implode(', ', $withEmoji) . ')' : ''), !$withEmoji);
}

echo "\n{$checks} checks, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);

