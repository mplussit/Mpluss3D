/**
 * M+ 3D – «Regn med Lego».
 *
 * Prosjektet har 1–12 rammer. Panelet viser innstillingene for valgt ramme;
 * stripa under visningen viser alle rammene med regnetegn mellom. Ved hver
 * endring sendes hele prosjektet til serveren, som validerer det, regner ut
 * geometrien og lager én STL per ramme (bufret, så bare endrede rammer lages
 * på nytt). Klossene i visningen er bare en illustrasjon.
 */
import { LegoViewer } from '../lego-viewer.js';
import {
    API, $, setStatus, setFieldError, clearAllErrors, showMessage, hideMessage, downloadFrom,
    bindHelpDialog, bindViewButtons, webglMissing, fmt, escapeHtml, CHECK_ICON,
} from '../ui.js';

const MODEL = 'lego';
const DEBOUNCE_MS = 300;
const NUM_KEYS = { frame: ['wall', 'clearance'], bottom: ['bottom_thickness'], field: ['field_depth', 'text_depth'] };

const state = {
    schema: null,
    frames: [],          // som sendes til serveren; rows er hele rutenettet i redigeringsverktøyet
    selected: 0,
    operatorCards: true,
    current: null,
    stlIds: new Map(),
    valid: false,
    generating: false,
    requestSeq: 0,
    abort: null,
};

let viewer;
let debounceTimer;
let setActiveView = () => {};

init().catch((err) => showMessage('Kunne ikke starte appen: ' + err.message));

async function init() {
    try {
        viewer = new LegoViewer($('#viewer'));
    } catch {
        webglMissing($('#viewer'));
    }
    const res = await fetch(`${API}?action=schema&model=${MODEL}`);
    if (!res.ok) throw new Error('mangler kontakt med serveren');
    state.schema = await res.json();
    state.frames = state.schema.defaults.frames.map(toEditable);
    state.operatorCards = state.schema.defaults.operator_cards;
    buildStatic();
    bindEvents();
    syncForm();
    generate();
}

/** Tallene vises som tekst med desimalkomma, så læreren kan skrive dem selv. */
function toEditable(f) {
    const out = { ...f, rows: [...f.rows] };
    for (const k of Object.values(NUM_KEYS).flat()) out[k] = fmt(f[k]);
    return out;
}

const frame = () => state.frames[state.selected];

/* ------------------------------------------------------------------ Skjema */

function buildStatic() {
    const s = state.schema;
    const mmField = (key) => {
        const spec = s.parameters[key];
        const wrap = document.createElement('div');
        wrap.className = 'field mm-field';
        wrap.dataset.field = key;
        wrap.innerHTML = `
            <label for="f-${key}" class="field-label">${escapeHtml(spec.label)}</label>
            <span class="input-row"><input type="text" id="f-${key}" inputmode="decimal" autocomplete="off" data-num="${key}">
                <span class="unit">mm</span><span class="range muted">${fmt(spec.min)}–${fmt(spec.max)} mm</span></span>
            ${spec.help ? `<p class="field-help">${escapeHtml(spec.help)}</p>` : ''}
            <p class="field-error" data-error-for="${key}"></p>`;
        return wrap;
    };
    for (const [box, keys] of Object.entries(NUM_KEYS)) {
        for (const k of keys) $(`#mm-${box}`).appendChild(mmField(k));
    }
    const seg = (id, name, options) => {
        $(id).innerHTML = Object.entries(options).map(([k, label]) =>
            `<label><input type="radio" name="${name}" value="${escapeHtml(k)}"> ${escapeHtml(label)}</label>`).join('');
    };
    seg('#field-options', 'field', s.choices.field.options);
    seg('#side-options', 'field_side', s.choices.field_side.options);
    seg('#mode-options', 'text_mode', s.choices.text_mode.options);
    seg('#style-options', 'text_style', s.choices.text_style.options);
}

