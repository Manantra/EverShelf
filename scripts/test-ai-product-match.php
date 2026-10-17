ATI#!/usr/bin/env php
<?php
/**
 * Regression tests: the product the AI reads must match the RIGHT catalog article.
 *
 * The report: "I scan tomatoes, the AI reads Pomodoro, but then it associates it with
 * Passata di pomodori instead of creating or reusing the product Pomodoro". The cause
 * was not the vision model:
 *
 *   api/index.php  collectDuplicateProductIds() merged any two barcode-less products
 *                  whose names merely shared a substring — "pomodoro" is contained in
 *                  "passata di pomodoro". The consolidator then kept the longer name
 *                  (productCanonicalScore(): more stock, more history) and DELETED the
 *                  row the user had just created. The comment above that block already
 *                  promised "when at least one row has a barcode": it was not enforced.
 *
 *   ai_product_suggest  ranked by a plain CASE (exact / starts-with / contains), so a
 *                  preparation that only contains the word could lead the list of
 *                  candidates the user taps.
 *
 *   app.js         the same "fresh produce vs preparation" guard written for the
 *                  auto-match was dead code: nothing filtered the candidate list.
 *
 * What must stay true:
 *   * one article, two spellings ("Pomodoro" ↔ "Pomodori") is the SAME row,
 *   * "Pomodoro" and "Passata di pomodoro" are two different articles, always,
 *   * the loose head-prefix rule ("Piadina" ⊂ "Piadina Romagnola") needs a barcode,
 *   * the relevance score puts the real match ahead of the preparation,
 *   * the client drops candidates of another nature before showing them.
 *
 * Run: php scripts/test-ai-product-match.php
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
        echo "FAIL: {$msg} (got " . var_export($actual, true) . ", expected " . var_export($expected, true) . ")\n";
        $fail++;
    } else {
        echo "OK: {$msg}\n";
    }
}

$root = __DIR__ . '/..';
$js   = (string)file_get_contents($root . '/assets/js/app.js');
$api  = (string)file_get_contents($root . '/api/index.php');

/** Run a slice of the app's own JS in node and return stdout ('' when node is missing). */
function js_eval(string $source, string $code): string
{
    static $node = null;
    if ($node === null) {
        $node = trim((string)shell_exec('command -v node 2>/dev/null'));
    }
    if ($node === '') {
        return '';
    }
    $file = tempnam(sys_get_temp_dir(), 'evershelf-') . '.js';
    file_put_contents($file, $source . "\n" . $code . "\n");
    $out = (string)shell_exec(escapeshellarg($node) . ' ' . escapeshellarg($file) . ' 2>/dev/null');
    @unlink($file);
    return trim($out);
}

/** Extract a whole top-level function from app.js (up to the next top-level function). */
function slice_function(string $source, string $signature): string
{
    $start = strpos($source, $signature);
    if ($start === false) {
        return '';
    }
    $end = strpos($source, "\nfunction ", $start + 1);
    return $end === false ? substr($source, $start) : substr($source, $start, $end - $start);
}

/** The vocabulary engine + data, so extracted JS functions run as they do in the app. */
function js_vocab_source(string $root): string
{
    return (string)file_get_contents($root . '/assets/js/vocab.generated.js') . "\n"
        . (string)file_get_contents($root . '/assets/js/core/vocab.js');
}

/** Window that starts at the first $needle (empty when the anchor is gone). */
function slice_from(string $source, string $needle, int $len = 4000): string
{
    $pos = strpos($source, $needle);
    return $pos === false ? '' : substr($source, $pos, $len);
}

// ── The same article, two spellings ─────────────────────────────────────────
// The number relation is the vocabulary's (curated forms + suffix pairs, no regex).
assert_true(vocabSameLemma('Pomodoro', 'Pomodori'), 'lemma: singular and plural are the same word');
assert_true(vocabSameLemma('Mele', 'Mela'), 'lemma: a plural and its singular meet');
assert_true(!vocabSameLemma('Pomodoro', 'Pomodorini'), 'lemma: a different word stays different');
assert_true(productNamesSameArticle('Pomodoro', 'Pomodori'), 'Pomodoro ↔ Pomodori is one article');
assert_true(productNamesSameArticle('Uovo', 'Uova'), 'Uovo ↔ Uova is one article');
assert_true(productNamesSameArticle(' pomodoro ', 'POMODORO'), 'whitespace/case never split an article');
assert_true(productNamesSameArticle('Biscotto', 'Biscotti'), 'Biscotto ↔ Biscotti is one article');

