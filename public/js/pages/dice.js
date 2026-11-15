/**
 * M+ 3D – brukergrensesnitt for terninggeneratoren.
 *
 * Kontrollene bygges fra API-ets skjema (app/config/models/dice_cube.php),
 * så nye parametere dukker opp automatisk. Ved hver endring genereres en ny
 * STL på serveren, som vises i forhåndsvisningen og kan lastes ned.
 */
import { DiceViewer } from '../viewer.js';
import {
    API, $, fieldControl, setStatus, clearAllErrors, showMessage, hideMessage,
    downloadFrom, bindHelpDialog, bindViewButtons, webglMissing, fmt, escapeHtml, CHECK_ICON,
    setFieldError as setError, clearFieldError,
} from '../ui.js';

const DEBOUNCE_MS = 350;
const SIZE_KEYS = ['width', 'height', 'depth'];

const state = {
    schema: null,
    values: {},          // geometriparametere
    decor: 'pips',
    labelAuto: true,
    labelSize: null,
    current: null,       // siste vellykkede generering
    generating: false,
    requestSeq: 0,
    abort: null,
    valid: false,
};

let viewer;
let debounceTimer;

init().catch((err) => showMessage('Kunne ikke starte appen: ' + err.message));

async function init() {
    try {
        viewer = new DiceViewer($('#viewer'));
    } catch (err) {
        webglMissing($('#viewer'));
    }

    const res = await fetch(`${API}?action=schema`);
    if (!res.ok) throw new Error('mangler kontakt med serveren');
    state.schema = await res.json();

    const d = state.schema.defaults;
    for (const key of Object.keys(state.schema.parameters)) state.values[key] = d[key];
    state.decor = d.decor;

    buildForm();
    bindEvents();
    generate();
}

/* ------------------------------------------------------------------ Skjema */

function buildForm() {
    const container = $('#param-fields');
    container.innerHTML = '';
    const params = state.schema.parameters;

    // Bredde/høyde/dybde vises som én kontroll «Terningstørrelse» i denne versjonen.
    const sizeSpec = params.width;
    container.appendChild(fieldControl('size', {
        ...sizeSpec, label: 'Terningstørrelse',
        help: 'Lengden på hver kant.',
    }, state.values.width, (v) => {
        SIZE_KEYS.forEach((k) => (state.values[k] = v));
        scheduleGenerate();
    }));

    for (const [key, spec] of Object.entries(params)) {
        if (spec.group === 'size') continue;
        container.appendChild(fieldControl(key, spec, state.values[key], (v) => {
            state.values[key] = v;
            scheduleGenerate();
        }));
    }

    const decorBox = $('#decor-options');
    decorBox.innerHTML = '';
    for (const [value, label] of Object.entries(state.schema.label.decor)) {
        const lab = document.createElement('label');
        lab.innerHTML = `<input type="radio" name="decor" value="${value}"> ${escapeHtml(label)}`;
        lab.querySelector('input').checked = value === state.decor;
        decorBox.appendChild(lab);
    }
    renderSheetPreview();
    const ls = $('#label-size');
    ls.min = state.schema.label.min;
    ls.max = state.schema.label.max;
    ls.step = state.schema.label.step;
}

function bindEvents() {
    $('#decor-options').addEventListener('change', (e) => {
        state.decor = e.target.value;
        renderSheetPreview();
        updateButtons();
    });
    bindHelpDialog();
    $('#label-auto').addEventListener('change', (e) => {
        state.labelAuto = e.target.checked;
        $('#label-size-field').hidden = state.labelAuto;
        if (!state.labelAuto && state.current) {
            $('#label-size').value = state.current.params.label_size;
            state.labelSize = state.current.params.label_size;
        }
        clearFieldError('label');
        scheduleGenerate();
    });
    $('#label-size').addEventListener('input', (e) => {
        state.labelSize = e.target.value;
        clearFieldError('label');
        scheduleGenerate();
    });
    $('#show-names').addEventListener('change', (e) => viewer?.setShowNames(e.target.checked));
    bindViewButtons(viewer);
    $('#download-stl').addEventListener('click', downloadStl);
    $('#download-pdf').addEventListener('click', downloadPdf);
}

