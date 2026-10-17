<?php
/**
 * Custom product tags (#97).
 *
 * A household attaches free-form labels to the catalogue ("senza glutine",
 * "bio", "bimbi", "scorta") and filters the pantry by them. The labels live in
 * two relational tables instead of a comma-separated column on `products`, so
 * "which products carry tag X?" is an index lookup and renaming a tag is one
 * row, not a rewrite of every product.
 *
 * Tags belong to the *product* (the catalogue row), which is what the inventory
 * row shows: the same product in the pantry and in the freezer carries the same
 * labels. Everything is off with TAGS_ENABLED=false — the option is a normal
 * `.env` setting, like the other product rules.
 */

/** Are custom tags switched on for this instance? */
function tagsEnabled(): bool
{
    return strtolower(trim((string)env('TAGS_ENABLED', 'true'))) !== 'false';
}

/** Create the two tables when missing (idempotent, called from initializeDB). */
function tagsEnsureTables(PDO $db): void
{
    $db->exec("CREATE TABLE IF NOT EXISTS product_tags (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        name_key TEXT NOT NULL UNIQUE,
        color TEXT DEFAULT '',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS product_tag_links (
        product_id INTEGER NOT NULL,
        tag_id INTEGER NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (product_id, tag_id),
        FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
        FOREIGN KEY (tag_id) REFERENCES product_tags(id) ON DELETE CASCADE
    )");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_tag_links_tag ON product_tag_links(tag_id)");
}

/**
 * The comparable form of a tag: case-insensitive and whitespace-insensitive, so
 * "Senza Glutine", "senza  glutine" and "senza glutine" are one tag.
 */
function tagNameKey(string $name): string
{
    $name = preg_replace('/\s+/u', ' ', trim($name)) ?? '';
    return function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
}

/** The display form of a tag: trimmed, single spaces, at most 24 characters. */
function tagNameClean(string $name): string
{
    $name = preg_replace('/\s+/u', ' ', trim($name)) ?? '';
    $name = trim($name, " ,;");
    if (function_exists('mb_substr')) {
        return mb_substr($name, 0, 24, 'UTF-8');
    }
    return substr($name, 0, 24);
}

/** Every tag with how many products carry it (ordered by name). */
function tagsAll(PDO $db): array
{
    tagsEnsureTables($db);
    $rows = $db->query("
        SELECT t.id, t.name, t.color, COUNT(l.product_id) AS products
          FROM product_tags t
          LEFT JOIN product_tag_links l ON l.tag_id = t.id
         GROUP BY t.id
         ORDER BY t.name COLLATE NOCASE
    ")->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'id'       => (int)$r['id'],
            'name'     => (string)$r['name'],
            'color'    => (string)($r['color'] ?? ''),
            'products' => (int)$r['products'],
        ];
    }
    return $out;
}

/** The tag names attached to one product. */
function tagsForProduct(PDO $db, int $productId): array
{
    tagsEnsureTables($db);
    $stmt = $db->prepare("
        SELECT t.name
          FROM product_tag_links l
          JOIN product_tags t ON t.id = l.tag_id
         WHERE l.product_id = ?
         ORDER BY t.name COLLATE NOCASE
    ");
    $stmt->execute([$productId]);
    return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Tags for a batch of products: product_id => [names]. One query for the whole
 * pantry instead of one per row.
 */
function tagsMapForProducts(PDO $db, array $productIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $productIds), fn(int $i): bool => $i > 0)));
    if (!$ids) {
        return [];
    }
    tagsEnsureTables($db);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare("
        SELECT l.product_id, t.name
          FROM product_tag_links l
          JOIN product_tags t ON t.id = l.tag_id
         WHERE l.product_id IN ($in)
         ORDER BY t.name COLLATE NOCASE
    ");
    $stmt->execute($ids);
    $map = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $map[(int)$r['product_id']][] = (string)$r['name'];
    }
    return $map;
}

/** Create a tag (or return the existing one with the same key). */
function tagsCreate(PDO $db, string $name, string $color = ''): ?array
{
    $clean = tagNameClean($name);
    $key   = tagNameKey($clean);
    if ($key === '') {
        return null;
    }
    tagsEnsureTables($db);
    $find = $db->prepare("SELECT id, name, color FROM product_tags WHERE name_key = ?");
    $find->execute([$key]);
    $row = $find->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        return ['id' => (int)$row['id'], 'name' => (string)$row['name'], 'color' => (string)($row['color'] ?? '')];
    }
    $ins = $db->prepare("INSERT INTO product_tags (name, name_key, color) VALUES (?, ?, ?)");
    $ins->execute([$clean, $key, $color]);
    return ['id' => (int)$db->lastInsertId(), 'name' => $clean, 'color' => $color];
}

