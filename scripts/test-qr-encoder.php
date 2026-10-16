#!/usr/bin/env php
<?php
/**
 * Regression tests for api/lib/qr.php — the offline QR encoder.
 *
 * Regression history: the first version of the encoder placed the 15 format bits
 * in the reverse order, so every rendered symbol was unreadable (a scanner reads a
 * garbage EC level / mask) while the matrix still "looked" like a QR Code. Two
 * further bugs hid behind it: the Reed-Solomon generator polynomial was built from
 * the GF *log* table instead of the exp table (the ECC came out as "... 00 00"),
 * and only a real decoder could tell.
 *
 * What this file locks down:
 *   * every frozen vector (scripts/fixtures/qr-vectors.json) rebuilds exactly.
 *     The forced-mask vectors are byte-identical to python-qrcode 8.x; the
 *     auto-mask ones are the ISO evaluation over the *complete* symbol (libraries
 *     that score without the format information sometimes pick another of the
 *     eight masks — all of them decode, so they are a regression lock, not a
 *     cross-check);
 *   * both copies of the format information decode back to the EC level + mask
 *     asked for, and the version information blocks match the table;
 *   * the ISO constants the tables are indexed by (M0 = 21522, L0 = 30660,
 *     Q0 = 13663, H0 = 5769, version 7 = 31892);
 *   * the renderers produce an SVG and a PNG, and an oversized payload is refused
 *     instead of silently truncated.
 *
 * Run: php scripts/test-qr-encoder.php
 */
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
        echo 'FAIL: ' . $msg . ' (got ' . var_export($actual, true)
            . ', expected ' . var_export($expected, true) . ")\n";
        $fail++;
    } else {
        echo "OK: {$msg}\n";
    }
}

$root = __DIR__ . '/..';
require_once $root . '/api/lib/qr.php';

// ── 1. The frozen vectors ────────────────────────────────────────────────────
$fixturePath = __DIR__ . '/fixtures/qr-vectors.json';
assert_true(is_file($fixturePath), 'scripts/fixtures/qr-vectors.json exists');
$fixture = json_decode((string)file_get_contents($fixturePath), true) ?: [];
$vectors = $fixture['vectors'] ?? [];
assert_true(count($vectors) >= 12, 'the fixture carries at least 12 vectors');

$mismatches = [];
$levelsSeen = [];
foreach ($vectors as $vector) {
    $matrix = evershelfQrMatrix($vector['text'], $vector['ecc'], $vector['version'], $vector['mask']);
    $rows = [];
    foreach ($matrix as $row) {
        $rows[] = implode('', $row);
    }
    $levelsSeen[$vector['ecc']] = true;
    if ($rows !== $vector['rows']) {
        $mismatches[] = $vector['ecc'] . ' v' . $vector['expected_version']
            . ' mask ' . var_export($vector['mask'], true) . ' ' . substr($vector['text'], 0, 24);
        continue;
    }
    if ((count($rows) - 17) / 4 !== $vector['expected_version']) {
        $mismatches[] = 'version drift for ' . substr($vector['text'], 0, 24);
    }
}
assert_same([], $mismatches, 'every frozen vector rebuilds exactly');
assert_true(count($levelsSeen) === 4, 'the fixture covers all four EC levels');

// ── 2. Structure of a symbol ─────────────────────────────────────────────────
$matrix = evershelfQrMatrix('HELLO', 'M', 1, 0);
$size = count($matrix);
assert_same(21, $size, 'version 1 is 21 modules wide');
assert_same(1, $matrix[$size - 8][8], 'the dark module is set');

// Finder patterns: the 7x7 rings in three corners and the light separator rings.
$finderOk = true;
foreach ([[0, 0], [0, $size - 7], [$size - 7, 0]] as [$row, $col]) {
    for ($dr = 0; $dr < 7; $dr++) {
        for ($dc = 0; $dc < 7; $dc++) {
            $expected = ($dr === 0 || $dr === 6 || $dc === 0 || $dc === 6
                || ($dr >= 2 && $dr <= 4 && $dc >= 2 && $dc <= 4)) ? 1 : 0;
            if ($matrix[$row + $dr][$col + $dc] !== $expected) {
                $finderOk = false;
            }
        }
    }
}
assert_true($finderOk, 'the three finder patterns are drawn correctly');

// Timing patterns: dark on the even indexes, with the separators kept light.
assert_same(1, $matrix[6][8], 'the horizontal timing pattern starts dark at column 8');
assert_same(0, $matrix[6][9], 'the horizontal timing pattern alternates');
assert_same(1, $matrix[8][6], 'the vertical timing pattern starts dark at row 8');

// ── 3. Format information ────────────────────────────────────────────────────
/**
 * Read one copy of the format information back the way a scanner does, then look
 * it up in the table: this is what the bit order bug broke.
 */