/** Viser innstillingene for valgt ramme i panelet. */
function syncForm() {
    const f = frame();
    $('#panel-frame').textContent = `Ramme ${state.selected + 1} av ${state.frames.length}`;
    $('#grid-cols').value = f.rows[0].length;
    $('#grid-rows').value = f.rows.length;
    for (const k of Object.values(NUM_KEYS).flat()) $(`#f-${k}`).value = f[k];
    $('#bottom-on').checked = f.bottom;
    $('#mm-bottom').hidden = !f.bottom;
    for (const name of ['field', 'field_side', 'text_mode', 'text_style']) {
        const el = document.querySelector(`input[name="${name}"][value="${CSS.escape(f[name])}"]`);
        if (el) el.checked = true;
    }
    const field = f.field;
    $('#field-settings').hidden = field === 'none';
    $('#text-box').hidden = f.text_mode !== 'custom';
    $('#style-box').hidden = field !== 'text';
    $('[data-field="text_depth"]').hidden = field !== 'text';
    $('#copies-box').hidden = field !== 'label';
    document.querySelector('input[name="text_mode"][value="blank"]').closest('label').hidden = field !== 'label';
    $('#f-text').value = f.text;
    $('#f-copies').value = f.copies;
    $('#operator-cards').checked = state.operatorCards;
    renderGrid();
    renderStrip();
}

/* ------------------------------------------------------------ Rutenettet */

function countCells(rows) {
    return rows.reduce((n, r) => n + [...r].filter((c) => c === '1').length, 0);
}

function renderGrid() {
    const rows = frame().rows;
    const g = $('#grid');
    g.style.setProperty('--cols', rows[0].length);
    g.innerHTML = rows.map((row, r) => [...row].map((v, c) =>
        `<button type="button" class="cell${v === '1' ? ' on' : ''}" data-c="${c}" data-r="${r}" role="gridcell"
            aria-pressed="${v === '1'}" aria-label="Rad ${r + 1}, kolonne ${c + 1}"></button>`).join('')).join('');
    updateCount();
}

function updateCount() {
    const n = countCells(frame().rows);
    $('#cell-count').textContent = `Plass til ${n} ${n === 1 ? 'knott' : 'knotter'}`;
}

function setCell(c, r, on) {
    const rows = frame().rows;
    const row = [...rows[r]];
    if ((row[c] === '1') === on) return false;
    row[c] = on ? '1' : '0';
    rows[r] = row.join('');
    const b = document.querySelector(`#grid [data-c="${c}"][data-r="${r}"]`);
    b.classList.toggle('on', on);
    b.setAttribute('aria-pressed', String(on));
    return true;
}

/** Ny størrelse på rutenettet. Var formen et fullt rektangel, fylles det nye også. */
function resizeGrid(cols, rows) {
    const f = frame();
    const full = countCells(f.rows) === f.rows.length * f.rows[0].length;
    const out = [];
    for (let r = 0; r < rows; r++) {
        let s = '';
        for (let c = 0; c < cols; c++) s += f.rows[r]?.[c] ?? (full ? '1' : '0');
        out.push(full ? '1'.repeat(cols) : s);
    }
    f.rows = out;
}

function bindGrid() {
    const g = $('#grid');
    let paint = null;
    g.addEventListener('pointerdown', (e) => {
        const b = e.target.closest('.cell');
        if (!b) return;
        e.preventDefault();
        paint = !b.classList.contains('on');
        setCell(Number(b.dataset.c), Number(b.dataset.r), paint);
        updateCount();
        g.setPointerCapture(e.pointerId);
    });
    g.addEventListener('pointermove', (e) => {
        if (paint === null) return;
        const b = document.elementFromPoint(e.clientX, e.clientY)?.closest('#grid .cell');
        if (b && setCell(Number(b.dataset.c), Number(b.dataset.r), paint)) updateCount();
    });
    const end = () => {
        if (paint === null) return;
        paint = null;
        renderStrip();
        scheduleGenerate();
    };
    g.addEventListener('pointerup', end);
    g.addEventListener('pointercancel', end);
    g.addEventListener('keydown', (e) => {
        const b = e.target.closest('.cell');
        if (!b || (e.key !== ' ' && e.key !== 'Enter')) return;
        e.preventDefault();
        setCell(Number(b.dataset.c), Number(b.dataset.r), !b.classList.contains('on'));
        updateCount();
        renderStrip();
        scheduleGenerate();
    });
}

