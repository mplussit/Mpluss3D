/**
 * M+ 3D – Kulespill med ark.
 *
 * Læreren velger hva som står i hullene (tall, regnestykker, bokstaver eller
 * symboler), hvilke oppgaver elevene får og hvor mange varianter som skal
 * lages. Ved hver endring lager serveren alle sidene (api.php?action=describe,
 * MarbleSheets) og sender dem som tegneelementer. Forhåndsvisningen tegner dem
 * som SVG, og PDF-en tegnes fra de samme elementene, så de blir like.
 * «Lag stykker» gir et nytt frø, altså nye verdier og stykker.
 */
import { MarbleViewer } from '../marble-viewer.js';
import {
    API, $, setStatus, setFieldError, clearAllErrors, showMessage, hideMessage,
    downloadFrom, bindHelpDialog, bindViewButtons, escapeHtml,
} from '../ui.js';
import { initSheets } from '../sheets.js';

const MODEL = 'marble';
const DEBOUNCE_MS = 300;
const NUMBER_KEYS = ['num_min', 'num_max', 'value_min', 'value_max', 'balls', 'calc_count', 'calc_terms', 'find_count', 'variants'];
const BOOL_KEYS = ['unique', 'task_round', 'task_calc', 'no_negative', 'task_find', 'answer_key'];
const OP_LABELS = { '+': '+', '-': '–', '*': '×', '/': ':' };

const state = {
    schema: null,
    values: {},
    current: null,
    page: 0,
    show: '3d',
    valid: false,
    loading: false,
    seq: 0,
    abort: null,
};

let viewer = null;
let viewerReady = null;
let debounceTimer;

// Arkfaner: kulespillet i 3D og én fane per side i PDF-en.
const sheets = initSheets({
    threeD: { label: 'Kulespillet' },
    docs: [{ key: 'pdf', request: () => ({ url: `${API}?action=pdf&model=${MODEL}`, body: requestBody() }) }],
});

init().catch((err) => showMessage('Kunne ikke starte appen: ' + err.message));

async function init() {
    const res = await fetch(`${API}?action=schema&model=${MODEL}`);
    if (!res.ok) throw new Error('mangler kontakt med serveren');
    state.schema = await res.json();
    state.values = structuredClone(state.schema.defaults);
    state.values.seed = newSeed();
    buildControls();
    bindEvents();
    syncControls();
    refresh();
}

function newSeed() {
    return 1 + Math.floor(Math.random() * 2147483646);
}

/* ------------------------------------------------------------------ Skjema */

function buildControls() {
    const s = state.schema;
    $('#content-options').innerHTML = Object.entries(s.contents).map(([k, label]) =>
        `<label><input type="radio" name="content" value="${k}"> ${escapeHtml(label)}</label>`).join('');
    $('#ops-options').innerHTML = Object.entries(s.operations).map(([op, name]) =>
        `<label title="${escapeHtml(name)}"><input type="checkbox" name="ops" value="${escapeHtml(op)}"> <span class="op-sign">${OP_LABELS[op]}</span></label>`).join('');
    $('#max-options').innerHTML = Object.keys(s.expression_max).map((m) =>
        `<label><input type="radio" name="expr_max" value="${m}"> ${m}</label>`).join('');
    $('#calc-ops').innerHTML = ['+', '-'].map((op) =>
        `<label title="${op === '+' ? 'Pluss' : 'Minus'}"><input type="checkbox" name="calc_ops" value="${op}"> <span class="op-sign">${OP_LABELS[op]}</span></label>`).join('');
    const b = s.files;
    $('#board-sub').textContent = `${b.board.filename} · ${b.board.size}`;
    $('#base-sub').textContent = `${b.base.filename} · ${b.base.size}, lomme til arket`;
}