/** Rename / recolour a tag. Returns false when the id or the name is invalid. */
function tagsUpdate(PDO $db, int $id, string $name, string $color = ''): bool
{
    $clean = tagNameClean($name);
    $key   = tagNameKey($clean);
    if ($id <= 0 || $key === '') {
        return false;
    }
    tagsEnsureTables($db);
    // A rename onto an existing tag merges the two: the links move, the row goes.
    $find = $db->prepare("SELECT id FROM product_tags WHERE name_key = ? AND id <> ?");
    $find->execute([$key, $id]);
    $other = (int)($find->fetchColumn() ?: 0);
    if ($other > 0) {
        $db->prepare("UPDATE OR IGNORE product_tag_links SET tag_id = ? WHERE tag_id = ?")->execute([$other, $id]);
        $db->prepare("DELETE FROM product_tag_links WHERE tag_id = ?")->execute([$id]);
        $db->prepare("DELETE FROM product_tags WHERE id = ?")->execute([$id]);
        return true;
    }
    $db->prepare("UPDATE product_tags SET name = ?, name_key = ?, color = ? WHERE id = ?")
       ->execute([$clean, $key, $color, $id]);
    return true;
}

/** Delete a tag and every link to it (the products stay). */
function tagsDelete(PDO $db, int $id): bool
{
    if ($id <= 0) {
        return false;
    }
    tagsEnsureTables($db);
    $db->prepare("DELETE FROM product_tag_links WHERE tag_id = ?")->execute([$id]);
    $stmt = $db->prepare("DELETE FROM product_tags WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->rowCount() > 0;
}

/**
 * Replace the tags of a product with the given names (unknown names are
 * created). Returns the stored names, so the client can redraw the pills
 * without asking again. A product keeps at most 12 tags.
 */
function tagsSetForProduct(PDO $db, int $productId, array $names): array
{
    if ($productId <= 0) {
        return [];
    }
    tagsEnsureTables($db);
    $wanted = [];
    foreach ($names as $name) {
        $clean = tagNameClean((string)$name);
        $key   = tagNameKey($clean);
        if ($key !== '' && !isset($wanted[$key])) {
            $wanted[$key] = $clean;
        }
        if (count($wanted) >= 12) {
            break;
        }
    }

    $tagIds = [];
    foreach ($wanted as $clean) {
        $tag = tagsCreate($db, $clean);
        if ($tag) {
            $tagIds[(int)$tag['id']] = (string)$tag['name'];
        }
    }

    $current = $db->prepare("SELECT tag_id FROM product_tag_links WHERE product_id = ?");
    $current->execute([$productId]);
    $before = array_map('intval', $current->fetchAll(PDO::FETCH_COLUMN));

    $drop = array_diff($before, array_keys($tagIds));
    if ($drop) {
        $in = implode(',', array_fill(0, count($drop), '?'));
        $db->prepare("DELETE FROM product_tag_links WHERE product_id = ? AND tag_id IN ($in)")
           ->execute(array_merge([$productId], array_values($drop)));
    }
    $add = array_diff(array_keys($tagIds), $before);
    if ($add) {
        $ins = $db->prepare("INSERT OR IGNORE INTO product_tag_links (product_id, tag_id) VALUES (?, ?)");
        foreach ($add as $tagId) {
            $ins->execute([$productId, (int)$tagId]);
        }
    }

    $stored = array_values($tagIds);
    sort($stored, SORT_NATURAL | SORT_FLAG_CASE);
    return $stored;
}

/**
 * Attach `tags` to every row of a result set that carries `product_id`
 * (inventory list, product read). No-op when the feature is off.
 */
function tagsAttachToRows(PDO $db, array &$rows): void
{
    if (!tagsEnabled() || !$rows) {
        return;
    }
    $map = tagsMapForProducts($db, array_column($rows, 'product_id'));
    foreach ($rows as &$row) {
        $row['tags'] = $map[(int)($row['product_id'] ?? 0)] ?? [];
    }
    unset($row);
}

