#!/usr/bin/env php
<?php
/**
 * Guard test — the JavaScript files stay structurally sane.
 *
 * A single lost closing brace does not break the parser: it silently *nests* the functions
 * below it inside the one above, and the file still loads. That is how `_openedQueueSiblings`
 * disappeared: `dismissBannerItemAfterAction()` was missing its `}`, so five functions ended
 * up inside it and the opened banner threw "is not defined" on every dashboard paint —
 * while `node -c` and every other check stayed green.
 *
 * What this locks:
 *   1. No top-level `function` declaration may be swallowed by the body of another one
 *      (brace matching, per file).
 *   2. Every inline handler the markup calls (`onclick="fn(`, `onchange="fn(`, …) must be
 *      declared at column 0, or be a browser/`window` API we do not own.
 *
 * Run: php scripts/test-js-structure.php
 */
$root = dirname(__DIR__);
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

$files = array_merge(
    [$root . '/sw.js'],
    glob($root . '/assets/js/*.js') ?: [],
    glob($root . '/assets/js/core/*.js') ?: []
);
// Generated data files have no logic (and no declarations to nest).
$files = array_values(array_filter($files, fn($f) => !str_contains($f, 'generated')));

/** Column-0 function names of a file, with the line range each one owns. */
function topLevelFunctions(string $source): array
{
    $lines = preg_split('/\R/', $source) ?: [];
    $starts = [];
    foreach ($lines as $i => $line) {
        if (preg_match('/^(?:async\s+)?function\s+([A-Za-z_$][\w$]*)\s*\(/', $line, $m)) {
            $starts[] = ['line' => $i, 'name' => $m[1]];
        }
    }
    $out = [];
    foreach ($starts as $idx => $start) {
        // Body opens at the first '{' at or after the declaration line.
        $open = $start['line'];
        while ($open < count($lines) && !str_contains($lines[$open], '{')) {
            $open++;
            if ($open > $start['line'] + 8) { break; }
        }
        $depth = 0;
        $end = null;
        for ($i = $open; $i < count($lines); $i++) {
            $depth += substr_count($lines[$i], '{') - substr_count($lines[$i], '}');
            if ($depth <= 0) { $end = $i; break; }
        }
        $out[] = ['name' => $start['name'], 'line' => $start['line'] + 1, 'end' => $end];
    }
    return $out;
}

$nested = [];
$measured = 0;
foreach ($files as $file) {
    $src = (string)file_get_contents($file);
    $fns = topLevelFunctions($src);
    $measured += count($fns);
    foreach ($fns as $fn) {
        if ($fn['end'] === null) {
            $nested[] = basename($file) . ': ' . $fn['name'] . ' (body never closes)';
            continue;
        }
        foreach ($fns as $other) {
            if ($other['line'] > $fn['line'] && $other['line'] <= $fn['end'] + 1 && $other !== $fn) {
                $nested[] = basename($file) . ': ' . $other['name'] . ' is inside ' . $fn['name'];
                break;
            }
        }
    }
}
check('no top-level function is swallowed by a missing brace (' . $measured . ' functions scanned)', $nested === [], implode(' | ', array_slice($nested, 0, 5)));

// Every inline handler in the markup must be a real, top-level function.
$app = (string)file_get_contents($root . '/assets/js/app.js');
$html = (string)file_get_contents($root . '/index.html');
$declared = [];
foreach ($files as $file) {
    $src = (string)file_get_contents($file);
    foreach (topLevelFunctions($src) as $fn) {
        $declared[$fn['name']] = true;
    }
    // A handler can also be a top-level binding (const x = debounce(...)) or a window.global.
    if (preg_match_all('/^(?:const|let|var)\s+([A-Za-z_$][\w$]*)\s*=/m', $src, $bm)) {
        foreach ($bm[1] as $name) { $declared[$name] = true; }
    }
    if (preg_match_all('/^window\.([A-Za-z_$][\w$]*)\s*=/m', $src, $wm)) {
        foreach ($wm[1] as $name) { $declared[$name] = true; }
    }
}
// A handler may also be an inline expression: skip keywords and browser APIs we do not own.
$browserApi = ['event', 'this', 'window', 'document', 'alert', 'history', 'location', 'open', 'close',
    'print', 'focus', 'blur', 'if', 'return', 'try', 'setTimeout', 'stopPropagation', 'preventDefault'];
$scripts = [$html, $app];
foreach ($files as $file) { $scripts[] = (string)file_get_contents($file); }
$missing = [];
foreach ($scripts as $src) {
    if (!preg_match_all('/\bon(?:click|change|input|submit|keydown|keyup|blur|focus)="\s*([A-Za-z_$][\w$]*)\s*\(/', $src, $m)) {
        continue;
    }
    foreach (array_unique($m[1]) as $handler) {
        if (in_array($handler, $browserApi, true) || isset($declared[$handler])) {
            continue;
        }
        $missing[$handler] = true;
    }
}
check('every inline handler resolves to a top-level function', $missing === [], implode(', ', array_keys($missing)));

// The regression that started this guard: the opened banner called a function that a missing
// brace had swallowed. Lock the name at column 0 by name, so it cannot happen again.
check('the opened-banner helpers are top-level again',
    (bool)preg_match('/^function _openedQueueSiblings\(/m', $app)
    && (bool)preg_match('/^function _showOpenedTab\(/m', $app)
    && (bool)preg_match('/^function bannerUseInRecipe\(/m', $app));

echo "\n" . ($fail === 0 ? 'PASS' : 'FAIL') . " — {$checks} checks, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
