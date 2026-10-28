/**
 * EverShelf Med — the CORE of the medicine scanner (country-NEUTRAL).
 *
 * Nothing Italian lives here. This file knows:
 *   - the per-country schema (`data/medical_code_schemas.json`, served by `med_status`): the
 *     `codes` a country declares, the `ignored` PACKAGING codes, the `ocr` patterns, the GS1
 *     range;
 *   - the GS1 element string of a 2D DataMatrix (a standard, not a national rule);
 *   - the registry of COUNTRY MODULES: a country that needs more than a regex declares
 *     `"module": "<slug>"` in the schema and ships `assets/js/med/<slug>.js`, which registers
 *     its own `localCode()` — no national rule is written here.
 *
 * The question "is this read one of your drug codes?" is answered by the module, never here —
 * so adding a country is a data block plus one small file, and dropping a country leaves a
 * scanner that still recognises the patterns of the others (a PZN, a CN, an NDC…).
 *
 * Loaded before app.js (index.html + the service-worker shell).
 */

/** The registered country modules, keyed by the `module` slug of the schema. `globalThis` so
 *  the same file runs in the browser and in the node guard test (no `window` shim there). */
globalThis.EVERSHELF_MED_MODULES = globalThis.EVERSHELF_MED_MODULES || {};

/**
 * Register one country module.
 *
 * @param {string} slug the value of `"module"` in data/medical_code_schemas.json
 * @param {{cc:string, localCode:function(string):({code:string,type:string,label_key:string,aic?:string}|null)}} module
 */
function _medRegisterCountryModule(slug, module) {
    const key = String(slug || '').toLowerCase().trim();
    if (!key || !module || typeof module.localCode !== 'function') return;
    globalThis.EVERSHELF_MED_MODULES[key] = module;
}

/** The module of the ACTIVE country (the schema says which), or null. */
function _medCountryModule() {
    const schema = _medStatus && _medStatus.schema;
    const slug = schema && schema.module ? String(schema.module).toLowerCase() : '';
    if (!slug) return null;
    return globalThis.EVERSHELF_MED_MODULES[slug] || null;
}

/** Strip the decoration a label or a scanner adds around a code. */
function _medCompactCode(code) {
    return String(code || '').toUpperCase().trim().replace(/[\s\-._/]+/g, '');
}

/**
 * The GS1 element string of a 2D DataMatrix (EU FMD), parsed into its AIs.
 *
 * Country-neutral: it knows the standard, not what a country means by a number. A box carries
 * GTIN (`01`), expiry (`17`), batch (`10`) and serial (`21`); AI `71x` is the national
 * reimbursement number, whose CONTENT belongs to the country module.
 *
 * Parsed by walking the string, because the AIs have different lengths: `01`/`02` take 14
 * characters, `11`/`15`/`17` take 6, and a variable-length AI (`10`, `21`, `30`, `37`, `71x`)
 * ends at the GS separator (FNC1, `\x1d`). An AI we do not know with no separator ends the
 * walk; a read that yields no AI at all is not a GS1 element string. Same algorithm as
 * `medicalGs1Parse()` in api/lib/medical.php (two ports, one rule).
 *
 * @returns {{fmd:boolean, ais:Object, gtin:string|null, expiry:string|null, batch:string|null, serial:string|null}}
 */
function _medGs1Parse(read) {
    const out = { fmd: false, ais: {}, gtin: null, expiry: null, batch: null, serial: null };
    let s = String(read || '').trim();
    if (!s) return out;
    s = s.replace(/\\u001d|\\x1d/g, '\x1d');
    const fixed = { '01': 14, '02': 14, '11': 6, '15': 6, '17': 6, '20': 2 };
    const variable = ['10', '21', '30', '37'];
    let pos = 0;
    while (pos < s.length) {
        if (s[pos] === '\x1d') { pos++; continue; }
        const three = s.substr(pos, 3);
        if (/^71\d$/.test(three)) {
            let end = s.indexOf('\x1d', pos + 3);
            if (end === -1) end = s.length;
            const value = s.substring(pos + 3, end);
            if (value) out.ais['71'] = value.toUpperCase();
            pos = end;
            continue;
        }
        const two = s.substr(pos, 2);
        if (Object.prototype.hasOwnProperty.call(fixed, two)) {
            const value = s.substr(pos + 2, fixed[two]);
            if (value.length < fixed[two]) break;
            out.ais[two] = value;
            pos += 2 + fixed[two];
            continue;
        }
        if (variable.indexOf(two) !== -1) {
            let end = s.indexOf('\x1d', pos + 2);
            if (end === -1) end = s.length;
            const value = s.substring(pos + 2, end);
            if (value) out.ais[two] = value;
            pos = end;
            continue;
        }
        break;
    }
    if (!Object.keys(out.ais).length) return out;
    out.gtin = out.ais['01'] || null;
    out.expiry = out.ais['17'] || null;
    out.batch = out.ais['10'] || null;
    out.serial = out.ais['21'] || null;
    out.fmd = out.gtin !== null || out.ais['71'] !== undefined || out.expiry !== null;
    return out;
}

