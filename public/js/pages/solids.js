/**
 * M+ 3D – brukergrensesnitt for romfigurer og utbrettingsmodeller.
 *
 * Kontrollene bygges fra API-skjemaet (app/config/models/solid_figures.php).
 * Ved hver endring svarer serveren med STL-fila og den felles
 * geometrimodellen (egenskaper, flater, kanter, nett og A4-plassering).
 * 3D-visningen, 2D-nettet og egenskapene vises fra det samme svaret, så de
 * kan aldri bli uenige med PDF-ene.
 */
import { SolidViewer } from '../solids-viewer.js';
import { netSvg } from '../net-svg.js';
import {
    API, $, fieldControl, setStatus, setFieldError, clearAllErrors,
    showMessage, hideMessage, downloadFrom, bindHelpDialog, bindViewButtons, webglMissing,
    fmt, escapeHtml, CHECK_ICON,
} from '../ui.js';
import { initSheets } from '../sheets.js';

const MODEL = 'solids';
const DEBOUNCE_MS = 250;

const state = {
    schema: null,
    shape: 'cube',
    values: {},
    hollow: false,
    level: 2,
    netPrint: 'tiles',
    show: '3d',
    current: null,
    stlId: null,
    generating: false,
    valid: false,
    requestSeq: 0,
    abort: null,
};

let viewer;
let debounceTimer;
let setActiveView = () => {};

// Arkfaner: 3D-visningene og én fane per side i PDF-en.
const sheets = initSheets({
    threeD: { group: '#show-options' },
    docs: [
        { key: 'net', request: () => ({ url: `${API}?${query('pdf', 'net')}` }) },
        { key: 'tasks', request: () => ({ url: `${API}?${query('pdf', 'tasks')}` }) },
    ],
});

init().catch((err) => showMessage('Kunne ikke starte appen: ' + err.message));

async function init() {
    try {
        viewer = new SolidViewer($('#viewer'));
    } catch {
        webglMissing($('#viewer'));
    }
    const res = await fetch(`${API}?action=schema&model=${MODEL}`);
    if (!res.ok) throw new Error('mangler kontakt med serveren');
    state.schema = await res.json();
    const d = state.schema.defaults;
    for (const key of Object.keys(state.schema.parameters)) state.values[key] = d[key];
    state.shape = d.shape;
    state.hollow = d.hollow;
    state.level = d.level;
    state.netPrint = d.net_print;

    buildStatic();
    buildDims();
    bindEvents();
    generate();
}

/* ------------------------------------------------------------------ Skjema */

function buildStatic() {
    const s = state.schema;
    $('#shape-options').innerHTML = Object.entries(s.shapes).map(([k, sh]) =>
        `<label title="${escapeHtml(sh.title)}"><input type="radio" name="shape" value="${k}"${k === state.shape ? ' checked' : ''}>`
        + `${shapeIcon(k)}<span>${escapeHtml(sh.short)}</span></label>`).join('');
    $('#wall-fields').appendChild(fieldControl('wall', s.parameters.wall, state.values.wall, (v) => {
        state.values.wall = v;
        scheduleGenerate();
    }));
    $('#net-fields').appendChild(fieldControl('tab_width', s.parameters.tab_width, state.values.tab_width, (v) => {
        state.values.tab_width = v;
        scheduleGenerate();
    }));
    $('#net-print-options').innerHTML = radios('net_print', s.choices.net_print.options, state.netPrint);
    $('#level-options').innerHTML = radios('level', s.choices.level.options, String(state.level));
    syncHollow();
}

function radios(name, options, current) {
    return Object.entries(options).map(([k, label]) =>
        `<label><input type="radio" name="${name}" value="${escapeHtml(k)}"${k === current ? ' checked' : ''}> ${escapeHtml(label)}</label>`).join('');
}

/** Glidebrytere for målene til figuren som er valgt. */
function buildDims() {
    const box = $('#dim-fields');
    box.innerHTML = '';
    for (const key of state.schema.shapes[state.shape].params) {
        box.appendChild(fieldControl(key, state.schema.parameters[key], state.values[key], (v) => {
            state.values[key] = v;
            scheduleGenerate();
        }));
    }
}

function syncHollow() {
    $('#wall-fields').hidden = !state.hollow;
    document.querySelector(`#hollow-options input[value="${state.hollow ? 1 : 0}"]`).checked = true;
}

