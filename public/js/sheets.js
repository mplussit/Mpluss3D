/**
 * M+ 3D – arkfaner: 3D-faner og PDF-faner over forhåndsvisningen.
 *
 * 3D-fanene er visningsvalgene siden allerede har (en radiogruppe, f.eks.
 * «Samlet | Enkeltobjekt | Tallbygger»). Gruppen skjules, og et klikk på en
 * fane velger det tilsvarende valget, så sidens egen kode gjør resten.
 *
 * PDF-fanene er én fane per side i PDF-en(e). Serveren lager PDF-en med de
 * samme parameterne som nedlastingen, og sidene tegnes med pdf.js. Det er
 * altså nøyaktig den samme fila læreren får. Fanenavnene kommer fra serveren
 * (X-Page-Titles). PDF-ene hentes på nytt når innstillingene endres.
 *
 * Bruk:
 *   const sheets = initSheets({
 *       threeD: { group: '#mode-options', label: 'Klossene' },   // eller bare label
 *       docs: [{ key: 'pdf', request: () => ({ url, body }) }],
 *   });
 *   sheets.refresh();   // etter hver endring i innstillingene
 */
import * as pdfjs from 'pdfjs-dist';

pdfjs.GlobalWorkerOptions.workerSrc = 'https://cdn.jsdelivr.net/npm/pdfjs-dist@6.2.108/build/pdf.worker.min.mjs';

const DEBOUNCE_MS = 400;

