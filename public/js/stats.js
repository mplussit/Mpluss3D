/**
 * M+ 3D – bruksstatistikk til M+.
 *
 * M+ teller når en modell åpnes fra menyen. M+ 3D sier fra når læreren laster
 * ned en fil: «fil» for modellfilen til 3D-printeren (3MF) og «pdf» for en PDF.
 * Bare sidenavnet, hendelsen og skolens organisasjonsnummer sendes, aldri noe
 * om læreren.
 *
 * Åpnes en side fra menyen i M+ (?side=…&tilbake=<M+-adresse>&org=<orgnr>),
 * og index.php har godtatt tilbake (body[data-mplus-tilbake]), lagres
 *   mplus3d.org           orgnr (9 siffer) eller «lisens»; ellers tom streng
 *   mplus3d.mplussOrigin  opprinnelsen til tilbake, f.eks. https://app.mpluss.no
 * Uten tilbake brukes det som er lagret fra før.
 *
 * Kallene går til <M+-opprinnelse>/php/o_bruk3d.php med navigator.sendBeacon
 * (enkel forespørsel, ingen CORS-forhåndssjekk, sendes selv om siden lukkes).
 * Statistikken skal aldri stoppe eller forsinke nedlastingen.
 */

export const ORG_KEY = 'mplus3d.org';
export const ORIGIN_KEY = 'mplus3d.mplussOrigin';
const PATH = '/php/o_bruk3d.php';

/** M+ som hører til hver M+ 3D-vert, når læreren ikke har kommet fra M+. */
const DEFAULT_ORIGINS = {
    '3d.mpluss.no': 'https://app.mpluss.no',
    '3dtest.mpluss.no': 'https://apptest.mpluss.no',
};

/**
 * Lagrer skolen og M+-adressen fra lenken. Kalles bare når tilbake er godtatt.
 * @param {string} search  location.search
 * @param {Storage} storage
 */
export function remember(search, storage) {
    const params = new URLSearchParams(search);
    const back = params.get('tilbake');
    if (!back) return;
    const org = params.get('org') ?? '';
    storage.setItem(ORG_KEY, /^[0-9]{9}$/.test(org) || org === 'lisens' ? org : '');
    storage.setItem(ORIGIN_KEY, new URL(back).origin);
}

/**
 * Adressen statistikken sendes til, eller null (lokalt uten lagret M+).
 * @param {Storage} storage
 * @param {string} hostname  location.hostname
 */
export function statistikkUrl(storage, hostname) {
    const origin = storage.getItem(ORIGIN_KEY) || DEFAULT_ORIGINS[hostname];
    return origin ? origin + PATH : null;
}

/**
 * Sender én hendelse. Kaster aldri.
 * @param {string} side      samme verdi som ?side=
 * @param {'fil'|'pdf'} hendelse
 */
export function sendStatistikk(side, hendelse) {
    try {
        const url = statistikkUrl(localStorage, location.hostname);
        if (!url || !side || !navigator.sendBeacon) return;
        navigator.sendBeacon(url, new URLSearchParams({
            side,
            hendelse,
            orgnr: localStorage.getItem(ORG_KEY) || '',
        }));
    } catch {
        // Statistikken skal aldri stoppe nedlastingen.
    }
}

/** Én hendelse per ulike side (en fil kan ha innhold fra flere modeller). */
export function sendForSider(sider, hendelse) {
    for (const side of new Set(sider.filter(Boolean))) sendStatistikk(side, hendelse);
}

/** Sidenavnet til siden som er åpen (body[data-page], satt av index.php). */
export function currentSide() {
    const page = typeof document !== 'undefined' ? document.body?.dataset.page : '';
    return page && page !== 'meny' ? page : '';
}

// Ved sidevisning: ta vare på skolen og M+-adressen når tilbake er godtatt.
if (typeof document !== 'undefined' && document.body?.dataset.mplusTilbake === '1') {
    try { remember(location.search, localStorage); } catch { /* privat modus o.l. */ }
}