// ── Never the passata: two free-text titles that share a substring stay apart ─
assert_true(!productNamesSameArticle('Pomodoro', 'Passata di pomodoro'), 'Pomodoro is NOT Passata di pomodoro');
assert_true(!productNamesSameArticle('Passata di pomodoro', 'Sugo di pomodoro'), 'a passata is not a sugo');
assert_true(!productNamesSameArticle('Riso', 'Riso basmati'), 'no head-prefix match without a barcode');
assert_true(productNamesSameArticle('Riso', 'Riso basmati', true), 'with a barcode the head rule applies');
assert_true(productNamesSameArticle('Piadina', 'Piadina Romagnola', true), 'AI "Piadina" + OFF "Piadina Romagnola"');
assert_true(!productNamesSameArticle('Pomodoro', 'Passata di pomodoro', true), 'a contained word never merges, barcode or not');
assert_true(productNamesSameArticle('Tè', 'Te'), 'the accent is a spelling, not another word (folded)');

// A head-prefix match is a VARIETY, never a derived product: the curated dictionary
// (the same one the shopping list buys with) decides, for every product, not just produce.
assert_true(!productNamesSameArticle('Latte', 'Latte di mandorla', true), 'milk is not almond milk');
assert_true(!productNamesSameArticle('Farina', 'Farina di riso', true), 'flour is not rice flour');
assert_true(!productNamesSameArticle('Pasta', 'Pasta fresca', true), 'dry pasta is not fresh pasta');
assert_true(!productNamesSameArticle('Pomodoro', 'Pomodori pelati', true), 'a tomato is not a peeled tomato');
assert_true(productNamesSameArticle('Yogurt', 'Yogurt greco', true), 'a greek yogurt is the yogurt family');
assert_true(productNamesSameArticle('Cracker', 'Cracker integrali', true), 'a wholemeal cracker is a cracker');

// ── The consolidator (which DELETES a row) uses the safe rule ───────────────
$collect = slice_from($api, 'function collectDuplicateProductIds(PDO $db, array $row): array {', 3500);
assert_true($collect !== '', 'api: collectDuplicateProductIds() exists');
assert_true(str_contains($collect, 'productNamesSameArticle($nName, $otherName, $barcode !== null || $otherBarcode !== null)'),
    'api: the loose match is gated on a barcode, as the comment always promised');
assert_true(!str_contains($collect, '$overlap'), 'api: the raw substring rule is gone');
assert_true(str_contains($collect, "'event'    => 'product_merge_declined'"),
    'api: a declined merge is logged, so it stays greppable');

// ── Reuse, don't duplicate: the same article at another number ──────────────
$dupAnchor = 'function findDuplicateProductId(PDO $db, string $name, string $brand, ?string $barcode, ?int $excludeId = null): ?int {';
$dup = slice_from($api, $dupAnchor, 4200);
assert_true($dup !== '', 'api: findDuplicateProductId() exists');
assert_true(str_contains($dup, 'vocabTokens($name)[0]') && str_contains($dup, 'productNamesSameArticle($name'), 'api: the reuse path also looks for the same article at another number');
assert_true(str_contains($dup, "productNamesSameArticle(\$name, (string)(\$c['name'] ?? ''))"),
    'api: and re-checks the candidate with the same rule');
assert_true(str_contains($dup, 'isSoldByPieceProduct($name)'), 'api: the reuse guard knows loose produce');
assert_true(str_contains($dup, 'productRowClashesWithLooseProduce($name, $c)'),
    'api: a conserve row is not the loose vegetable that shares its name');
assert_true(str_contains($api, 'function productRowClashesWithLooseProduce(string $name, array $row): ?string'),
    'api: the loose-produce rule lives in one shared helper');
assert_true(str_contains($api, 'productRowClashesWithLooseProduce($seedName, $c)'),
    'api: the consolidator (which deletes a row) applies the same rule');
assert_true(str_contains($dup, "'event'    => 'product_reuse_declined'"),
    'api: a declined reuse is logged, so it stays greppable');
assert_true(isProduceCategory('verdura') && isProduceCategory('FRUTTA'), 'api: isProduceCategory() knows both produce categories');
assert_true(!isProduceCategory('conserve') && !isProduceCategory('') && !isProduceCategory('altro'),
    'api: and nothing else is produce');

// ── The candidate ranking puts the real match first ────────────────────────
assert_same(100, aiProductNameRelevance('Pomodoro', 'Pomodoro'), 'relevance: the same name scores 100');
assert_same(90, aiProductNameRelevance('Pomodoro', 'Pomodori'), 'relevance: another number scores 90');
assert_true(aiProductNameRelevance('Pomodoro', 'Passata di pomodoro') < 90,
    'relevance: a preparation that contains the word scores below a real match (' . aiProductNameRelevance('Pomodoro', 'Passata di pomodoro') . ')');