/* ----------------------------------------------------------- Rammestripa */

function miniShape(rows, size = 44) {
    const C = rows[0].length;
    const R = rows.length;
    const s = size / Math.max(C, R);
    const cells = rows.flatMap((row, r) => [...row].map((v, c) => v === '1'
        ? `<rect x="${c * s}" y="${r * s}" width="${s}" height="${s}"/>` : '')).join('');
    return `<svg width="${C * s}" height="${R * s}" viewBox="0 0 ${C * s} ${R * s}" aria-hidden="true">${cells}</svg>`;
}

function renderStrip() {
    const ops = state.schema.choices.operator.options;
    const invalid = new Set((state.errors ?? []).map((e) => e.frame).filter((i) => i !== undefined));
    $('#frame-strip').innerHTML = state.frames.map((f, i) => {
        const n = countCells(f.rows);
        const card = `<button type="button" class="frame-card${i === state.selected ? ' selected' : ''}${invalid.has(i) ? ' invalid' : ''}"
                data-select="${i}" role="listitem" aria-pressed="${i === state.selected}">
            ${miniShape(f.rows)}<span class="frame-name">Ramme ${i + 1}</span><span class="frame-count num">${n}</span></button>`;
        if (i === state.frames.length - 1) return card;
        const sel = `<select class="op-select" data-op="${i}" aria-label="Regnetegn mellom ramme ${i + 1} og ${i + 2}">${
            Object.entries(ops).map(([k, label]) => `<option value="${escapeHtml(k)}"${k === f.operator ? ' selected' : ''}>${escapeHtml(k === '' ? '·' : label)}</option>`).join('')}</select>`;
        return card + sel;
    }).join('');
    $('#equation').textContent = 'Regnestykket: ' + state.frames.map((f, i) =>
        countCells(f.rows) + (i < state.frames.length - 1 && f.operator ? ` ${f.operator} ` : i < state.frames.length - 1 ? '   ' : '')).join('');
    const max = state.schema.max_frames;
    $('#frame-add').disabled = state.frames.length >= max;
    $('#frame-dup').disabled = state.frames.length >= max;
    $('#frame-del').disabled = state.frames.length <= 1;
}

/* --------------------------------------------------------------- Hendelser */

