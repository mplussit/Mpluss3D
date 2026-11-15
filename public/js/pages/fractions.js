/**
 * M+ 3D – brukergrensesnitt for brøksirklene.
 *
 * Kontrollene bygges fra API-skjemaet (app/config/models/fraction_circles.php).
 * Ved hver endring lager serveren én STL per nevner (bufret per brikke), og
 * visningen laster bare brikkene som er nye.
 */
import { FractionViewer } from '../fraction-viewer.js';
import {
    API, $, fieldControl, setStatus, setFieldError, clearFieldError, clearAllErrors,
    showMessage, hideMessage, downloadFrom, bindHelpDialog, bindViewButtons, webglMissing,
    fmt, escapeHtml, CHECK_ICON, DOWNLOAD_ICON,
} from '../ui.js';

const MODEL = 'fractions';
const DEBOUNCE_MS = 350;

const state = {
    schema: null,
    values: {},
    denominators: new Set(),
    labels: true,
    current: null,
    generating: false,
    requestSeq: 0,
    abort: null,
    valid: false,
    display: { set: 'all', mode: 'whole' },
};

let viewer;
let debounceTimer;

init().catch((err) => showMessage('Kunne ikke starte appen: ' + err.message));

async function init() {
    try {
        viewer = new FractionViewer($('#viewer'));
    } catch {
        webglMissing($('#viewer'));
    }
    const res = await fetch(`${API}?action=schema&model=${MODEL}`);
    if (!res.ok) throw new Error('mangler kontakt med serveren');
    state.schema = await res.json();

    const d = state.schema.defaults;
    for (const key of Object.keys(state.schema.parameters)) state.values[key] = d[key];
    state.denominators = new Set(d.denominators);
    state.labels = d.labels;

    buildForm();
    bindEvents();
    generate();
}

/* ------------------------------------------------------------------ Skjema */

function buildForm() {
    const params = state.schema.parameters;
    const main = $('#param-fields');
    const labelBox = $('#label-fields');
    main.innerHTML = '';
    labelBox.innerHTML = '';
    for (const [key, spec] of Object.entries(params)) {
        const el = fieldControl(key, spec, state.values[key], (v) => {
            state.values[key] = v;
            scheduleGenerate();
        });
        (spec.group === 'label' ? labelBox : main).appendChild(el);
    }
    $('#labels-on').checked = state.labels;
    labelBox.hidden = !state.labels;

    const grid = $('#den-options');
    grid.innerHTML = '';
    const { min, max } = state.schema.denominators;
    for (let n = min; n <= max; n++) {
        const b = document.createElement('button');
        b.type = 'button';
        b.className = 'den-btn';
        b.dataset.den = n;
        b.style.setProperty('--den-color', state.schema.colors[n].hex);
        b.setAttribute('aria-label', n === 1 ? 'Hel (1)' : `1/${n}, ${state.schema.names[n][1]}`);
        b.innerHTML = `<span class="den-frac num">${n === 1 ? '1' : `1/${n}`}</span>`;
        grid.appendChild(b);
    }
    syncDenominatorButtons();
}

function syncDenominatorButtons() {
    document.querySelectorAll('.den-btn').forEach((b) =>
        b.setAttribute('aria-pressed', String(state.denominators.has(Number(b.dataset.den)))));
}

function bindEvents() {
    bindHelpDialog();
    const setActiveView = bindViewButtons(viewer);

    $('#den-options').addEventListener('click', (e) => {
        const b = e.target.closest('.den-btn');
        if (!b) return;
        const n = Number(b.dataset.den);
        state.denominators.has(n) ? state.denominators.delete(n) : state.denominators.add(n);
        syncDenominatorButtons();
        clearFieldError('denominators');
        scheduleGenerate();
    });
    document.querySelectorAll('[data-preset]').forEach((b) => b.addEventListener('click', () => {
        const { min, max, default: def } = state.schema.denominators;
        state.denominators = new Set(b.dataset.preset === 'all'
            ? Array.from({ length: max - min + 1 }, (_, i) => i + min) : def);
        syncDenominatorButtons();
        clearFieldError('denominators');
        scheduleGenerate();
    }));
    $('#labels-on').addEventListener('change', (e) => {
        state.labels = e.target.checked;
        $('#label-fields').hidden = !state.labels;
        scheduleGenerate();
    });
    $('#set-options').addEventListener('change', (e) => {
        state.display.set = e.target.value === 'all' ? 'all' : Number(e.target.value);
        viewer?.setDisplay({ set: state.display.set });
        setActiveView(null);
    });
    $('#mode-options').addEventListener('change', (e) => {
        state.display.mode = e.target.value;
        viewer?.setDisplay({ mode: state.display.mode });
        setActiveView(null);
    });
    $('#print-list').addEventListener('click', (e) => {
        const b = e.target.closest('[data-download]');
        const part = b && state.current?.parts.find((p) => p.id === b.dataset.download);
        if (part) downloadFrom(`${part.stl_url}&download=1`, part.filename, b, updateButtons);
    });
    $('#download-zip').addEventListener('click', () =>
        downloadFrom(`${API}?${query('zip')}`, `${baseName()}.zip`, $('#download-zip'), updateButtons));
    $('#download-pdf').addEventListener('click', () =>
        downloadFrom(`${API}?${query('pdf')}`, `${baseName()}.pdf`, $('#download-pdf'), updateButtons));
}