assert_true(aiProductNameRelevance('Pomodori pelati', 'Pomodori pelati') > aiProductNameRelevance('Pomodori pelati', 'Pomodori'),
    'relevance: the closer article wins inside the same family');

$ranked = aiProductSuggestRank([
    ['name' => 'Passata di pomodoro', 'total_qty' => 12],
    ['name' => 'Pomodori', 'total_qty' => 2],
    ['name' => 'Pomodoro ciliegino', 'total_qty' => 1],
], 'Pomodoro', 3, fn(array $r): float => (float)$r['total_qty']);
assert_same('Pomodori', $ranked[0]['name'] ?? '', 'ranking: the real match leads the list');
assert_same('Passata di pomodoro', $ranked[2]['name'] ?? '', 'ranking: the preparation sinks to the bottom');
assert_same(2, count(aiProductSuggestRank($ranked, 'Pomodoro', 2)), 'ranking: the list is cut back to the requested size');

$suggest = slice_from($api, 'function aiProductSuggest(PDO $db): void {', 7000);
assert_true(str_contains($suggest, 'aiProductSuggestRank($inStock, $q, $limit'), 'api: the in-stock list is ranked');
assert_true(str_contains($suggest, 'aiProductSuggestRank($finished, $q, $limit'), 'api: the finished list is ranked');
assert_true(str_contains($suggest, 'aiProductSuggestRank($catalog, $q, $limit)'), 'api: the catalog list is ranked');

// ── The client shows only candidates of the same nature ────────────────────
$fetch = slice_from($js, 'async function _fetchAiMatchCandidates(aiProduct) {', 1600);
assert_true(str_contains($fetch, '_aiMatchKeepSameNature(res.in_stock, q, aiProduct?.category || \'\')'), 'app.js: the in-stock candidates are filtered');
assert_true(str_contains($fetch, '_aiMatchKeepSameNature(res.finished, q, aiProduct?.category || \'\')'), 'app.js: the finished candidates are filtered');
assert_true(str_contains($fetch, '_aiMatchKeepSameNature(res.catalog, q, aiProduct?.category || \'\')'), 'app.js: the catalog candidates are filtered');

// The fresh/preparation rule now comes from the vocabulary, not from a regex in app.js:
// run the app's own engine in node and ask it about the names that produced the report.
$processedProbe = "\nconst names = " . json_encode(['Pomodoro','Pomodori','Pomodorini','Pomodoro ciliegino','Latte intero','Farina integrale','Passata di pomodoro','Pomodori pelati','Sugo di pomodoro','Pomodori secchi','Confettura di albicocche','Yogurt bianco']) . ";\nconsole.log(JSON.stringify(names.map(n => vocabLooksProcessed(n))));";
$processedFlags = json_decode(js_eval(js_vocab_source($root), $processedProbe), true);
if (!is_array($processedFlags)) {
    echo "SKIP: node is not available — the freshness probes were not run\n";
} else {
    foreach (['Pomodoro','Pomodori','Pomodorini','Pomodoro ciliegino','Latte intero','Farina integrale'] as $i => $fresh) {
        assert_same(false, $processedFlags[$i] ?? null, "fresh produce stays fresh: {$fresh}");
    }
    foreach (['Passata di pomodoro','Pomodori pelati','Sugo di pomodoro','Pomodori secchi','Confettura di albicocche','Yogurt bianco'] as $i => $processed) {
        assert_same(true, $processedFlags[$i + 6] ?? null, "a preparation is recognised as such: {$processed}");
    }
}

// ── The AI also says HOW the product is counted, and the app must take it ──
assert_same('pz', aiNormalizePantryUnit('pezzi'), 'php: "pezzi" becomes pz');
assert_same('pz', aiNormalizePantryUnit('pieces'), 'php: "pieces" becomes pz');
assert_same('conf', aiNormalizePantryUnit('confezione'), 'php: a jar/bag becomes conf');
assert_same('g', aiNormalizePantryUnit('GR'), 'php: units are matched case-insensitively');
assert_same('l', aiNormalizePantryUnit('litre'), 'php: a litre is l');
assert_same('', aiNormalizePantryUnit('cucchiai'), 'php: a unit the pantry cannot store is dropped');
assert_same('', aiNormalizePantryUnit(''), 'php: an empty unit stays empty');
assert_same(500.0, aiNormalizePantryQuantity('500'), 'php: a numeric string quantity is accepted');
assert_same(2.0, aiNormalizePantryQuantity(2), 'php: an int quantity is accepted');
assert_same(0.0, aiNormalizePantryQuantity(0), 'php: zero means "the AI said nothing"');
assert_same(0.0, aiNormalizePantryQuantity(-3), 'php: a negative quantity is refused');
assert_same(0.0, aiNormalizePantryQuantity('un paio'), 'php: prose is not a quantity');
assert_same(0.0, aiNormalizePantryQuantity(999999), 'php: an absurd quantity is refused');

