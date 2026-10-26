#!/usr/bin/env php
<?php
/**
 * Guard tests: the vocabulary is DATA, and the code stays language-neutral.
 *
 * The rule this file protects: no product name, genre, brand or plural rule may live in
 * the code — it lives in api/vocab/<lang>.json, is read by api/lib/vocabulary.php and
 * mirrored to the browser through the generated assets/js/vocab.generated.js. Adding a
 * language is adding a JSON file; adding a word must never mean editing a function.
 *
 * Locked here:
 *   1. the JSON is valid and complete (the keys the engine reads exist),
 *   2. the generated JS is byte-identical to a fresh regeneration,
 *   3. NO vocabulary word appears in the logic files (comments stripped), so a product
 *      name re-introduced in api/index.php or app.js fails the build.
 *
 * Run: php scripts/test-vocabulary.php
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

$root  = __DIR__ . '/..';
$files = glob($root . '/api/vocab/*.json') ?: [];
assert_true(count($files) >= 1, 'api/vocab/ holds at least one language');
$required = ['lang', 'fold', 'word_separators', 'plural_pairs', 'pack_size_units', 'stop_words',
             'number_forms', 'agreement_forms', 'phrase_map', 'keyword_map', 'categories',
             'brands', 'piece_produce', 'produce_preparations', 'processed', 'prepared_salad',
             'classic_piece_defaults', 'locale', 'capture'];
$vocab = [];
foreach ($files as $file) {
    $lang = basename($file, '.json');
    $data = json_decode((string)file_get_contents($file), true);
    assert_true(is_array($data), "api/vocab/{$lang}.json is valid JSON");
    $vocab[$lang] = $data;
    foreach ($required as $key) {
        assert_true(array_key_exists($key, (array)$data), "api/vocab/{$lang}.json carries '{$key}'");
    }
    assert_true(($data['lang'] ?? '') === $lang, "api/vocab/{$lang}.json declares its own language");
    foreach ((array)($data['categories'] ?? []) as $rule) {
        assert_true(in_array((string)($rule['cat'] ?? ''), validProductCategories(), true),
            "category '{$rule['cat']}' is one of the app's categories");
        assert_true(!empty($rule['terms']), "category '{$rule['cat']}' has terms");
    }
}

$expected  = json_encode($vocab, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$generated = (string)file_get_contents($root . '/assets/js/vocab.generated.js');

/** Strip block/inline comments so documentation may still show examples. */
function strip_comments(string $code): string
{
    $code = preg_replace('#/\*.*?\*/#s', ' ', $code) ?? $code;
    $code = preg_replace('#<!--.*?-->#s', ' ', $code) ?? $code;
    $code = preg_replace('#(^|\s)//[^\n]*#', '$1', $code) ?? $code;
    return $code;
}

/** Every word the vocabulary teaches, as a list of scan terms. */
function vocabulary_words(array $data): array
{
    $words = [];
    foreach (['piece_produce', 'produce_preparations', 'processed', 'classic_piece_defaults'] as $key) {
        foreach ((array)($data[$key] ?? []) as $term) {
            $words[] = rtrim((string)$term, '*');
        }
    }
    foreach (['categories', 'brands'] as $group) {
        foreach ((array)($data[$group] ?? []) as $rule) {
            foreach ((array)($rule['terms'] ?? []) as $term) {
                foreach (explode('+', rtrim((string)$term, '*')) as $part) {
                    $words[] = trim($part);
                }
            }
        }
    }
    foreach ((array)($data['prepared_salad']['phrases'] ?? []) as $phrase) {
        $words[] = implode(' ', (array)$phrase);
    }
    foreach ((array)($data['prepared_salad']['brands'] ?? []) as $brand) {
        $words[] = (string)$brand;
    }
    return array_values(array_unique(array_filter($words, static fn($w) => mb_strlen($w) >= 3)));
}

$logicFiles = [
    'api/index.php', 'api/database.php', 'api/lib/product_kind.php', 'api/lib/vocabulary.php',
    'assets/js/app.js', 'assets/js/core/vocab.js',
];
// RATCHET: how many vocabulary words each file is still allowed to carry.
//
// The migrated batches (the two name→category guessers, produce/preparations/brands,
// the plural/singular tables, the shopping dictionaries, the recipe weak tokens, the
// trailing-genre and ingredient-head lists) are at 0 and MUST stay at 0. The leftovers
// are the expiry heuristics (api/database.php, assets/js/app.js) and the recipe
// ingredient groups / prompt prose: their baseline may only go DOWN — migrate a batch,
// lower its number. A new product name in any of these files fails the build.
$baseline = [
    'api/index.php'               => 177,
    'api/database.php'            => 164,
    'api/lib/product_kind.php'    => 0,
    'api/lib/vocabulary.php'      => 0,
    'assets/js/app.js'            => 226,
    'assets/js/core/vocab.js'     => 0,
];
// The scan is on the language the CODE is written in: a translated vocabulary file is
// data for that language and may legitimately share a word with an identifier.
$scan = [];
foreach (vocabulary_words((array)($vocab['it'] ?? [])) as $word) {
    $scan[mb_strtolower($word)] = true;
}
foreach ($logicFiles as $rel) {
    $code = strip_comments((string)file_get_contents($root . '/' . $rel));
    $hits = [];
    foreach (array_keys($scan) as $word) {
        if (preg_match('/(?<![\p{L}])' . preg_quote($word, '/') . '(?![\p{L}])/iu', $code) === 1) {
            $hits[] = $word;
        }
    }
    $allowed = (int)($baseline[$rel] ?? 0);
    assert_true(count($hits) <= $allowed,
        $rel . ": " . count($hits) . " vocabulary word(s), baseline {$allowed} (no new ones — migrate, don't add)");
}


