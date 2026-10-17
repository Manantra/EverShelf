<?php
/**
 * Parent / child products (#284).
 *
 * One umbrella product ("Spaghetti") with concrete children (Barilla n.5,
 * Rummo, De Cecco), each with its own brand, barcode, price and stock — while
 * the pantry can show what the family holds in total. The child keeps its own
 * row (you need to know *which* spaghetti is there); the parent is a catalogue
 * row with no stock of its own.
 *
 * The link is one nullable column (`products.parent_id`) and the depth is one
 * level on purpose: a variant of a variant is a different product, and a tree
 * would make every aggregate query recursive for no benefit.
 *
 * Everything is off with PRODUCT_VARIANTS_ENABLED=false — a normal `.env`
 * setting like the other product rules.
 */

/** Are parent/child products switched on for this instance? */
function productVariantsEnabled(): bool
{
    return strtolower(trim((string)env('PRODUCT_VARIANTS_ENABLED', 'true'))) !== 'false';
}

/**
 * Attach the family information to pantry rows: `parent_id`, `parent_name`,
 * `family_qty` (the same unit across the siblings) and `family_count`.
 */
function familyAttachToRows(PDO $db, array &$rows): void
{
    if (!productVariantsEnabled() || !$rows) {
        return;
    }
    $ids = [];
    foreach ($rows as $row) {
        $pid = (int)($row['product_id'] ?? 0);
        if ($pid > 0) {
            $ids[$pid] = true;
        }
    }
    if (!$ids) {
        return;
    }
    $ids = array_keys($ids);
    $in = implode(',', array_fill(0, count($ids), '?'));

    $parentOf = [];
    $stmt = $db->prepare("SELECT id, parent_id FROM products WHERE id IN ($in)");
    $stmt->execute($ids);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $parentOf[(int)$r['id']] = $r['parent_id'] !== null ? (int)$r['parent_id'] : null;
    }
    $parentIds = array_values(array_unique(array_filter($parentOf, fn($v) => $v !== null)));
    if (!$parentIds) {
        return;
    }

    $pIn = implode(',', array_fill(0, count($parentIds), '?'));
    $names = [];
    $stmt = $db->prepare("SELECT id, name FROM products WHERE id IN ($pIn)");
    $stmt->execute($parentIds);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $names[(int)$r['id']] = (string)$r['name'];
    }

    // One aggregate for every family on the page: the unit is part of the key,
    // because "2 pz" and "500 g" of the same family are not the same thing.
    $agg = [];
    $stmt = $db->prepare("
        SELECT p.parent_id, p.unit, SUM(i.quantity) AS qty, COUNT(DISTINCT p.id) AS products
          FROM products p
          JOIN inventory i ON i.product_id = p.id
         WHERE p.parent_id IN ($pIn) AND i.quantity > 0
         GROUP BY p.parent_id, p.unit
    ");
    $stmt->execute($parentIds);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $agg[(int)$r['parent_id']][(string)$r['unit']] = [
            'qty'      => (float)$r['qty'],
            'products' => (int)$r['products'],
        ];
    }

    foreach ($rows as &$row) {
        $pid = (int)($row['product_id'] ?? 0);
        $parentId = $parentOf[$pid] ?? null;
        if ($parentId === null || !isset($names[$parentId])) {
            continue;
        }
        $unit = (string)($row['unit'] ?? 'pz');
        $row['parent_id']    = $parentId;
        $row['parent_name']  = $names[$parentId];
        $row['family_qty']   = $agg[$parentId][$unit]['qty'] ?? (float)($row['quantity'] ?? 0);
        $row['family_count'] = $agg[$parentId][$unit]['products'] ?? 1;
    }
    unset($row);
}


/** The children of an umbrella product, with their stock. */
function familyChildren(PDO $db, int $parentId): array
{
    $stmt = $db->prepare("
        SELECT p.id, p.name, p.brand, p.unit,
               COALESCE(SUM(i.quantity), 0) AS quantity
          FROM products p
          LEFT JOIN inventory i ON i.product_id = p.id
         WHERE p.parent_id = ?
         GROUP BY p.id
         ORDER BY p.name COLLATE NOCASE
    ");
    $stmt->execute([$parentId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** Umbrella products (a catalogue row with at least one child). */
function familyNames(PDO $db): array
{
    $rows = $db->query("
        SELECT p.id, p.name, COUNT(c.id) AS children
          FROM products p
          JOIN products c ON c.parent_id = p.id
         GROUP BY p.id
         ORDER BY p.name COLLATE NOCASE
    ")->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $r) {
        $out[] = ['id' => (int)$r['id'], 'name' => (string)$r['name'], 'children' => (int)$r['children']];
    }
    return $out;
}

/**
 * Link a product to an umbrella. `$parentId = null` detaches it.
 * Returns [ok, error_key?]: a product cannot be its own parent, an umbrella
 * cannot itself have a parent (one level), and a child cannot adopt a child.
 */
function familySetParent(PDO $db, int $childId, ?int $parentId): array
{
    if ($childId <= 0) {
        return [false, 'error.product_id_required'];
    }
    if ($parentId !== null && $parentId === $childId) {
        return [false, 'error.family_self_parent'];
    }
    $stmt = $db->prepare("SELECT id, parent_id FROM products WHERE id = ?");
    $stmt->execute([$childId]);
    $child = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$child) {
        return [false, 'error.product_not_found'];
    }
    if ($parentId === null) {
        $db->prepare("UPDATE products SET parent_id = NULL WHERE id = ?")->execute([$childId]);
        return [true, null];
    }
    $stmt->execute([$parentId]);
    $parent = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$parent) {
        return [false, 'error.product_not_found'];
    }
    if ($parent['parent_id'] !== null) {
        return [false, 'error.family_parent_is_child'];
    }
    $hasChildren = (int)$db->query(
        "SELECT COUNT(*) FROM products WHERE parent_id = " . $childId
    )->fetchColumn();
    if ($hasChildren > 0) {
        return [false, 'error.family_parent_has_children'];
    }
    $db->prepare("UPDATE products SET parent_id = ? WHERE id = ?")->execute([$parentId, $childId]);
    return [true, null];
}

/**
 * Find the umbrella with this name, or create a catalogue row for it (no stock:
 * you buy the brand, not the family). Case-insensitive on the display name.
 */
function familyResolveParent(PDO $db, string $name): ?int
{
    $name = trim($name);
    if ($name === '') {
        return null;
    }
    $stmt = $db->prepare("SELECT id FROM products WHERE name = ? COLLATE NOCASE AND parent_id IS NULL LIMIT 1");
    $stmt->execute([$name]);
    $id = (int)($stmt->fetchColumn() ?: 0);
    if ($id > 0) {
        return $id;
    }
    $ins = $db->prepare("INSERT INTO products (name, category, unit, default_quantity) VALUES (?, '', 'pz', 1)");
    $ins->execute([$name]);
    return (int)$db->lastInsertId();
}
