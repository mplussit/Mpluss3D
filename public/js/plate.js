/**
 * M+ 3D – 3MF-dialogen: bygg en byggeplate og lagre den som 3MF-fil.
 *
 * Læreren legger objekter (STL-filene på siden) på byggeplaten, med antall,
 * og kan gå videre til en annen modell og legge til flere, slik som med
 * oppsamlings-PDF-en. Objektene plasseres automatisk på platen (rader, med
 * valgt avstand), og toppvisningen viser hvordan de ligger. Platen kan være
 * en av de vanlige printerne, eller en størrelse læreren skriver inn selv.
 *
 * «Lagre 3MF» skriver én 3MF-fil (ZIP med 3D/3dmodel.model) der hvert objekt
 * er ett <object> og hvert eksemplar ett <item> med plassering på platen.
 * Alt skjer i nettleseren; STL-filene lagres i IndexedDB, så byggeplaten
 * er med selv om læreren bytter side.
 *
 * Bruk (fra sheets.js):
 *   initPlate(() => [{ filename, stl_url, count, label? }, …]);
 */

import { currentSide, sendForSider } from './stats.js';

const DB_NAME = 'mplus3d-byggeplate';
const STORE = 'objekter';
const PRINTER_KEY = 'mplus3d.plate.printer';
const CUSTOM_KEY = 'mplus3d.plate.custom';
const SPACING_KEY = 'mplus3d.plate.spacing';
const EDGE_MM = 5;          // fri kant langs platen
const MAX_COUNT = 99;

/** De vanligste printerne: byggeflate X × Y × Z i mm. */
export const PRINTERS = [
    { group: 'Bambu Lab', items: [
        { id: 'bambu-a1mini', name: 'Bambu Lab A1 mini', size: [180, 180, 180] },
        { id: 'bambu-a1', name: 'Bambu Lab A1', size: [256, 256, 256] },
        { id: 'bambu-p1', name: 'Bambu Lab P1S / P1P / P2S', size: [256, 256, 256] },
        { id: 'bambu-x1', name: 'Bambu Lab X1 Carbon / X1E', size: [256, 256, 256] },
        { id: 'bambu-h2d', name: 'Bambu Lab H2D', size: [350, 320, 325] },
    ] },
    { group: 'Prusa', items: [
        { id: 'prusa-mini', name: 'Prusa MINI+', size: [180, 180, 180] },
        { id: 'prusa-mk4', name: 'Prusa MK4S / MK4 / MK3S+', size: [250, 210, 220] },
        { id: 'prusa-core1', name: 'Prusa CORE One', size: [250, 220, 270] },
        { id: 'prusa-xl', name: 'Prusa XL', size: [360, 360, 360] },
    ] },
    { group: 'Creality', items: [
        { id: 'ender3', name: 'Creality Ender-3 / Ender-3 V3 SE', size: [220, 220, 250] },
        { id: 'k1', name: 'Creality K1 / K1C', size: [220, 220, 250] },
        { id: 'k1max', name: 'Creality K1 Max', size: [300, 300, 300] },
        { id: 'k2plus', name: 'Creality K2 Plus', size: [350, 350, 350] },
    ] },
    { group: 'Andre', items: [
        { id: 'elegoo-n4', name: 'Elegoo Neptune 4 / 4 Pro', size: [225, 225, 265] },
        { id: 'kobra3', name: 'Anycubic Kobra 3', size: [250, 250, 260] },
        { id: 'flashforge-5m', name: 'Flashforge Adventurer 5M', size: [220, 220, 220] },
        { id: 'ultimaker-s3', name: 'UltiMaker S3', size: [230, 190, 200] },
        { id: 'ultimaker-s5', name: 'UltiMaker S5', size: [330, 240, 300] },
    ] },
];
const ALL_PRINTERS = PRINTERS.flatMap((g) => g.items);
const DEFAULT_PRINTER = 'bambu-a1';

// Fargene på objektene i toppvisningen (gjentas ved mange objekter).
const COLORS = ['#f36d21', '#2e6db4', '#226b45', '#c8a415', '#8a3fa8', '#c81e1e', '#1d8a8a', '#7a5230'];

/**
 * @param {() => {filename: string, stl_url: string, count?: number, label?: string}[]} parts
 *        STL-filene på denne siden (tom liste mens modellen lages)
 */
let plate = null;   // dialogen på denne siden (initPlate)

/**
 * Legger STL-filer på byggeplaten uten å åpne dialogen (f.eks. knappen
 * «Legg valgt ramme på byggeplaten» i Regn med Lego). Gir antall objekter lagt til.
 * @param {{part: {filename: string, stl_url: string, label?: string}, count: number}[]} list
 */
export async function addToPlate(list) {
    if (!plate) throw new Error('Byggeplaten er ikke klar.');
    return plate.add(list);
}