/* ------------------------------------------------------------- Generering */

function requestBody() {
    return { ...state.values, denominators: [...state.denominators].sort((a, b) => a - b), labels: state.labels };
}

function query(action) {
    const body = requestBody();
    const q = new URLSearchParams({ action, model: MODEL, download: '1' });
    for (const [k, v] of Object.entries(body)) {
        q.set(k, Array.isArray(v) ? v.join(',') : typeof v === 'boolean' ? (v ? '1' : '0') : String(v));
    }
    return q;
}

function baseName() {
    return `broeksirkler-${String(state.current?.params.diameter ?? state.values.diameter).replace('.', '_')}mm`;
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
    const n = state.denominators.size;
    setStatus(`Lager ${n} ${n === 1 ? 'brikke' : 'brikker'} …`, true);
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

        // Hent bare brikker som er nye siden sist.
        const known = new Map((state.current?.parts ?? []).map((p) => [p.id, p]));
        const pieces = await Promise.all(data.parts.map(async (p) => {
            const piece = {
                denominator: p.info.denominator, id: p.id, color: p.info.color,
                label: p.info.label, thickness: data.params.thickness, depth: data.params.label_depth,
            };
            if (!known.has(p.id) || !viewer?.geometries.has(p.info.denominator)
                || viewer.geometries.get(p.info.denominator).id !== p.id) {
                const r = await fetch(p.stl_url, { signal: state.abort.signal });
                if (!r.ok) throw new Error('Kunne ikke hente STL-filen.');
                piece.buffer = await r.arrayBuffer();
            }
            return piece;
        }));
        if (seq !== state.requestSeq) return;

        state.current = data;
        state.valid = true;
        buildSetOptions(data);
        viewer?.setPieces(pieces, data.params.diameter);
        viewer?.setDisplay(state.display);
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

/** «Brøksett»-valget under visningen: Alle + hver valgt nevner. */
function buildSetOptions(data) {
    const dens = data.parts.map((p) => p.info.denominator);
    if (state.display.set !== 'all' && !dens.includes(state.display.set)) state.display.set = 'all';
    const opts = [['all', 'Alle', null], ...data.parts.map((p) => [String(p.info.denominator), p.info.fraction, p.info.color])];
    $('#set-options').innerHTML = opts.map(([value, label, color]) =>
        `<label>${color ? `<span class="swatch" style="background:${escapeHtml(color)}"></span>` : ''}` +
        `<input type="radio" name="set" value="${value}"${String(state.display.set) === value ? ' checked' : ''}> ` +
        `<span class="num">${escapeHtml(label)}</span></label>`).join('');
}

function updateInfo(data) {
    const total = data.model.total_pieces;
    const files = data.parts.length;
    const ok = data.parts.every((p) => p.check.ok);
    $('#model-info').innerHTML =
        `<span class="num">${total}</span> ${total === 1 ? 'brikke' : 'brikker'} i <span class="num">${files}</span> ` +
        `${files === 1 ? 'STL-fil' : 'STL-filer'} · Ø <span class="num">${fmt(data.params.diameter)} mm</span> · ` +
        (ok ? `<span class="valid">${CHECK_ICON}alle er lukkede og målriktige</span>`
            : '<span class="invalid">kontrollen feilet</span>');
}

function renderPrintList(data) {
    $('#print-list tbody').innerHTML = data.parts.map((p) => {
        const i = p.info;
        const note = !data.params.labels ? '' : (!i.label.shown ? 'uten merking' : (i.label.reduced ? 'liten merking' : ''));
        return `<tr>
            <th scope="row"><span class="swatch" style="background:${escapeHtml(i.color)}"></span>
                <span class="num frac">${escapeHtml(i.fraction)}</span>
                <span class="muted">${escapeHtml(i.name)}${note ? ` · ${note}` : ''}</span></th>
            <td class="num count">${p.count} stk</td>
            <td><button type="button" class="icon-btn" data-download="${escapeHtml(p.id)}"
                aria-label="Last ned ${escapeHtml(p.filename)}">${DOWNLOAD_ICON}<span>STL</span></button></td>
        </tr>`;
    }).join('');
    const total = data.model.total_pieces;
    $('#print-total').textContent = `${total} ${total === 1 ? 'brikke' : 'brikker'}`;
    $('#zip-sub').textContent = `${data.parts.length} ${data.parts.length === 1 ? 'fil' : 'filer'} og oversikt over antall`;
}

function updateButtons() {
    const ready = state.valid && !state.generating && !!state.current;
    $('#download-zip').disabled = !ready;
    $('#download-pdf').disabled = !ready;
    document.querySelectorAll('#print-list [data-download]').forEach((b) => (b.disabled = !ready));
}
