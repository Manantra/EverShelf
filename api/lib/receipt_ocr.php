<?php
/**
 * Receipt OCR (#114).
 *
 * One photo of a supermarket receipt becomes a list of `{name, brand, qty,
 * unit, price}` rows. The model only *proposes*: nothing is written until the
 * user confirms the review table in the browser (`inventory_add` runs from
 * there, one row at a time). Keeping the sanitising here — and not inline in
 * the endpoint — is what lets the guard test feed it junk without a database.
 */

/** Is receipt scanning switched on for this instance? */
function receiptOcrEnabled(): bool
{
    return strtolower(trim((string)env('RECEIPT_OCR_ENABLED', 'true'))) !== 'false';
}

/** Upper bound of lines accepted from one photo. */
function receiptOcrMaxItems(): int
{
    $max = (int)env('RECEIPT_OCR_MAX_ITEMS', '60');
    return max(1, min(200, $max > 0 ? $max : 60));
}

/** The units a pantry row can use; anything else from the model is dropped. */
function receiptOcrUnits(): array
{
    return ['pz', 'g', 'ml', 'conf'];
}

/**
 * Sanitise one model row. Returns null when the row cannot become a pantry row
 * (no name, absurd quantity, unknown unit): the user never sees junk to delete.
 */
function receiptSanitizeItem($row): ?array
{
    if (!is_array($row)) {
        return null;
    }
    $name = trim((string)($row['name'] ?? ''));
    $name = preg_replace('/\s+/u', ' ', $name) ?? '';
    if ($name === '' || mb_strlen($name) > 80) {
        return null;
    }
    $unit = strtolower(trim((string)($row['unit'] ?? 'pz')));
    if (!in_array($unit, receiptOcrUnits(), true)) {
        $unit = 'pz';
    }
    $qty = (float)str_replace(',', '.', (string)($row['qty'] ?? $row['quantity'] ?? 0));
    if (!is_finite($qty) || $qty <= 0 || $qty > 9999) {
        $qty = 1.0;
    }
    $price = (float)str_replace(',', '.', (string)($row['price'] ?? 0));
    if (!is_finite($price) || $price < 0 || $price > 99999) {
        $price = 0.0;
    }
    $brand = trim((string)($row['brand'] ?? ''));
    $brand = preg_replace('/\s+/u', ' ', $brand) ?? '';
    if (mb_strlen($brand) > 60) {
        $brand = mb_substr($brand, 0, 60);
    }
    return [
        'name'  => $name,
        'brand' => $brand,
        'qty'   => round($qty, 3),
        'unit'  => $unit,
        'price' => round($price, 2),
    ];
}

/**
 * Turn the model's answer into a list of rows: tolerant of code fences, a bare
 * array, an object with `items`, or a single object. Never throws.
 */
function receiptParseModelJson(string $text): array
{
    $text = trim($text);
    // Strip ```json … ``` and anything before the first bracket.
    $text = preg_replace('/^```[a-zA-Z]*\s*/', '', $text) ?? $text;
    $text = preg_replace('/\s*```$/', '', $text) ?? $text;
    $start = strpos($text, '[');
    $objStart = strpos($text, '{');
    if ($start === false || ($objStart !== false && $objStart < $start)) {
        $start = $objStart;
    }
    if ($start === false) {
        return [];
    }
    $endBracket = strrpos($text, ']');
    $endBrace = strrpos($text, '}');
    $end = max($endBracket === false ? -1 : $endBracket, $endBrace === false ? -1 : $endBrace);
    if ($end <= $start) {
        return [];
    }
    $json = substr($text, $start, $end - $start + 1);
    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        return [];
    }
    if (isset($decoded['items']) && is_array($decoded['items'])) {
        $decoded = $decoded['items'];
    } elseif (isset($decoded['name'])) {
        $decoded = [$decoded];
    }
    $out = [];
    foreach ($decoded as $row) {
        $clean = receiptSanitizeItem($row);
        if ($clean !== null) {
            $out[] = $clean;
        }
        if (count($out) >= receiptOcrMaxItems()) {
            break;
        }
    }
    return $out;
}