function bindEvents() {
    bindHelpDialog();
    setActiveView = bindViewButtons(viewer);

    $('#shape-options').addEventListener('change', (e) => {
        state.shape = e.target.value;
        buildDims();
        scheduleGenerate(0);
    });
    $('#hollow-options').addEventListener('change', (e) => {
        state.hollow = e.target.value === '1';
        syncHollow();
        scheduleGenerate(0);
    });
    $('#show-faces').addEventListener('change', (e) => viewer?.setShowFaces(e.target.checked));
    $('#show-edges').addEventListener('change', (e) => viewer?.setShowEdges(e.target.checked));
    $('#net-print-options').addEventListener('change', (e) => {
        state.netPrint = e.target.value;
        scheduleGenerate(0);
    });
    $('#level-options').addEventListener('change', (e) => {
        state.level = Number(e.target.value);
        updateButtons();
    });
    $('#show-options').addEventListener('change', (e) => {
        state.show = e.target.value;
        $('#solid-views').dataset.show = state.show;
        setActiveView(null);
    });

    $('#download-stl').addEventListener('click', () => {
        const part = state.current?.parts[0];
        if (part) downloadFrom(`${part.stl_url}&download=1`, part.filename, $('#download-stl'), updateButtons);
    });
    $('#download-net').addEventListener('click', () =>
        downloadFrom(`${API}?${query('pdf', 'net')}`, pdfName('nett'), $('#download-net'), updateButtons));
    $('#download-tasks').addEventListener('click', () =>
        downloadFrom(`${API}?${query('pdf', 'tasks')}`, pdfName(`oppgaver-niva${state.level}`), $('#download-tasks'), updateButtons));
}

/* ------------------------------------------------------------- Generering */

function requestBody() {
    return {
        shape: state.shape, ...state.values, hollow: state.hollow,
        level: String(state.level), net_print: state.netPrint,
    };
}

function query(action, documentType) {
    const q = new URLSearchParams({ action, model: MODEL, download: '1', document: documentType });
    for (const [k, v] of Object.entries(requestBody())) q.set(k, typeof v === 'boolean' ? (v ? '1' : '0') : String(v));
    return q;
}

