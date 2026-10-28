<?php
/**
 * The local medicine catalogue (AIFA open data, imported once — no HTTP call at all).
 *
 * Rationale: there is NO free, unlimited, key-less REST API for Italian medicines. AIFA
 * publishes the national list as open data (CSV/XML); `scripts/import-medicines.php --country=IT` loads it into
 * this SQLite table, and the app answers by AIC or by name from there. That keeps the promise
 * the module already made — offline-first, no third party, no key — while making a box
 * *recognisable* instead of merely stored.
 *
 * What a row may contain: identification only (AIC, commercial name, active ingredient,
 * pharmaceutical form, pack description, marketing authorisation holder, ATC). No dose advice,
 * no indication: the household still reads the leaflet, and `med_lookup` never answers a
 * therapy question (see medical.php).
 */

/**
 * The canonical key of the local catalogue: the NATIONAL drug number of the active country.
 *
 * Deliberately country-neutral: Italy keys on the AIC (9 digits), the US on the NDC (10-11),
 * Germany on the PZN (8). A leading `A` in front of exactly 9 digits is dropped because the
 * Italian pack PRINTS it there but the number is the 9 digits; everything that is not a digit
 * or a letter (dashes, dots, spaces) is decoration. 6..20 characters, or the value is refused.
 */
function medicineNormalizeKey(string $value): string
{
    $c = strtoupper(trim($value));
    $c = (string)preg_replace('/^A(?=\d{9}$)/', '', $c);
    $c = (string)preg_replace('/[^0-9A-Z]/', '', $c);
    return (strlen($c) >= 6 && strlen($c) <= 20) ? $c : '';
}

/** Backwards-compatible name: the AIC is simply Italy's national key. */
function medicineNormalizeAic(string $aic): string
{
    return medicineNormalizeKey($aic);
}

/** A searchable form of a title: lowercase, accents and punctuation removed. */
function medicineNormalizeName(string $name): string
{
    $s = mb_strtolower(trim($name), 'UTF-8');
    $s = strtr($s, [
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a',
        'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
        'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
        'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o',
        'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
        'ç' => 'c', 'ñ' => 'n', 'ß' => 'ss',
    ]);
    $s = (string)preg_replace('/[^a-z0-9]+/u', ' ', $s);
    return trim((string)preg_replace('/\s+/', ' ', $s));
}

/**
 * The table. `aic` is the primary key (one pack per AIC in the AIFA list, so an upsert is the
 * natural import: re-running the importer updates names instead of duplicating rows).
 */
