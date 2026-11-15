/**
 * Tallkombinasjoner med tallstaver. Samme regler som RodTasks::partitions()
 * og RodTasks::showcase() i PHP, så visningen og PDF-en er enige.
 */

/**
 * Alle måter å bygge target på med stavene i settet, der antall tas hensyn til.
 * Hver måte er sortert synkende ([5, 3, 2]). Færrest staver først.
 * @param {number} target
 * @param {Record<number, number>} counts lengde → antall
 * @returns {number[][]}
 */
export function partitions(target, counts, { minParts = 2, maxParts = target, limit = 5000 } = {}) {
    const lens = Object.keys(counts).map(Number).filter((n) => counts[n] > 0).sort((a, b) => b - a);
    const out = [];
    const walk = (rest, maxLen, acc, used) => {
        if (out.length >= limit) return;
        if (rest === 0) {
            if (acc.length >= minParts) out.push(acc);
            return;
        }
        if (acc.length >= maxParts) return;
        for (const len of lens) {
            if (len > maxLen || len > rest || (used[len] ?? 0) >= counts[len]) continue;
            walk(rest - len, len, [...acc, len], { ...used, [len]: (used[len] ?? 0) + 1 });
        }
    };
    walk(target, target, [], {});
    return out.sort(compare);
}

function compare(a, b) {
    if (a.length !== b.length) return a.length - b.length;
    for (let i = 0; i < a.length; i++) if (a[i] !== b[i]) return b[i] - a[i];
    return 0;
}

/**
 * Rekkefølge som sprer valgene jevnt over en liste med len elementer:
 * midten, kvartene, åttendedelene … (van der Corput-følgen, fra 1/2).
 */
export function spread(len) {
    const order = [];
    const used = new Set();
    for (let k = 1; order.length < len; k++) {
        let v = 0;
        let f = 0.5;
        for (let i = k; i > 0; i >>= 1, f /= 2) if (i & 1) v += f;
        const idx = Math.floor(v * len);
        if (!used.has(idx)) {
            used.add(idx);
            order.push(idx);
        }
    }
    return order;
}

/**
 * Et variert utvalg: én fra hver størrelse (2 staver, 3 staver …) om gangen,
 * spredt over hver gruppe.
 */
export function showcase(parts, n) {
    const groups = new Map();
    for (const p of parts) {
        if (!groups.has(p.length)) groups.set(p.length, []);
        groups.get(p.length).push(p);
    }
    for (const [k, g] of groups) groups.set(k, spread(g.length).map((i) => g[i]));
    const sizes = [...groups.keys()].sort((a, b) => a - b);
    const out = [];
    for (let round = 0; out.length < n; round++) {
        let added = false;
        for (const s of sizes) {
            const g = groups.get(s);
            if (g[round] && out.length < n) {
                out.push(g[round]);
                added = true;
            }
        }
        if (!added) break;
    }
    return out.sort(compare);
}

export const sumText = (p) => p.join(' + ');

/** Heltall fra 2 til 20, ellers null. */
export function parseTarget(value) {
    const s = String(value).trim();
    if (!/^\d{1,2}$/.test(s)) return null;
    const n = Number(s);
    return n >= 2 && n <= 20 ? n : null;
}