/** Setter kontrollene fra state.values og viser bare det som gjelder. */
function syncControls() {
    const v = state.values;
    document.querySelector(`#content-options input[value="${v.content}"]`).checked = true;
    for (const k of NUMBER_KEYS) {
        const el = $(`#f-${k}`);
        if (el && document.activeElement !== el) el.value = v[k];
    }
    for (const k of BOOL_KEYS) $(`#f-${k}`).checked = !!v[k];
    document.querySelectorAll('#ops-options input').forEach((el) => (el.checked = v.ops.includes(el.value)));
    document.querySelectorAll('#calc-ops input').forEach((el) => (el.checked = v.calc_ops.includes(el.value)));
    document.querySelector(`#max-options input[value="${v.expr_max}"]`).checked = true;
    document.querySelector(`#find-size input[value="${v.find_size}"]`).checked = true;

    const symbols = v.content === 'letters' || v.content === 'symbols';
    $('#opt-numbers').hidden = v.content !== 'numbers';
    $('#opt-expressions').hidden = v.content !== 'expressions';
    $('#opt-values').hidden = !symbols;
    $('#unique-box').hidden = v.content === 'expressions';
    $('#calc-block').hidden = !symbols;
    $('#calc-example').textContent = v.content === 'symbols' ? '★ + ● – ▲' : 'A + B – C';
    $('#values-help').textContent = v.content === 'symbols'
        ? 'Hvert symbol får en verdi. Verdiene står på et eget verdiark.'
        : 'Hver bokstav får en verdi. Verdiene står på et eget verdiark.';
    $('#sub-round').hidden = !v.task_round;
    $('#sub-calc').hidden = !v.task_calc;
    $('#sub-find').hidden = !v.task_find;
}

function bindEvents() {
    bindHelpDialog();
    const form = $('#controls');
    form.addEventListener('change', (e) => {
        const t = e.target;
        if (t.name === 'content') state.values.content = t.value;
        else if (t.name === 'ops') state.values.ops = checked('#ops-options');
        else if (t.name === 'calc_ops') state.values.calc_ops = checked('#calc-ops');
        else if (t.name === 'expr_max') state.values.expr_max = Number(t.value);
        else if (t.name === 'find_size') state.values.find_size = Number(t.value);
        else if (t.id?.startsWith('f-') && t.type === 'checkbox') state.values[t.id.slice(2)] = t.checked;
        else return;
        syncControls();
        schedule(0);
    });
    form.addEventListener('input', (e) => {
        const t = e.target;
        if (t.type !== 'number' || !t.id?.startsWith('f-')) return;
        if (t.value === '') return;
        state.values[t.id.slice(2)] = Number(t.value);
        schedule();
    });
    form.addEventListener('submit', (e) => e.preventDefault());

    $('#make-new').addEventListener('click', () => {
        state.values.seed = newSeed();
        schedule(0);
    });

    $('#lift-board').addEventListener('change', (e) => viewer?.setLifted(e.target.checked));

    $('#download-pdf').addEventListener('click', () =>
        downloadFrom(`${API}?action=pdf&model=${MODEL}&download=1`, pdfName(), $('#download-pdf'), updateButtons, requestBody()));
    $('#download-board').addEventListener('click', () => {
        const f = state.schema.files.board;
        downloadFrom(f.path, f.filename, $('#download-board'), () => ($('#download-board').disabled = false));
    });
    $('#download-base').addEventListener('click', () => {
        const f = state.schema.files.base;
        downloadFrom(f.path, f.filename, $('#download-base'), () => ($('#download-base').disabled = false));
    });
}


function checked(sel) {
    return [...document.querySelectorAll(`${sel} input:checked`)].map((el) => el.value);
}

function requestBody() {
    const v = state.values;
    const body = {};
    for (const k of [...NUMBER_KEYS, ...BOOL_KEYS, 'content', 'ops', 'calc_ops', 'expr_max', 'find_size', 'seed']) body[k] = v[k];
    return body;
}

function pdfName() {
    const c = { numbers: 'tall', expressions: 'regnestykker', letters: 'bokstaver', symbols: 'symboler' }[state.values.content];
    return `kulespill-ark-${c}.pdf`;
}

/* ------------------------------------------------------------- Generering */

function schedule(delay = DEBOUNCE_MS) {
    state.valid = false;
    updateButtons();
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(refresh, delay);
}