/* ------------------------------------------------------------- Generering */

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
    setStatus('Lager modell …', true);
    updateButtons();

    try {
        const res = await fetch(`${API}?action=generate`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ ...state.values, decor: state.decor, label: labelValue() }),
            signal: state.abort.signal,
        });
        const data = await res.json().catch(() => ({ ok: false, error: 'Ugyldig svar fra serveren.' }));
        if (seq !== state.requestSeq) return;

        clearAllErrors();
        if (!data.ok) {
            state.valid = false;
            if (data.errors) {
                data.errors.forEach((e) => setFieldError(e.field, e.message));
                showMessage('Noen verdier passer ikke sammen. Se feilmeldingen ved kontrollen.');
            } else {
                showMessage(data.error || 'Genereringen mislyktes.');
            }
            setStatus('Ingen gyldig modell', false);
            $('#model-info').innerHTML = '<span class="invalid">Ingen gyldig modell for disse verdiene. Visningen viser forrige modell.</span>';
            return;
        }

        const stl = await fetch(data.stl_url, { signal: state.abort.signal });
        if (!stl.ok) throw new Error('Kunne ikke hente STL-filen.');
        const buffer = await stl.arrayBuffer();
        if (seq !== state.requestSeq) return;

        viewer?.setModel(buffer, data.model.dimensions);
        state.current = data;
        state.valid = true;
        hideMessage();
        if (data.warnings?.length) showMessage(data.warnings.join(' '), 'warn');
        updateInfo(data);
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

function updateInfo(data) {
    const d = data.model.dimensions;
    const c = data.check;
    const cube = d.width === d.height && d.height === d.depth;
    $('#model-info').innerHTML =
        `<span class="num">${fmt(d.width)} × ${fmt(d.depth)} × ${fmt(d.height)} mm</span>${cube ? '' : ' (B × D × H)'}` +
        ` · <span class="num">${c.triangles.toLocaleString('nb-NO')}</span> trekanter · ` +
        (c.ok ? `<span class="valid">${CHECK_ICON}lukket og målriktig modell</span>`
              : '<span class="invalid">kontrollen feilet</span>');
    $('#label-auto-value').textContent = `(${fmt(data.params.label_size)} mm)`;
}

/** Lite forhåndsbilde av etikettarket: 6 ruter med stiplet klippekant. */
function renderSheetPreview() {
    const PIPS = {   // samme mønstre som cube.scad og PdfGenerator
        1: [[0, 0]], 2: [[-1, 1], [1, -1]], 3: [[-1, 1], [0, 0], [1, -1]],
        4: [[-1, -1], [-1, 1], [1, -1], [1, 1]], 5: [[-1, -1], [-1, 1], [0, 0], [1, -1], [1, 1]],
        6: [[-1, -1], [-1, 0], [-1, 1], [1, -1], [1, 0], [1, 1]],
    };
    const faces = state.schema.faces;
    const order = ['top', 'front', 'right', 'left', 'back', 'bottom'];
    $('#sheet-preview').innerHTML = order.map((face) => {
        const v = faces[face];
        let inner = '';
        if (state.decor === 'pips' && PIPS[v]) {
            inner = PIPS[v].map(([u, w]) => `<circle cx="${50 + u * 25}" cy="${50 - w * 25}" r="8" fill="#161616"/>`).join('');
        } else if (state.decor === 'numbers' || state.decor === 'pips') {
            inner = `<text x="50" y="50" dy=".35em" text-anchor="middle" font-family="Bricolage Grotesque, sans-serif" font-weight="800" font-size="56" fill="#161616">${escapeHtml(v)}</text>`;
        }
        return `<svg viewBox="-4 -4 108 108"><rect x="0" y="0" width="100" height="100" fill="#fffdf9" stroke="#161616" stroke-width="2" stroke-dasharray="6 5"/>${inner}</svg>`;
    }).join('');
}

/* ------------------------------------------------------------- Nedlasting */

async function downloadStl() {
    if (!state.current) return;
    await downloadFrom(`${state.current.stl_url}&download=1`, state.current.filename, $('#download-stl'), updateButtons);
}

async function downloadPdf() {
    if (!state.current) return;
    const q = new URLSearchParams({ action: 'pdf', download: '1', decor: state.decor });
    for (const [k, v] of Object.entries(state.values)) q.set(k, v);
    q.set('label', labelValue());
    const name = state.current.filename.replace(/\.stl$/, '-dekor.pdf');
    await downloadFrom(`${API}?${q}`, name, $('#download-pdf'), updateButtons);
}

/* ------------------------------------------------------------ Hjelpefunksjoner */

function labelValue() {
    return state.labelAuto ? 'auto' : String(state.labelSize ?? '');
}

function updateButtons() {
    const ready = state.valid && !state.generating && !!state.current;
    $('#download-stl').disabled = !ready;
    $('#download-pdf').disabled = !ready;
}

/** Bredde/høyde/dybde vises som én kontroll («size»). */
function setFieldError(field, message) {
    setError(SIZE_KEYS.includes(field) ? 'size' : field, message);
}
