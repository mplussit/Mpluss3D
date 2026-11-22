/**
 * Kulespill: én side fra serveren som SVG. MarbleViewer bruker den som
 * tekstur for hullarket på 3D-modellen. Arkfanene viser selve PDF-en
 * (se sheets.js).
 *
 * Sidene kommer ferdig lagt ut fra MarbleSheets (describe().pages): enkle
 * tegneelementer i millimeter fra øvre venstre hjørne av A4. PDF-en
 * (MarblePdf) tegner nøyaktig de samme elementene, så forhåndsvisningen
 * blir lik PDF-en. Her regnes ingenting ut. Tekst bruker Helvetica/Arial,
 * som har samme bredder som Helvetica i PDF-en.
 */

const NS = 'http://www.w3.org/2000/svg';
const FONT = "Helvetica, Arial, 'Liberation Sans', sans-serif";

/**
 * @param {{items: object[], label?: string}} page
 * @param {{view?: number[], width?: number, height?: number}} opts
 * @returns {string}
 */
export function pageSvg(page, opts = {}) {
    const view = opts.view ?? [0, 0, 210, 297];
    const size = opts.width ? ` width="${opts.width}" height="${opts.height}"` : '';
    const body = page.items.map(item).join('');
    return `<svg xmlns="${NS}" viewBox="${view.join(' ')}"${size} role="img" aria-label="${esc(page.label ?? 'Side')}">`
        + `<rect x="0" y="0" width="210" height="297" fill="#fff"/>${body}</svg>`;
}

function paint(it) {
    let s = ` fill="${it.f ?? 'none'}"`;
    if (it.s) {
        s += ` stroke="${it.s}" stroke-width="${it.lw ?? 0.2}"`;
        if (it.d) s += ` stroke-dasharray="${it.d[0]} ${it.d[1]}"`;
    }
    return s;
}

function item(it) {
    switch (it.k) {
        case 'rect':
            return `<rect x="${it.x}" y="${it.y}" width="${it.w}" height="${it.h}"${paint(it)}/>`;
        case 'line':
            return `<line x1="${it.x1}" y1="${it.y1}" x2="${it.x2}" y2="${it.y2}"${paint({ ...it, f: null })}/>`;
        case 'circle':
            return `<circle cx="${it.x}" cy="${it.y}" r="${it.r}"${paint(it)}/>`;
        case 'poly': {
            const pts = it.p.map((q) => `${q[0]},${q[1]}`).join(' ');
            return it.open
                ? `<polyline points="${pts}"${paint({ ...it, f: null })}/>`
                : `<polygon points="${pts}"${paint(it)}/>`;
        }
        case 'text': {
            const anchor = { C: 'middle', R: 'end' }[it.a] ?? 'start';
            return `<text x="${it.x}" y="${it.y}" font-family="${FONT}" font-size="${it.sz}"`
                + `${it.b ? ' font-weight="bold"' : ''} fill="${it.c}" text-anchor="${anchor}">${esc(it.t)}</text>`;
        }
        default:
            return '';
    }
}

function esc(s) {
    return String(s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
}