async function refresh() {
    const seq = ++state.seq;
    state.abort?.abort();
    state.abort = new AbortController();
    state.loading = true;
    setStatus('Lager arkene …', true);
    updateButtons();
    try {
        const res = await fetch(`${API}?action=describe&model=${MODEL}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(requestBody()),
            signal: state.abort.signal,
        });
        const data = await res.json().catch(() => ({ ok: false, error: 'Ugyldig svar fra serveren.' }));
        if (seq !== state.seq) return;
        clearAllErrors();
        if (!res.ok || !data.pages) {
            state.valid = false;
            if (data.errors) {
                data.errors.forEach((e) => setFieldError(e.field, e.message));
                showMessage('Noen valg passer ikke. Se feilmeldingen ved kontrollen.');
            } else {
                showMessage(data.error || 'Kunne ikke lage arkene.');
            }
            setStatus('Ingen gyldige ark', false);
            $('#model-info').innerHTML = '<span class="invalid">Ingen gyldige ark for disse valgene. Visningen viser de forrige arkene.</span>';
            return;
        }
        const pageKind = state.current?.pages[state.page];
        state.current = data;
        state.valid = true;
        // Bli på samme slags side (f.eks. «Oppgaver 2») når arkene lages på nytt.
        const same = pageKind ? data.pages.findIndex((p) => p.label === pageKind.label) : -1;
        state.page = same >= 0 ? same : Math.min(state.page, data.pages.length - 1);
        hideMessage();
        if (data.warnings?.length) showMessage(data.warnings.join(' '), 'warn');
        show3d();
        updateInfo();
        setStatus(null);
    } catch (err) {
        if (err.name === 'AbortError') return;
        console.error(err);
        state.valid = false;
        showMessage('Kontakten med serveren ble brutt. Prøv igjen.');
        setStatus('Feil', false);
    } finally {
        if (seq === state.seq) {
            state.loading = false;
            updateButtons();
        }
    }
}

/* ---------------------------------------------------------------- Visning */

function updateInfo() {
    const d = state.current;
    const v = state.values;
    const kinds = { holes: 0, values: 0, tasks: 0, key: 0 };
    d.pages.forEach((p) => kinds[p.kind]++);
    const parts = [`<span class="num">${d.pages.length} sider</span>`];
    parts.push(`${kinds.holes} hullark`);
    if (kinds.values) parts.push(`${kinds.values} verdiark`);
    if (kinds.tasks) parts.push(`${kinds.tasks} oppgaveark`);
    if (kinds.key) parts.push('fasit');
    parts.push(`${v.variants} ${v.variants === 1 ? 'variant' : 'varianter'}`);
    $('#model-info').innerHTML = parts.join(' · ');
    $('#pdf-sub').textContent = `${pdfName()} · ${d.pages.length} sider`;
}

function updateButtons() {
    $('#download-pdf').disabled = !(state.valid && !state.loading && state.current);
    sheets.refresh();
}

/* --------------------------------------------------------------------- 3D */

async function show3d() {
    if (!viewerReady) {
        viewerReady = (async () => {
            try {
                viewer = new MarbleViewer($('#viewer'));
            } catch {
                $('#viewer').innerHTML = '<p class="muted" style="padding:20px">3D-visning er ikke tilgjengelig i denne nettleseren (WebGL mangler).</p>';
                return;
            }
            bindViewButtons(viewer);
            viewer.setLifted($('#lift-board').checked);
            setStatus('Laster kulespillet …', true);
            try {
                await viewer.load(state.schema.files.board.path, state.schema.files.base.path);
                setStatus(null);
            } catch (err) {
                console.error(err);
                setStatus('Kunne ikke laste 3D-modellene', false);
            }
        })();
    }
    await viewerReady;
    if (viewer && state.current) updateSheet3d();
}

/** Hullarket for siden som er valgt (samme variant), eller det første. */
function updateSheet3d() {
    const pages = state.current.pages;
    const cur = pages[state.page];
    const holes = pages.filter((p) => p.kind === 'holes');
    const page = holes.find((p) => p.variant === cur.variant) ?? holes[0];
    if (page) viewer.setSheet(page, state.current.sheet).catch((err) => console.error(err));
}
