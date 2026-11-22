/**
 * M+ 3D – brukergrensesnitt for Base 10.
 *
 * Kontrollene bygges fra API-skjemaet (app/config/models/base10_blocks.php).
 * Ved hver endring lager serveren én STL per valgt objekttype (bufret per
 * kloss), og visningen laster bare klossene som er nye. Tallbyggeren er bare
 * en visning: den bruker de samme STL-filene og lager ingen nye.
 */
import { Base10Viewer } from '../base10-viewer.js';
import {
    API, $, fieldControl, setStatus, setFieldError, clearFieldError, clearAllErrors,
    showMessage, hideMessage, downloadFrom, downloadAll, bindHelpDialog, bindViewButtons, webglMissing,
    fmt, escapeHtml, CHECK_ICON, DOWNLOAD_ICON,
} from '../ui.js';
import { parseNumber, expanded, terms, words, describe, check } from '../place-value.js';
import { initSheets } from '../sheets.js';

const MODEL = 'base10';
const DEBOUNCE_MS = 350;
const ORDER = ['ener', 'tier', 'hundrer', 'tusener'];

const state = {
    schema: null,
    values: {},
    grid: true,
    hollow: true,
    types: new Set(),
    counts: {},
    current: null,
    generating: false,
    requestSeq: 0,
    abort: null,
    valid: false,
    display: { mode: 'set', single: 'tier', number: 243 },
};

let viewer;
let debounceTimer;

// Arkfaner: 3D-visningene og én fane per side i PDF-en.
const sheets = initSheets({
    threeD: { group: '#mode-options' },
    docs: [{ key: 'pdf', request: () => ({ url: `${API}?${query('pdf')}` }) }],
});

init().catch((err) => showMessage('Kunne ikke starte appen: ' + err.message));

async function init() {
    try {
        viewer = new Base10Viewer($('#viewer'));
    } catch {
        webglMissing($('#viewer'));
    }
    const res = await fetch(`${API}?action=schema&model=${MODEL}`);
    if (!res.ok) throw new Error('mangler kontakt med serveren');
    state.schema = await res.json();

    const d = state.schema.defaults;
    for (const key of Object.keys(state.schema.parameters)) state.values[key] = d[key];
    state.grid = d.grid;
    state.hollow = d.hollow;
    state.types = new Set(d.types);
    state.counts = { ...d.counts };

    buildForm();
    bindEvents();
    updateBuilder();
    generate();
}

/* ------------------------------------------------------------------ Skjema */

function buildForm() {
    const boxes = { grid: $('#grid-fields'), thousand: $('#thousand-fields') };
    const main = $('#param-fields');
    main.innerHTML = '';
    Object.values(boxes).forEach((b) => (b.innerHTML = ''));
    for (const [key, spec] of Object.entries(state.schema.parameters)) {
        const el = fieldControl(key, spec, state.values[key], (v) => {
            state.values[key] = v;
            scheduleGenerate();
        });
        (boxes[spec.group] ?? main).appendChild(el);
    }
    $('#grid-on').checked = state.grid;
    $('#hollow-on').checked = state.hollow;

    const list = $('#type-list');
    list.innerHTML = ORDER.map((t) => {
        const s = state.schema.types[t];
        const name = s.names[0][0].toUpperCase() + s.names[0].slice(1);
        return `<div class="type-row" data-field="count_${t}">
            <label class="check type-check">
                <input type="checkbox" data-type="${t}">
                <span class="swatch" style="background:${escapeHtml(s.color.hex)}"></span>
                <span class="type-name">${escapeHtml(name)}</span>
                <span class="type-meta num">verdi ${s.value}</span>
            </label>
            <label class="type-count">
                <span class="visually-hidden">Antall ${escapeHtml(s.names[1])}</span>
                <input type="number" min="0" max="${s.max}" step="1" inputmode="numeric" data-count="${t}">
                <span class="unit">stk</span>
            </label>
            <p class="field-error" data-error-for="count_${t}"></p>
        </div>`;
    }).join('');
    syncTypes();
}

