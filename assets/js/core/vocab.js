/**
 * EverShelf — vocabulary engine (browser side).
 *
 * Product names, genres, brands and plurals are DATA: assets/js/vocab.generated.js,
 * produced from api/vocab/<lang>.json by scripts/gen-vocab-js.php. This file is the
 * algorithm and is a port of api/lib/vocabulary.php — no product word, no genre, no
 * brand, and no regex on vocabulary belongs here. Adding a language = adding a JSON on
 * the server and regenerating; a missing entry never invents anything.
 *
 * Term syntax (one term = one array item in the JSON):
 *   "pane"                a whole word
 *   "pomodor*"            a stem (pomodoro, pomodori, pomodorini…)
 *   "fette biscottate"    consecutive words
 *   "estratto+vaniglia"   all these words, in any order
 */
function _vocabData() {
    const all = (typeof EVERSHELF_VOCAB_ALL !== 'undefined' && EVERSHELF_VOCAB_ALL) ? EVERSHELF_VOCAB_ALL : {};
    const lang = (typeof _currentLang === 'string' && _currentLang) ? _currentLang : 'it';
    return all[lang] || all.it || {};
}

/** True when the character is one the tokenizer keeps INSIDE a word (data, not code). */
function _vocabSeparators() {
    if (!_vocabSeparators._set) {
        const list = String(_vocabData().word_separators || " \t\n\r.,;:!?()[]{}\"'«»…/\\-–—_+*&%#@|");
        const set = new Set();
        Array.from(list).forEach(ch => set.add(ch));
        _vocabSeparators._set = set;
    }
    return _vocabSeparators._set;
}

/** Lowercase + diacritics folded, spaces collapsed (no regex). */
function vocabFold(text) {
    const fold = _vocabData().fold || {};
    let out = String(text == null ? '' : text).trim().toLowerCase();
    let folded = '';
    Array.from(out).forEach(ch => { folded += (fold[ch] !== undefined ? fold[ch] : ch); });
    let collapsed = '';
    let prevSpace = false;
    Array.from(folded).forEach(ch => {
        const isSpace = ch === ' ';
        if (isSpace && prevSpace) return;
        collapsed += ch;
        prevSpace = isSpace;
    });
    return collapsed;
}

/** Words of a name, folded — any script (Latin, accented, Cyrillic, CJK). */
function vocabTokens(text) {
    const seps = _vocabSeparators();
    const tokens = [];
    let cur = '';
    Array.from(vocabFold(text)).forEach(ch => {
        if (ch === ' ' || seps.has(ch)) {
            if (cur !== '') { tokens.push(cur); cur = ''; }
            return;
        }
        cur += ch;
    });
    if (cur !== '') tokens.push(cur);
    return tokens;
}

/** Does an already tokenized name satisfy one term? */
function vocabMatchesTerm(tokens, term) {
    const t = String(term || '').trim();
    if (!t || !tokens.length) return false;
    if (t.includes('+')) {
        return t.split('+').every(part => vocabMatchesTerm(tokens, part));
    }
    const isStem = t.endsWith('*');
    const words = vocabTokens(isStem ? t.slice(0, -1) : t);
    if (!words.length) return false;
    for (let i = 0; i <= tokens.length - words.length; i++) {
        let ok = true;
        for (let j = 0; j < words.length; j++) {
            const token = tokens[i + j] || '';
            const hit = (isStem && j === words.length - 1) ? token.startsWith(words[j]) : token === words[j];
            if (!hit) { ok = false; break; }
        }
        if (ok) return true;
    }
    return false;
}

function vocabMatchesTermsTokens(tokens, terms) {
    return (terms || []).some(term => vocabMatchesTerm(tokens, term));
}

function vocabNameHasAnyTerm(name, terms) {
    return vocabMatchesTermsTokens(vocabTokens(name), terms);
}

/** The local category of a product name: brands first, then the ordered generic terms. */
function vocabCategoryOfName(name, brand = '') {
    const data = _vocabData();
    const tokens = vocabTokens(String(name || '') + ' ' + String(brand || ''));
    if (!tokens.length) return 'altro';
    for (const rule of (data.brands || [])) {
        if (vocabMatchesTermsTokens(tokens, rule.terms)) return rule.cat || 'altro';
    }
    for (const rule of (data.categories || [])) {
        if (vocabMatchesTermsTokens(tokens, rule.terms)) return rule.cat || 'altro';
    }
    return 'altro';
}

/** Does the title carry a pack size ("500 g", "1,5 l")? Format, not vocabulary. */
function vocabHasPackSize(name) {
    const units = _vocabData().pack_size_units || [];
    const tokens = vocabTokens(name);
    for (let i = 0; i < tokens.length - 1; i++) {
        const number = tokens[i].replace(',', '.');
        if (number !== '' && !isNaN(Number(number)) && units.includes(tokens[i + 1])) return true;
    }
    return false;
}

