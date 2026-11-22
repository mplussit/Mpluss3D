/**
 * M+ 3D – brukergrensesnitt for tallstavene.
 *
 * Kontrollene bygges fra API-skjemaet (app/config/models/number_rods.php).
 * Ved hver endring lager serveren én STL per valgt stavlengde (bufret per
 * stav), og visningen laster bare stavene som er nye. Fargene og
 * tallkombinasjonene er bare visning: de lager ingen nye filer.
 */
import { RodsViewer } from '../rods-viewer.js';
import {
    API, $, fieldControl, setStatus, setFieldError, clearFieldError, clearAllErrors,
    showMessage, hideMessage, downloadFrom, downloadAll, bindHelpDialog, bindViewButtons, webglMissing,
    fmt, escapeHtml, CHECK_ICON, DOWNLOAD_ICON,
} from '../ui.js';
import { partitions, showcase, sumText, parseTarget } from '../rod-combos.js';
import { initSheets } from '../sheets.js';

const MODEL = 'rods';
const DEBOUNCE_MS = 350;
const MAX_COMBOS = 6;
const LENGTHS = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10];

const state = {
    schema: null,
    values: {},
    grid: false,
    labels: false,
    lengths: new Set(),
    counts: {},
    colorMode: 'classic',
    color: '#f36d21',
    colors: {},
    current: null,
    generating: false,
    requestSeq: 0,
    abort: null,
    valid: false,
    display: { mode: 'stairs', single: 5, target: 10, combos: [] },
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
        viewer = new RodsViewer($('#viewer'));
    } catch {
        webglMissing($('#viewer'));
    }
    const res = await fetch(`${API}?action=schema&model=${MODEL}`);
    if (!res.ok) throw new Error('mangler kontakt med serveren');
    state.schema = await res.json();

    const d = state.schema.defaults;
    for (const key of Object.keys(state.schema.parameters)) state.values[key] = d[key];
    state.grid = d.grid;
    state.labels = d.labels;
    state.lengths = new Set(d.lengths);
    state.counts = { ...d.counts };
    state.colorMode = d.color_mode;
    state.color = d.color;
    state.colors = { ...d.colors };

    buildForm();
    bindEvents();
    generate();
}

/* ------------------------------------------------------------------ Skjema */

function buildForm() {
    const boxes = { grid: $('#grid-fields'), label: $('#label-fields') };
    const main = $('#param-fields');
    main.innerHTML = '';
    Object.values(boxes).forEach((b) => (b.innerHTML = ''));
    for (const [key, spec] of Object.entries(state.schema.parameters)) {
        const el = fieldControl(key, spec, state.values[key], (v) => {
            state.values[key] = v;
            if (key === 'unit') syncRows();
            scheduleGenerate();
        });
        (boxes[spec.group] ?? main).appendChild(el);
    }
    $('#grid-on').checked = state.grid;
    $('#labels-on').checked = state.labels;

    const modes = state.schema.colors.modes;
    $('#color-modes').innerHTML = Object.entries(modes).map(([k, label]) =>
        `<label><input type="radio" name="color-mode" value="${escapeHtml(k)}"${k === state.colorMode ? ' checked' : ''}> ${escapeHtml(label)}</label>`).join('');
    $('#single-color').value = state.color;

    $('#rod-list').innerHTML = LENGTHS.map((n) => `<div class="type-row rod-row" data-field="count_${n}" data-length="${n}">
            <label class="check type-check">
                <input type="checkbox" data-rod="${n}">
                <span class="rod-swatch" style="--n:${n}"></span>
                <span class="type-name">Stav ${n}</span>
                <span class="type-meta num" data-size="${n}"></span>
            </label>
            <span class="rod-inputs">
                <input type="color" class="rod-color" data-color="${n}" aria-label="Farge for stav ${n}" hidden>
                <label class="type-count">
                    <span class="visually-hidden">Antall staver med lengde ${n}</span>
                    <input type="number" min="0" max="${state.schema.lengths.count_max}" step="1" inputmode="numeric" data-count="${n}">
                    <span class="unit">stk</span>
                </label>
            </span>
            <p class="field-error" data-error-for="count_${n}"></p>
            <p class="field-error" data-error-for="color_${n}"></p>
        </div>`).join('');
    syncRows();
}

/** Fargen stav n vises med nå. */
function colorOf(n) {
    if (state.colorMode === 'single') return state.color;
    if (state.colorMode === 'custom') return state.colors[n];
    return state.schema.colors.classic[n].hex;
}