/**
 * Is this read one of the DRUG CODES of the active country? Answered by its module.
 *
 * @returns {{code:string,type:string,label_key:string,aic?:string}|null}
 */
function _medLocalCode(code) {
    const module = _medCountryModule();
    if (!module) return null;
    let result = null;
    try { result = module.localCode(String(code || '')); } catch (e) { result = null; }
    return (result && result.code) ? result : null;
}


/**
 * Is this read one of the PACKAGING codes the schema tells us to throw away?
 *
 * A packaging code (a label of the production line, a lot, a serial) says nothing about which
 * drug the box contains. The schema lists them under `ignored` with the action to take instead
 * (`ocr`: read the drug code printed on the label).
 */
function _medCodeIgnored(code) {
    const schema = _medStatus.schema;
    const c = _medCompactCode(code);
    if (!schema || !c || !Array.isArray(schema.ignored)) return false;
    // The country's own drug code is never a packaging code.
    if (_medLocalCode(c)) return false;
    for (const def of schema.ignored) {
        if (!def || !def.pattern) continue;
        let re = null;
        try { re = new RegExp(def.pattern); } catch (e) { re = null; }
        if (re && re.test(c)) return true;
    }
    return false;
}

/** The label of an ignored code (`medical.code_line`), so the UI can name what it read. */
function _medIgnoredLabelKey(code) {
    const schema = _medStatus.schema;
    const c = _medCompactCode(code);
    if (!schema || !c || !Array.isArray(schema.ignored)) return '';
    if (_medLocalCode(c)) return '';
    for (const def of schema.ignored) {
        if (!def || !def.pattern) continue;
        let re = null;
        try { re = new RegExp(def.pattern); } catch (e) { re = null; }
        if (re && re.test(c)) return String(def.label_key || '');
    }
    return '';
}

/** The shape the label prints (e.g. `A000000000`): the guide frame shows it, from the data. */
function _medOcrSample() {
    const schema = _medStatus && _medStatus.schema;
    const sample = schema && schema.ocr && schema.ocr.sample ? String(schema.ocr.sample) : '';
    return sample;
}

/** The OCR patterns of the country block: what the label prints, written down as data. */
function _medOcrPatterns() {
    const schema = _medStatus.schema;
    const list = schema && schema.ocr && Array.isArray(schema.ocr.patterns) ? schema.ocr.patterns : [];
    return list.map(String).filter(Boolean);
}

/**
 * The drug code inside a page of OCR text.
 *
 * The patterns are DATA (`schema.ocr.patterns`): a country that prints a different number only
 * changes its block in `data/medical_code_schemas.json`. OCR puts spaces between the groups of
 * a printed code (`A 040 313 076`), so every pattern runs twice: on the page as read, and on a
 * copy with the separators removed. Validating the hit is the country module's job
 * (`_medLocalCode`), so this function never decides what a drug code looks like.
 *
 * @returns {string|null} the canonical drug number, if the page carries one
 */
function _medOcrFindAic(text, patterns) {
    const source = String(text || '');
    if (!source) return null;
    const compact = source.replace(/[\s.\-_:]+/g, '');
    const hits = [];
    for (const pattern of (patterns || [])) {
        let re = null;
        try { re = new RegExp(pattern, 'i'); } catch (e) { re = null; }
        if (!re) continue;
        for (const haystack of [source, compact]) {
            const m = re.exec(haystack);
            if (!m) continue;
            const local = _medLocalCode(m[0]);
            if (local && local.aic) return local.aic;
            if (local) hits.push(local.code);
        }
    }
    // A module without an `aic` still answers with a canonical code: return the first hit's key.
    if (hits.length) return hits[0];
    // NO loose fallback: a bare 9-digit run is exactly the pack serial printed under the SSN
    // barcode, and taking it as the drug number is what loaded somebody else's box.
    return null;
}