function bindEvents() {
    bindHelpDialog();
    setActiveView = bindViewButtons(viewer);
    bindGrid();

    const sizeChange = () => {
        const cols = clampInt($('#grid-cols').value, 1, state.schema.grid.max);
        const rows = clampInt($('#grid-rows').value, 1, state.schema.grid.max);
        if (cols === null || rows === null) return;
        resizeGrid(cols, rows);
        renderGrid();
        renderStrip();
        scheduleGenerate();
    };
    $('#grid-cols').addEventListener('change', sizeChange);
    $('#grid-rows').addEventListener('change', sizeChange);
    $('#make-rect').addEventListener('click', () => {
        const f = frame();
        f.rows = f.rows.map((r) => '1'.repeat(r.length));
        renderGrid();
        renderStrip();
        scheduleGenerate(0);
    });

    $('#controls').addEventListener('input', (e) => {
        const k = e.target.dataset.num;
        if (k) {
            frame()[k] = e.target.value;
            scheduleGenerate();
        } else if (e.target.id === 'f-text') {
            frame().text = e.target.value;
            renderStrip();
            scheduleGenerate();
        } else if (e.target.id === 'f-copies') {
            frame().copies = e.target.value;
            scheduleGenerate();
        }
    });
    $('#controls').addEventListener('change', (e) => {
        const t = e.target;
        if (['field', 'field_side', 'text_mode', 'text_style'].includes(t.name)) {
            frame()[t.name] = t.value;
            if (t.name === 'field' && t.value !== 'label' && frame().text_mode === 'blank') frame().text_mode = 'auto';
            syncForm();
            scheduleGenerate(0);
        } else if (t.id === 'bottom-on') {
            frame().bottom = t.checked;
            syncForm();
            scheduleGenerate(0);
        } else if (t.id === 'operator-cards') {
            state.operatorCards = t.checked;
            scheduleGenerate();
        }
    });

    $('#frame-strip').addEventListener('click', (e) => {
        const b = e.target.closest('[data-select]');
        if (!b) return;
        state.selected = Number(b.dataset.select);
        clearAllErrors();
        showErrors();
        syncForm();
        updateViewer();
        updateDims();
        updateButtons();
    });
    $('#frame-strip').addEventListener('change', (e) => {
        const i = e.target.dataset.op;
        if (i === undefined) return;
        state.frames[Number(i)].operator = e.target.value;
        renderStrip();
        scheduleGenerate();
    });
    $('#frame-add').addEventListener('click', () => {
        state.frames.push(toEditable(state.schema.defaults.frames[0]));
        state.selected = state.frames.length - 1;
        afterFramesChanged();
    });
    $('#frame-dup').addEventListener('click', () => {
        const copy = structuredClone(frame());
        state.frames.splice(state.selected + 1, 0, copy);
        state.selected += 1;
        afterFramesChanged();
    });
    $('#frame-del').addEventListener('click', () => {
        if (state.frames.length <= 1) return;
        state.frames.splice(state.selected, 1);
        state.selected = Math.min(state.selected, state.frames.length - 1);
        afterFramesChanged();
    });

    $('#show-options').addEventListener('change', () => {
        updateViewer();
        setActiveView(null);
    });
    $('#show-bricks').addEventListener('change', updateViewer);

    $('#download-stl').addEventListener('click', () => {
        const part = state.current?.parts[state.selected];
        if (part) downloadFrom(`${part.stl_url}&download=1`, part.filename, $('#download-stl'), updateButtons);
    });
    $('#download-zip').addEventListener('click', () =>
        downloadFrom(`${API}?action=zip&model=${MODEL}`, `${archiveName()}.zip`, $('#download-zip'), updateButtons, requestBody()));
    $('#download-pdf').addEventListener('click', () =>
        downloadFrom(`${API}?action=pdf&model=${MODEL}&download=1`, `${archiveName()}-etiketter.pdf`, $('#download-pdf'), updateButtons, requestBody()));
}

function afterFramesChanged() {
    state.stlIds.clear();
    syncForm();
    scheduleGenerate(0);
}

function clampInt(v, min, max) {
    const n = Number(String(v).trim());
    return Number.isInteger(n) && n >= min && n <= max ? n : null;
}

function archiveName() {
    const n = state.frames.length;
    return `regn-med-lego-${n}-${n === 1 ? 'ramme' : 'rammer'}`;
}

/* ------------------------------------------------------------- Generering */

function requestBody() {
    return { frames: state.frames, operator_cards: state.operatorCards };
}

function scheduleGenerate(delay = DEBOUNCE_MS) {
    state.valid = false;
    updateButtons();
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(generate, delay);
}

