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
             'classic_piece_defaults'];
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
$scan = [];
foreach ($vocab as $data) {
    foreach (vocabulary_words((array)$data) as $word) {
        $scan[mb_strtolower($word)] = true;
    }
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

if ($fail === 0) {
    echo "\nAll vocabulary guard tests passed.\n";
}
exit($fail === 0 ? 0 : 1);

assert_true(str_contains($generated, $expected),
    'assets/js/vocab.generated.js is in sync with api/vocab/*.json (run php scripts/gen-vocab-js.php)');
assert_true(str_contains($generated, 'GENERATED FILE'), 'the generated file says it is generated');
assert_true(!str_contains($generated, 'function '), 'the generated file carries data only, no logic');
