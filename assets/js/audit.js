/**
 * Quick audit mode (#285) — stocktaking one card at a time.
 *
 * An inventory drifts, and correcting it one product at a time is too slow to
 * ever be done. This is a focused UI over `inventory_update`: pick a scope, then
 * answer three big buttons for each item (there is / almost out / finished).
 * The answers are queued and flushed every AUDIT_MODE_BATCH_SIZE items and at
 * the end, so a broken connection costs at most one batch, and the summary
 * counts what was changed. Nothing is written for "there is": the row is right.
 *
 * Kiosk friendly: no keyboard required, arrow keys as a bonus, big targets.
 */

let _audit = null;
/** Flush every N answered items; the .env value can lower or raise it. */
let _auditBatchSize = 5;

/** Clamp what the settings page sends (1..50); called from loadSettings(). */
function _auditSetBatchSize(value) {
    const n = Math.round(Number(value) || 5);
    _auditBatchSize = Math.max(1, Math.min(50, n));
}

/** The quantity that means "still here, but nearly gone" for this row's unit. */
function _auditAlmostOutQty(item) {
    const unit = String(item.unit || 'pz');
    if (unit === 'pz') return 1;
    if (unit === 'conf') return 0.5;
    if (unit === 'kg' || unit === 'l') return 0.1;
    return 100; // g / ml
}

function _auditItems() {
    return _inventoryVisibleItems(_allInventoryCache || currentInventory || []);
}

/** Entry point: choose the scope (everything or one location). */
function _auditStart() {
    const items = _auditItems();
    if (!items.length) {
        showToast(t('audit.empty'), 'info');
        return;
    }
    const byLoc = {};
    items.forEach(it => { byLoc[it.location] = (byLoc[it.location] || 0) + 1; });
    const scopes = [`<button type="button" class="audit-scope" onclick="_auditBegin(null)">${escapeHtml(t('audit.scope_all'))} (${items.length})</button>`];
    Object.keys(byLoc).forEach(loc => {
        const info = LOCATIONS[loc] || { icon: '📦', label: loc };
        scopes.push(`<button type="button" class="audit-scope" onclick="_auditBegin('${escapeHtml(loc)}')">${info.icon} ${escapeHtml(info.label || loc)} (${byLoc[loc]})</button>`);
    });
    const el = document.getElementById('audit-overlay');
    if (!el) return;
    el.hidden = false;
    el.innerHTML = `<div class="audit-panel">
        <div class="audit-head">
            <h3>${escapeHtml(t('audit.title'))}</h3>
            <button type="button" class="audit-close" onclick="_auditStop()" aria-label="${escapeHtml(t('audit.close'))}">✕</button>
        </div>
        <p class="audit-hint">${escapeHtml(t('audit.scope_hint'))}</p>
        <div class="audit-scopes">${scopes.join('')}</div>
    </div>`;
}

function _auditBegin(location) {
    _audit = {
        items: _auditItems().filter(it => !location || it.location === location),
        index: 0,
        queue: [],
        errors: 0,
        counts: { there: 0, almost: 0, finished: 0, skipped: 0 },
    };
    if (!_audit.items.length) {
        _audit = null;
        _auditStop();
        return;
    }
    _auditRender();
}

function _auditRender() {
    const el = document.getElementById('audit-overlay');
    if (!el) return;
    if (!_audit) {
        el.hidden = true;
        el.innerHTML = '';
        return;
    }
    const it = _audit.items[_audit.index];
    if (!it) {
        _auditFinish();
        return;
    }
    const total = _audit.items.length;
    const done = _audit.index;
    const locInfo = LOCATIONS[it.location] || { icon: '📦', label: it.location };
    const qty = stripHtml(formatQuantity(it.quantity, it.unit, it.default_quantity, it.package_unit));
    el.hidden = false;
    el.innerHTML = `<div class="audit-panel" role="dialog" aria-modal="true">
        <div class="audit-progress"><span style="width:${Math.round((done / total) * 100)}%"></span></div>
        <div class="audit-head">
            <h3>${escapeHtml(it.display_name || it.name)}</h3>
            <button type="button" class="audit-close" onclick="_auditStop()" aria-label="${escapeHtml(t('audit.close'))}">✕</button>
        </div>
        <p class="audit-meta">${locInfo.icon} ${escapeHtml(locInfo.label || it.location)} · ${escapeHtml(qty)}${it.brand ? ' · ' + escapeHtml(it.brand) : ''}</p>
        <p class="audit-hint">${escapeHtml(t('audit.question'))} <b>${done + 1}/${total}</b></p>
        <div class="audit-answers">
            <button type="button" class="audit-answer audit-there" onclick="_auditAnswer('there')"><span aria-hidden="true">✓</span> ${escapeHtml(t('audit.there_is'))}</button>
            <button type="button" class="audit-answer audit-almost" onclick="_auditAnswer('almost')"><span aria-hidden="true">◐</span> ${escapeHtml(t('audit.almost_out'))}</button>
            <button type="button" class="audit-answer audit-finished" onclick="_auditAnswer('finished')"><span aria-hidden="true">✗</span> ${escapeHtml(t('audit.finished'))}</button>
        </div>
        <button type="button" class="audit-skip" onclick="_auditAnswer('skip')">${escapeHtml(t('audit.skip'))} →</button>
    </div>`;
}

