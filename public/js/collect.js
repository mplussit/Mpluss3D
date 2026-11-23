/**
 * M+ 3D – PDF-dialogen og oppsamlings-PDF, som i M+.
 *
 * Fra dialogen kan læreren
 *   - lage en PDF med sidene som er valgt (direkte nedlasting), eller
 *   - legge de valgte sidene til i oppsamlings-PDF-en, gå videre til en
 *     annen modell og legge til flere sider, og til slutt lage én PDF med alt.
 *
 * Sidene er de samme PDF-ene som arkfanene viser (se sheets.js). Når sider
 * legges til, kopieres de ut i en egen liten PDF som lagres i nettleseren
 * (IndexedDB), så de er med selv om læreren bytter side eller lukker fanen.
 * Oppsamlings-PDF-en tømmes når den er laget, slik som i M+.
 *
 * «Merk ark med» skriver en tekst langs høyre kant på hver side (som i M+).
 * Sidene står i en tabell med to kolonner: Oppgave (valgt fra start) og Fasit
 * (ikke valgt) for sidene som har fasit. Fasitsidene kommer etter oppgavene i PDF-en.
 */

const PDF_LIB = 'pdf-lib';
const DB_NAME = 'mplus3d';
const STORE = 'oppsamling';

/**
 * @param {{ documents: () => {bytes: Uint8Array|null, titles: string[], numPages: number, error: string|null}[],
 *           loading: () => boolean }} source
 */