function readFormatCopy(array $matrix, bool $vertical): int
{
    $size = count($matrix);
    $bits = 0;
    for ($i = 0; $i < 15; $i++) {
        if ($vertical) {
            $value = $i < 6 ? $matrix[$i][8] : ($i < 8 ? $matrix[$i + 1][8] : $matrix[$size - 15 + $i][8]);
        } else {
            $value = $i < 8 ? $matrix[8][$size - 1 - $i] : ($i < 9 ? $matrix[8][15 - $i] : $matrix[8][14 - $i]);
        }
        if ($value === 1) {
            $bits |= 1 << $i;
        }
    }
    return $bits;
}

$formatFailures = [];
foreach (['L', 'M', 'Q', 'H'] as $level) {
    foreach ([0, 3, 7] as $mask) {
        $symbol = evershelfQrMatrix('HELLO', $level, 2, $mask);
        $expected = EVERSHELF_QR_FORMAT_INFO[EVERSHELF_QR_ECC_BITS[$level] * 8 + $mask];
        if (readFormatCopy($symbol, true) !== $expected || readFormatCopy($symbol, false) !== $expected) {
            $formatFailures[] = $level . '/' . $mask;
        }
    }
}
assert_same([], $formatFailures, 'both format information copies decode back to the value written');

// The ISO constants the tables are indexed by: a wrong index bumps into these.
assert_same(21522, EVERSHELF_QR_FORMAT_INFO[EVERSHELF_QR_ECC_BITS['M'] * 8 + 0], 'M/mask 0 is the ISO codeword 21522');
assert_same(30660, EVERSHELF_QR_FORMAT_INFO[EVERSHELF_QR_ECC_BITS['L'] * 8 + 0], 'L/mask 0 is the ISO codeword 30660');
assert_same(13663, EVERSHELF_QR_FORMAT_INFO[EVERSHELF_QR_ECC_BITS['Q'] * 8 + 0], 'Q/mask 0 is the ISO codeword 13663');
assert_same(5769, EVERSHELF_QR_FORMAT_INFO[EVERSHELF_QR_ECC_BITS['H'] * 8 + 0], 'H/mask 0 is the ISO codeword 5769');
assert_same(31892, EVERSHELF_QR_VERSION_INFO[0], 'version 7 carries the ISO version information 31892');

// Version information (versions 7+), both blocks.
$symbol = evershelfQrMatrix(str_repeat('https://paypal.me/dadaloop82/', 4), 'M', 12, 3);
$symbolSize = count($symbol);
$expectedVersion = EVERSHELF_QR_VERSION_INFO[12 - 7];
$versionBits = 0;
$versionOk = true;
for ($i = 0; $i < 18; $i++) {
    $row = intdiv($i, 3);
    $col = $i % 3;
    $a = $symbol[$symbolSize - 11 + $col][$row];
    $b = $symbol[$row][$symbolSize - 11 + $col];
    if ($a !== $b) {
        $versionOk = false;
    }
    if ($a === 1) {
        $versionBits |= 1 << $i;
    }
}
assert_true($versionOk, 'both version information blocks match');
assert_same($expectedVersion, $versionBits, 'the version information holds the version 12 codeword');

// ── 4. Encoder behaviour ─────────────────────────────────────────────────────
$repeat = evershelfQrMatrix('HELLO', 'M', 1, 0);
assert_same($matrix, $repeat, 'encoding is deterministic');

$small = evershelfQrMatrix('A', 'H');
assert_same(21, count($small), 'a one byte payload still fits version 1 at level H');

$big = evershelfQrMatrix(str_repeat('x', 400), 'L', null, 0);
assert_same(69, count($big), 'a 400 byte payload needs version 13 at level L');

$threw = false;
try {
    evershelfQrMatrix(str_repeat('x', 3000), 'H');
} catch (InvalidArgumentException $e) {
    $threw = true;
}
assert_true($threw, 'a payload beyond version 40 is refused');

// ── 5. Renderers ─────────────────────────────────────────────────────────────
$svg = evershelfQrSvg('https://paypal.me/dadaloop82');
assert_true(str_starts_with($svg, '<?xml'), 'the SVG renderer emits an XML document');
assert_true(str_contains($svg, 'shape-rendering="crispEdges"'), 'the SVG keeps modules crisp');
assert_true(substr_count($svg, 'h-') > 100, 'the SVG contains the dark modules as a path');
assert_true(str_contains($svg, 'viewBox="0 0 222 222"'), 'the SVG box counts the four module quiet zone');

if (function_exists('imagecreatetruecolor')) {
    $png = evershelfQrPng('https://paypal.me/dadaloop82');
    assert_true(is_string($png) && str_starts_with($png, "\x89PNG"), 'the PNG renderer emits a PNG');
} else {
    echo "OK: PNG renderer skipped (the GD extension is not loaded)\n";
}

echo "\n" . ($fail === 0 ? "All QR encoder tests passed.\n" : "{$fail} failure(s).\n");
exit($fail === 0 ? 0 : 1);
