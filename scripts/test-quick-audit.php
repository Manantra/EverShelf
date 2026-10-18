<?php
/**
 * Guard test — quick audit mode (#285).
 *
 * The stocktake is client-only, so two halves again:
 *  - functional: the "almost out" quantity is computed by the very function the
 *    UI calls (extracted from assets/js/audit.js and run in node), per unit;
 *  - wiring: the entry point exists and can be switched off, each card reads a
 *    sentence with the quantity emphasised, the five decisions are real (confirm
 *    writes nothing, quantity/location open the normal edit sheet ABOVE the run,
 *    "esaurito" is the use-all action, "deperito" is the throw-all action), the
 *    writes are batched and a failed one is reported, and all six locales carry
 *    the strings and their placeholders.
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

check('a stocktake opens on a scope picker', strpos($audit, 'function _auditBegin(location, saved = null)') !== false);
check('one card at a time', strpos($audit, '_audit.items[_audit.index]') !== false);
check('the card reads a full sentence', strpos($audit, 'function _auditSentence(it)') !== false
    && strpos($audit, "t(brand ? 'audit.sentence_brand' : 'audit.sentence')") !== false);
check('the sentence makes the quantity the loudest fact',
    strpos($audit, 'class="audit-qty"') !== false);
check('the sentence carries the four facts',
    strpos($audit, "replace('{qty}'") !== false
    && strpos($audit, "replace('{name}'") !== false
    && strpos($audit, "replace('{brand}'") !== false
    && strpos($audit, "replace('{location}'") !== false);
check('the values are escaped before they reach the sentence',
    strpos($audit, 'escapeHtml(stripHtml(formatQuantity(') !== false
    && strpos($audit, 'escapeHtml(it.display_name || it.name') !== false);
check('the five decisions are on the card',
    strpos($audit, "onclick=\"_auditAnswer('there')\"") !== false
    && strpos($audit, "onclick=\"_auditEdit('quantity')\"") !== false
    && strpos($audit, "onclick=\"_auditEdit('location')\"") !== false
    && strpos($audit, "onclick=\"_auditAnswer('finished')\"") !== false
    && strpos($audit, "onclick=\"_auditAnswer('spoiled')\"") !== false);
check('"almost out" and "skip" stay as the quiet answers',
    strpos($audit, "onclick=\"_auditAnswer('almost')\"") !== false
    && strpos($audit, "onclick=\"_auditAnswer('skip')\"") !== false);
check('a confirm writes nothing', strpos($audit, "} else if (kind === 'there') {") !== false);
check('changing quantity opens the existing edit sheet',
    strpos($audit, 'editInventoryItem(it.id)') !== false);
check('the edit sheet can land on the quantity field', strpos($app, 'id="edit-qty-group"') !== false);
check('the edit sheet can land on the location field', strpos($app, 'id="edit-loc-group"') !== false);
check('a saved edit refreshes the card underneath',
    strpos($app, '_auditItemSaved(payload.id)') !== false
    && strpos($audit, 'function _auditItemSaved(id)') !== false);
check('the stocktake sheet hides the recipe shortcut (no dead-end behind the run)',
    strpos($audit, '#modal-content .btn-recipe-from-ingredient') !== false);
check('"exhausted" is the real use-all action',
    strpos($audit, "kind: 'use'") !== false
    && substr_count($audit, 'use_all: true') >= 2);
check('"spoiled" is the real throw-all action (waste reason)',
    strpos($audit, "kind: 'waste'") !== false
    && strpos($audit, "_wasteNotesForReason('spoiled')") !== false);
check('the answers are batched', strpos($audit, '_audit.queue.length >= _auditBatchSize') !== false);
check('a flush sends inventory_update with a quantity',
    strpos($audit, "api('inventory_update', {}, 'POST', { id: row.id, quantity: row.quantity })") !== false);
check('a flush sends inventory_use for exhausted/spoiled',
    substr_count($audit, "api('inventory_use', {}, 'POST', {") >= 2);
check('the backend accepts a quantity update',
    strpos($api, 'if (isset($input[\'quantity\'])) { $fields[] = "quantity = ?";') !== false);
check('the summary counts every answer', strpos($audit, 'audit.summary_line') !== false
    && strpos($audit, "replace('{spoiled}'") !== false);
check('an unsaved change is reported', strpos($audit, 'state.errors') !== false);
check('the keyboard never fires while the edit sheet is open',
    strpos($audit, "modal.style.display === 'flex'") !== false);

// ------------------------------------------------- paused run (one hour)
check('closing a run keeps the point reached',
    strpos($audit, "AUDIT_RESUME_KEY = 'evershelf_audit_resume'") !== false
    && strpos($audit, 'function _auditPersist()') !== false);
check('a run is resumable only within the hour',
    strpos($audit, 'AUDIT_RESUME_TTL_MS = 60 * 60 * 1000') !== false
    && strpos($audit, '> AUDIT_RESUME_TTL_MS') !== false);
check('the scope picker offers to resume it',
    strpos($audit, 'function _auditResume()') !== false
    && strpos($audit, 'onclick="_auditResume()"') !== false);
check('resuming skips everything already answered',
    strpos($audit, '!answered[String(id)]') !== false);
check('every answer is persisted at once', strpos($audit, '_audit.answered[String(it.id)] = kind;') !== false
    && substr_count($audit, '_auditPersist();') >= 3);
check('a completed run is not offered again', strpos($audit, '_auditClearPersist();') !== false);

// ------------------------------------------------------- scale from the card
check('the scale button is drawn only when the scale is connected',
    strpos($audit, 'function _auditScaleReady(it)') !== false
    && strpos($audit, 's.scale_enabled && s.scale_gateway_url && _scaleConnected') !== false
    && strpos($audit, 'onclick="_auditScale()"') !== false);
check('the scale button starts the real reading',
    strpos($audit, 'function _auditScale()') !== false
    && strpos($audit, 'readScaleForEdit()') !== false);
check('the scale is offered only for weighable rows',
    strpos($audit, 'function _auditWeighableUnit(it)') !== false);

// --------------------------------------------------------- undo last answer
check('the card offers a back button once something is answered',
    strpos($audit, 'class="audit-back"') !== false
    && strpos($audit, 'onclick="_auditUndo()"') !== false);
check('the summary can undo the last answer too',
    strpos($audit, 'class="audit-undo"') !== false
    && substr_count($audit, 'onclick="_auditUndo()"') >= 2);
check('a still-queued answer is dropped, not sent',
    strpos($audit, 'state.queue.indexOf(entry.row)') !== false
    && strpos($audit, 'state.queue.splice(queuedAt, 1)') !== false);
check('a sent quantity change is put back',
    strpos($audit, 'quantity: entry.previousQuantity') !== false);
check('a sent use-all/throw-all is undone on the server',
    strpos($audit, "api('transaction_undo'") !== false
    && strpos($audit, 'row.transactionId') !== false);
check('the run is only rewound when the revert worked',
    strpos($audit, "showToast(t('audit.undo_failed')") !== false);
check('the summary keeps the finished run for the undo',
    strpos($audit, 'state.finished = true') !== false);
check('Backspace is the keyboard shortcut for undo',
    strpos($audit, "ev.key === 'Backspace'") !== false);

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
$keys = ['start', 'title', 'scope_hint', 'scope_all', 'question', 'sentence', 'sentence_brand',
         'confirm_yes', 'change_qty', 'change_location', 'read_scale', 'resume', 'resume_hint',
         'undo_last', 'undo_done', 'undo_failed',
         'almost_out', 'exhausted', 'spoiled', 'skip', 'close', 'empty', 'summary_title',
         'summary_line', 'summary_errors', 'done'];
$placeholders = [
    'sentence'       => ['{qty}', '{name}', '{location}'],
    'sentence_brand' => ['{qty}', '{name}', '{brand}', '{location}'],
    'resume'         => ['{n}'],
    'summary_line'   => ['{there}', '{almost}', '{finished}', '{spoiled}', '{skipped}'],
];
$emoji = '/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}]/u';
foreach (['it', 'en', 'de', 'fr', 'es', 'zh'] as $lang) {
    $data = json_decode((string)file_get_contents($root . '/translations/' . $lang . '.json'), true);
    $missing = [];
    foreach ($keys as $key) {
        if (!isset($data['audit'][$key]) || !is_string($data['audit'][$key]) || $data['audit'][$key] === '') {
            $missing[] = 'audit.' . $key;
        }
    }
    foreach ($placeholders as $key => $tokens) {
        foreach ($tokens as $token) {
            if (strpos((string)($data['audit'][$key] ?? ''), $token) === false) {
                $missing[] = 'audit.' . $key . ' lacks ' . $token;
            }
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
    foreach (['confirm_yes', 'change_qty', 'change_location', 'read_scale', 'resume', 'undo_last', 'almost_out', 'exhausted', 'spoiled', 'skip', 'question'] as $key) {
        if (preg_match($emoji, (string)($data['audit'][$key] ?? ''))) {
            $withEmoji[] = $key;
        }
    }
    check("$lang keeps the icons out of the labels" . ($withEmoji ? ' (' . implode(', ', $withEmoji) . ')' : ''), !$withEmoji);
}

echo "\n{$checks} checks, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);

