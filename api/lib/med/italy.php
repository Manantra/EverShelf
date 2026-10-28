<?php
/**
 * EverShelf Med — the ITALY module. Country code, kept out of the core on purpose.
 *
 * What is Italian here, and nowhere else:
 *   1. the number that identifies the drug is the **AIC**, and the box PRINTS it —
 *      `A040313076` in Code 39, or the bare 9 digits of the older Code 32 form;
 *   2. the 2D DataMatrix of the EU FMD carries it as the national reimbursement number
 *      (AI **710**), next to the lot the scanner must not mistake for a drug code;
 *   3. the SSN label (`Pharmacode`/`Laetus`) and any short alphanumeric are PACKAGING: the
 *      schema lists them under `ignored` with `action: "ocr"`, because the AIC is printed and
 *      has to be read off the label;
 *   4. the catalogue is the AIFA open data (CSV/XML), imported once by
 *      `scripts/import-medicines.php --country=IT`: its column names are here.
 *
 * The core (`api/lib/medical.php`) knows none of this: it reads the country schema, walks the
 * generic GS1 element string, applies the `codes`/`ignored`/`ocr` lists of the data file, and
 * asks the country module only "is this read one of YOUR drug codes?". A new country = a block
 * in `data/medical_code_schemas.json` (`"module": "<slug>"`) + a file like this one.
 */

/** The AIC of a read, in its canonical 9-digit form, or null ("this is not a drug code"). */
function medicalItalyAicFromCode(string $code): ?string
{
    $c = medicalCompactCode($code);
    if ($c === '') {
        return null;
    }
    if (preg_match('/^A(\d{9})$/', $c, $m)) {
        return $m[1];
    }
    // NO bare 9-digit form. The SSN label prints a second Code 39 with the pack's progressive
    // number (`014058142`), which is indistinguishable from an old Code 32 AIC: accepting it
    // meant storing a serial as if it were a medicine. The printed block always carries the A.
    // The FMD DataMatrix: the AIC is the national reimbursement number (AI 710), possibly
    // zero-padded by the encoder. The lot (AI 10) is never a drug code.
    $gs1 = medicalGs1Parse($code);
    $nhrn = (string)($gs1['ais']['71'] ?? '');
    if ($nhrn !== '' && preg_match('/^0*(\d{9})$/', strtoupper($nhrn), $m)) {
        return $m[1];
    }
    return null;
}

/**
 * Is this read the Italian drug number? The core calls this (via `module.local_code`) before it
 * looks at the generic pattern list, because the three printed forms cannot be expressed as one
 * regex over the whole read.
 *
 * @return array{code:string,type:string,label_key:string,aic:string}|null
 *         `code` is what the box prints (`A` + 9), `aic` is the 9 digits the AIFA catalogue is
 *         keyed by.
 */
function medicalItalyLocalCode(string $code): ?array
{
    $aic = medicalItalyAicFromCode($code);
    if ($aic === null) {
        return null;
    }
    return ['code' => 'A' . $aic, 'type' => 'aic', 'label_key' => 'medical.code_aic', 'aic' => $aic];
}

/**
 * The AIFA open-data columns, by the many names the releases have used.
 *
 * The file is data somebody else writes: the headers change between editions (and between the
 * CSV and the XML export), so a column is recognised by its NORMALISED name and not by its
 * position. This map is Italian because the file is; another country's module brings its own.
 */
function medicalItalyColumnAliases(): array
{
    return [
        'aic' => ['aic', 'codice aic', 'cod aic', 'codiceaic', 'aic9', 'aic7', 'aic code', 'code aic',
                  'codice', 'code', 'cod medicinale', 'codice medicinale'],
        'name' => ['denominazione', 'nome', 'name', 'denominazione farmaco', 'descrizione', 'description',
                   'nome commerciale', 'product name'],
        'ingredient' => ['principio attivo', 'principioattivo', 'active ingredient', 'ingredient',
                         'sostanza attiva', 'sostanze attive', 'active substances'],
        'form' => ['forma', 'forma farmaceutica', 'pharmaceutical form', 'form', 'formafarmaceutica'],
        'dosage' => ['dosaggio', 'dose', 'strength', 'dosage', 'concentrazione'],
        'pack' => ['confezione', 'descrizione confezione', 'pack', 'packaging', 'confezionamento',
                   'descrizione della confezione'],
        'company' => ['titolare', 'titolare aic', 'ditta', 'azienda', 'company', 'marketing authorisation holder',
                      'titolare dell autorizzazione'],
        'atc' => ['atc', 'codice atc', 'atc code', 'codiceatc'],
        'fascia' => ['fascia', 'classe', 'fascia ssn', 'class', 'regime'],
    ];
}

/**
 * The module descriptor the core reads. The naming convention is the contract: the schema says
 * `"module": "it"`, the core loads `api/lib/med/it.php` and calls `medicalItalyModule()`.
 */
function medicalItalyModule(): array
{
    return [
        'cc' => 'IT',
        'slug' => 'it',
        // Callable names, so the core never has to know what "an AIC" is.
        'local_code' => 'medicalItalyLocalCode',
        'dataset_columns' => 'medicalItalyColumnAliases',
        'dataset_id' => 'aifa',
    ];
}
