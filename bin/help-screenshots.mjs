/**
 * Lager skjermbildene til hjelpesiden (public/hjelp/bilder/*.webp).
 *
 *   php -S 127.0.0.1:8099 -t public      (i ett vindu)
 *   node bin/help-screenshots.mjs        (i et annet)
 *
 * Skriptet starter Chrome uten vindu (headless), styrer det med Chrome
 * DevTools Protocol og tar bilder av sidene og dialogene. Plasseringen av
 * knappene som hjelpesiden setter nummer på, skrives til bilder/merker.json.
 * Miljøvariabler: M3D_URL (standard http://127.0.0.1:8099/), CHROME (sti til Chrome/Edge).
 */
import { spawn } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';

const BASE = process.env.M3D_URL ?? 'http://127.0.0.1:8099/';
const OUT = path.resolve(import.meta.dirname, '../public/hjelp/bilder');
const PORT = 9333;
const CHROME = process.env.CHROME ?? [
    'C:/Program Files/Google/Chrome/Application/chrome.exe',
    'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
    '/usr/bin/google-chrome', '/usr/bin/chromium',
].find((p) => fs.existsSync(p));

fs.mkdirSync(OUT, { recursive: true });
const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'm3d-shots-'));
const chrome = spawn(CHROME, [
    '--headless=new', `--remote-debugging-port=${PORT}`, `--user-data-dir=${profile}`,
    '--use-angle=swiftshader', '--enable-unsafe-swiftshader', '--hide-scrollbars',
    '--no-first-run', '--no-default-browser-check', '--lang=nb-NO', 'about:blank',
], { stdio: 'ignore' });

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const marks = {};