$visionPrompt = slice_from($api, 'function geminiBarcodeVisual(): void {', 8000);
assert_true($visionPrompt !== '', 'api: geminiBarcodeVisual() exists');
assert_true(str_contains($visionPrompt, '\\"unit\\":\\"...\\"') && str_contains($visionPrompt, '\\"quantity\\":0'),
    'the visual prompt asks for the unit and the plausible quantity');
assert_true(str_contains($visionPrompt, "'unit'          => aiNormalizePantryUnit("),
    'and the answer is normalized before it reaches the client');

$identify = slice_from($api, 'function geminiIdentifyProduct(): void {', 4000);
assert_true(str_contains($identify, '"unit": "{$p[\'unit\']}"'), 'the identify prompt asks for the unit too');
assert_true(str_contains($identify, "\$identified['unit']         = aiNormalizePantryUnit("),
    'and normalizes it the same way');

$fragments = evershelfIdentifyPromptFragments('it');
assert_true(isset($fragments['unit'], $fragments['quantity'], $fragments['package_size']),
    'the localized prompt carries the unit/quantity/package_size fragments');

// ── The client takes the AI unit instead of a hardcoded "pz/1" ─────────────
assert_true(str_contains($js, 'const storage = _aiStorageFields({ ...p, name: aiName, category: localCategory });'),
    'app.js: the AI add saves the unit the model suggested');
assert_true(str_contains($js, 'const storage = _aiStorageFields({ ...draft, name: draftName, category: draftCategory });'),
    'app.js: the same fields are re-applied when the row is opened');
assert_true(str_contains($js, 'const storage = _aiStorageFields({ ...id, name: id.name, category: cat });'),
    'app.js: the legacy AI dialog saves them too');
assert_true(str_contains($js, 'unit: p.unit || \'\','), 'app.js: the draft keeps the unit read by the AI');

$unitSource = implode("\n", array_filter([
    slice_function($js, 'function _isSoldByPieceProductName(name, category = \'\') {'),
    slice_function($js, 'function _aiSuggestedUnit(unit, name, category = \'\') {'),
    slice_function($js, 'function _aiSuggestedQuantity(qty) {'),
    slice_function($js, 'function _aiStorageFields(draft) {'),
]));
$unitProbe = "\nconsole.log(JSON.stringify([" .
    "_aiStorageFields({unit:'pz',quantity:2,name:'Pomodoro',category:'verdura'})," .
    "_aiStorageFields({unit:'g',quantity:500,name:'Pomodoro',category:'verdura'})," .
    "_aiStorageFields({unit:'g',quantity:500,name:'Parmigiano',category:'latticini'})," .
    "_aiStorageFields({unit:'conf',quantity:1,package_size:'700 g',name:'Passata di pomodoro',category:'conserve'})," .
    "_aiStorageFields({unit:'ml',quantity:1000,name:'Latte fresco',category:'latticini'})," .
    "_aiStorageFields({unit:'',quantity:0,name:'Cosi',category:'altro'})," .
    "]));";
$units = json_decode(js_eval(js_vocab_source($root) . "\n" . $unitSource, $unitProbe), true);
if (!is_array($units)) {
    echo "SKIP: node is not available — the JS unit probes were not run\n";
} else {
    assert_same(['unit' => 'pz', 'default_quantity' => 2, 'package_unit' => ''], $units[0],
        'js: two tomatoes are pieces, 2');
    assert_same(['unit' => 'pz', 'default_quantity' => 1, 'package_unit' => ''], $units[1],
        'js: a tomato read as 500 g still lands on pieces (produce is never weighed)');
    assert_same(['unit' => 'g', 'default_quantity' => 500, 'package_unit' => ''], $units[2],
        'js: cut cheese keeps the grams the AI read');
    assert_same(['unit' => 'conf', 'default_quantity' => 700, 'package_unit' => 'g'], $units[3],
        'js: a jar keeps its pack size (700 g) for the conf bookkeeping');
    assert_same(['unit' => 'ml', 'default_quantity' => 1000, 'package_unit' => ''], $units[4],
        'js: milk keeps the millilitres');
    assert_same(['unit' => 'pz', 'default_quantity' => 1, 'package_unit' => ''], $units[5],
        'js: nothing usable falls back to the old default');
}

if ($fail === 0) {
    echo "\nAll AI product-match guard tests passed.\n";
}
exit($fail === 0 ? 0 : 1);