// ── The engine answers from the data (no code knowledge) ───────────────────
$it  = (array)($vocab['it'] ?? []);
$any = (array)($it['categories'][0] ?? []);
if (!empty($any['terms'][0])) {
    assert_true(vocabCategoryOfName((string)$any['terms'][0]) === (string)$any['cat'],
        "the vocabulary answers '{$any['cat']}' for '{$any['terms'][0]}' from data");
}
assert_true(vocabCategoryOfName('zzz prodotto inesistente zzz') === 'altro',
    'an unknown name stays altro (the data never invents a category)');
$path = evershelfVocabPath('xx');
assert_true($path === evershelfVocabPath('it') || $path === evershelfVocabPath('en'),
    'an unknown locale falls back to a shipped vocabulary');

// ── The browser engine really exposes every function it promises ───────────
// A lost closing brace in assets/js/core/vocab.js leaves the rest of the file
// nested inside the previous function: the file still parses (so `node --check`
// and the browser are happy) but every later function is `undefined` at runtime,
// and the app calls into the void. Count them from the source and ask node whether
// each one is defined once the whole file has been evaluated.
$vocabJs = (string)file_get_contents(__DIR__ . '/../assets/js/core/vocab.js');
preg_match_all('/^function ([A-Za-z0-9_]+)\(/m', $vocabJs, $mjs);
$jsFunctions = $mjs[1] ?? [];
assert_true(count($jsFunctions) >= 10, 'core/vocab.js declares its engine functions at top level');
$node = trim((string)shell_exec('command -v node 2>/dev/null'));
if ($node === '') {
    echo "SKIP: node is not available — the browser engine probe was not run\n";
} else {
    $file = tempnam(sys_get_temp_dir(), 'evershelf-') . '.js';
    file_put_contents($file, "const fs = require('fs');\n"
        . "\$src = fs.readFileSync(" . json_encode(__DIR__ . '/../assets/js/core/vocab.js') . ", 'utf8');\n"
        . "\$missing = " . json_encode($jsFunctions) . ".filter(n => (new Function(\$src + '\\n; return typeof ' + n + ';'))() !== 'function');\n"
        . "console.log(JSON.stringify(\$missing));\n");
    $missing = json_decode((string)shell_exec(escapeshellarg($node) . ' ' . escapeshellarg($file) . ' 2>/dev/null'), true);
    @unlink($file);
    if (!is_array($missing)) {
        echo "SKIP: the browser engine probe did not answer\n";
    } else {
        assert_true($missing === [],
            'core/vocab.js: every top-level function is defined when the file is evaluated'
            . ($missing === [] ? '' : ' (nested/unreachable: ' . implode(', ', $missing) . ')'));
    }
}

// ── The generated files are in sync with their data ────────────────────────
assert_true(str_contains($generated, $expected),
    'assets/js/vocab.generated.js is in sync with api/vocab/*.json (run php scripts/gen-vocab-js.php)');
assert_true(str_contains($generated, 'GENERATED FILE'), 'the generated file says it is generated');
assert_true(!str_contains($generated, 'function '), 'the generated file carries data only, no logic');

// ── The offline screensaver facts are TEXT: data → generated JS ────────────
$factsData = json_decode((string)file_get_contents($root . '/data/food_facts_fallback.json'), true);
$facts = [];
foreach ((array)$factsData as $code => $list) {
    if (preg_match('/^[a-z]{2}$/', (string)$code) !== 1 || !is_array($list)) {
        continue;
    }
    $sentences = array_values(array_filter(array_map('strval', $list), static fn($s) => trim($s) !== ''));
    if ($sentences) {
        $facts[$code] = $sentences;
    }
}
assert_true(count($facts) >= 1, 'data/food_facts_fallback.json carries the offline facts');
$factsGenerated = (string)file_get_contents($root . '/assets/js/food_facts.generated.js');
assert_true(str_contains($factsGenerated, json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
    'assets/js/food_facts.generated.js is in sync with data/food_facts_fallback.json (run php scripts/gen-food-facts-js.php)');

// ── No language name and no sentence of prose inside app.js ────────────────
$appCode = strip_comments((string)file_get_contents($root . '/assets/js/app.js'));
foreach ($vocab as $code => $data) {
    $name = trim((string)($data['locale']['name'] ?? ''));
    assert_true($name === '' || !str_contains($appCode, "'" . $name . "'"),
        "app.js does not hardcode the name of '{$code}' ({$name}) — it comes from the data");
}
assert_true(!str_contains($appCode, 'ogni italiano spreca') && !str_contains($appCode, 'Lebensmittelverschwendung'),
    'app.js carries no anti-waste sentence (they live in data/food_facts_fallback.json)');

if ($fail === 0) {
    echo "\nAll vocabulary guard tests passed.\n";
}
exit($fail === 0 ? 0 : 1);