/** Avkrysning, antall og hvilke underfelt som vises. */
function syncTypes() {
    document.querySelectorAll('[data-type]').forEach((c) => (c.checked = state.types.has(c.dataset.type)));
    document.querySelectorAll('[data-count]').forEach((i) => {
        const t = i.dataset.count;
        i.value = state.counts[t];
        i.disabled = !state.types.has(t);
        i.closest('.type-row').classList.toggle('off', !state.types.has(t));
    });
    $('#grid-fields').hidden = !state.grid;
    $('#thousand-box').hidden = !state.types.has('tusener');
    $('#thousand-fields').hidden = !state.hollow;
}

function bindEvents() {
    bindHelpDialog();
    const setActiveView = bindViewButtons(viewer);

    $('#grid-on').addEventListener('change', (e) => {
        state.grid = e.target.checked;
        syncTypes();
        scheduleGenerate();
    });
    $('#hollow-on').addEventListener('change', (e) => {
        state.hollow = e.target.checked;
        syncTypes();
        scheduleGenerate();
    });
    $('#type-list').addEventListener('change', (e) => {
        const t = e.target.dataset.type;
        if (!t) return;
        if (e.target.checked) {
            state.types.add(t);
            if (!(state.counts[t] > 0)) state.counts[t] = 1;
        } else {
            state.types.delete(t);
        }
        clearFieldError('types');
        clearFieldError(`count_${t}`);
        syncTypes();
        updateBuilder();
        scheduleGenerate();
    });
    $('#type-list').addEventListener('input', (e) => {
        const t = e.target.dataset.count;
        if (!t) return;
        const v = e.target.value.trim();
        state.counts[t] = /^\d{1,4}$/.test(v) ? Number(v) : v;
        clearFieldError(`count_${t}`);
        updateBuilder();
        scheduleGenerate();
    });

    $('#mode-options').addEventListener('change', (e) => {
        state.display.mode = e.target.value;
        $('#single-group').hidden = state.display.mode !== 'single';
        $('#builder-group').hidden = state.display.mode !== 'builder';
        updateBuilder();
        viewer?.setDisplay(state.display);
        setActiveView(null);
    });
    $('#single-options').addEventListener('change', (e) => {
        state.display.single = e.target.value;
        viewer?.setDisplay(state.display);
        setActiveView(null);
    });
    $('#number').addEventListener('input', () => {
        updateBuilder();
    });

    $('#print-list').addEventListener('click', (e) => {
        const b = e.target.closest('[data-download]');
        const part = b && state.current?.parts.find((p) => p.id === b.dataset.download);
        if (part) downloadFrom(`${part.stl_url}&download=1`, part.filename, b, updateButtons);
    });
    $('#download-all').addEventListener('click', () =>
        downloadAll(state.current?.parts ?? [], $('#download-all'), updateButtons));
    $('#download-pdf').addEventListener('click', () =>
        downloadFrom(`${API}?${query('pdf')}`, `${baseName()}.pdf`, $('#download-pdf'), updateButtons));
}

/* ------------------------------------------------------------- Tallbygger */

/**
 * Oppdaterer uttrykkene under tallfeltet og visningen. Mangler en objekttype
 * som tallet trenger, vises en melding og ingen klosser – aldri et ufullstendig tall.
 */
