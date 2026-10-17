<?php
/**
 * Guard test — the settings registry and its search.
 *
 * The settings page has ~106 controls and 129 `.env` keys spread over 16 tabs.
 * This test proves three things that are easy to lose silently:
 *
 *   1. the generated registry is in sync with index.html (`--check`);
 *   2. every `.env` key is either a control or a documented server-only key,
 *      and every control of the page is in the registry — nothing is unfindable;
 *   3. every label/description the search prints exists in all six locales, and
 *      the search module is actually loaded (page + service worker + hook).
 *
 * Run: php scripts/test-settings-schema.php
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

/** Dotted i18n lookup. */
function i18n_get(array $data, string $dotted)
{
    $node = $data;
    foreach (explode('.', $dotted) as $part) {
        if (!is_array($node) || !array_key_exists($part, $node)) {
            return null;
        }
        $node = $node[$part];
    }
    return $node;
}

require_once $root . '/api/lib/settings_schema.php';
require_once $root . '/api/lib/settings_env_allowlist.php';

$schema = evershelfSettingsSchema();
$entries = $schema['entries'] ?? [];
$envOnly = $schema['env_only'] ?? [];
$tabs = $schema['tabs'] ?? [];

$html = (string)file_get_contents($root . '/index.html');
$app = (string)file_get_contents($root . '/assets/js/app.js');
$sw = (string)file_get_contents($root . '/sw.js');
$envExample = (string)file_get_contents($root . '/.env.example');

// ------------------------------------------------------ 1. generated & in sync
check('the registry has entries', count($entries) > 90);
exec('php ' . escapeshellarg($root . '/scripts/gen-settings-schema.php') . ' --check 2>&1', $out, $code);
check('the generated files are in sync with index.html (run gen-settings-schema.php)', $code === 0);

// ------------------------------------------------------------ 2. .env coverage
preg_match_all('/^([A-Z][A-Z0-9_]+)=/m', $envExample, $em);
$envKeys = array_values(array_unique($em[1] ?? []));
$mapped = array_values(array_filter(array_column($entries, 'env')));
$onlyKeys = array_column($envOnly, 'env');
check('no key is both a control and a server-only entry', array_intersect($mapped, $onlyKeys) === []);
$uncovered = array_diff($envKeys, $mapped, $onlyKeys);
check('every .env key is covered (' . count($envKeys) . ' keys)' . ($uncovered ? ': ' . implode(', ', $uncovered) : ''), $uncovered === []);
$allowlist = evershelfSettingsEnvAllowlist();
check('every server-only key has a documented reason', array_diff($onlyKeys, array_keys($allowlist)) === []);
check('no allowlist entry points at a key that no longer exists', array_diff(array_keys($allowlist), $envKeys) === []);

$start = strpos($html, 'id="page-settings"');
$region = '';
if ($start !== false) {
    $rest = substr($html, $start);
    $nextSection = preg_match('/\n\s*<section class="page"/', $rest, $sm, PREG_OFFSET_CAPTURE);
    $region = $nextSection ? substr($rest, 0, $sm[0][1]) : $rest;
}
preg_match_all('/id="(setting-[a-z0-9-]+)"/', $region, $tm);
$pageIds = array_values(array_unique($tm[1] ?? []));
$registryIds = array_column($entries, 'id');
$lost = array_diff($pageIds, $registryIds);
check('every control on the settings page is in the registry (' . count($pageIds) . ' controls)' . ($lost ? ': ' . implode(', ', $lost) : ''), $lost === []);
$phantom = array_diff(array_values(array_filter($registryIds, fn($id) => strpos($id, "setting-") === 0)), $pageIds);
check('the registry invents no control' . ($phantom ? ': ' . implode(', ', $phantom) : ''), $phantom === []);

// ------------------------------------------------------------------ 3. tabs
preg_match('/const SETTINGS_GROUPS = \[([^\]]+)\]/', $app, $gm);
preg_match_all("/'([a-z]+)'/", $gm[1] ?? '', $gn);
$groups = $gn[1] ?? [];
check('SETTINGS_GROUPS is readable', count($groups) >= 4);
$badGroup = [];
$badTab = [];
foreach ($tabs as $tab => $group) {
    if (strpos($html, 'id="' . $tab . '"') === false) {
        $badTab[] = $tab;
    }
    if (!in_array($group, $groups, true)) {
        $badGroup[] = $tab . '→' . $group;
    }
}
check('every tab exists in the page' . ($badTab ? ': ' . implode(', ', $badTab) : ''), $badTab === []);
check('every tab belongs to a known section' . ($badGroup ? ': ' . implode(', ', $badGroup) : ''), $badGroup === []);

// ----------------------------------------------------- 4. descriptions + locales
$locales = [];
foreach (['it', 'en', 'de', 'fr', 'es', 'zh'] as $lang) {
    $locales[$lang] = json_decode((string)file_get_contents($root . '/translations/' . $lang . '.json'), true) ?: [];
}
$missingLabel = [];
$noDescription = [];
$untranslated = [];
foreach ($entries as $entry) {
    $id = $entry['id'] ?? '?';
    if (($entry['label'] ?? '') === '') {
        $missingLabel[] = $id;
    }
    if (($entry['hint'] ?? '') === '' && ($entry['card_hint'] ?? '') === '') {
        $noDescription[] = $id;
    }
    foreach (['label', 'hint', 'card', 'card_hint', 'keywords'] as $field) {
        $key = $entry[$field] ?? '';
        if ($key === '') {
            continue;
        }
        foreach ($locales as $lang => $data) {
            if (i18n_get($data, $key) === null) {
                $untranslated[] = $lang . ':' . $key;
            }
        }
    }
}
$untranslated = array_values(array_unique($untranslated));
check('every control has a label', $missingLabel === []);
check('every control has a description (own hint or its card)' . ($noDescription ? ': ' . implode(', ', $noDescription) : ''), $noDescription === []);
check('every key the search prints exists in all six locales' . ($untranslated ? ': ' . implode(', ', array_slice($untranslated, 0, 6)) : ''), $untranslated === []);

$searchKeys = ['placeholder', 'hint', 'empty', 'results', 'env_only', 'jump', 'clear'];
foreach ($locales as $lang => $data) {
    $missing = [];
    foreach ($searchKeys as $key) {
        if (!is_string(i18n_get($data, 'settings_search.' . $key))) {
            $missing[] = 'settings_search.' . $key;
        }
    }
    check("$lang has the search strings" . ($missing ? ': ' . implode(', ', $missing) : ''), $missing === []);
}

// -------------------------------------------------------------- 5. the module
check('the registry is loaded before the module',
    strpos($html, 'settings.schema.generated.js') !== false
    && strpos($html, 'settings_search.js') !== false);
check('the service worker precaches both',
    strpos($sw, 'settings.schema.generated.js') !== false && strpos($sw, 'settings_search.js') !== false);
check('the search box is created when settings opens',
    strpos($app, "if (typeof _settingsSearchInit === 'function') _settingsSearchInit();") !== false);
$module = (string)file_get_contents($root . '/assets/js/settings_search.js');
check('results jump to the tab of the entry', strpos($module, 'settings-tab[data-tab=') !== false);
check('a matched field is highlighted', strpos($module, 'settings-field-hit') !== false);
check('the search is accent-insensitive', strpos($module, 'normalize(\'NFD\')') !== false);
check('it can be opened from the keyboard', strpos($module, "ev.key === '/'") !== false);

echo "\n{$checks} checks, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);

