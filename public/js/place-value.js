/**
 * Plassverdi for tallbyggeren (ren logikk uten 3D, så den kan testes for seg).
 *
 *   decompose(243)  → { tusener: 0, hundrer: 2, tier: 4, ener: 3 }
 *   expanded(243)   → "243 = 200 + 40 + 3"
 *   terms(243)      → "243 = 2 × 100 + 4 × 10 + 3 × 1"
 *   words(243)      → "to hundre og førtitre"
 *   check(243, types, counts) → hva mangler i settet
 */

export const PLACES = [
    { type: 'tusener', value: 1000, one: 'tusener', many: 'tusenere' },
    { type: 'hundrer', value: 100, one: 'hundrer', many: 'hundrere' },
    { type: 'tier', value: 10, one: 'tier', many: 'tiere' },
    { type: 'ener', value: 1, one: 'ener', many: 'enere' },
];

export const MAX = 9999;

/** Tolker feltet. Gir null for alt som ikke er et helt tall fra 0 til 9999. */
export function parseNumber(text) {
    const s = String(text ?? '').trim();
    if (!/^\d{1,4}$/.test(s)) return null;
    const n = Number(s);
    return n <= MAX ? n : null;
}

export function decompose(n) {
    return {
        tusener: Math.floor(n / 1000) % 10,
        hundrer: Math.floor(n / 100) % 10,
        tier: Math.floor(n / 10) % 10,
        ener: n % 10,
    };
}

/** 405 → "405 = 400 + 5" (plasser med null utelates). */
export function expanded(n) {
    const d = decompose(n);
    const parts = PLACES.filter((p) => d[p.type] > 0).map((p) => String(d[p.type] * p.value));
    return `${n} = ${parts.length ? parts.join(' + ') : '0'}`;
}

/** 405 → "405 = 4 × 100 + 0 × 10 + 5 × 1" (fra høyeste plass som er i bruk). */
export function terms(n) {
    const d = decompose(n);
    const first = PLACES.findIndex((p) => d[p.type] > 0);
    const used = first < 0 ? [PLACES[3]] : PLACES.slice(first);
    return `${n} = ${used.map((p) => `${d[p.type]} × ${p.value}`).join(' + ')}`;
}

/** «3 hundrere, 1 tier og 5 enere» */
export function describe(n) {
    const d = decompose(n);
    const parts = PLACES.filter((p) => d[p.type] > 0).map((p) => `${d[p.type]} ${d[p.type] === 1 ? p.one : p.many}`);
    if (!parts.length) return 'ingen klosser';
    return parts.length === 1 ? parts[0] : `${parts.slice(0, -1).join(', ')} og ${parts[parts.length - 1]}`;
}

const ONES = ['', 'en', 'to', 'tre', 'fire', 'fem', 'seks', 'sju', 'åtte', 'ni', 'ti',
    'elleve', 'tolv', 'tretten', 'fjorten', 'femten', 'seksten', 'sytten', 'atten', 'nitten'];
const TENS = ['', '', 'tjue', 'tretti', 'førti', 'femti', 'seksti', 'sytti', 'åtti', 'nitti'];

/** Norske tallord (samme regler som Base10Tasks::words i PHP). */
export function words(n) {
    if (n === 0) return 'null';
    const below100 = (x) => (x < 20 ? ONES[x] : TENS[Math.floor(x / 10)] + (x % 10 ? ONES[x % 10] : ''));
    const parts = [];
    const th = Math.floor(n / 1000);
    const h = Math.floor(n / 100) % 10;
    const rest = n % 100;
    if (th) parts.push(`${th === 1 ? 'ett' : ONES[th]} tusen`);
    if (h) parts.push(`${h === 1 ? 'ett' : ONES[h]} hundre`);
    if (rest) parts.push((parts.length ? 'og ' : '') + below100(rest));
    return parts.join(' ');
}

/**
 * Sjekker tallet mot settet.
 *   missing  typer tallet trenger, men som ikke er valgt → tallet kan ikke vises
 *   short    typer der settet har for få klosser (tallet vises, men med merknad)
 * @param {string[]} types valgte typer
 * @param {Record<string, number>} counts antall per type
 */
export function check(n, types, counts) {
    const d = decompose(n);
    const missing = [];
    const short = [];
    for (const p of PLACES) {
        const need = d[p.type];
        if (!need) continue;
        if (!types.includes(p.type)) missing.push({ ...p, need });
        else if ((counts[p.type] ?? 0) < need) short.push({ ...p, need, have: counts[p.type] ?? 0 });
    }
    return { digits: d, missing, short };
}