function updateBuilder() {
    const n = parseNumber($('#number').value);
    const note = $('#viewer-note');
    const inBuilder = state.display.mode === 'builder';
    if (n === null) {
        $('#number-sum').textContent = '';
        $('#number-terms').textContent = '';
        $('#number-words').textContent = 'Skriv et helt tall fra 0 til 9999.';
        $('#builder-group').classList.add('invalid');
        showNote(inBuilder ? 'Skriv et helt tall fra 0 til 9999.' : null);
        state.display.number = -1;
        viewer?.setDisplay({ number: -1 });
        return;
    }
    $('#builder-group').classList.remove('invalid');
    $('#number-sum').textContent = expanded(n);
    $('#number-terms').textContent = terms(n);
    $('#number-words').textContent = `${words(n)} · ${describe(n)}`;

    // Sjekk mot det som faktisk er generert (samme typer som i visningen).
    const types = state.current ? state.current.parts.map((p) => p.key) : [...state.types];
    const counts = state.current ? Object.fromEntries(state.current.parts.map((p) => [p.key, p.count])) : state.counts;
    const { missing, short } = check(n, types, counts);
    state.display.number = missing.length ? -1 : n;
    viewer?.setDisplay({ number: state.display.number });

    if (!inBuilder) {
        showNote(null);
    } else if (missing.length) {
        const names = missing.map((m) => `${m.need} ${m.need === 1 ? m.one : m.many}`);
        const off = missing.map((m) => m.one[0].toUpperCase() + m.one.slice(1));
        showNote(`Tallet ${n} trenger ${names.join(' og ')}, men ${off.join(' og ').toLowerCase()} er ikke valgt i settet. `
            + `Slå på ${off.join(' og ')} i panelet for å vise tallet.`, 'error');
    } else if (n === 0) {
        showNote('Tallet 0 har ingen klosser.');
    } else if (short.length) {
        const s = short.map((m) => `${m.have} ${m.have === 1 ? m.one : m.many} (trenger ${m.need})`);
        showNote(`Settet har bare ${s.join(' og ')}. Visningen er riktig, men det trengs flere klosser for å bygge tallet.`, 'warn');
    } else {
        showNote(null);
    }
    note.dataset.number = String(n);
}

function showNote(text, kind = 'info') {
    const el = $('#viewer-note');
    el.hidden = !text;
    el.textContent = text || '';
    el.dataset.kind = kind;
}

/* ------------------------------------------------------------- Generering */

function requestBody() {
    const body = { ...state.values, grid: state.grid, hollow: state.hollow, types: ORDER.filter((t) => state.types.has(t)) };
    for (const t of ORDER) body[`count_${t}`] = state.types.has(t) ? state.counts[t] : 0;
    return body;
}

function query(action) {
    const q = new URLSearchParams({ action, model: MODEL, download: '1' });
    for (const [k, v] of Object.entries(requestBody())) {
        q.set(k, Array.isArray(v) ? v.join(',') : typeof v === 'boolean' ? (v ? '1' : '0') : String(v));
    }
    return q;
}

function baseName() {
    return `base10-${String(state.current?.params.unit ?? state.values.unit).replace('.', '_')}mm`;
}

function scheduleGenerate() {
    state.valid = false;
    updateButtons();
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(generate, DEBOUNCE_MS);
}