function pdfName(suffix) {
    const stl = state.current?.parts[0]?.filename ?? 'romfigur.stl';
    return stl.replace(/(-hul)?\.stl$/, `-${suffix}.pdf`);
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
    setStatus('Lager figuren …', true);
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
        const part = data.parts[0];
        let buffer = null;
        if (part.id !== state.stlId) {
            const r = await fetch(part.stl_url, { signal: state.abort.signal });
            if (!r.ok) throw new Error('Kunne ikke hente STL-filen.');
            buffer = await r.arrayBuffer();
        }
        if (seq !== state.requestSeq) return;

        state.current = data;
        state.valid = true;
        if (buffer && viewer) {
            viewer.setModel(buffer, data.model.geometry);
            state.stlId = part.id;
        }
        $('#net-view').innerHTML = netSvg(data.model);
        hideMessage();
        if (data.warnings?.length) showMessage(data.warnings.join(' '), 'warn');
        updateInfo(data);
        renderProps(data.model.geometry);
        renderNetStatus(data.model);
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

function updateInfo(data) {
    const part = data.parts[0];
    const i = part.info;
    const m = data.model;
    $('#model-info').innerHTML =
        `<span class="num">${escapeHtml(i.name)}</span> · <span class="num">${i.size_mm.map(fmt).join(' × ')} mm</span> · `
        + (i.hollow ? `hul, ${fmt(i.wall)} mm vegg` : 'massiv') + ' · '
        + (part.check.ok ? `<span class="valid">${CHECK_ICON}lukket og målriktig</span>` : '<span class="invalid">kontrollen feilet</span>');
    $('#stl-sub').textContent = `${part.filename} · ca. ${fmt(Math.round(m.material_g.print_g))} g PLA`;
    $('#hollow-hint').textContent = i.hollow
        ? (i.roof
            ? 'Hulrommet er lukket. Taket i hulrommet skrår 45°, så figuren kan skrives ut uten støtte. Veggen blir derfor tykkere øverst.'
            : 'Hulrommet er lukket og følger sideflatene, som heller nok til at figuren kan skrives ut uten støtte.')
        : 'Massiv: én lukket figur, sliceren bestemmer fyllgraden. Hul: tynt skall, tilgjengelig for alle seks figurene.';
}

const cm2 = (mm2) => `${fmt(Math.round(mm2) / 100)} cm²`;
const cm3 = (mm3) => `${fmt(Math.round(mm3 / 100) / 10)} cm³`;

function renderProps(g) {
    const c = g.counts;
    const faces = c.curved_faces ? `${c.faces} <span class="muted">(${c.curved_faces} krum)</span>` : `${c.faces}`;
    const edges = c.curved_edges ? `${c.edges} <span class="muted">(sirkelformede)</span>` : `${c.edges}`;
    const verts = c.vertex_word === 'toppunkt' ? `${c.vertices} <span class="muted">(toppunktet)</span>` : `${c.vertices}`;
    const rows = [
        ['Figur', escapeHtml(g.name)],
        ['Flater', faces],
        ['Kanter', edges],
        [c.vertex_word === 'toppunkt' ? 'Hjørner / toppunkt' : 'Hjørner', verts],
        ['Grunnflateareal', `${cm2(g.base_area)} <span class="muted">(${fmt(Math.round(g.base_area))} mm²)</span>`],
        ['Overflateareal', `${cm2(g.surface_area)} <span class="muted">(${fmt(Math.round(g.surface_area))} mm²)</span>`],
        ['Volum', `${cm3(g.volume)} <span class="muted">(${fmt(Math.round(g.volume))} mm³)</span>`],
    ];
    $('#props').innerHTML = rows.map(([k, v]) => `<div><dt>${k}</dt><dd class="num">${v}</dd></div>`).join('');
    const note = $('#props-note');
    note.hidden = !c.note;
    note.textContent = c.note ?? '';
    $('#measures tbody').innerHTML = Object.values(g.measures).map((m) =>
        `<tr><th scope="row">${escapeHtml(m.label)} <span class="muted">(${escapeHtml(m.symbol)})</span></th>`
        + `<td class="num">${fmt(m.value)} ${escapeHtml(m.unit)}</td><td class="muted">${escapeHtml(m.formula ?? '')}</td></tr>`).join('')
        + `<tr><th scope="row">Overflaten består av</th><td colspan="2">${escapeHtml(g.surface_shapes)}</td></tr>`
        + `<tr><th scope="row">Symmetriplan</th><td colspan="2">${g.symmetry_planes === 'uendelig' ? 'uendelig mange' : g.symmetry_planes}</td></tr>`;
}

function renderNetStatus(m) {
    const L = m.layout;
    const el = $('#net-status');
    const box = $('#net-print-box');
    box.hidden = L.fits;
    const size = `${fmt(Math.round(L.size[0]))} × ${fmt(Math.round(L.size[1]))} mm`;
    if (L.mode === 'single') {
        el.className = 'net-status ok';
        el.textContent = `Får plass på ett A4-ark (${L.orientation === 'L' ? 'liggende' : 'stående'}) i målestokk 1:1. Nettet er ${size} (${m.net.variant}).`;
        $('#net-sub').textContent = 'Utbrettingsfigur i målestokk 1:1, ett ark';
    } else if (L.mode === 'tiles') {
        el.className = 'net-status warn';
        el.textContent = `Nettet er ${size} og får ikke plass på ett A4-ark i 1:1. Velg hvordan det skal skrives ut:`;
        $('#net-sub').textContent = `Målestokk 1:1 på ${L.pages.length} ark + oversikt`;
    } else {
        el.className = 'net-status warn';
        el.textContent = `Nettet er ${size} og får ikke plass på ett A4-ark i 1:1. Det er forminsket til ${L.scale_label}:`;
        $('#net-sub').textContent = `Skalert ${L.scale_label} på ett ark (ikke 1:1)`;
    }
}

function updateButtons() {
    const ready = state.valid && !state.generating && !!state.current;
    for (const id of ['#download-stl', '#download-net', '#download-tasks']) $(id).disabled = !ready;
    $('#tasks-sub').textContent = `${state.schema?.choices.level.options[state.level] ?? ''} · fem sider og fasit`;
    sheets.refresh();
}

/** Små ikoner til figurvalget. */
function shapeIcon(k) {
    const p = {
        cube: '<path d="M4 7l6-3 6 3v7l-6 3-6-3z M4 7l6 3 6-3 M10 10v7"/>',
        cuboid: '<path d="M2 8l7-3 9 3v6l-7 3-9-3z M2 8l9 3 7-3 M11 11v6"/>',
        pyramid: '<path d="M10 3l7 12-7 3-7-3z M10 3v15"/>',
        triprism: '<path d="M3 15l4-9h9l-4 9z M3 15h9 M12 15l4-9"/>',
        cylinder: '<ellipse cx="10" cy="5" rx="6" ry="2"/><path d="M4 5v10a6 2 0 0 0 12 0V5"/>',
        cone: '<path d="M10 3l6 12a6 2 0 0 1-12 0z"/>',
    }[k] ?? '';
    return `<svg class="shape-icon" width="20" height="20" viewBox="0 0 20 20" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round">${p}</svg>`;
}