/**
 * Is this scanned code a medicine pack, for the active country?
 *
 * Country-NEUTRAL: the identifying code comes from the country module (`_medLocalCode()`), the
 * packaging codes from the schema's `ignored` list, and the remaining national formats are
 * plain patterns (`pzn`, `cn`, `ndc`, an EAN where the national code really is one). A country
 * with no schema — or whose module is not registered — claims NOTHING.
 *
 * @returns {{detected:boolean, confidence:'high'|'probable'|'none', type:string, label_key:string, aic:string|null, ignored:boolean}}
 */
function _medDetectCode(code) {
    const out = { detected: false, confidence: 'none', type: '', label_key: '', aic: null, ignored: false };
    const schema = _medStatus.schema;
    if (!schema || !schema.status || schema.status === 'none') return out;
    const c = String(code || '').trim().toUpperCase();
    if (!c) return out;
    const digits = c.replace(/\D/g, '');
    const status = String(schema.status || 'none');
    const confidence = status === 'full' ? 'high' : 'probable';

    // 1. The country's own drug code — only if the schema declares that type, so the data file
    //    stays in charge of what is recognisable.
    const local = _medLocalCode(c);
    if (local) {
        const def = (schema.codes || []).find(d => d && d.type === local.type);
        if (def) {
            return {
                detected: true,
                confidence: confidence,
                type: String(local.type),
                label_key: String(local.label_key || def.label_key || ''),
                aic: local.aic ? String(local.aic) : null,
                ignored: false,
            };
        }
    }

    // 2. A packaging code (a line code of the box): say so, claim nothing.
    if (_medCodeIgnored(c)) {
        return { detected: false, confidence: 'none', type: '', label_key: _medIgnoredLabelKey(c), aic: null, ignored: true };
    }

    // Austrian PZN is embedded in the EAN (908888…): check it before the plain EAN rule.
    for (const def of (schema.codes || [])) {
        if (def && def.type === 'pzn_in_ean' && def.prefix && c.startsWith(String(def.prefix))) {
            return { detected: true, confidence: 'high', type: def.type, label_key: def.label_key || '', aic: null, ignored: false };
        }
    }

    // EAN-13: "ours" only when the GS1 prefix belongs to this country AND the schema declares
    // an EAN as a national code (a country whose number is not an EAN simply omits it).
    if (/^\d{13}$/.test(c)) {
        for (const range of (schema.gs1 || [])) {
            const lo = String((range && range[0]) || '');
            const hi = String((range && range[1]) || lo);
            const prefix = c.slice(0, lo.length);
            if (lo && prefix >= lo && prefix <= hi) {
                const def = (schema.codes || []).find(d => d.type === 'ean13' && Number(d.digits || 13) === 13);
                if (def) {
                    return { detected: true, confidence: confidence, type: 'ean13', label_key: def.label_key || '', aic: null, ignored: false };
                }
            }
        }
        return out;
    }

    // National short codes.
    for (const def of (schema.codes || [])) {
        if (def.type === 'ean13' || def.type === 'pzn_in_ean') continue;
        if (!_medCodeMatches(def, c, digits)) continue;
        return { detected: true, confidence: confidence, type: def.type, label_key: def.label_key || '', aic: null, ignored: false };
    }
    return out;
}

/**
 * Does this read match ONE code definition of the country schema?
 *
 * The `pattern` is matched against the whole code exactly as the box prints it, letters
 * included — that is the only way to tell a Laetus Pharmacode (`123456`) from the lot/serial
 * fragment of an EU FMD DataMatrix (`16G87N`). A definition without a pattern falls back to its
 * digit count, so a schema written before the patterns still works; the numeric bounds
 * (Pharmacode 3…131070) always apply.
 *
 * @param {Object} def one entry of schema.codes
 * @param {string} code the whole read, upper-cased
 * @param {string} digits the same read without letters
 * @returns {boolean}
 */
function _medCodeMatches(def, code, digits) {
    if (!def) return false;
    if (def.pattern) {
        let re = null;
        try { re = new RegExp(def.pattern); } catch (e) { re = null; }
        if (!re || !re.test(code)) return false;
    } else {
        const wanted = def.digits;
        if (Array.isArray(wanted)) {
            if (!wanted.map(Number).includes(digits.length)) return false;
        } else if (typeof wanted === 'number' && wanted > 0) {
            if (digits.length !== wanted) return false;
        } else if (def.type !== 'pharmacode') {
            return false;
        }
    }
    if (def.type === 'pharmacode' || def.min !== undefined || def.max !== undefined) {
        if (!/^\d+$/.test(code)) return false;
        const min = Number(def.min === undefined ? 3 : def.min);
        const max = Number(def.max === undefined ? 131070 : def.max);
        const n = Number(code);
        if (!(n >= min && n <= max)) return false;
    }
    return true;
}
