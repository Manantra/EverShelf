<?php
/**
 * EverShelf Med — the opt-in medicine cabinet, deliberately small and deterministic.
 *
 * Four rules, in this order:
 *   1. The module is OFF unless the owner turned it on (`MED_ENABLED` / the Settings switch)
 *      and the household accepted the disclaimer: an app that counts tomatoes must not start
 *      guessing therapies.
 *   2. Food and medicines never mix: a location can be marked `medical`, a product can be
 *      flagged, and those rows are excluded from recipes/shopping suggestions.
 *   3. "What do I have for a headache?" is answered by a CLOSED taxonomy of 8 household
 *      categories (data/medical_categories.json) and the user's own inventory — no AI, no
 *      network, no dosage, no diagnosis.
 *   4. The reply always carries the disclaimer: the app lists boxes, the doctor treats people.
 */

/**
 * The module is on only when the owner enabled it — via `.env` (`MED_ENABLED`) or the
 * Settings toggle (`app_settings.med_enabled`). Off by default, always.
 */
function medicalEnabled(?PDO $db = null): bool
{
    static $memo = null;
    if (strtolower(trim((string)env('MED_ENABLED', 'false'))) === 'true') {
        return true;
    }
    if ($memo !== null) {
        return $memo;
    }
    $memo = false;
    try {
        $db = $db ?: (function_exists('getDB') ? getDB() : null);
        if ($db instanceof PDO) {
            $stmt = $db->prepare("SELECT value FROM app_settings WHERE key = 'med_enabled'");
            $stmt->execute();
            $raw = (string)($stmt->fetchColumn() ?: '');
            $memo = in_array(strtolower(trim($raw, " \t\n\r\"")), ['1', 'true', 'yes', 'on'], true);
        }
    } catch (Throwable $e) {
        $memo = false;
    }
    return $memo;
}

/** The closed Phase-1 taxonomy: data/medical_categories.json (8 household categories). */
function medicalCategories(): array
{
    static $cats = null;
    if ($cats !== null) {
        return $cats;
    }
    $path = dirname(__DIR__, 2) . '/data/medical_categories.json';
    $raw = is_readable($path) ? (string)file_get_contents($path) : '';
    $parsed = json_decode($raw, true);
    $cats = (is_array($parsed) && !empty($parsed['categories'])) ? array_values($parsed['categories']) : [];
    if (!$cats) {
        EverLog::warn('medical categories missing or unreadable', ['event' => 'medical_categories_missing', 'path' => $path]);
    }
    return $cats;
}

/** The ATC map (Phase 2): data/medical_atc.json, kept for the AIFA import. */
function medicalAtcData(): array
{
    static $data = null;
    if ($data !== null) {
        return $data;
    }
    $path = dirname(__DIR__, 2) . '/data/medical_atc.json';
    $raw = is_readable($path) ? (string)file_get_contents($path) : '';
    $parsed = json_decode($raw, true);
    if (!is_array($parsed) || empty($parsed['groups'])) {
        EverLog::warn('ATC map missing or unreadable', ['event' => 'medical_atc_missing', 'path' => $path]);
        $parsed = ['groups' => []];
    }
    $data = $parsed;
    return $data;
}

/** The 8 valid category slugs (the only values `products.med_tags` may contain). */
function medicalCategorySlugs(): array
{
    return array_values(array_filter(array_map(static fn($c) => (string)($c['slug'] ?? ''), medicalCategories())));
}

/** The category slugs of a product (comma separated list in products.med_tags). */
function medicalTagsOf(array $row): array
{
    $raw = strtolower(trim((string)($row['med_tags'] ?? '')));
    if ($raw === '') {
        return [];
    }
    $valid = medicalCategorySlugs();
    return array_values(array_filter(array_map('trim', explode(',', $raw)), static fn($t) => in_array($t, $valid, true)));
}

/**
 * Which household categories a sentence points at — deterministic, offline, longest phrase first.
 *
 * @return array<int,array{slug:string,label_key:string,atc:array<int,string>,label:string}>
 */
function medicalMatchSymptom(string $text): array
{
    $hay = mb_strtolower(trim($text));
    if ($hay === '') {
        return [];
    }
    // Every [phrase => category], longest first so "mal di testa" beats a bare "testa".
    $phrases = [];
    foreach (medicalCategories() as $cat) {
        $slug = (string)($cat['slug'] ?? '');
        if ($slug === '') {
            continue;
        }
        foreach ((array)($cat['symptoms'] ?? []) as $words) {
            foreach ((array)$words as $word) {
                $w = mb_strtolower(trim((string)$word));
                if ($w !== '') {
                    $phrases[$w] = [
                        'slug' => $slug,
                        'label_key' => (string)($cat['label_key'] ?? ''),
                        'atc' => array_values((array)($cat['atc'] ?? [])),
                    ];
                }
            }
        }
    }
    uksort($phrases, static fn($a, $b) => mb_strlen((string)$b) <=> mb_strlen((string)$a));
    $out = [];
    $seen = [];
    foreach ($phrases as $phrase => $cat) {
        $slug = (string)$cat['slug'];
        if (isset($seen[$slug]) || mb_strpos($hay, (string)$phrase) === false) {
            continue;
        }
        $seen[$slug] = true;
        $key = (string)$cat['label_key'];
        $out[] = ['slug' => $slug, 'label_key' => $key, 'atc' => array_values($cat['atc']), 'label' => $key];
    }
    return $out;
}

/**
 * Is this pantry row a medicine? The flag on the product wins; otherwise the location.
 *
 * @param array<string,mixed> $row      inventory/product row (is_medical, location)
 * @param array<int,string>   $medLocs  location slugs marked medical in Settings
 */
function medicalIsMedicalRow(array $row, array $medLocs = []): bool
{
    if (!empty($row['is_medical'])) {
        return true;
    }
    $loc = mb_strtolower(trim((string)($row['location'] ?? '')));
    return $loc !== '' && in_array($loc, array_map('mb_strtolower', $medLocs), true);
}

/** The medical location slugs the household marked (app_settings.medical_locations). */
function medicalLocationSlugs(array $settings): array
{
    $raw = $settings['medical_locations'] ?? [];
    if (is_string($raw)) {
        $raw = json_decode($raw, true) ?: [];
    }
    return array_values(array_filter(array_map(static fn($v) => trim((string)$v), (array)$raw)));
}
