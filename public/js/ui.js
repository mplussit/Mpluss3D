/**
 * Felles hjelpefunksjoner for modellsidene i M+ 3D: skjemakontroller,
 * statuslinje, meldinger, feil ved feltene, nedlasting og hjelpedialog.
 */

export const API = 'api.php';

export const $ = (sel) => document.querySelector(sel);

/** Glidebryter med verdi og enhet. onInput(verdi) kalles ved hver endring. */
export function fieldControl(key, spec, value, onInput) {
    const wrap = document.createElement('div');
    wrap.className = 'field';
    wrap.dataset.field = key;
    const id = `f-${key}`;
    wrap.innerHTML = `
        <label for="${id}" class="field-label">${escapeHtml(spec.label)}
            <span class="field-value"><output id="${id}-out"></output> <span class="unit">${escapeHtml(spec.unit)}</span></span></label>
        <input type="range" id="${id}" min="${spec.min}" max="${spec.max}" step="${spec.step}">
        ${spec.help ? `<p class="field-help">${escapeHtml(spec.help)}</p>` : ''}
        <p class="field-error" data-error-for="${key}"></p>`;
    const input = wrap.querySelector('input');
    input.value = value;
    wrap.querySelector('output').textContent = fmt(value);
    input.addEventListener('input', () => {
        const v = parseFloat(input.value);
        wrap.querySelector('output').textContent = fmt(v);
        onInput(v);
    });
    return wrap;
}

export function setStatus(text, busy = false) {
    const el = $('#viewer-status');
    el.hidden = !text;
    el.textContent = text || '';
    el.classList.toggle('busy', busy);
}

export function setFieldError(field, message) {
    const el = document.querySelector(`[data-error-for="${field}"]`);
    if (el && !el.textContent.includes(message)) el.textContent = (el.textContent ? el.textContent + ' ' : '') + message;
    document.querySelector(`[data-field="${field}"]`)?.classList.add('invalid');
}

export function clearFieldError(field) {
    const el = document.querySelector(`[data-error-for="${field}"]`);
    if (el) el.textContent = '';
    document.querySelector(`[data-field="${field}"]`)?.classList.remove('invalid');
}

export function clearAllErrors() {
    document.querySelectorAll('[data-error-for]').forEach((el) => (el.textContent = ''));
    document.querySelectorAll('.field.invalid').forEach((el) => el.classList.remove('invalid'));
}

export function showMessage(text, kind = 'error') {
    const el = $('#message');
    el.textContent = text;
    el.className = 'message' + (kind === 'warn' ? ' warn' : '');
    el.hidden = false;
}

export function hideMessage() {
    $('#message').hidden = true;
}

/** Grønn hake + tekst (gyldig modell). */
export const CHECK_ICON = '<svg width="16" height="16" viewBox="0 0 16 16" aria-hidden="true"><path d="M3 8.5l3.2 3L13 4.5" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>';

export const DOWNLOAD_ICON = '<svg width="16" height="16" viewBox="0 0 16 16" aria-hidden="true"><path d="M8 2v8M4.5 6.8L8 10.3l3.5-3.5M3 13.5h10" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';

/**
 * Henter en fil og lagrer den med gitt navn. Feil fra API-et (JSON) vises
 * som melding og ved feltene. Knappen er deaktivert mens filen hentes;
 * afterwards() kalles til slutt (for å sette knappene riktig igjen). Med body
 * sendes parameterne som JSON (POST).
 */
export async function downloadFrom(url, filename, button, afterwards, body = null) {
    if (button) button.disabled = true;
    try {
        const res = body
            ? await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
            : await fetch(url);
        const type = res.headers.get('Content-Type') || '';
        if (!res.ok || type.includes('application/json')) {
            const data = await res.json().catch(() => ({}));
            if (data.errors) data.errors.forEach((e) => setFieldError(e.field, e.message));
            showMessage(data.error || 'Nedlastingen mislyktes.');
            return;
        }
        const blob = await res.blob();
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        a.remove();
        setTimeout(() => URL.revokeObjectURL(a.href), 10000);
    } catch {
        showMessage('Nedlastingen mislyktes. Sjekk nettforbindelsen og prøv igjen.');
    } finally {
        afterwards?.();
    }
}

/**
 * Laster ned alle STL-filene, én vanlig .stl-fil per del (ingen ZIP).
 * Nettleseren kan spørre én gang om siden får laste ned flere filer.
 */
export async function downloadAll(parts, button, afterwards) {
    if (button) button.disabled = true;
    try {
        for (const p of parts) {
            await downloadFrom(`${p.stl_url}&download=1`, p.filename, null, null);
            await new Promise((r) => setTimeout(r, 400));   // litt pause, så nettleseren tar alle
        }
    } finally {
        afterwards?.();
    }
}

/** Hjelpedialogen («Hva er STL og PDF?»). */
export function bindHelpDialog() {
    const dialog = $('#help-dialog');
    if (!dialog) return;
    $('#help-open')?.addEventListener('click', () => dialog.showModal());
    dialog.addEventListener('click', (e) => { if (e.target === dialog) dialog.close(); });
}

/** Visningsknappene inne i 3D-visningen. Den aktive er svart. */
export function bindViewButtons(viewer) {
    const set = (name) => document.querySelectorAll('[data-view]').forEach((b) =>
        b.setAttribute('aria-pressed', String(b.dataset.view === name)));
    document.querySelectorAll('[data-view]').forEach((b) =>
        b.addEventListener('click', () => {
            viewer?.setView(b.dataset.view);
            set(b.dataset.view);
        }));
    if (viewer) viewer.onUserInteract = () => set(null);
    return set;
}

export function webglMissing(container) {
    container.innerHTML = '<p class="muted" style="padding:20px">3D-visning er ikke tilgjengelig i denne nettleseren (WebGL mangler). Du kan fortsatt laste ned filene.</p>';
}

export function fmt(n) {
    return Number(n).toLocaleString('nb-NO', { maximumFractionDigits: 2 });
}

export function escapeHtml(s) {
    return String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}