/** Register one answer and move on. "There is" writes nothing: the row is right. */
function _auditAnswer(kind) {
    if (!_audit) return;
    const it = _audit.items[_audit.index];
    if (!it) return;
    if (kind === 'almost' || kind === 'finished') {
        _audit.queue.push({
            id: it.id,
            quantity: kind === 'finished' ? 0 : _auditAlmostOutQty(it),
            label: it.display_name || it.name,
        });
        _audit.counts[kind]++;
        if (_audit.queue.length >= _auditBatchSize) _auditFlush();
    } else if (kind === 'there') {
        _audit.counts.there++;
    } else {
        _audit.counts.skipped++;
    }
    _audit.index++;
    _auditRender();
}

/** Apply the queued answers; a failure is counted, never swallowed silently. */
async function _auditFlush() {
    const state = _audit;
    if (!state || !state.queue.length) return;
    const batch = state.queue.splice(0, state.queue.length);
    for (const row of batch) {
        try {
            const res = await api('inventory_update', {}, 'POST', { id: row.id, quantity: row.quantity });
            if (!res || res.success === false) state.errors++;
        } catch (_e) {
            state.errors++;
        }
    }
}

async function _auditFinish() {
    const state = _audit;
    await _auditFlush();
    _audit = null;
    const el = document.getElementById('audit-overlay');
    if (!el) return;
    const c = state ? state.counts : { there: 0, almost: 0, finished: 0, skipped: 0 };
    const errors = state ? state.errors : 0;
    el.hidden = false;
    el.innerHTML = `<div class="audit-panel">
        <div class="audit-head">
            <h3>${escapeHtml(t('audit.summary_title'))}</h3>
            <button type="button" class="audit-close" onclick="_auditStop(true)" aria-label="${escapeHtml(t('audit.close'))}">✕</button>
        </div>
        <p class="audit-summary">${t('audit.summary_line')
            .replace('{there}', c.there)
            .replace('{almost}', c.almost)
            .replace('{finished}', c.finished)
            .replace('{skipped}', c.skipped)}</p>
        ${errors ? `<p class="audit-hint">${escapeHtml(t('audit.summary_errors').replace('{n}', errors))}</p>` : ''}
        <button type="button" class="audit-done" onclick="_auditStop(true)">${escapeHtml(t('audit.done'))}</button>
    </div>`;
}

/** Leave the overlay. `reload` refreshes the pantry with what was just written. */
function _auditStop(reload = false) {
    if (_audit) {
        _auditFlush();
        _audit = null;
    }
    const el = document.getElementById('audit-overlay');
    if (el) {
        el.hidden = true;
        el.innerHTML = '';
    }
    if (reload) loadInventory();
}

// Arrow keys for a tablet with a keyboard attached; a kiosk needs none of this.
document.addEventListener('keydown', (ev) => {
    if (!_audit) return;
    if (ev.key === 'Escape') {
        _auditStop(true);
    } else if (ev.key === 'ArrowRight') {
        ev.preventDefault();
        _auditAnswer('there');
    } else if (ev.key === 'ArrowDown') {
        ev.preventDefault();
        _auditAnswer('almost');
    } else if (ev.key === 'ArrowLeft') {
        ev.preventDefault();
        _auditAnswer('finished');
    }
});
