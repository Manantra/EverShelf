#!/usr/bin/env php
<?php
/**
 * Guard test — a custom storage location can be renamed, and the pantry follows.
 *
 * The request: "in Settings, besides creating new storage locations, I must be able to
 * rename them". A custom location is not one thing but three, and they have to agree:
 *
 *   CUSTOM_LOCATIONS        the label the household typed (.env, or its DB fallback),
 *   inventory.location      the slug every stock row carries (`custom_armadietto_farmaci`),
 *   medical_locations       the label the medicine wizard stored.
 *
 * Renaming only the label (the easy client-side swap) empties the pantry tab and silently
 * un-marks the medicine cabinet, so the rename is ONE server action that moves stock, history
 * and the medicine marking, and only then writes the label. This test locks that contract,
 * the client wiring (pencil next to ✕, a real dialog, the action call) and the six locales.
 *
 * Run: php scripts/test-location-rename.php
 */

$root = dirname(__DIR__);
$api  = (string)file_get_contents($root . '/api/index.php');
$app  = (string)file_get_contents($root . '/assets/js/app.js');
$html = (string)file_get_contents($root . '/index.html');
$css  = (string)file_get_contents($root . '/assets/css/style.css');
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

/** Window of source that starts at $needle (empty when the anchor is gone). */
function slice_from(string $source, string $needle, int $len = 9000): string
{
    $pos = strpos($source, $needle);
    return $pos === false ? '' : substr($source, $pos, $len);
}

// ── The action exists, is dispatched, and is the one that moves the rows ────
check('the action is dispatched by the router',
    str_contains($api, "case 'location_rename':") && str_contains($api, 'locationRenameAction($db);'));
check('the handler is a real top-level function',
    (bool)preg_match('/\nfunction locationRenameAction\(PDO \$db\): void\n\{/', $api));

$body = slice_from($api, 'function locationRenameAction(PDO $db): void');
// Stop at the next top-level section so a match cannot come from elsewhere in the file.
$end = strpos($body, '// ===== GEMINI AI FUNCTIONS =====');
$body = $end === false ? $body : substr($body, 0, $end);
check('the handler body is extractable', strlen($body) > 1500, (string)strlen($body));

check('the stock moves with the new name',
    str_contains($body, 'UPDATE inventory SET location = ? WHERE location = ?'));
check('the history moves too (no orphaned transactions)',
    str_contains($body, 'UPDATE transactions SET location = ? WHERE location = ?'));
check('the label list is rewritten in place, never appended twice',
    str_contains($body, "\$updates = ['CUSTOM_LOCATIONS' => implode(',', \$labels)];")
    && str_contains($body, "array_search(\$oldLabel, \$labels, true)"));
check('the medicine marking follows the rename',
    str_contains($body, "'medical_locations'") && str_contains($body, 'strtolower($oldLabel)'));
check('a label-only change does not touch the rows',
    (bool)preg_match('/if \(\$newSlug !== \$oldSlug\) \{[\s\S]{0,400}?UPDATE inventory/s', $body));
check('a refused rename carries a translated key, never English prose',
    str_contains($body, "'error_key' => 'settings.custom_locations.rename_missing'")
    && str_contains($body, "'error_key' => 'settings.custom_locations.invalid'")
    && str_contains($body, "'error_key' => 'settings.custom_locations.rename_failed'")
    && str_contains($body, "'error_key' => 'error.already_exists'"));
check('the refusals cover reserved names, duplicates and the comma-separated list',
    str_contains($body, 'inventoryBuiltinLocations()')
    && str_contains($body, 'mb_strlen($new) > 40')
    && str_contains($body, "str_contains(\$new, ',')")
    && str_contains($body, 'isset($map[$newSlug])'));
check('stock and label move together (transaction, rollback on failure)',
    str_contains($body, '$db->beginTransaction()')
    && str_contains($body, '$db->rollBack()')
    && str_contains($body, '$db->commit()'));
check('the label is written to .env with the DB fallback, and read back',
    str_contains($body, 'evershelfWriteEnvFile(')
    && str_contains($body, 'saveEnvOverrides(')
    && str_contains($body, "env('CUSTOM_LOCATIONS') !== \$updates['CUSTOM_LOCATIONS']"));
check('the rename is logged with a stable event key',
    str_contains($body, "'event' => 'location_rename',")
    && str_contains($body, "'location_rename_failed'"));

// ── The client: a pencil next to the ✕, a real dialog, one API call ─────────
check('the custom-location row shows a rename control',
    str_contains($app, 'onclick="renameCustomLocation(${i})"')
    && str_contains($app, 'appliance-rename')
    && str_contains($css, '.appliance-item .appliance-rename'));