/** Avkrysning, antall, farger, mål og hvilke underfelt som vises. */
function syncRows() {
    const u = state.values.unit;
    for (const n of LENGTHS) {
        const on = state.lengths.has(n);
        const row = document.querySelector(`.rod-row[data-length="${n}"]`);
        row.classList.toggle('off', !on);
        row.querySelector('[data-rod]').checked = on;
        const count = row.querySelector('[data-count]');
        count.value = state.counts[n];
        count.disabled = !on;
        const color = row.querySelector('[data-color]');
        color.hidden = state.colorMode !== 'custom';
        color.value = state.colors[n];
        row.querySelector('.rod-swatch').style.background = colorOf(n);
        row.querySelector('[data-size]').textContent = `${fmt(n * u)} mm`;
    }
    $('#grid-fields').hidden = !state.grid;
    $('#label-fields').hidden = !state.labels;
    $('#single-color-box').hidden = state.colorMode !== 'single';
}

function bindEvents() {
    bindHelpDialog();
    const setActiveView = bindViewButtons(viewer);

    $('#grid-on').addEventListener('change', (e) => {
        state.grid = e.target.checked;
        syncRows();
        scheduleGenerate();
    });
    $('#labels-on').addEventListener('change', (e) => {
        state.labels = e.target.checked;
        syncRows();
        scheduleGenerate();
    });

    $('#rod-list').addEventListener('change', (e) => {
        const n = Number(e.target.dataset.rod);
        if (n) {
            if (e.target.checked) {
                state.lengths.add(n);
                if (!(state.counts[n] > 0)) state.counts[n] = 1;
            } else {
                state.lengths.delete(n);
            }
            clearFieldError('lengths');
            clearFieldError(`count_${n}`);
            syncRows();
            scheduleGenerate();
        }
    });
    $('#rod-list').addEventListener('input', (e) => {
        const c = Number(e.target.dataset.count);
        if (c) {
            const v = e.target.value.trim();
            state.counts[c] = /^\d{1,4}$/.test(v) ? Number(v) : v;
            clearFieldError(`count_${c}`);
            scheduleGenerate();
        }
        const k = Number(e.target.dataset.color);
        if (k) {
            state.colors[k] = e.target.value;
            syncRows();
            scheduleGenerate();
        }
    });
    $('#all-lengths').addEventListener('click', () => {
        for (const n of LENGTHS) {
            state.lengths.add(n);
            if (!(state.counts[n] > 0)) state.counts[n] = 1;
        }
        clearFieldError('lengths');
        syncRows();
        scheduleGenerate();
    });
    $('#count-all').addEventListener('change', (e) => {
        const v = e.target.value.trim();
        if (!/^\d{1,3}$/.test(v) || Number(v) < 1 || Number(v) > state.schema.lengths.count_max) return;
        for (const n of state.lengths) {
            state.counts[n] = Number(v);
            clearFieldError(`count_${n}`);
        }
        syncRows();
        scheduleGenerate();
    });

    $('#color-modes').addEventListener('change', (e) => {
        state.colorMode = e.target.value;
        syncRows();
        scheduleGenerate();
    });
    $('#single-color').addEventListener('input', (e) => {
        state.color = e.target.value;
        syncRows();
        scheduleGenerate();
    });

    $('#mode-options').addEventListener('change', (e) => {
        state.display.mode = e.target.value;
        $('#single-group').hidden = state.display.mode !== 'single';
        $('#combo-group').hidden = state.display.mode !== 'combos';
        updateCombos();
        setActiveView(null);
    });
    $('#single-options').addEventListener('change', (e) => {
        state.display.single = Number(e.target.value);
        viewer?.setDisplay(state.display);
        setActiveView(null);
    });
    $('#target').addEventListener('input', () => updateCombos());

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

/* ------------------------------------------------------- Tallkombinasjoner */

/**
 * Finner kombinasjonene for mållengden med stavene som faktisk er generert
 * (samme lengder og antall som i settet), og viser et variert utvalg.
 */
function updateCombos() {
    const inCombos = state.display.mode === 'combos';
    const t = parseTarget($('#target').value);
    $('#combo-group').classList.toggle('invalid', t === null);
    if (t === null) {
        $('#combo-count').textContent = 'Skriv en lengde fra 2 til 20.';
        $('#combo-list').textContent = '';
        state.display.target = -1;
        state.display.combos = [];
        showNote(inCombos ? 'Skriv en lengde fra 2 til 20.' : null);
        viewer?.setDisplay(state.display);
        return;
    }
    const counts = state.current
        ? Object.fromEntries(state.current.parts.map((p) => [p.info.length, p.count]))
        : Object.fromEntries([...state.lengths].map((n) => [n, Number(state.counts[n]) || 0]));
    const all = partitions(t, counts);
    const shown = showcase(all, MAX_COMBOS);
    state.display.target = t;
    state.display.combos = shown;
    viewer?.setDisplay(state.display);

    $('#combo-count').textContent = !all.length
        ? 'Ingen kombinasjoner med stavene i settet'
        : `${all.length} ${all.length === 1 ? 'måte' : 'måter'} med stavene i settet`
            + (all.length > shown.length ? ` – viser ${shown.length}` : '');
    $('#combo-list').innerHTML = shown.map((p) => `<span>${t} = ${sumText(p)}</span>`).join(' ');
    $('#combo-group').dataset.count = String(all.length);

    if (!inCombos) {
        showNote(null);
    } else if (!all.length) {
        showNote(`Ingen kombinasjoner av to eller flere staver i settet blir ${t} lang. `
            + 'Velg flere lengder eller et større antall i panelet.', 'warn');
    } else {
        showNote(null);
    }
}

function showNote(text, kind = 'info') {
    const el = $('#viewer-note');
    el.hidden = !text;
    el.textContent = text || '';
    el.dataset.kind = kind;
}

/* ------------------------------------------------------------- Generering */

function requestBody() {
    const lengths = LENGTHS.filter((n) => state.lengths.has(n));
    const body = {
        ...state.values, grid: state.grid, labels: state.labels, lengths,
        color_mode: state.colorMode, color: state.color,
    };
    for (const n of LENGTHS) {
        body[`count_${n}`] = state.lengths.has(n) ? state.counts[n] : 0;
        body[`color_${n}`] = state.colors[n];
    }
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
    return `tallstaver-${String(state.current?.params.unit ?? state.values.unit).replace('.', '_')}mm`;
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
    const n = state.lengths.size;
    setStatus(`Lager ${n} ${n === 1 ? 'stav' : 'staver'} …`, true);
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

        // Hent bare staver som er nye siden sist.
        const pieces = await Promise.all(data.parts.map(async (p) => {
            const piece = { length: p.info.length, id: p.id, info: p.info };
            if (viewer?.parts.get(p.info.length)?.id !== p.id) {
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
        updateCombos();             // kombinasjonene for det nye settet før stavene byttes
        viewer?.setParts(pieces);
        hideMessage();
        if (data.warnings?.length) showMessage(data.warnings.join(' '), 'warn');
        updateInfo(data);
        renderPrintList(data);
        setStatus(null);
    } catch (err) {
        if (err.name === 'AbortError') return;
        console.error(err);
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
    const lens = data.parts.map((p) => p.info.length);
    if (!lens.includes(state.display.single)) state.display.single = lens.includes(5) ? 5 : lens[lens.length - 1];
    $('#single-options').innerHTML = data.parts.map((p) =>
        `<label><span class="swatch" style="background:${escapeHtml(p.info.color)}"></span>` +
        `<input type="radio" name="single" value="${p.info.length}"${p.info.length === state.display.single ? ' checked' : ''}> ` +
        `${p.info.length}</label>`).join('');
}

function updateInfo(data) {
    const m = data.model;
    const ok = data.parts.every((p) => p.check.ok);
    $('#model-info').innerHTML =
        `<span class="num">${m.total_pieces}</span> staver i <span class="num">${data.parts.length}</span> ` +
        `${data.parts.length === 1 ? 'STL-fil' : 'STL-filer'} · enhet <span class="num">${fmt(data.params.unit)} mm</span> · ` +
        (ok ? `<span class="valid">${CHECK_ICON}alle er lukkede og målriktige</span>`
            : '<span class="invalid">kontrollen feilet</span>');
}

function renderPrintList(data) {
    $('#print-list tbody').innerHTML = data.parts.map((p) => {
        const i = p.info;
        return `<tr>
            <th scope="row"><span class="rod-swatch" style="--n:${i.length};background:${escapeHtml(i.color)}"></span>
                <span class="frac">Stav ${i.length}</span>
                <span class="muted">${escapeHtml(i.size_mm.map(fmt).join(' × '))} mm · ${escapeHtml(i.color_name)}</span></th>
            <td class="num count">${p.count} stk</td>
            <td><button type="button" class="icon-btn" data-download="${escapeHtml(p.id)}"
                aria-label="Last ned ${escapeHtml(p.filename)}">${DOWNLOAD_ICON}<span>STL</span></button></td>
        </tr>`;
    }).join('');
    const m = data.model;
    $('#print-total').textContent = `${m.total_pieces} staver · ca. ${grams(m.material_g.print)} PLA`;
    $('#material-hint').textContent = `Filament er et overslag for 2 vegger og 15 % fyll. Alle stavene etter hverandre `
        + `blir ${m.total_length} enheter (${fmt(Math.round(m.total_length * data.params.unit) / 10)} cm).`;
    $('#all-sub').textContent = `${data.parts.length} ${data.parts.length === 1 ? 'STL-fil' : 'STL-filer'}, én per stavlengde`;
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