export function initSheets({ threeD = {}, docs = [] }) {
    const wrap = document.querySelector('.viewer-wrap');
    const bar = document.getElementById('sheet-tabs');
    // Fanene står i hele bredden rett under toppfeltet.
    document.querySelector('.layout')?.before(bar);
    const pane = document.createElement('div');
    pane.className = 'sheet-pane';
    pane.hidden = true;
    pane.innerHTML = `
        <div class="sheet-stage"><canvas class="sheet-canvas" role="img"></canvas><p class="sheet-note" role="status" hidden></p></div>
        <p class="hint sheet-hint">Forhåndsvisningen er den samme PDF-en som du laster ned.</p>`;
    wrap.querySelector('.viewer-surface').after(pane);
    const stage = pane.querySelector('.sheet-stage');
    const canvas = pane.querySelector('canvas');
    const note = pane.querySelector('.sheet-note');

    const group = threeD.group ? document.querySelector(threeD.group) : null;
    if (group) group.closest('.control-group')?.setAttribute('hidden', '');

    const state = {
        active: { kind: '3d', index: 0 },
        loaded: docs.map(() => ({ key: null, doc: null, titles: [], error: null })),
        seq: 0,
        timer: null,
        renderTask: null,
    };

    /* ------------------------------------------------------------ Faner */

    function threeDOptions() {
        if (!group) return [{ label: threeD.label ?? '3D-modell', input: null }];
        return [...group.querySelectorAll('input[type=radio]')].map((input) => ({
            label: input.closest('label').textContent.trim(),
            input,
        }));
    }

    function pdfPages() {
        const pages = [];
        state.loaded.forEach((d, di) => {
            if (d.error) {
                pages.push({ doc: di, page: 0, label: 'Kan ikke lage arket', error: d.error });
                return;
            }
            const n = d.doc ? d.doc.numPages : 0;
            for (let p = 1; p <= n; p++) {
                pages.push({ doc: di, page: p, label: d.titles[p - 1] || `Side ${p}` });
            }
        });
        return pages;
    }

    function renderBar() {
        const three = threeDOptions();
        const pages = pdfPages();
        if (state.active.kind === '3d' && group) {
            const i = three.findIndex((o) => o.input.checked);
            state.active.index = Math.max(0, i);
        }
        if (state.active.kind === 'pdf' && state.active.index >= pages.length) {
            state.active.index = Math.max(0, pages.length - 1);
        }
        const tab = (kind, i, label) => {
            const on = state.active.kind === kind && state.active.index === i;
            return `<button type="button" role="tab" class="sheet-tab sheet-tab-${kind}" data-kind="${kind}" data-index="${i}"`
                + ` aria-selected="${on}" tabindex="${on ? 0 : -1}" title="${esc(label)}">${esc(label)}</button>`;
        };
        const loading = docs.length && !pages.length && !state.loaded.some((d) => d.error);
        bar.innerHTML = `
            <span class="sheet-group" role="presentation">3D</span>
            ${three.map((o, i) => tab('3d', i, o.label)).join('')}
            ${docs.length ? '<span class="sheet-group sheet-group-pdf" role="presentation">PDF</span>' : ''}
            ${pages.map((p, i) => tab('pdf', i, p.label)).join('')}
            ${loading ? '<span class="sheet-loading">Lager arkene …</span>' : ''}`;
    }

    bar.setAttribute('role', 'tablist');
    bar.setAttribute('aria-label', 'Faner: 3D-modell og sidene i PDF-en');
    bar.addEventListener('click', (e) => {
        const b = e.target.closest('.sheet-tab');
        if (b) select(b.dataset.kind, Number(b.dataset.index), true);
    });
    bar.addEventListener('keydown', (e) => {
        if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(e.key)) return;
        const tabs = [...bar.querySelectorAll('.sheet-tab')];
        const cur = tabs.indexOf(document.activeElement);
        if (cur < 0) return;
        e.preventDefault();
        const next = e.key === 'Home' ? 0 : e.key === 'End' ? tabs.length - 1
            : (cur + (e.key === 'ArrowRight' ? 1 : -1) + tabs.length) % tabs.length;
        tabs[next].click();
        bar.querySelectorAll('.sheet-tab')[next]?.focus();
    });
    group?.addEventListener('change', () => { if (state.active.kind === '3d') renderBar(); });

    function select(kind, index, focus = false) {
        state.active = { kind, index };
        if (kind === '3d') {
            const opt = threeDOptions()[index];
            if (opt?.input && !opt.input.checked) {
                opt.input.checked = true;
                opt.input.dispatchEvent(new Event('change', { bubbles: true }));
            }
        }
        wrap.dataset.tab = kind;
        pane.hidden = kind !== 'pdf';
        renderBar();
        if (focus) bar.querySelector('[aria-selected="true"]')?.focus();
        if (kind === 'pdf') drawPage();
        else window.dispatchEvent(new Event('resize'));   // 3D-visningen får riktig størrelse igjen
    }

    /* ------------------------------------------------------------ PDF */

    // PDF-ene hentes uavhengig av 3D-modellen. Er innstillingene ugyldige,
    // svarer serveren med en feilmelding, og den vises i stedet for arket.
    function refresh() {
        clearTimeout(state.timer);
        state.timer = setTimeout(load, DEBOUNCE_MS);
    }

    async function load() {
        const seq = ++state.seq;
        await Promise.all(docs.map(async (d, i) => {
            const req = d.request();
            const key = req.url + '|' + JSON.stringify(req.body ?? null);
            const cur = state.loaded[i];
            if (cur.key === key && (cur.doc || cur.error)) return;
            try {
                const res = req.body
                    ? await fetch(req.url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(req.body) })
                    : await fetch(req.url);
                const type = res.headers.get('Content-Type') || '';
                if (!res.ok || !type.includes('application/pdf')) {
                    const data = await res.json().catch(() => ({}));
                    throw new Error(data.error || 'Arket kunne ikke lages.');
                }
                const titles = parseTitles(res.headers.get('X-Page-Titles'));
                const data = new Uint8Array(await res.arrayBuffer());
                const doc = await pdfjs.getDocument({ data, isEvalSupported: false }).promise;
                if (seq !== state.seq) { doc.destroy(); return; }
                cur.doc?.destroy();
                Object.assign(cur, { key, doc, titles, error: null });
            } catch (err) {
                if (seq !== state.seq) return;
                cur.doc?.destroy();
                Object.assign(cur, { key, doc: null, titles: [], error: err.message || 'Arket kunne ikke lages.' });
            }
        }));
        if (seq !== state.seq) return;
        renderBar();
        if (state.active.kind === 'pdf') drawPage();
    }

    async function drawPage() {
        const page = pdfPages()[state.active.index];
        if (!page) return showNote('Lager arkene …');
        if (page.error) return showNote(page.error);
        const pdfPage = await state.loaded[page.doc].doc.getPage(page.page);
        const zoom = window.m3dScale?.get() ?? 1;
        const box = stage.getBoundingClientRect();
        const unscaled = pdfPage.getViewport({ scale: 1 });
        // Plass i CSS-piksler inne i den (eventuelt forstørrede) flaten.
        const availW = Math.max(100, box.width / zoom - 24);
        const availH = Math.max(100, box.height / zoom - 24);
        const fit = Math.min(availW / unscaled.width, availH / unscaled.height);
        const ratio = (window.devicePixelRatio || 1) * zoom;
        const viewport = pdfPage.getViewport({ scale: fit * ratio });
        state.renderTask?.cancel();
        canvas.width = Math.round(viewport.width);
        canvas.height = Math.round(viewport.height);
        canvas.style.width = `${Math.round(unscaled.width * fit)}px`;
        canvas.style.height = `${Math.round(unscaled.height * fit)}px`;
        canvas.setAttribute('aria-label', `${page.label}, side ${page.page} i PDF-en`);
        note.hidden = true;
        canvas.hidden = false;
        const task = pdfPage.render({ canvas, canvasContext: canvas.getContext('2d'), viewport });
        state.renderTask = task;
        try {
            await task.promise;
        } catch { /* avbrutt av en nyere tegning */ }
    }

    function showNote(text) {
        canvas.hidden = true;
        note.hidden = false;
        note.textContent = text;
    }

    let resizeTimer;
    const redraw = () => {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(() => { if (state.active.kind === 'pdf') drawPage(); }, 120);
    };
    window.addEventListener('m3d-scale', redraw);
    new ResizeObserver(redraw).observe(stage);

    // Valg som bare påvirker PDF-en (f.eks. nivå eller dekor) gir ikke alltid
    // ny 3D-modell. Sjekk derfor PDF-ene etter hver endring i panelet også.
    // load() henter bare PDF-er som faktisk er endret.
    const panel = document.querySelector('.panel');
    for (const type of ['input', 'change', 'click']) {
        panel?.addEventListener(type, () => setTimeout(refresh, 0));
    }

    wrap.dataset.tab = '3d';
    renderBar();
    return { refresh, select };
}

function parseTitles(header) {
    try {
        const list = JSON.parse(decodeURIComponent(header || '[]'));
        return Array.isArray(list) ? list.map(String) : [];
    } catch {
        return [];
    }
}

function esc(s) {
    return String(s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
}