/** Fresh produce this household counts by piece (and not a preparation of it). */
function vocabIsPieceProduce(name) {
    const data = _vocabData();
    if (vocabNameHasAnyTerm(name, data.produce_preparations || []) || vocabHasPackSize(name)) return false;
    return vocabNameHasAnyTerm(name, data.piece_produce || []);
}

/** Fresh produce whose default unit is the piece — the old "classic" subset (data). */
function vocabIsClassicPiece(name) {
    return vocabNameHasAnyTerm(name, _vocabData().classic_piece_defaults || []);
}

/** A preparation of a product: never the fresh article of the same word. */
function vocabLooksProcessed(name) {
    return vocabNameHasAnyTerm(name, _vocabData().processed || []);
}

/** "Insalata di riso" is a prepared dish, not leafy produce. */
function vocabIsPreparedSalad(name, brand = '') {
    const rules = _vocabData().prepared_salad || {};
    const tokens = vocabTokens(name);
    for (const phrase of (rules.phrases || [])) {
        if (vocabMatchesTerm(tokens, (phrase || []).join(' '))) return true;
    }
    for (const pair of (rules.requires || [])) {
        if ((pair || []).every(word => vocabMatchesTerm(tokens, String(word)))) return true;
    }
    if (brand) {
        const brandTokens = vocabTokens(brand);
        for (const word of (rules.brands || [])) {
            if (vocabMatchesTerm(brandTokens, String(word))) return true;
        }
    }
    return false;
}

/** Curated number forms of a word ("Pomodoro"/"Pomodori"), or null for an unknown word. */
function vocabNumberForms(word) {
    if (!vocabNumberForms._index) {
        const index = {};
        const forms = _vocabData().number_forms || {};
        Object.keys(forms).forEach(canonical => {
            const v = forms[canonical];
            if (!Array.isArray(v) || v.length < 2) return;
            [canonical, v[0], v[1]].forEach(key => {
                const fold = vocabFold(String(key));
                if (fold !== '' && !index[fold]) {
                    index[fold] = { singular: v[0], plural: v[1], gender: v[2] || '' };
                }
            });
        });
        vocabNumberForms._index = index;
    }
    const hit = vocabNumberForms._index[vocabFold(word)];
    return hit || null;
}

function vocabAgreementIndex() {
    if (!vocabAgreementIndex._index) {
        const index = {};
        (_vocabData().agreement_forms || []).forEach((group, i) => {
            (group || []).forEach(form => {
                const fold = vocabFold(String(form));
                if (fold !== '') index[fold] = i;
            });
        });
        vocabAgreementIndex._index = index;
    }
    return vocabAgreementIndex._index;
}

/** The same word at another number: curated forms, agreement groups, suffix pairs. */
function vocabSameLemmaWord(a, b) {
    const fa = vocabFold(a);
    const fb = vocabFold(b);
    if (!fa || !fb || fa === fb) return fa === fb && fa !== '';
    const formsA = vocabNumberForms(fa);
    const formsB = vocabNumberForms(fb);
    if (formsA && formsB && formsA.singular === formsB.singular) return true;
    const agreements = vocabAgreementIndex();
    if (agreements[fa] !== undefined && agreements[fb] !== undefined && agreements[fa] === agreements[fb]) return true;
    const minLen = Math.min(Array.from(fa).length, Array.from(fb).length);
    if (minLen < 4) return false;
    const endsWith = (word, suffix) => {
        if (!suffix) return false;
        const w = Array.from(word);
        const s = Array.from(suffix);
        return w.length >= s.length && w.slice(w.length - s.length).join('') === s.join('');
    };
    const drop = (word, suffix) => {
        const w = Array.from(word);
        const s = Array.from(suffix);
        return w.length >= s.length ? w.slice(0, w.length - s.length).join('') : '';
    };
    for (const pair of (_vocabData().plural_pairs || [])) {
        const sa = String(pair[0] || '');
        const sb = String(pair[1] || '');
        if (sa === '' || sb === '') continue;
        if (endsWith(fa, sa) && endsWith(fb, sb) && drop(fa, sa) === drop(fb, sb)) return true;
        if (endsWith(fa, sb) && endsWith(fb, sa) && drop(fa, sb) === drop(fb, sa)) return true;
    }
    return false;
}

/** Two TITLES at another number, word by word ("Uova medie" ↔ "Uovo medio"). */
function vocabSameLemma(a, b) {
    const ta = vocabTokens(a);
    const tb = vocabTokens(b);
    if (!ta.length || ta.length !== tb.length) return false;
    return ta.every((word, i) => vocabSameLemmaWord(word, tb[i]));
}