async function generate() {
    const seq = ++state.requestSeq;
    state.abort?.abort();
    state.abort = new AbortController();
    state.generating = true;
    setStatus(`Lager ${state.frames.length === 1 ? 'rammen' : state.frames.length + ' rammer'} …`, true);
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
        state.errors = [];
        if (!data.ok) {
            state.valid = false;
            state.errors = data.errors ?? [];
            if (data.errors) showErrors();
            else showMessage(data.error || 'Genereringen mislyktes.');
            renderStrip();
            setStatus('Ingen gyldig modell', false);
            $('#model-info').innerHTML = '<span class="invalid">Rammene kan ikke lages før feilen er rettet. Visningen viser forrige modell.</span>';
            return;
        }
        const pieces = await Promise.all(data.parts.map(async (p, index) => {
            const piece = { index, id: p.id, info: p.info };
            if (viewer?.parts.get(index)?.id !== p.id) {
                const r = await fetch(p.stl_url, { signal: state.abort.signal });
                if (!r.ok) throw new Error('Kunne ikke hente STL-filen.');
                piece.buffer = await r.arrayBuffer();
            }
            return piece;
        }));
        if (seq !== state.requestSeq) return;
        state.current = data;
        state.valid = true;
        viewer?.setParts(pieces);
        updateViewer();
        hideMessage();
        if (data.warnings?.length) showMessage(data.warnings.join(' '), 'warn');
        renderStrip();
        updateDims();
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

/** Feil på valgt ramme vises ved feltene; alle feil står i meldingen. */
function showErrors() {
    const errs = state.errors ?? [];
    if (!errs.length) return;
    for (const e of errs) {
        if (e.frame === state.selected) setFieldError(e.field, e.message.replace(/^Ramme \d+: /, ''));
    }
    showMessage(errs.map((e) => e.message).join(' '));
}

/* ---------------------------------------------------------------- Visning */

function updateViewer() {
    const mode = document.querySelector('input[name="show"]:checked').value;
    viewer?.setDisplay({ mode, selected: state.selected, bricks: $('#show-bricks').checked });
}

function updateDims() {
    const part = state.current?.parts[state.selected];
    if (!part || !state.valid) return;
    const i = part.info;
    const mm = (v) => `${fmt(v)} mm`;
    $('#capacity').textContent = `Plass til ${i.count} ${i.count === 1 ? 'knott' : 'knotter'}`;
    const rows = [
        [i.rectangle ? 'Innvendig' : 'Innvendig (største mål)', `${fmt(i.inner_mm[0])} × ${mm(i.inner_mm[1])}`],
        ['Utvendig (rammen)', `${fmt(i.frame_outer_mm[0])} × ${mm(i.frame_outer_mm[1])}`],
        ['Utvendig med merkefelt', i.field ? `${fmt(i.outer_mm[0])} × ${mm(i.outer_mm[1])}` : '–'],
        ['Total høyde', mm(i.height_mm) + (i.top_mm > i.height_mm ? ` (${mm(i.top_mm)} med tekst)` : '')],
        ['Innvendig høyde', mm(i.inner_height_mm) + (i.bottom_mm ? ` over ${mm(i.bottom_mm)} bunn` : ', uten bunn')],
    ];
    if (i.label) rows.push(['Etikett', `${fmt(i.label[0])} × ${mm(i.label[1])}`]);
    if (i.text) rows.push(['Tekst', `«${i.text.string}», ${mm(i.text.size)} høy`]);
    $('#dims').innerHTML = rows.map(([k, v]) => `<div><dt>${k}</dt><dd class="num">${escapeHtml(v)}</dd></div>`).join('');
    const ok = state.current.parts.every((p) => p.check.ok);
    $('#model-info').innerHTML = `<span class="num">${escapeHtml(i.name)}</span> · ${escapeHtml(part.filename)} · `
        + (ok ? `<span class="valid">${CHECK_ICON}alle rammer er lukkede og målriktige</span>` : '<span class="invalid">kontrollen feilet</span>');
    $('#stl-sub').textContent = `${part.filename} · ca. ${fmt(Math.round(i.material_g))} g PLA`;
    $('#zip-sub').textContent = `${state.current.parts.length} STL-${state.current.parts.length === 1 ? 'fil' : 'filer'} og en oversikt`;
    const labels = state.current.model.labels;
    $('#pdf-sub').textContent = `Oversikt${labels ? ` og ${labels} ${labels === 1 ? 'etikett' : 'etiketter'}` : ''} i faktisk størrelse`;
}

function updateButtons() {
    const ready = state.valid && !state.generating && !!state.current;
    for (const id of ['#download-stl', '#download-zip', '#download-pdf']) $(id).disabled = !ready;
}