export function initCollect(source) {
    const open = document.getElementById('pdf-open');
    const badge = document.getElementById('pdf-count');
    if (!open) return { refresh() {} };

    const dialog = document.createElement('dialog');
    dialog.className = 'dialog pdf-dialog';
    dialog.setAttribute('aria-labelledby', 'pdf-dialog-title');
    dialog.innerHTML = `
        <h2 id="pdf-dialog-title">PDF</h2>
        <section class="pdf-section" aria-labelledby="pdf-pages-heading">
            <h3 id="pdf-pages-heading">Lag PDF av sidene:</h3>
            <div class="pdf-pages" id="pdf-pages"></div>
            <div class="pdf-actions">
                <button type="button" class="btn btn-primary btn-small" id="pdf-make">Lag PDF</button>
                <button type="button" class="btn btn-secondary btn-small" id="pdf-add">Legg til i oppsamlings-PDF</button>
            </div>
            <p class="pdf-feedback" id="pdf-feedback" role="status"></p>
        </section>
        <section class="pdf-section" aria-labelledby="pdf-collect-heading">
            <h3 id="pdf-collect-heading">Oppsamlings-PDF</h3>
            <p id="pdf-collect-info"></p>
            <ul class="pdf-collect-list" id="pdf-collect-list"></ul>
            <div class="pdf-actions">
                <button type="button" class="btn btn-primary btn-small" id="pdf-collect-make">Lag oppsamlings-PDF</button>
                <button type="button" class="btn btn-secondary btn-small" id="pdf-collect-delete">Slett</button>
            </div>
        </section>
        <section class="pdf-section pdf-options">
            <div class="pdf-label-row">
                <label class="check"><input type="checkbox" id="pdf-label-on"> Merk ark med:</label>
                <input type="text" id="pdf-label" maxlength="60" disabled aria-label="Tekst på arkene">
            </div>
            <label class="pdf-filename">Filnavn
                <span><input type="text" id="pdf-filename" maxlength="80"> .pdf</span></label>
            <p class="hint">NB: PDF-filen lagres i nettleserens nedlastningsmappe.</p>
        </section>
        <form method="dialog"><button class="btn btn-secondary btn-small">Lukk</button></form>`;
    document.body.appendChild(dialog);

    const q = (sel) => dialog.querySelector(sel);
    const labelOn = q('#pdf-label-on');
    const labelInput = q('#pdf-label');
    const filename = q('#pdf-filename');
    const feedback = q('#pdf-feedback');
    let busy = false;

    labelOn.addEventListener('change', () => {
        labelInput.disabled = !labelOn.checked;
        if (labelOn.checked) labelInput.focus();
    });
    dialog.addEventListener('click', (e) => { if (e.target === dialog) dialog.close(); });

    open.addEventListener('click', () => {
        if (!filename.value) filename.value = `Mpluss3D_${today()}`;
        feedback.textContent = '';
        renderPages();
        renderCollected();
        dialog.showModal();
    });

    /* ------------------------------------------------------------ Sidene på denne siden */

    /** Sidene i PDF-ene: hasFasit = siden har fasit, fasitReady = fasiten er hentet. */
    function pages() {
        const list = [];
        source.documents().forEach((d, di) => {
            for (let p = 0; p < d.numPages; p++) {
                const hasFasit = !!d.answers?.includes(p + 1);
                list.push({ doc: di, page: p, title: d.titles[p] || `Side ${p + 1}`, hasFasit, fasitReady: hasFasit && !!d.fasitBytes });
            }
        });
        return list;
    }

    function renderPages() {
        const box = q('#pdf-pages');
        const docs = source.documents();
        const list = pages();
        const error = docs.find((d) => d.error)?.error;
        if (!list.length) {
            box.innerHTML = `<p class="muted">${esc(error || (source.loading() ? 'Lager arkene …' : 'Denne siden har ingen PDF.'))}</p>`;
        } else {
            const anyFasit = list.some((p) => p.hasFasit);
            // Tynn linje under annenhver side (ikke under den siste), så radene er lette å følge.
            const sep = (i) => (i % 2 === 1 && i < list.length - 1 ? ' pdf-row-sep' : '');
            const fasitCell = (p, i) => {
                if (!p.hasFasit) return `<span class="pdf-cell${sep(i)}"></span>`;
                return `<span class="pdf-cell${sep(i)}"><input type="checkbox" data-page="${i}" data-kind="fasit"`
                    + `${p.fasitReady ? '' : ' disabled title="Lager fasiten …"'} aria-label="${esc(p.title)}, fasit"></span>`;
            };
            box.innerHTML = `
                <div class="pdf-pages-grid${anyFasit ? '' : ' no-fasit'}" role="group" aria-label="Sidene">
                    <span class="pdf-col-head">Side</span><span class="pdf-col-head">Oppgave</span>${anyFasit ? '<span class="pdf-col-head pdf-fasit">Fasit</span>' : ''}
                    ${list.map((p, i) => `
                        <label class="pdf-page-title pdf-cell${sep(i)}" for="pdf-page-${i}">${esc(p.title)}</label>
                        <span class="pdf-cell${sep(i)}"><input type="checkbox" id="pdf-page-${i}" data-page="${i}" data-kind="task" checked></span>
                        ${anyFasit ? fasitCell(p, i) : ''}`).join('')}
                </div>`;
        }
        updatePageButtons();
    }

    /** De valgte sidene: oppgavene i rekkefølge, så fasitsidene. */
    function selectedPages() {
        const list = pages();
        const pick = (kind) => [...dialog.querySelectorAll(`#pdf-pages input[data-kind="${kind}"]:checked`)]
            .map((c) => list[Number(c.dataset.page)]).filter(Boolean);
        return [
            ...pick('task'),
            ...pick('fasit').filter((p) => p.fasitReady).map((p) => ({ ...p, title: `${p.title} – fasit`, fasit: true })),
        ];
    }

    function updatePageButtons() {
        const none = !selectedPages().length;
        q('#pdf-make').disabled = busy || none;
        q('#pdf-add').disabled = busy || none;
    }
    q('#pdf-pages').addEventListener('change', updatePageButtons);

    // Kopierer de valgte sidene ut i en ny PDF. Gir bytes og sidenavnene.
    async function extract(sel) {
        const { PDFDocument } = await import(PDF_LIB);
        const out = await PDFDocument.create();
        const docs = source.documents();
        const loaded = new Map();
        for (const p of sel) {
            const key = `${p.doc}${p.fasit ? 'f' : ''}`;
            if (!loaded.has(key)) loaded.set(key, await PDFDocument.load(p.fasit ? docs[p.doc].fasitBytes : docs[p.doc].bytes));
            const [page] = await out.copyPages(loaded.get(key), [p.page]);
            out.addPage(page);
        }
        return { bytes: await out.save(), titles: sel.map((p) => p.title) };
    }

    q('#pdf-make').addEventListener('click', () => run(async () => {
        const { bytes } = await extract(selectedPages());
        save(await finish([bytes]), fileName());
    }));

    q('#pdf-add').addEventListener('click', () => run(async () => {
        const sel = selectedPages();
        const { bytes, titles } = await extract(sel);
        await store.add({ model: modelName(), titles, bytes, added: Date.now() });
        feedback.textContent = sel.length === 1 ? 'Lagt til 1 side.' : `Lagt til ${sel.length} sider.`;
        await renderCollected();
    }));

    /* ------------------------------------------------------------ Oppsamlings-PDF */

    async function renderCollected() {
        const items = await store.all();
        const count = items.reduce((n, it) => n + it.titles.length, 0);
        q('#pdf-collect-info').textContent = `Oppsamlings-PDF har ${count === 0 ? 'ingen' : count} ${count === 1 ? 'side' : 'sider'}.`;
        q('#pdf-collect-list').innerHTML = items.map((it) => `
            <li><span><strong>${esc(it.model)}</strong> · ${esc(it.titles.join(', '))}</span>
                <button type="button" class="pdf-remove" data-remove="${it.id}" aria-label="Fjern ${esc(it.model)} fra oppsamlings-PDF">✕</button></li>`).join('');
        q('#pdf-collect-make').disabled = busy || !count;
        q('#pdf-collect-delete').hidden = !count;
        badge.textContent = String(count);
        badge.hidden = !count;
        open.setAttribute('aria-label', count ? `PDF, oppsamlings-PDF har ${count} ${count === 1 ? 'side' : 'sider'}` : 'PDF');
        return items;
    }

    q('#pdf-collect-list').addEventListener('click', (e) => {
        const b = e.target.closest('[data-remove]');
        if (b) run(async () => { await store.remove(Number(b.dataset.remove)); await renderCollected(); });
    });

    q('#pdf-collect-delete').addEventListener('click', () => {
        if (!confirm('Er du sikker på at du vil slette oppsamlings-PDF-en?')) return;
        run(async () => { await store.clear(); feedback.textContent = ''; await renderCollected(); });
    });

    q('#pdf-collect-make').addEventListener('click', () => run(async () => {
        const items = await store.all();
        if (!items.length) return;
        save(await finish(items.map((it) => it.bytes)), fileName());
        await store.clear();
        await renderCollected();
        dialog.close();
    }));

    /* ------------------------------------------------------------ Felles */

    // Slår sammen PDF-ene og skriver «Merk ark med» på hver side.
    async function finish(parts) {
        const { PDFDocument, StandardFonts, degrees, rgb } = await import(PDF_LIB);
        const out = await PDFDocument.create();
        out.setTitle(filename.value.trim() || 'M+ 3D');
        out.setCreator('M+ 3D');
        for (const bytes of parts) {
            const src = await PDFDocument.load(bytes);
            const copied = await out.copyPages(src, src.getPageIndices());
            copied.forEach((p) => out.addPage(p));
        }
        const text = labelOn.checked ? latin1(labelInput.value.trim()) : '';
        if (text) {
            const font = await out.embedFont(StandardFonts.Helvetica);
            const size = 11;
            const width = font.widthOfTextAtSize(text, size);
            const mm = 72 / 25.4;
            for (const page of out.getPages()) {
                const { width: w, height: h } = page.getSize();
                // Langs høyre kant, lest nedenfra og opp, og slutter 14 mm fra toppen (som i M+).
                page.drawText(text, {
                    x: w - 3 * mm, y: h - 14 * mm - width, size, font,
                    rotate: degrees(90), color: rgb(0, 0, 0),
                });
            }
        }
        return out.save();
    }

    async function run(task) {
        if (busy) return;
        busy = true;
        updatePageButtons();
        q('#pdf-collect-make').disabled = true;
        dialog.setAttribute('aria-busy', 'true');
        try {
            await task();
        } catch (err) {
            console.error(err);
            feedback.textContent = 'Noe gikk galt. Prøv igjen.';
        } finally {
            busy = false;
            dialog.removeAttribute('aria-busy');
            updatePageButtons();
            await renderCollected().catch(() => {});
        }
    }

    function fileName() {
        const base = filename.value.trim().replace(/\.pdf$/i, '').replace(/[\\/:*?"<>|]+/g, '-') || `Mpluss3D_${today()}`;
        return `${base}.pdf`;
    }

    renderCollected().catch(() => {});

    // Arkfanene kaller denne når PDF-ene er hentet på nytt.
    return { refresh() { if (dialog.open) renderPages(); } };
}

/* ------------------------------------------------------------ Lagring (IndexedDB) */

// Faller tilbake til minnet hvis IndexedDB ikke er tilgjengelig (f.eks. privat
// vindu i noen nettlesere). Da forsvinner oppsamlingen når siden byttes.
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
        async add(item) {
            const ok = await tx('readwrite', (s) => s.add(item)).catch(() => null);
            if (ok == null) memory.push({ ...item, id: nextId++ });
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

function save(bytes, name) {
    const a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob([bytes], { type: 'application/pdf' }));
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

// Standardskriften i PDF-en (Helvetica, WinAnsi) har bare latinske tegn.
function latin1(s) {
    return [...s].map((c) => (c.charCodeAt(0) <= 0xff ? c : '?')).join('');
}

function esc(s) {
    return String(s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
}