function medicinesEnsureTable(PDO $db): void
{
    $db->exec("CREATE TABLE IF NOT EXISTS medicines (
        aic TEXT PRIMARY KEY,
        name TEXT NOT NULL DEFAULT '',
        name_norm TEXT NOT NULL DEFAULT '',
        ingredient TEXT DEFAULT '',
        form TEXT DEFAULT '',
        dosage TEXT DEFAULT '',
        pack TEXT DEFAULT '',
        company TEXT DEFAULT '',
        atc TEXT DEFAULT '',
        fascia TEXT DEFAULT '',
        imported_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");
    $db->exec('CREATE INDEX IF NOT EXISTS idx_medicines_name ON medicines(name_norm)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_medicines_ingredient ON medicines(ingredient)');
}


/**
 * Insert or update one row. Everything but the AIC and the name is optional (the AIFA files
 * differ between releases); an empty field never overwrites a value a previous import had.
 */
function medicineUpsert(PDO $db, array $row): bool
{
    $aic = medicineNormalizeAic((string)($row['aic'] ?? ''));
    $name = trim((string)($row['name'] ?? ''));
    if ($aic === '' || $name === '') {
        return false;
    }
    $stmt = $db->prepare('SELECT * FROM medicines WHERE aic = ?');
    $stmt->execute([$aic]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $pick = static function (string $key) use ($row, $existing): string {
        $value = trim((string)($row[$key] ?? ''));
        return $value !== '' ? $value : (string)($existing[$key] ?? '');
    };
    $finalName = $pick('name');
    $db->prepare("INSERT INTO medicines (aic, name, name_norm, ingredient, form, dosage, pack, company, atc, fascia, imported_at)
                  VALUES (?,?,?,?,?,?,?,?,?,?, CURRENT_TIMESTAMP)
                  ON CONFLICT(aic) DO UPDATE SET
                    name = excluded.name, name_norm = excluded.name_norm,
                    ingredient = excluded.ingredient, form = excluded.form,
                    dosage = excluded.dosage, pack = excluded.pack,
                    company = excluded.company, atc = excluded.atc, fascia = excluded.fascia,
                    imported_at = CURRENT_TIMESTAMP")
       ->execute([
            $aic,
            $finalName,
            medicineNormalizeName($finalName),
            $pick('ingredient'),
            $pick('form'),
            $pick('dosage'),
            $pick('pack'),
            $pick('company'),
            $pick('atc'),
            $pick('fascia'),
        ]);
    return true;
}

/** One medicine by AIC (with or without the printed `A`), or null. */
function medicineFindByAic(PDO $db, string $aic): ?array
{
    $key = medicineNormalizeAic($aic);
    if ($key === '') {
        return null;
    }
    try {
        $stmt = $db->prepare('SELECT * FROM medicines WHERE aic = ?');
        $stmt->execute([$key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return null;
    }
    return $row ?: null;
}

/**
 * Search by commercial name or active ingredient. A name read by the OCR of a box is noisy, so
 * the query is normalised the same way the stored titles are, and every token must be found
 * (AND): "tachipirina 1000" matches "TACHIPIRINA 1000 MG COMPRESSE", while a second word that
 * is not on the pack must not fall back to an unrelated product.
 */
function medicineSearch(PDO $db, string $query, int $limit = 8): array
{
    $q = medicineNormalizeName($query);
    if (mb_strlen($q) < 3) {
        return [];
    }
    $limit = max(1, min(25, $limit));
    $tokens = array_slice(array_values(array_filter(explode(' ', $q))), 0, 5);
    $where = [];
    $params = [];
    foreach ($tokens as $token) {
        $where[] = '(name_norm LIKE ? OR ingredient LIKE ?)';
        $params[] = '%' . $token . '%';
        $params[] = '%' . $token . '%';
    }
    $sql = 'SELECT * FROM medicines WHERE ' . implode(' AND ', $where)
         . ' ORDER BY (name_norm LIKE ?) DESC, LENGTH(name) ASC LIMIT ' . $limit;
    $params[] = $q . '%';
    try {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * What the import left behind: how many rows and when. The UI must be able to say "not loaded
 * yet" instead of letting an unknown box look like a catalogue that disagrees.
 */
function medicinesDatasetInfo(PDO $db): array
{
    $info = ['loaded' => false, 'count' => 0, 'updated_at' => ''];
    try {
        $info['count'] = (int)$db->query('SELECT COUNT(*) FROM medicines')->fetchColumn();
        $info['updated_at'] = (string)($db->query('SELECT MAX(imported_at) FROM medicines')->fetchColumn() ?: '');
    } catch (Throwable $e) {
        return $info;
    }
    $info['loaded'] = $info['count'] > 0;
    return $info;
}

/** The public shape of a medicine row (no internal columns, no advice). */
function medicinePublicRow(array $row): array
{
    return [
        'aic' => (string)($row['aic'] ?? ''),
        'name' => (string)($row['name'] ?? ''),
        'ingredient' => (string)($row['ingredient'] ?? ''),
        'form' => (string)($row['form'] ?? ''),
        'dosage' => (string)($row['dosage'] ?? ''),
        'pack' => (string)($row['pack'] ?? ''),
        'company' => (string)($row['company'] ?? ''),
        'atc' => (string)($row['atc'] ?? ''),
        'fascia' => (string)($row['fascia'] ?? ''),
    ];
}

/**
 * The catalogue field a header cell maps to, or '' when it is none of them.
 *
 * `$aliases` is the COUNTRY map (Italy: `medicalItalyColumnAliases()` in api/lib/med/it.php):
 * the store knows how to import a table, not what a national export calls its columns.
 *
 * @param array<string,array<int,string>> $aliases field => accepted header names
 */
function medicineFieldForHeader(string $label, array $aliases): string
{
    $key = medicineNormalizeName($label);
    $key = trim((string)preg_replace('/\s+/', ' ', $key));
    if ($key === '') {
        return '';
    }
    foreach ($aliases as $field => $names) {
        if (in_array($key, $names, true)) {
            return (string)$field;
        }
    }
    // "CODICE_AIC_9" / "AIC 9 CIFRE": a PREFIX is enough, because the suffix only adds the
    // width ("codice aic 9"). The reverse (a key ENDING with an alias) is deliberately not
    // accepted: it mapped openFDA's `labeler_name` to `name`, so the company became the drug.
    foreach ($aliases as $field => $names) {
        foreach ($names as $alias) {
            if (str_starts_with($key, $alias . ' ') || $key === $alias) {
                return (string)$field;
            }
        }
    }
    return '';
}

/**
 * Map a header row (list of labels) to column index → field.
 *
 * @param array<int,mixed> $header
 * @param array<string,array<int,string>> $aliases
 */
function medicinesHeaderMap(array $header, array $aliases): array
{
    $map = [];
    foreach ($header as $index => $label) {
        $field = medicineFieldForHeader((string)$label, $aliases);
        if ($field !== '' && !isset($map[$field])) {
            $map[$field] = (int)$index;
        }
    }
    return $map;
}

/** Pick the delimiter of the first line (`;` is what the Italian exports use). */
function medicinesDetectDelimiter(string $line): string
{
    $best = ',';
    $bestCount = 0;
    foreach ([';', ',', "\t", '|'] as $candidate) {
        $count = substr_count($line, $candidate);
        if ($count > $bestCount) {
            $bestCount = $count;
            $best = $candidate;
        }
    }
    return $best;
}

/**
 * Import a national medicine export into the local catalogue.
 *
 * Country-neutral on purpose: the column names arrive from the country module (Italy:
 * `medicalItalyColumnAliases()`), so the same importer serves any national dataset that is a
 * table of `aic`/`name`/`ingredient`/… columns.
 *
 * Streaming on purpose (a national list is tens of MB): CSV is read line by line with `fgetcsv`,
 * XML with `XMLReader` when the extension is there, JSON (the openFDA NDC shape) in one piece, and the whole import runs inside one
 * transaction — an interrupted import leaves the previous catalogue intact instead of half of a
 * new one. `dry_run` counts without writing, so a contributor can check the header map before
 * touching a household's database.
 *
 * @param array{dry_run?:bool,truncate?:bool,limit?:int} $options
 * @param array<string,array<int,string>> $aliases field => accepted header names (country map)
 * @return array{ok:bool,format:string,saved:int,skipped:int,rows:int,columns:array<string,string>,error?:string}
 */
function medicinesImportFile(PDO $db, string $path, array $options = [], array $aliases = []): array
{
    $out = ['ok' => false, 'format' => '', 'saved' => 0, 'skipped' => 0, 'rows' => 0, 'columns' => []];
    // No country map, no import: guessing the columns is how a whole catalogue lands in the
    // wrong field.
    if ($aliases === [] || !isset($aliases['aic'], $aliases['name'])) {
        $out['error'] = 'missing_columns';
        return $out;
    }
    if (!is_readable($path)) {
        $out['error'] = 'unreadable_file';
        return $out;
    }
    $dryRun = !empty($options['dry_run']);
    $limit = max(0, (int)($options['limit'] ?? 0));
    $head = (string)file_get_contents($path, false, null, 0, 512);
    $trimmed = ltrim($head);
    $looksJson = str_ends_with(strtolower($path), '.json')
        || str_starts_with($trimmed, '{') || str_starts_with($trimmed, '[');
    $looksXml = !$looksJson && (str_ends_with(strtolower($path), '.xml')
        || str_starts_with($trimmed, '<?xml') || str_starts_with($trimmed, '<'));
    $out['format'] = $looksJson ? 'json' : ($looksXml ? 'xml' : 'csv');
    $save = static function (array $row) use ($db, $dryRun, &$out): void {
        $out['rows']++;
        if (medicineNormalizeAic((string)($row['aic'] ?? '')) === '' || trim((string)($row['name'] ?? '')) === '') {
            $out['skipped']++;
            return;
        }
        if ($dryRun) {
            $out['saved']++;
            return;
        }
        if (medicineUpsert($db, $row)) {
            $out['saved']++;
        } else {
            $out['skipped']++;
        }
    };

    try {
        if (!$dryRun) {
            $db->beginTransaction();
            if (!empty($options['truncate'])) {
                $db->exec('DELETE FROM medicines');
            }
        }
        if ($out['format'] === 'csv') {
            $fh = fopen($path, 'rb');
            if (!$fh) {
                $out['error'] = 'unreadable_file';
                return $out;
            }
            $first = (string)fgets($fh);
            rewind($fh);
            $delim = medicinesDetectDelimiter($first);
            $header = fgetcsv($fh, 0, $delim);
            $header = is_array($header) ? $header : [];
            $map = medicinesHeaderMap($header, $aliases);
            foreach ($map as $field => $index) {
                $out['columns'][$field] = (string)($header[(int)$index] ?? '');
            }
            if (!isset($map['aic'], $map['name'])) {
                if (!$dryRun) { $db->rollBack(); }
                fclose($fh);
                $out['error'] = 'missing_columns';
                return $out;
            }
            $read = 0;
            while (($cells = fgetcsv($fh, 0, $delim)) !== false) {
                if (!is_array($cells) || $cells === [null]) {
                    continue;
                }
                $read++;
                if ($limit > 0 && $read > $limit) {
                    break;
                }
                $row = [];
                foreach ($map as $field => $index) {
                    $row[$field] = trim((string)($cells[(int)$index] ?? ''));
                }
                $save($row);
            }
            fclose($fh);
        } elseif ($out['format'] === 'json') {
            $out['columns'] = _medicinesImportJson($path, $limit, $save, $aliases);
            if ($out['columns'] === []) {
                if (!$dryRun) { $db->rollBack(); }
                $out['error'] = 'missing_columns';
                return $out;
            }
        } else {
            $out['columns'] = _medicinesImportXml($path, $limit, $save, $aliases);
            if ($out['columns'] === []) {
                if (!$dryRun) { $db->rollBack(); }
                $out['error'] = 'missing_columns';
                return $out;
            }
        }
        if (!$dryRun) {
            $db->commit();
        }
        $out['ok'] = $out['saved'] > 0;
    } catch (Throwable $e) {
        if (!$dryRun && $db->inTransaction()) {
            $db->rollBack();
        }
        $out['error'] = 'import_failed';
        // The message is what the operator needs: a wrong delimiter or a locked database is a
        // five-second fix, and this script only ever runs from a shell.
        $out['detail'] = $e->getMessage();
        EverLog::error('Medicine catalogue import failed', [
            'event' => 'medicines_import_failed',
            'path' => $path,
            'message' => $e->getMessage(),
        ]);
    }
    return $out;
}


/**
 * The JSON half — the openFDA NDC Directory shape (`{ "results": [ {…}, … ] }`), and any bare
 * array of objects with the same field names.
 *
 * A national catalogue that is published as JSON needs no CSV conversion: the same column map
 * (`$aliases`) is applied to the object keys, and a nested array (openFDA's
 * `active_ingredients`) is flattened to its scalar parts so the active ingredient column is
 * still filled.
 *
 * @param array<string,array<int,string>> $aliases
 * @return array<string,string> field → the JSON key it came from (empty = refused)
 */
function _medicinesImportJson(string $path, int $limit, callable $save, array $aliases): array
{
    $decoded = json_decode((string)file_get_contents($path), true);
    if (!is_array($decoded)) {
        return [];
    }
    $rows = isset($decoded['results']) && is_array($decoded['results']) ? $decoded['results'] : $decoded;
    if (!is_array($rows)) {
        return [];
    }
    $columns = [];
    $read = 0;
    foreach ($rows as $item) {
        if (!is_array($item)) {
            continue;
        }
        $row = [];
        foreach ($item as $key => $value) {
            $field = medicineFieldForHeader((string)$key, $aliases);
            if ($field === '') {
                continue;
            }
            if (is_array($value)) {
                // openFDA nests objects (active_ingredients); flatten the scalar parts and never
                // overwrite a column that a flat key already filled with a better value.
                $value = implode(' ', array_filter(array_map(
                    static fn($v) => is_scalar($v) ? trim((string)$v) : '',
                    $value
                )));
                if (trim($value) === '') {
                    continue;
                }
            }
            if (!is_scalar($value)) {
                continue;
            }
            $columns[$field] = (string)$key;
            $row[$field] = trim((string)$value);
        }
        $read++;
        if ($limit > 0 && $read > $limit) {
            break;
        }
        if ($row) {
            $save($row);
        }
    }
    return $columns;
}

/**
 * The XML half: `<record>`, `<row>`, `<farmaco>` or `<medicinale>` elements whose CHILD TAGS
 * name the fields. Kept separate so the CSV path stays readable; returns the mapped columns
 * (empty = refused, i.e. no AIC/name tag).
 *
 * @param array<string,array<int,string>> $aliases
 * @return array<string,string> field → the tag name it came from
 */
function _medicinesImportXml(string $path, int $limit, callable $save, array $aliases): array
{
    $columns = [];
    $read = 0;
    if (class_exists('XMLReader')) {
        $reader = new XMLReader();
        if (!$reader->open($path)) {
            return [];
        }
        while ($reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT
                || !in_array($reader->localName, ['record', 'row', 'farmaco', 'medicinale'], true)) {
                continue;
            }
            $node = $reader->readOuterXML();
            $xml = @simplexml_load_string($node);
            if ($xml === false) {
                continue;
            }
            $row = [];
            foreach ($xml->children() as $child) {
                $field = medicineFieldForHeader((string)$child->getName(), $aliases);
                if ($field !== '') {
                    $columns[$field] = (string)$child->getName();
                    $row[$field] = trim((string)$child);
                }
            }
            $read++;
            if ($limit > 0 && $read > $limit) {
                break;
            }
            if ($row) {
                $save($row);
            }
        }
        $reader->close();
        return $columns;
    }
    // No XMLReader: the file is small enough to load (a fixture, or a stripped export).
    $raw = (string)file_get_contents($path);
    if (preg_match_all('/<(record|row|farmaco|medicinale)\b[^>]*>(.*?)<\/\1>/si', $raw, $matches)) {
        foreach ($matches[2] as $body) {
            $row = [];
            if (preg_match_all('/<([a-z0-9_:-]+)\b[^>]*>(.*?)<\/\1>/si', $body, $kids, PREG_SET_ORDER)) {
                foreach ($kids as $kid) {
                    $field = medicineFieldForHeader((string)$kid[1], $aliases);
                    if ($field !== '') {
                        $columns[$field] = (string)$kid[1];
                        $row[$field] = trim((string)$kid[2]);
                    }
                }
            }
            $read++;
            if ($limit > 0 && $read > $limit) {
                break;
            }
            if ($row) {
                $save($row);
            }
        }
    }
    return $columns;
}
