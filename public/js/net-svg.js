/**
 * Utbrettingsnettet som SVG.
 *
 * Tegner nøyaktig de samme regionene, klippe- og brettelinjene og den samme
 * A4-plasseringen som PDF-en (SolidNet + NetLayout på serveren). Her regnes
 * ingen geometri ut; alt kommer fra describe().net og describe().layout.
 * Enheten i SVG-en er millimeter.
 */

const NS = 'http://www.w3.org/2000/svg';
const PAGE = { P: [210, 297], L: [297, 210] };
const MARGIN = 10;
const TAB = '#e4e0d8';

/** @returns {string} SVG-kode */
export function netSvg(desc) {
    const { net, layout, geometry } = desc;
    const colors = Object.fromEntries(geometry.faces.map((f) => [f.id, f.color]));
    const body = netBody(net, colors, layout.mode !== 'scaled');
    let content;
    let view;
    if (layout.mode === 'tiles') {
        // Hele nettet med arkene som rammer (som oversikten i PDF-en).
        const w = layout.pages.map((p) => p.window);
        const x0 = Math.min(...w.map((r) => r[0])) - 4;
        const y0 = Math.min(...w.map((r) => r[1])) - 4;
        const x1 = Math.max(...w.map((r) => r[0] + r[2])) + 4;
        const y1 = Math.max(...w.map((r) => r[1] + r[3])) + 4;
        const sheets = layout.pages.map((p) => {
            const [x, y, ww, hh] = p.window;
            return `<rect x="${x}" y="${y}" width="${ww}" height="${hh}" class="net-tile"/>`
                + `<text x="${x + 3}" y="${y + 9}" class="net-tile-label">${p.label}</text>`;
        }).join('');
        view = [x0, y0, x1 - x0, y1 - y0];
        content = `<rect x="${x0}" y="${y0}" width="${x1 - x0}" height="${y1 - y0}" class="net-sheet"/>${body}${sheets}`;
    } else {
        const [W, H] = PAGE[layout.orientation];
        const pg = layout.pages[0];
        const s = layout.scale;
        view = [-6, -6, W + 12, H + 12];
        const band = layout.mode === 'scaled'
            ? `<rect x="${MARGIN}" y="23" width="${W - 2 * MARGIN}" height="7" class="net-band warn"/>`
              + `<text x="${MARGIN + 2.5}" y="27.9" class="net-band-text warn">SKALERT – målestokk ${esc(layout.scale_label)}</text>`
            : `<rect x="${MARGIN}" y="23" width="${W - 2 * MARGIN}" height="7" class="net-band"/>`
              + `<text x="${MARGIN + 2.5}" y="27.9" class="net-band-text">Målestokk 1:1 – skriv ut i 100 %</text>`;
        const ctl = `<g class="net-ruler"><line x1="${W - MARGIN - 50}" y1="${H - 14}" x2="${W - MARGIN}" y2="${H - 14}"/>`
            + [0, 10, 20, 30, 40, 50].map((m) => `<line x1="${W - MARGIN - 50 + m}" y1="${H - 14}" x2="${W - MARGIN - 50 + m}" y2="${H - 16.6}"/>`).join('')
            + `<text x="${W - MARGIN - 53}" y="${H - 13}" text-anchor="end">50 mm</text></g>`;
        content = `<rect x="0" y="0" width="${W}" height="${H}" class="net-sheet"/>`
            + `<text x="${MARGIN}" y="19.5" class="net-title">${esc(geometry.name)}</text>${band}`
            + `<g transform="translate(${pg.origin[0]} ${pg.origin[1]}) scale(${s})">${body}</g>${ctl}`;
    }
    return `<svg xmlns="${NS}" viewBox="${view.join(' ')}" role="img" aria-label="Utbrettingsfigur: ${esc(geometry.name)}">${content}</svg>`;
}

function netBody(net, colors, dims) {
    const fills = net.regions.map((r) => {
        const fill = r.kind === 'face' ? tint(colors[r.face] ?? '#f36d21', 0.84) : TAB;
        return `<path d="${regionPath(r.edges)}" fill="${fill}"/>`;
    }).join('');
    const folds = net.folds.map((e) => `<path d="${primPath(e)}"/>`).join('');
    const cuts = net.cuts.map((e) => `<path d="${primPath(e)}"/>`).join('');
    const labels = net.regions.filter((r) => r.label).map((r) => {
        const lines = r.kind === 'face' && !dims ? [r.label.lines[0]] : r.label.lines;
        const fs = r.label.size * 0.776;
        const lh = fs * 1.25;
        const y0 = r.label.at[1] - (lh * (lines.length - 1)) / 2 + fs * 0.36;
        return lines.map((t, i) => `<text x="${r.label.at[0]}" y="${y0 + i * lh}" font-size="${i === 0 ? fs : fs * 0.85}" `
            + `class="${r.kind === 'tab' ? 'net-tab-label' : i === 0 ? 'net-face-label' : 'net-dim-label'}">${esc(t)}</text>`).join('');
    }).join('');
    return `<g class="net-fills">${fills}</g><g class="net-folds">${folds}</g><g class="net-cuts">${cuts}</g><g class="net-labels">${labels}</g>`;
}

const f = (v) => Math.round(v * 1000) / 1000;
const at = (c, r, a) => [c[0] + r * Math.cos(a), c[1] + r * Math.sin(a)];

function arcTo(c, r, a0, a1) {
    // SVG-buer kan ikke være hele sirkler: del i biter på høyst 180°.
    const n = Math.max(1, Math.ceil((a1 - a0) / Math.PI - 1e-9));
    let d = '';
    for (let i = 1; i <= n; i++) {
        const p = at(c, r, a0 + ((a1 - a0) * i) / n);
        d += ` A ${f(r)} ${f(r)} 0 0 1 ${f(p[0])} ${f(p[1])}`;
    }
    return d;
}

function primPath(e) {
    if (e[0] === 'L') return `M ${f(e[1][0])} ${f(e[1][1])} L ${f(e[2][0])} ${f(e[2][1])}`;
    const [, c, r, a0, a1] = e;
    const s = at(c, r, a0);
    return `M ${f(s[0])} ${f(s[1])}${arcTo(c, r, a0, a1)}`;
}

function regionPath(edges) {
    let d = '';
    let cur = null;
    for (const e of edges) {
        const start = e[0] === 'L' ? e[1] : at(e[1], e[2], e[3]);
        if (!cur || Math.abs(cur[0] - start[0]) > 1e-3 || Math.abs(cur[1] - start[1]) > 1e-3) d += ` M ${f(start[0])} ${f(start[1])}`;
        if (e[0] === 'L') {
            d += ` L ${f(e[2][0])} ${f(e[2][1])}`;
            cur = e[2];
        } else {
            d += arcTo(e[1], e[2], e[3], e[4]);
            cur = at(e[1], e[2], e[4]);
        }
    }
    return d.trim() + ' Z';
}

function tint(hex, t) {
    const c = [1, 3, 5].map((i) => parseInt(hex.slice(i, i + 2), 16));
    return `rgb(${c.map((v) => Math.round(v + (255 - v) * t)).join(',')})`;
}

function esc(s) {
    return String(s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
}