export function initPlate(parts) {
    const open = document.getElementById('plate-open');
    const badge = document.getElementById('plate-count');
    if (!open) return;

    const dialog = document.createElement('dialog');
    dialog.className = 'dialog pdf-dialog plate-dialog';
    dialog.setAttribute('aria-labelledby', 'plate-dialog-title');
    dialog.innerHTML = `
        <h2 id="plate-dialog-title">3MF – byggeplate</h2>
        <div class="plate-layout">
            <div class="plate-view">
                <canvas class="plate-canvas" id="plate-canvas" role="img" aria-label="Byggeplaten sett ovenfra"></canvas>
                <p class="plate-summary" id="plate-summary" role="status"></p>
            </div>
            <div class="plate-side">
                <section class="pdf-section" aria-labelledby="plate-printer-heading">
                    <h3 id="plate-printer-heading">Byggeplate</h3>
                    <select id="plate-printer" class="plate-select" aria-label="Printer">
                        ${PRINTERS.map((g) => `<optgroup label="${esc(g.group)}">${g.items.map((p) =>
                            `<option value="${p.id}">${esc(p.name)} – ${p.size[0]} × ${p.size[1]} mm</option>`).join('')}</optgroup>`).join('')}
                        <option value="custom">Egendefinert størrelse …</option>
                    </select>
                    <div class="plate-custom" id="plate-custom" hidden>
                        <label>Bredde (X)<span><input type="number" id="plate-w" min="50" max="1000" step="1"> mm</span></label>
                        <label>Dybde (Y)<span><input type="number" id="plate-d" min="50" max="1000" step="1"> mm</span></label>
                        <label>Høyde (Z)<span><input type="number" id="plate-h" min="10" max="1000" step="1"> mm</span></label>
                    </div>
                    <label class="plate-spacing">Avstand mellom objektene
                        <span><input type="number" id="plate-spacing" min="0" max="50" step="1"> mm</span></label>
                </section>
                <section class="pdf-section" aria-labelledby="plate-page-heading">
                    <h3 id="plate-page-heading">Legg til fra denne siden</h3>
                    <div class="plate-parts" id="plate-parts"></div>
                    <p class="pdf-feedback" id="plate-feedback" role="status"></p>
                </section>
                <section class="pdf-section" aria-labelledby="plate-items-heading">
                    <h3 id="plate-items-heading">På byggeplaten</h3>
                    <ul class="plate-items" id="plate-items"></ul>
                </section>
            </div>
        </div>
        <section class="pdf-section pdf-options">
            <label class="pdf-filename">Filnavn
                <span><input type="text" id="plate-filename" maxlength="80"> .3mf</span></label>
            <div class="pdf-actions">
                <button type="button" class="btn btn-primary btn-small" id="plate-save">Lagre 3MF</button>
                <button type="button" class="btn btn-secondary btn-small" id="plate-clear">Tøm byggeplaten</button>
            </div>
            <p class="hint">NB: 3MF-filen lagres i nettleserens nedlastningsmappe. Åpne den i Bambu Studio, PrusaSlicer, OrcaSlicer, Cura eller en annen slicer.</p>
        </section>
        <form method="dialog"><button class="btn btn-secondary btn-small">Lukk</button></form>`;
    document.body.appendChild(dialog);

    const q = (sel) => dialog.querySelector(sel);
    const printer = q('#plate-printer');
    const custom = q('#plate-custom');
    const [wIn, dIn, hIn] = ['#plate-w', '#plate-d', '#plate-h'].map(q);
    const spacingIn = q('#plate-spacing');
    const filename = q('#plate-filename');
    const feedback = q('#plate-feedback');
    const canvas = q('#plate-canvas');

    let items = [];             // objektene på platen (fra IndexedDB)
    let layout = null;          // siste plassering
    let hover = null;           // id til objektet musa står over i lista
    let busy = false;
    let partsKey = '';
    let poll = null;

    /* ------------------------------------------------------------ Innstillinger */

    const saved = loadSettings();
    printer.value = ALL_PRINTERS.some((p) => p.id === saved.printer) || saved.printer === 'custom' ? saved.printer : DEFAULT_PRINTER;
    [wIn.value, dIn.value, hIn.value] = saved.custom;
    spacingIn.value = saved.spacing;
    custom.hidden = printer.value !== 'custom';

    printer.addEventListener('change', () => {
        custom.hidden = printer.value !== 'custom';
        storeSetting(PRINTER_KEY, printer.value);
        relayout();
    });
    custom.addEventListener('input', () => {
        storeSetting(CUSTOM_KEY, JSON.stringify([wIn.value, dIn.value, hIn.value]));
        relayout();
    });
    spacingIn.addEventListener('input', () => {
        storeSetting(SPACING_KEY, spacingIn.value);
        relayout();
    });

    /** Platen som er valgt: [bredde, dybde, høyde] i mm. */
    function plateSize() {
        if (printer.value === 'custom') {
            const num = (el, min, def) => {
                const v = parseFloat(el.value);
                return Number.isFinite(v) && v >= min ? Math.min(v, 1000) : def;
            };
            return [num(wIn, 50, 220), num(dIn, 50, 220), num(hIn, 10, 250)];
        }
        return (ALL_PRINTERS.find((p) => p.id === printer.value) ?? ALL_PRINTERS[0]).size;
    }

    function spacing() {
        const v = parseFloat(spacingIn.value);
        return Number.isFinite(v) ? Math.min(Math.max(v, 0), 50) : 5;
    }

    /* ------------------------------------------------------------ Åpne og lukke */

    dialog.addEventListener('click', (e) => { if (e.target === dialog) dialog.close(); });
    dialog.addEventListener('close', () => { clearInterval(poll); poll = null; });

    open.addEventListener('click', async () => {
        if (!filename.value) filename.value = `Mpluss3D_byggeplate_${today()}`;
        feedback.textContent = '';
        partsKey = '';
        renderParts();
        dialog.showModal();
        await reload();
        // Modellen kan være under arbeid når dialogen åpnes: følg med på STL-filene.
        poll = setInterval(renderParts, 800);
    });

    /* ------------------------------------------------------------ Legg til fra denne siden */

    function pageParts() {
        try {
            return (parts() ?? []).filter((p) => p?.stl_url && p?.filename);
        } catch {
            return [];
        }
    }

    function renderParts() {
        const list = pageParts();
        const key = list.map((p) => p.stl_url).join('|');
        if (key === partsKey && key) return;
        partsKey = key;
        const box = q('#plate-parts');
        if (!list.length) {
            box.innerHTML = '<p class="muted">Lager modellen …</p>';
            return;
        }
        box.innerHTML = `
            <div class="plate-parts-grid" role="group" aria-label="STL-filene på denne siden">
                <span class="pdf-col-head">Objekt</span><span class="pdf-col-head">Antall</span><span class="pdf-col-head"></span>
                ${list.map((p, i) => `
                    <span class="plate-part-name" title="${esc(p.filename)}">${esc(partLabel(p))}</span>
                    <input type="number" class="plate-num" data-count="${i}" min="1" max="${MAX_COUNT}" step="1"
                        value="${clampCount(p.count ?? 1)}" aria-label="Antall av ${esc(partLabel(p))}">
                    <button type="button" class="btn btn-secondary btn-small plate-add" data-add="${i}">Legg til</button>`).join('')}
            </div>
            ${list.length > 1 ? '<div class="pdf-actions"><button type="button" class="btn btn-primary btn-small" id="plate-add-all">Legg til alle</button></div>' : ''}`;
        updateButtons();
    }

    q('#plate-parts').addEventListener('click', (e) => {
        const list = pageParts();
        const one = e.target.closest('[data-add]');
        const all = e.target.closest('#plate-add-all');
        if (!one && !all) return;
        const picks = (one ? [Number(one.dataset.add)] : list.map((_, i) => i)).map((i) => {
            const input = q(`#plate-parts [data-count="${i}"]`);
            return { part: list[i], count: clampCount(input?.value ?? 1) };
        }).filter((p) => p.part);
        run(async () => {
            let n = 0;
            for (const { part, count } of picks) {
                await addPart(part, count);
                n += count;
            }
            feedback.textContent = n === 1 ? 'Lagt 1 objekt på byggeplaten.' : `Lagt ${n} objekter på byggeplaten.`;
        });
    });

    async function addPart(part, count) {
        const res = await fetch(part.stl_url);
        if (!res.ok) throw new Error('Kunne ikke hente STL-filen.');
        const bytes = await res.arrayBuffer();
        const crc = crc32(new Uint8Array(bytes));
        const same = items.find((it) => it.crc === crc && it.bytes.byteLength === bytes.byteLength);
        if (same) {
            same.count = clampCount(same.count + count);
            await store.put(same);
        } else {
            const mesh = parseStl(bytes);
            if (!mesh.count) throw new Error('STL-filen er tom.');
            await store.put({
                model: modelName(), side: currentSide(), label: partLabel(part), filename: part.filename,
                bytes, crc, size: mesh.size, count, rot: 0, added: Date.now(),
            });
        }
        await reload();
    }

    /* ------------------------------------------------------------ På byggeplaten */

    async function reload() {
        items = await store.all();
        relayout();
    }

    function relayout() {
        const [W, D, H] = plateSize();
        layout = arrange(items, W, D, H, spacing());
        renderItems();
        draw();
        updateBadge();
    }

    function renderItems() {
        const ul = q('#plate-items');
        if (!items.length) {
            ul.innerHTML = '<li class="muted">Byggeplaten er tom. Legg til objekter fra denne siden, '
                + 'eller gå til en annen modell og legg til derfra.</li>';
        } else {
            ul.innerHTML = items.map((it, i) => {
                const [w, d, h] = it.size;
                const missing = layout.missing.get(it.id) ?? 0;
                const note = layout.tooTall.has(it.id)
                    ? `<span class="plate-warn">For høy for platen (${fmt(h)} mm)</span>`
                    : missing ? `<span class="plate-warn">${missing} av ${it.count} får ikke plass</span>` : '';
                return `
                <li data-item="${it.id}">
                    <span class="plate-swatch" style="background:${COLORS[i % COLORS.length]}" aria-hidden="true"></span>
                    <span class="plate-item-text"><strong>${esc(it.label)}</strong>
                        <small>${esc(it.model)} · ${fmt(w)} × ${fmt(d)} × ${fmt(h)} mm</small>${note}</span>
                    <span class="plate-stepper">
                        <button type="button" data-step="-1" aria-label="Ett færre av ${esc(it.label)}">−</button>
                        <input type="number" class="plate-num" data-item-count min="1" max="${MAX_COUNT}" value="${it.count}" aria-label="Antall av ${esc(it.label)}">
                        <button type="button" data-step="1" aria-label="Ett til av ${esc(it.label)}">+</button>
                    </span>
                    <button type="button" class="pdf-remove plate-rotate" data-rotate aria-pressed="${it.rot === 90}"
                        title="Drei 90°" aria-label="Drei ${esc(it.label)} 90 grader">⟳</button>
                    <button type="button" class="pdf-remove" data-remove aria-label="Fjern ${esc(it.label)} fra byggeplaten">✕</button>
                </li>`;
            }).join('');
        }
        const placed = layout.placed.length;
        const total = items.reduce((n, it) => n + it.count, 0);
        const [W, D] = plateSize();
        const summary = q('#plate-summary');
        summary.classList.toggle('plate-warn', placed < total);
        summary.textContent = !total
            ? `${fmt(W)} × ${fmt(D)} mm`
            : placed < total
                ? `${placed} av ${total} objekter får plass. Velg en større byggeplate, mindre avstand eller færre objekter.`
                : `${total} ${total === 1 ? 'objekt' : 'objekter'} på platen (${fmt(W)} × ${fmt(D)} mm).`;
        updateButtons();
    }

    const itemOf = (el) => items.find((it) => it.id === Number(el.closest('[data-item]')?.dataset.item));

    q('#plate-items').addEventListener('click', (e) => {
        const it = itemOf(e.target);
        if (!it) return;
        if (e.target.closest('[data-remove]')) {
            run(async () => { await store.remove(it.id); await reload(); });
        } else if (e.target.closest('[data-rotate]')) {
            it.rot = it.rot === 90 ? 0 : 90;
            run(async () => { await store.put(it); relayout(); });
        } else if (e.target.closest('[data-step]')) {
            const n = clampCount(it.count + Number(e.target.closest('[data-step]').dataset.step));
            if (n === it.count) return;
            it.count = n;
            run(async () => { await store.put(it); relayout(); });
        }
    });

    q('#plate-items').addEventListener('change', (e) => {
        if (!e.target.matches('[data-item-count]')) return;
        const it = itemOf(e.target);
        if (!it) return;
        it.count = clampCount(e.target.value);
        run(async () => { await store.put(it); relayout(); });
    });

    q('#plate-items').addEventListener('mouseover', (e) => setHover(itemOf(e.target)?.id ?? null));
    q('#plate-items').addEventListener('mouseleave', () => setHover(null));
    function setHover(id) {
        if (id === hover) return;
        hover = id;
        draw();
    }

    q('#plate-clear').addEventListener('click', () => {
        if (!items.length || !confirm('Er du sikker på at du vil tømme byggeplaten?')) return;
        run(async () => { await store.clear(); feedback.textContent = ''; await reload(); });
    });

    q('#plate-save').addEventListener('click', () => run(async () => {
        if (!layout.placed.length) return;
        const total = items.reduce((n, it) => n + it.count, 0);
        if (layout.placed.length < total
            && !confirm(`${total - layout.placed.length} av ${total} objekter får ikke plass på platen og blir ikke med i filen. Vil du lagre likevel?`)) return;
        const bytes = await write3mf(items, layout, filename.value.trim() || 'M+ 3D');
        save(bytes, fileName());
        // Statistikk: én «fil» per modell som har objekter i filen.
        sendForSider(items.filter((it) => layout.placed.some((p) => p.id === it.id))
            .map((it) => it.side || currentSide()), 'fil');
        feedback.textContent = '';
    }));

    /* ------------------------------------------------------------ Toppvisning */

    const silhouettes = new Map();   // id → { canvas, pxPerMm }

    function silhouette(it, color) {
        const key = `${it.id}:${color}`;
        if (silhouettes.has(key)) return silhouettes.get(key);
        const mesh = parseStl(it.bytes);
        const [w, d] = mesh.size;
        const pxPerMm = Math.min(4, 1200 / Math.max(w, d, 1));
        const c = document.createElement('canvas');
        c.width = Math.max(2, Math.ceil(w * pxPerMm) + 2);
        c.height = Math.max(2, Math.ceil(d * pxPerMm) + 2);
        const ctx = c.getContext('2d');
        ctx.fillStyle = color;
        ctx.strokeStyle = color;
        ctx.lineWidth = 0.6;
        const p = mesh.positions;
        const [minX, minY] = mesh.min;
        // Skjermkoordinater: x mot høyre, y nedover (bakkant øverst).
        const sx = (x) => 1 + (x - minX) * pxPerMm;
        const sy = (y) => 1 + (d - (y - minY)) * pxPerMm;
        // Alle trekantene tegnes med samme omløp, ellers opphever topp- og
        // bunnflatene hverandre når stien fylles (nonzero).
        ctx.beginPath();
        for (let i = 0; i < p.length; i += 9) {
            const ax = sx(p[i]), ay = sy(p[i + 1]);
            let bx = sx(p[i + 3]), by = sy(p[i + 4]);
            let cx = sx(p[i + 6]), cy = sy(p[i + 7]);
            const area = (bx - ax) * (cy - ay) - (by - ay) * (cx - ax);
            if (Math.abs(area) < 1e-9) continue;
            if (area < 0) [bx, by, cx, cy] = [cx, cy, bx, by];
            ctx.moveTo(ax, ay);
            ctx.lineTo(bx, by);
            ctx.lineTo(cx, cy);
            ctx.closePath();
        }
        ctx.fill();
        ctx.stroke();
        const s = { canvas: c, pxPerMm };
        silhouettes.set(key, s);
        return s;
    }

    function draw() {
        const [W, D] = plateSize();
        const box = canvas.parentElement;
        const maxW = box.clientWidth || 360;
        const maxH = Math.min(420, window.innerHeight * 0.5);
        const scale = Math.min(maxW / W, maxH / D);
        const dpr = window.devicePixelRatio || 1;
        const cw = Math.round(W * scale);
        const ch = Math.round(D * scale);
        canvas.style.width = `${cw}px`;
        canvas.style.height = `${ch}px`;
        canvas.width = Math.round(cw * dpr);
        canvas.height = Math.round(ch * dpr);
        const ctx = canvas.getContext('2d');
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        ctx.fillStyle = '#ece8e1';
        ctx.fillRect(0, 0, cw, ch);

        // Rutenett: tynn linje hver 10. mm, tydeligere hver 50. mm.
        for (let mm = 10; mm < Math.max(W, D); mm += 10) {
            ctx.strokeStyle = mm % 50 === 0 ? 'rgba(22,22,22,.18)' : 'rgba(22,22,22,.07)';
            ctx.lineWidth = 1;
            ctx.beginPath();
            if (mm < W) { ctx.moveTo(mm * scale + 0.5, 0); ctx.lineTo(mm * scale + 0.5, ch); }
            if (mm < D) { ctx.moveTo(0, ch - mm * scale + 0.5); ctx.lineTo(cw, ch - mm * scale + 0.5); }
            ctx.stroke();
        }
        ctx.strokeStyle = 'rgba(22,22,22,.45)';
        ctx.strokeRect(0.5, 0.5, cw - 1, ch - 1);

        if (!layout) return;
        const colorOf = new Map(items.map((it, i) => [it.id, COLORS[i % COLORS.length]]));
        for (const pl of layout.placed) {
            const it = items.find((x) => x.id === pl.id);
            if (!it) continue;
            const s = silhouette(it, colorOf.get(it.id));
            const k = scale / s.pxPerMm;
            ctx.save();
            ctx.translate(pl.cx * scale, ch - pl.cy * scale);
            if (pl.rot === 90) ctx.rotate(-Math.PI / 2);
            ctx.globalAlpha = hover == null || hover === it.id ? 0.9 : 0.35;
            ctx.drawImage(s.canvas, -s.canvas.width * k / 2, -s.canvas.height * k / 2, s.canvas.width * k, s.canvas.height * k);
            ctx.restore();
            if (hover === it.id) {
                ctx.strokeStyle = '#161616';
                ctx.lineWidth = 1.5;
                ctx.strokeRect((pl.cx - pl.w / 2) * scale, ch - (pl.cy + pl.d / 2) * scale, pl.w * scale, pl.d * scale);
            }
        }
    }

    /* ------------------------------------------------------------ Felles */

    function updateButtons() {
        q('#plate-save').disabled = busy || !layout?.placed.length;
        q('#plate-clear').hidden = !items.length;
        dialog.querySelectorAll('#plate-parts button').forEach((b) => (b.disabled = busy));
    }

    function updateBadge() {
        const count = items.reduce((n, it) => n + it.count, 0);
        badge.textContent = String(count);
        badge.hidden = !count;
        open.setAttribute('aria-label', count ? `3MF, byggeplaten har ${count} ${count === 1 ? 'objekt' : 'objekter'}` : '3MF');
    }

    async function run(task) {
        if (busy) return;
        busy = true;
        updateButtons();
        dialog.setAttribute('aria-busy', 'true');
        try {
            await task();
        } catch (err) {
            console.error(err);
            feedback.textContent = 'Noe gikk galt. Prøv igjen.';
        } finally {
            busy = false;
            dialog.removeAttribute('aria-busy');
            updateButtons();
        }
    }

    function fileName() {
        const base = filename.value.trim().replace(/\.3mf$/i, '').replace(/[\\/:*?"<>|]+/g, '-') || `Mpluss3D_byggeplate_${today()}`;
        return `${base}.3mf`;
    }

    store.all().then((all) => { items = all; updateBadge(); }).catch(() => {});

    plate = {
        async add(list) {
            items = await store.all();
            let n = 0;
            for (const { part, count } of list) {
                const c = clampCount(count);
                await addPart(part, c);
                n += c;
            }
            return n;
        },
    };
}

/* ------------------------------------------------------------ Plassering */

/**
 * Legger eksemplarene i rader (største dybde først), med avstand mellom
 * objektene og helst en fri kant langs platen, og midtstiller det hele.
 * Et objekt som ikke får plass slik det står, dreies 90° hvis det hjelper.
 */
export function arrange(items, W, D, H, gap) {
    // Først med fri kant langs platen; går tett inntil kanten bare hvis flere da får plass.
    const roomy = arrangeWithEdge(items, W, D, H, gap, EDGE_MM);
    if (!roomy.missing.size) return roomy;
    const tight = arrangeWithEdge(items, W, D, H, gap, 0);
    return tight.placed.length > roomy.placed.length ? tight : roomy;
}

function arrangeWithEdge(items, W, D, H, gap, edge) {
    const uw = W - 2 * edge;
    const ud = D - 2 * edge;
    const rects = [];
    const missing = new Map();
    const tooTall = new Set();
    for (const it of items) {
        const [w, d, h] = it.size;
        if (h > H + 1e-6) { tooTall.add(it.id); missing.set(it.id, it.count); continue; }
        let rot = it.rot === 90 ? 90 : 0;
        const dims = (r) => (r === 90 ? [d, w] : [w, d]);
        let [rw, rd] = dims(rot);
        if ((rw > uw || rd > ud) && (() => { const [a, b] = dims(90 - rot); return a <= uw && b <= ud; })()) {
            rot = 90 - rot;
            [rw, rd] = dims(rot);
        }
        for (let n = 0; n < it.count; n++) rects.push({ id: it.id, w: rw, d: rd, rot });
    }
    rects.sort((a, b) => b.d - a.d || b.w - a.w);

    const shelves = [];
    const placed = [];
    for (const r of rects) {
        let shelf = shelves.find((s) => s.x + r.w <= uw + 1e-6 && r.d <= s.d + 1e-6);
        if (!shelf) {
            const last = shelves[shelves.length - 1];
            const y = last ? last.y + last.d + gap : 0;
            if (r.w <= uw + 1e-6 && y + r.d <= ud + 1e-6) {
                shelf = { y, d: r.d, x: 0 };
                shelves.push(shelf);
            }
        }
        if (!shelf) {
            missing.set(r.id, (missing.get(r.id) ?? 0) + 1);
            continue;
        }
        placed.push({ ...r, x: shelf.x, y: shelf.y });
        shelf.x += r.w + gap;
    }

    // Midtstill radene hver for seg vannrett, og hele bunken loddrett.
    const usedD = shelves.length ? shelves[shelves.length - 1].y + shelves[shelves.length - 1].d : 0;
    const offY = edge + (ud - usedD) / 2;
    for (const s of shelves) s.offX = edge + (uw - (s.x - gap)) / 2;
    for (const p of placed) {
        const s = shelves.find((sh) => sh.y === p.y);
        p.cx = s.offX + p.x + p.w / 2;
        p.cy = D - (offY + p.y + p.d / 2);   // første rad bakerst på platen
    }
    return { placed, missing, tooTall };
}

/* ------------------------------------------------------------ STL */

const meshCache = new WeakMap();

/** Leser binær eller ASCII-STL. Gir trekantene og målene (bbox). */
export function parseStl(buffer) {
    if (meshCache.has(buffer)) return meshCache.get(buffer);
    const view = new DataView(buffer);
    let positions;
    const n = buffer.byteLength >= 84 ? view.getUint32(80, true) : 0;
    if (buffer.byteLength >= 84 && 84 + n * 50 === buffer.byteLength) {
        positions = new Float32Array(n * 9);
        for (let i = 0; i < n; i++) {
            const o = 84 + i * 50 + 12;
            for (let k = 0; k < 9; k++) positions[i * 9 + k] = view.getFloat32(o + k * 4, true);
        }
    } else {
        const text = new TextDecoder().decode(buffer);
        const nums = [];
        const re = /vertex\s+(\S+)\s+(\S+)\s+(\S+)/g;
        let m;
        while ((m = re.exec(text))) nums.push(+m[1], +m[2], +m[3]);
        positions = new Float32Array(nums.length - (nums.length % 9));
        positions.set(nums.slice(0, positions.length));
    }
    const min = [Infinity, Infinity, Infinity];
    const max = [-Infinity, -Infinity, -Infinity];
    for (let i = 0; i < positions.length; i += 3) {
        for (let k = 0; k < 3; k++) {
            const v = positions[i + k];
            if (v < min[k]) min[k] = v;
            if (v > max[k]) max[k] = v;
        }
    }
    const count = positions.length / 9;
    const size = count ? max.map((v, k) => round2(v - min[k])) : [0, 0, 0];
    const mesh = { positions, count, min, max, size };
    meshCache.set(buffer, mesh);
    return mesh;
}

/* ------------------------------------------------------------ 3MF */

/**
 * Lager 3MF-filen: ett objekt per STL (midtstilt i x/y, bunnen på z = 0) og
 * ett build-item per eksemplar, flyttet (og eventuelt dreid) til plassen på platen.
 */
export async function write3mf(items, layout, title) {
    const used = items.filter((it) => layout.placed.some((p) => p.id === it.id));
    const objectId = new Map(used.map((it, i) => [it.id, i + 1]));
    const parts = [];
    parts.push('<?xml version="1.0" encoding="UTF-8"?>\n'
        + '<model unit="millimeter" xml:lang="nb-NO" xmlns="http://schemas.microsoft.com/3dmanufacturing/core/2015/02">\n'
        + ` <metadata name="Title">${xml(title)}</metadata>\n`
        + ' <metadata name="Application">M+ 3D</metadata>\n'
        + ` <metadata name="CreationDate">${new Date().toISOString().slice(0, 10)}</metadata>\n`
        + ' <resources>\n');
    for (const it of used) {
        const mesh = parseStl(it.bytes);
        const cx = (mesh.min[0] + mesh.max[0]) / 2;
        const cy = (mesh.min[1] + mesh.max[1]) / 2;
        const z0 = mesh.min[2];
        const index = new Map();
        const verts = [];
        const tris = [];
        const p = mesh.positions;
        const vid = (i) => {
            const x = num(p[i] - cx), y = num(p[i + 1] - cy), z = num(p[i + 2] - z0);
            const key = `${x} ${y} ${z}`;
            let id = index.get(key);
            if (id === undefined) {
                id = verts.length;
                index.set(key, id);
                verts.push(`<vertex x="${x}" y="${y}" z="${z}"/>`);
            }
            return id;
        };
        for (let i = 0; i < p.length; i += 9) {
            const a = vid(i), b = vid(i + 3), c = vid(i + 6);
            if (a !== b && b !== c && a !== c) tris.push(`<triangle v1="${a}" v2="${b}" v3="${c}"/>`);
        }
        parts.push(`  <object id="${objectId.get(it.id)}" type="model" name="${xml(it.label)}">\n   <mesh>\n    <vertices>\n`
            + verts.join('\n') + '\n    </vertices>\n    <triangles>\n' + tris.join('\n')
            + '\n    </triangles>\n   </mesh>\n  </object>\n');
    }
    parts.push(' </resources>\n <build>\n');
    for (const pl of layout.placed) {
        // Radvektor-konvensjon i 3MF: p' = p · M. Dreiing 90° mot klokka: (x, y) → (−y, x).
        const m = pl.rot === 90 ? '0 1 0 -1 0 0 0 0 1' : '1 0 0 0 1 0 0 0 1';
        parts.push(`  <item objectid="${objectId.get(pl.id)}" transform="${m} ${num(pl.cx)} ${num(pl.cy)} 0" printable="1"/>\n`);
    }
    parts.push(' </build>\n</model>\n');

    const enc = new TextEncoder();
    return zip([
        { name: '[Content_Types].xml', data: enc.encode('<?xml version="1.0" encoding="UTF-8"?>\n'
            + '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            + '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            + '<Default Extension="model" ContentType="application/vnd.ms-package.3dmanufacturing-3dmodel+xml"/>'
            + '</Types>') },
        { name: '_rels/.rels', data: enc.encode('<?xml version="1.0" encoding="UTF-8"?>\n'
            + '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            + '<Relationship Target="/3D/3dmodel.model" Id="rel0" Type="http://schemas.microsoft.com/3dmanufacturing/2013/01/3dmodel"/>'
            + '</Relationships>') },
        { name: '3D/3dmodel.model', data: enc.encode(parts.join('')) },
    ]);
}

/* ------------------------------------------------------------ ZIP */

/** Enkel ZIP-skriver: deflate med CompressionStream når nettleseren har det, ellers lagret ukomprimert. */
export async function zip(files) {
    const enc = new TextEncoder();
    const chunks = [];
    const central = [];
    let offset = 0;
    const { dosTime, dosDate } = dosNow();
    for (const f of files) {
        const name = enc.encode(f.name);
        const crc = crc32(f.data);
        let data = f.data;
        let method = 0;
        const deflated = await deflateRaw(f.data);
        if (deflated && deflated.length < f.data.length) { data = deflated; method = 8; }
        const local = new DataView(new ArrayBuffer(30));
        local.setUint32(0, 0x04034b50, true);
        local.setUint16(4, 20, true);
        local.setUint16(6, 0x0800, true);          // UTF-8-navn
        local.setUint16(8, method, true);
        local.setUint16(10, dosTime, true);
        local.setUint16(12, dosDate, true);
        local.setUint32(14, crc, true);
        local.setUint32(18, data.length, true);
        local.setUint32(22, f.data.length, true);
        local.setUint16(26, name.length, true);
        local.setUint16(28, 0, true);
        chunks.push(new Uint8Array(local.buffer), name, data);

        const cd = new DataView(new ArrayBuffer(46));
        cd.setUint32(0, 0x02014b50, true);
        cd.setUint16(4, 20, true);
        cd.setUint16(6, 20, true);
        cd.setUint16(8, 0x0800, true);
        cd.setUint16(10, method, true);
        cd.setUint16(12, dosTime, true);
        cd.setUint16(14, dosDate, true);
        cd.setUint32(16, crc, true);
        cd.setUint32(20, data.length, true);
        cd.setUint32(24, f.data.length, true);
        cd.setUint16(28, name.length, true);
        cd.setUint32(42, offset, true);
        central.push(new Uint8Array(cd.buffer), name);
        offset += 30 + name.length + data.length;
    }
    const cdSize = central.reduce((n, c) => n + c.length, 0);
    const end = new DataView(new ArrayBuffer(22));
    end.setUint32(0, 0x06054b50, true);
    end.setUint16(8, files.length, true);
    end.setUint16(10, files.length, true);
    end.setUint32(12, cdSize, true);
    end.setUint32(16, offset, true);
    const out = new Uint8Array(offset + cdSize + 22);
    let pos = 0;
    for (const c of [...chunks, ...central, new Uint8Array(end.buffer)]) { out.set(c, pos); pos += c.length; }
    return out;
}

async function deflateRaw(data) {
    if (typeof CompressionStream === 'undefined') return null;
    try {
        const stream = new Blob([data]).stream().pipeThrough(new CompressionStream('deflate-raw'));
        return new Uint8Array(await new Response(stream).arrayBuffer());
    } catch {
        return null;
    }
}

let crcTable = null;
export function crc32(bytes) {
    if (!crcTable) {
        crcTable = new Uint32Array(256);
        for (let n = 0; n < 256; n++) {
            let c = n;
            for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
            crcTable[n] = c >>> 0;
        }
    }
    let crc = 0xffffffff;
    for (let i = 0; i < bytes.length; i++) crc = crcTable[(crc ^ bytes[i]) & 0xff] ^ (crc >>> 8);
    return (crc ^ 0xffffffff) >>> 0;
}

function dosNow() {
    const d = new Date();
    return {
        dosTime: (d.getHours() << 11) | (d.getMinutes() << 5) | (d.getSeconds() >> 1),
        dosDate: ((d.getFullYear() - 1980) << 9) | ((d.getMonth() + 1) << 5) | d.getDate(),
    };
}

/* ------------------------------------------------------------ Lagring (IndexedDB) */

// Faller tilbake til minnet hvis IndexedDB ikke er tilgjengelig (f.eks. privat
// vindu i noen nettlesere). Da forsvinner byggeplaten når siden byttes.
const store = (() => {
    let memory = [];
    let nextId = 1;
    let dbPromise = null;

    function db() {
        dbPromise ??= new Promise((resolve, reject) => {
            const req = indexedDB.open(DB_NAME, 1);
            req.onupgradeneeded = () => req.result.createObjectStore(STORE, { keyPath: 'id', autoIncrement: true });
            req.onsuccess = () => resolve(req.result);
            req.onerror = () => reject(req.error);
        }).catch(() => null);
        return dbPromise;
    }

    async function tx(mode, fn) {
        const d = await db();
        if (!d) return null;
        return new Promise((resolve, reject) => {
            const t = d.transaction(STORE, mode);
            const req = fn(t.objectStore(STORE));
            t.oncomplete = () => resolve(req?.result);
            t.onerror = () => reject(t.error);
        });
    }

    return {
        async all() {
            const r = await tx('readonly', (s) => s.getAll()).catch(() => null);
            return (r ?? memory).slice().sort((a, b) => a.added - b.added);
        },
        async put(item) {
            const id = await tx('readwrite', (s) => s.put(item)).catch(() => null);
            if (id != null) { item.id = id; return; }
            if (item.id == null) item.id = nextId++;
            memory = memory.filter((it) => it.id !== item.id).concat(item);
        },
        async remove(id) {
            await tx('readwrite', (s) => s.delete(id)).catch(() => null);
            memory = memory.filter((it) => it.id !== id);
        },
        async clear() {
            await tx('readwrite', (s) => s.clear()).catch(() => null);
            memory = [];
        },
    };
})();

/* ------------------------------------------------------------ Hjelpere */

function loadSettings() {
    const get = (k) => { try { return localStorage.getItem(k); } catch { return null; } };
    let customSize = ['220', '220', '250'];
    try {
        const c = JSON.parse(get(CUSTOM_KEY) ?? 'null');
        if (Array.isArray(c) && c.length === 3) customSize = c.map(String);
    } catch { /* bruk standard */ }
    return { printer: get(PRINTER_KEY) ?? DEFAULT_PRINTER, custom: customSize, spacing: get(SPACING_KEY) ?? '5' };
}

function storeSetting(key, value) {
    try { localStorage.setItem(key, value); } catch { /* ikke viktig */ }
}

function partLabel(p) {
    return p.label || String(p.filename).replace(/\.stl$/i, '');
}

function clampCount(v) {
    const n = Math.round(Number(v));
    return Number.isFinite(n) ? Math.min(Math.max(n, 1), MAX_COUNT) : 1;
}

function save(bytes, name) {
    const a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob([bytes], { type: 'model/3mf' }));
    a.download = name;
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(a.href), 10000);
}

function modelName() {
    return document.querySelector('.topbar h1')?.textContent.trim() || 'M+ 3D';
}

function today() {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

const round2 = (v) => Math.round(v * 100) / 100;
const num = (v) => String(Math.round(v * 10000) / 10000);
const fmt = (n) => Number(n).toLocaleString('nb-NO', { maximumFractionDigits: 1 });

function xml(s) {
    return String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&apos;' }[c]));
}

function esc(s) {
    return String(s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
}