async function generate() {
    const seq = ++state.requestSeq;
    state.abort?.abort();
    state.abort = new AbortController();
    state.generating = true;
    const n = state.types.size;
    setStatus(`Lager ${n} ${n === 1 ? 'kloss' : 'klosser'} …`, true);
    updateButtons();

    try {
        const res = await fetch(`${API}?action=generate&model=${MODEL}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(requestBody()),
            signal: state.abort.signal,
        });
        const data = await res.json().catch(() => ({ ok: false, error: 'Ugyldig svar fra serveren.' }));
        if (seq !== state.requestSeq) return;

        clearAllErrors();
        if (!data.ok) {
            state.valid = false;
            if (data.errors) {
                data.errors.forEach((e) => setFieldError(e.field, e.message));
                showMessage('Noen valg passer ikke. Se feilmeldingen ved kontrollen.');
            } else {
                showMessage(data.error || 'Genereringen mislyktes.');
            }
            setStatus('Ingen gyldig modell', false);
            $('#model-info').innerHTML = '<span class="invalid">Ingen gyldig modell for disse valgene. Visningen viser forrige modell.</span>';
            return;
        }

        // Hent bare klosser som er nye siden sist.
        const pieces = await Promise.all(data.parts.map(async (p) => {
            const piece = { type: p.key, id: p.id, info: p.info };
            if (viewer?.parts.get(p.key)?.id !== p.id) {
                const r = await fetch(p.stl_url, { signal: state.abort.signal });
                if (!r.ok) throw new Error('Kunne ikke hente STL-filen.');
                piece.buffer = await r.arrayBuffer();
            }
            return piece;
        }));
        if (seq !== state.requestSeq) return;

        state.current = data;
        state.valid = true;
        buildSingleOptions(data);
        viewer?.setParts(pieces);
        viewer?.setDisplay(state.display);
        updateBuilder();
        hideMessage();
        if (data.warnings?.length) showMessage(data.warnings.join(' '), 'warn');
        updateInfo(data);
        renderPrintList(data);
        setStatus(null);
    } catch (err) {
        if (err.name === 'AbortError') return;
        state.valid = false;
        showMessage('Kontakten med serveren ble brutt. Prøv igjen.');
        setStatus('Feil', false);
    } finally {
        if (seq === state.requestSeq) {
            state.generating = false;
            updateButtons();
        }
    }
}

/* ---------------------------------------------------------------- Visning */

function buildSingleOptions(data) {
    const keys = data.parts.map((p) => p.key);
    if (!keys.includes(state.display.single)) state.display.single = keys.includes('tier') ? 'tier' : keys[0];
    $('#single-options').innerHTML = data.parts.map((p) =>
        `<label><span class="swatch" style="background:${escapeHtml(p.info.color)}"></span>` +
        `<input type="radio" name="single" value="${escapeHtml(p.key)}"${p.key === state.display.single ? ' checked' : ''}> ` +
        `${escapeHtml(cap(p.info.name))}</label>`).join('');
}

function updateInfo(data) {
    const m = data.model;
    const ok = data.parts.every((p) => p.check.ok);
    $('#model-info').innerHTML =
        `<span class="num">${m.total_pieces}</span> klosser i <span class="num">${data.parts.length}</span> ` +
        `${data.parts.length === 1 ? 'STL-fil' : 'STL-filer'} · enhet <span class="num">${fmt(data.params.unit)} mm</span> · ` +
        (ok ? `<span class="valid">${CHECK_ICON}alle er lukkede og målriktige</span>`
            : '<span class="invalid">kontrollen feilet</span>');
}

function renderPrintList(data) {
    $('#print-list tbody').innerHTML = data.parts.map((p) => {
        const i = p.info;
        const size = i.size_mm.map(fmt).join(' × ');
        return `<tr>
            <th scope="row"><span class="swatch" style="background:${escapeHtml(i.color)}"></span>
                <span class="frac">${escapeHtml(cap(i.name))}</span>
                <span class="muted">${escapeHtml(size)} mm${i.hollow ? ' · hul' : ''}</span></th>
            <td class="num count">${p.count} stk</td>
            <td><button type="button" class="icon-btn" data-download="${escapeHtml(p.id)}"
                aria-label="Last ned ${escapeHtml(p.filename)}">${DOWNLOAD_ICON}<span>STL</span></button></td>
        </tr>`;
    }).join('');
    const m = data.model;
    $('#print-total').textContent = `${m.total_pieces} klosser · ca. ${grams(m.material_g.print)} PLA`;
    $('#material-hint').textContent = `Filament er et overslag for 2 vegger og 15 % fyll. Med settet kan elevene bygge alle tall `
        + `opp til ${m.largest_number}. Fargene finnes bare i forhåndsvisningen – skriv gjerne ut hver type i sin egen farge.`;
    $('#all-sub').textContent = `${data.parts.length} ${data.parts.length === 1 ? 'STL-fil' : 'STL-filer'}, én per klosstype`;
}

function updateButtons() {
    const ready = state.valid && !state.generating && !!state.current;
    $('#download-all').disabled = !ready;
    $('#download-pdf').disabled = !ready;
    document.querySelectorAll('#print-list [data-download]').forEach((b) => (b.disabled = !ready));
    sheets.refresh();
}

function grams(g) {
    return g >= 1000 ? `${fmt(Math.round(g / 10) / 100)} kg` : `${fmt(g)} g`;
}

function cap(s) {
    return s[0].toUpperCase() + s.slice(1);
}