try {
    const ws = await connect();
    let id = 0;
    const pending = new Map();
    ws.addEventListener('message', (e) => {
        const msg = JSON.parse(e.data);
        if (msg.id && pending.has(msg.id)) {
            const { resolve, reject } = pending.get(msg.id);
            pending.delete(msg.id);
            msg.error ? reject(new Error(msg.error.message)) : resolve(msg.result);
        }
    });
    const send = (method, params = {}) => new Promise((resolve, reject) => {
        const n = ++id;
        pending.set(n, { resolve, reject });
        ws.send(JSON.stringify({ id: n, method, params }));
    });
    const js = async (expr) => {
        const r = await send('Runtime.evaluate', { expression: expr, awaitPromise: true, returnByValue: true });
        if (r.exceptionDetails) throw new Error(r.exceptionDetails.exception?.description ?? r.exceptionDetails.text);
        return r.result.value;
    };
    const until = async (expr, ms = 60000) => {
        const t0 = Date.now();
        while (Date.now() - t0 < ms) {
            if (await js(`!!(${expr})`)) return;
            await sleep(250);
        }
        throw new Error(`Tidsavbrudd: ${expr}`);
    };
    const size = (width, height) => send('Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor: 1, mobile: false });
    const goto = async (url) => {
        await send('Page.navigate', { url: BASE + url });
        await sleep(500);
        await until('document.readyState === "complete"');
    };
    const shot = async (name, clipSelector = null, pad = 0) => {
        await sleep(600);
        let clip;
        if (clipSelector) {
            const r = await js(`(() => { const r = document.querySelector(${JSON.stringify(clipSelector)}).getBoundingClientRect();
                return { x: r.x, y: r.y, width: r.width, height: r.height }; })()`);
            clip = { x: Math.max(0, r.x - pad), y: Math.max(0, r.y - pad), width: r.width + 2 * pad, height: r.height + 2 * pad, scale: 1 };
        }
        const { data } = await send('Page.captureScreenshot', { format: 'webp', quality: 88, ...(clip ? { clip } : {}) });
        fs.writeFileSync(path.join(OUT, `${name}.webp`), Buffer.from(data, 'base64'));
        console.log('✓', name);
        return clip;
    };
    /** Plassering av elementene i prosent av bildet (til nummermerkene). */
    const mark = async (name, selectors, clip = null) => {
        marks[name] = await js(`(() => {
            const W = ${clip ? clip.width : 'innerWidth'}, H = ${clip ? clip.height : 'innerHeight'};
            const X = ${clip ? clip.x : 0}, Y = ${clip ? clip.y : 0};
            return Object.fromEntries(Object.entries(${JSON.stringify(selectors)}).map(([k, s]) => {
                const el = document.querySelector(s);
                if (!el) return [k, null];
                const r = el.getBoundingClientRect();
                return [k, { x: +((r.x - X) / W * 100).toFixed(1), y: +((r.y - Y) / H * 100).toFixed(1),
                             w: +(r.width / W * 100).toFixed(1), h: +(r.height / H * 100).toFixed(1) }];
            }));
        })()`);
    };

    await send('Page.enable');
    await send('Runtime.enable');
    await size(1280, 900);

    // 1. Menyen
    await goto('');
    await shot('meny');

    // 2. En modellside (brøksirkler) med knappene i toppfeltet
    await goto('?side=broeksirkler');
    await until('document.querySelector("#print-list tbody tr")');
    await until('!document.querySelector("#viewer-status:not([hidden])")');
    await sleep(1500);
    await shot('side');
    await mark('side', {
        meny: '.menu-btn', faner: '#sheet-tabs', panel: '.panel', mf: '#plate-open', pdf: '#pdf-open', hjelp: 'a.help-btn',
    });

    // 3. Et ark i forhåndsvisningen, med «Oppgave | Fasit»
    await until('document.querySelectorAll(".sheet-tab-pdf").length > 1');
    await js(`document.querySelectorAll('.sheet-tab-pdf')[1].click()`);
    await until('document.querySelector(".sheet-canvas") && !document.querySelector(".sheet-canvas").hidden');
    await sleep(2000);
    await shot('ark');
    await mark('ark', { faner: '.sheet-tab-pdf[aria-selected="true"]', variant: '.sheet-variant' });

    // 4. 3MF-dialogen
    await size(1280, 1000);
    await js(`document.querySelector('.sheet-tab-3d').click()`);
    await js(`document.getElementById('plate-open').click()`);
    await until('document.querySelector("#plate-parts .plate-add")');
    await sleep(800);
    let clip = await shot('3mf-tom', '.plate-dialog');
    await mark('3mf-tom', { printer: '#plate-printer', avstand: '.plate-spacing', leggtil: '#plate-parts', plate: '#plate-canvas' }, clip);

    // Legg halvdelene og firedelene på platen.
    await js(`(() => {
        const names = [...document.querySelectorAll('#plate-parts .plate-part-name')];
        const pick = (re) => names.findIndex((n) => re.test(n.textContent));
        for (const i of [pick(/1-2$/), pick(/1-4$/), pick(/1-3$/)]) {
            if (i >= 0) document.querySelectorAll('#plate-parts .plate-add')[i].click();
        }
    })()`);
    await until('document.querySelectorAll("#plate-items li[data-item]").length >= 1');
    await sleep(1500);
    // Klikkene kan bli avvist mens forrige legges til (én om gangen); legg til resten.
    for (let k = 0; k < 6; k++) {
        const n = await js('document.querySelectorAll("#plate-items li[data-item]").length');
        if (n >= 3) break;
        await js(`(() => {
            const names = [...document.querySelectorAll('#plate-parts .plate-part-name')].map((n) => n.textContent);
            const have = [...document.querySelectorAll('#plate-items strong')].map((s) => s.textContent);
            const want = names.map((t, i) => [t, i]).filter(([t]) => /1-(2|3|4)$/.test(t) && !have.includes(t));
            if (want.length) document.querySelectorAll('#plate-parts .plate-add')[want[0][1]].click();
        })()`);
        await sleep(1500);
    }
    clip = await shot('3mf-plate', '.plate-dialog');
    await mark('3mf-plate', {
        plate: '#plate-canvas', liste: '#plate-items', antall: '#plate-items .plate-stepper', drei: '#plate-items [data-rotate]',
        fjern: '#plate-items [data-remove]', lagre: '#plate-save', filnavn: '#plate-filename',
    }, clip);

    // For mange objekter: alle brøkene, på en liten plate.
    await js(`(() => { const s = document.getElementById('plate-printer'); s.value = 'bambu-a1mini'; s.dispatchEvent(new Event('change')); })()`);
    await js(`document.querySelector('#plate-items [data-item-count]').value = 40;
              document.querySelector('#plate-items [data-item-count]').dispatchEvent(new Event('change', { bubbles: true }))`);
    await sleep(1200);
    await shot('3mf-full', '.plate-view', 12);
    await js(`(() => { const s = document.getElementById('plate-printer'); s.value = 'bambu-a1'; s.dispatchEvent(new Event('change')); })()`);
    await js(`document.querySelector('#plate-items [data-item-count]').value = 2;
              document.querySelector('#plate-items [data-item-count]').dispatchEvent(new Event('change', { bubbles: true }))`);
    await sleep(800);
    await js(`document.querySelector('.plate-dialog form button').click()`);

    // 5. PDF-dialogen
    await js(`document.getElementById('pdf-open').click()`);
    await until('document.querySelector("#pdf-pages input")');
    await until('!document.querySelector("#pdf-pages input[data-kind=fasit][disabled]")', 30000).catch(() => {});
    await js(`(() => { const f = document.querySelectorAll('#pdf-pages input[data-kind=fasit]'); if (f[0]) f[0].click(); })()`);
    clip = await shot('pdf', '.pdf-dialog:not(.plate-dialog)');
    await mark('pdf', {
        sider: '#pdf-pages', lag: '#pdf-make', samle: '#pdf-add', oppsamling: '#pdf-collect-heading', merk: '.pdf-label-row', filnavn: '#pdf-filename',
    }, clip);
    await js(`document.getElementById('pdf-add').click()`);
    await until('document.querySelector("#pdf-collect-list li")');
    await js(`document.querySelector('.pdf-dialog:not(.plate-dialog) form button').click()`);

    // 6. Regn med Lego: byggeplate-knappene
    await size(1280, 900);
    await goto('?side=regnmedlego');
    await until('!document.getElementById("plate-add-frame").disabled');
    await js(`document.getElementById('plate-add-frame').click()`);
    await until('/lagt på byggeplaten/.test(document.getElementById("plate-feedback").textContent)');
    await js(`document.getElementById('plate-feedback').scrollIntoView({ block: 'end' })`);
    await sleep(800);
    // Utsnitt: fra «4 · Byggeplate» til meldingen under knappene.
    await js(`(() => {
        const h = [...document.querySelectorAll('.panel .panel-heading')].find((e) => /Byggeplate/.test(e.textContent));
        const a = h.getBoundingClientRect(), b = document.getElementById('plate-feedback').getBoundingClientRect();
        const box = document.createElement('div');
        box.id = 'shot-box';
        Object.assign(box.style, { position: 'fixed', left: (a.left - 16) + 'px', top: (a.top - 14) + 'px',
            width: (b.right - a.left + 32) + 'px', height: (b.bottom - a.top + 28) + 'px', pointerEvents: 'none' });
        document.body.appendChild(box);
    })()`);
    await shot('lego-byggeplate', '#shot-box');

    // 7. Toppfeltet med tallene på knappene (etter at noe er lagt til)
    await goto('?side=terning');
    await until('document.querySelector("#plate-count:not([hidden])")');
    await sleep(1200);
    clip = await shot('toppfelt', '.topbar', 0);

    fs.writeFileSync(path.join(OUT, 'merker.json'), JSON.stringify(marks, null, 2));
    console.log('Ferdig:', OUT);
    ws.close();
} finally {
    chrome.kill();
    await sleep(500);
    fs.rmSync(profile, { recursive: true, force: true });
}

async function connect() {
    for (let i = 0; i < 40; i++) {
        try {
            const list = await (await fetch(`http://127.0.0.1:${PORT}/json`)).json();
            const page = list.find((t) => t.type === 'page');
            if (page) {
                const ws = new WebSocket(page.webSocketDebuggerUrl);
                await new Promise((resolve, reject) => { ws.onopen = resolve; ws.onerror = reject; });
                return ws;
            }
        } catch { /* Chrome starter */ }
        await sleep(250);
    }
    throw new Error('Fikk ikke kontakt med Chrome.');
}