check('the rename is a dialog (not a prompt) with an input and a save button',
    str_contains($app, "ov.id = 'location-rename-overlay'")
    && str_contains($app, 'id="location-rename-input"')
    && str_contains($app, 'id="location-rename-save"'));
check('Esc closes the rename dialog through the shared overlay table',
    str_contains($app, "'location-rename-overlay': () => _closeLocationRename()"));
check('saving calls the server action instead of renaming locally',
    str_contains($app, "api('location_rename', {}, 'POST', { old: oldName, new: newName })"));
check('the storage card still explains what a custom location is',
    str_contains($html, 'data-i18n="settings.custom_locations.hint"'));
check('the client repaints the pickers and the live page after the rename',
    str_contains($app, 'mergeCustomLocations(s.custom_locations);')
    && str_contains($app, 'refreshCurrentPage();')
    && str_contains($app, '_medRenderLocationPicker(); _renderMedLocations();'));

// ── Creating a place persists it (the "Invalid location" bug) ───────────────
// The Settings card used to add the place to the browser only, until the page-wide "Salva":
// a product saved there before that answered "Invalid location", because the server builds
// its allow-list from CUSTOM_LOCATIONS. Add and remove now save first, then paint.
check('there is one writer for the custom-location list',
    (bool)preg_match('/async function _saveCustomLocations\(list\) \{[\s\S]{0,400}?api\(.save_settings., \{\}, .POST., \{ custom_locations: list \}\)/s', $app));
check('adding a place awaits the server before showing it',
    (bool)preg_match('/async function addCustomLocation\(\)[\s\S]{0,1200}?if \(!\(await _saveCustomLocations\(next\)\)\) return;/s', $app)
    && !preg_match('/function addCustomLocation\(\)[\s\S]{0,1200}?s\.custom_locations\.push\(name\)/s', $app));
check('removing a place awaits the server too',
    (bool)preg_match('/async function removeCustomLocation\(idx\)[\s\S]{0,1400}?!\(await _saveCustomLocations\(next\)\)/s', $app));
check('the medical wizard stores the place it creates the same way',
    (bool)preg_match('/async function onMedLocationCreate\(\)[\s\S]{0,1200}?!\(await _saveCustomLocations\(next\)\)/s', $app)
    && (bool)preg_match('/async function _medSetupFinish\(\)[\s\S]{0,900}?!\(await _saveCustomLocations\(next\)\)/s', $app));

// ── A place with stock is a place (orphan slugs adopted server-side) ────────
$apiSrc = (string)file_get_contents($root . '/api/index.php');
check('the server adopts a custom place whose label went missing',
    str_contains($apiSrc, 'function inventoryOrphanCustomLocations(): array')
    && (bool)preg_match('/function inventoryCustomLocationMap\(\): array \{[\s\S]{0,1200}?inventoryOrphanCustomLocations\(\)/s', $apiSrc)
    && (bool)preg_match("/SELECT DISTINCT location FROM inventory WHERE location LIKE 'custom\\\\\\\\_%'/", $apiSrc));
check('an adopted label is readable, not the raw slug',
    str_contains($apiSrc, 'function inventoryLabelFromSlug(string $slug): string'));

// The shipped helper, run for real: the label is rebuilt from the slug.
$labelFn = '';
if (preg_match('/function inventoryLabelFromSlug\(string \$slug\): string\s*\{.*?\n\}/s', $apiSrc, $m)) {
    $labelFn = $m[0];
}
check('the label helper is extractable', $labelFn !== '');
if ($labelFn !== '') {
    eval($labelFn);
    check('a lost label is rebuilt from the slug',
        inventoryLabelFromSlug('custom_dispensa_cucina') === 'Dispensa cucina'
        && inventoryLabelFromSlug('custom_mobile_soggiorno') === 'Mobile soggiorno');
}

// ── Six locales: the UI never prints a raw key ──────────────────────────────
$keys = ['rename', 'rename_title', 'rename_label', 'rename_hint', 'renamed', 'invalid',
         'rename_missing', 'rename_failed'];
$missing = [];
foreach (['it', 'en', 'de', 'fr', 'es', 'zh'] as $loc) {
    $doc = json_decode((string)file_get_contents($root . "/translations/{$loc}.json"), true);
    $block = (array)($doc['settings']['custom_locations'] ?? []);
    foreach ($keys as $key) {
        if (trim((string)($block[$key] ?? '')) === '') {
            $missing[] = "{$loc}: {$key}";
        }
    }
    // The "renamed" confirmation carries the new name.
    if (isset($block['renamed']) && !str_contains((string)$block['renamed'], '{name}')) {
        $missing[] = "{$loc}: renamed without {name}";
    }
}
check('all six locales carry the rename copy', $missing === [], implode(', ', array_slice($missing, 0, 6)));

echo "\n{$checks} checks, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
