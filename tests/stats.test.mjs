/**
 * Tester for bruksstatistikken (public/js/stats.js).
 *
 *   node --test tests/stats.test.mjs
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { remember, statistikkUrl, sendStatistikk, sendForSider, ORG_KEY, ORIGIN_KEY } from '../public/js/stats.js';

function memoryStorage(init = {}) {
    const m = new Map(Object.entries(init));
    return {
        getItem: (k) => (m.has(k) ? m.get(k) : null),
        setItem: (k, v) => m.set(k, String(v)),
        removeItem: (k) => m.delete(k),
    };
}

const BACK = 'tilbake=' + encodeURIComponent('https://apptest.mpluss.no/index.html');

test('gyldig orgnr og M+-opprinnelse lagres', () => {
    const s = memoryStorage();
    remember(`?side=terning&${BACK}&org=974000001`, s);
    assert.equal(s.getItem(ORG_KEY), '974000001');
    assert.equal(s.getItem(ORIGIN_KEY), 'https://apptest.mpluss.no');
});

test('org=lisens lagres', () => {
    const s = memoryStorage();
    remember(`?side=terning&${BACK}&org=lisens`, s);
    assert.equal(s.getItem(ORG_KEY), 'lisens');
});

test('ugyldig org lagres som tom streng og overskriver en tidligere skole', () => {
    for (const org of ['abc', '12345678', '1234567890', 'Lisens', '97400000a']) {
        const s = memoryStorage({ [ORG_KEY]: '974000001' });
        remember(`?side=terning&${BACK}&org=${org}`, s);
        assert.equal(s.getItem(ORG_KEY), '', org);
    }
});

test('manglende org tømmer en lagret skole', () => {
    const s = memoryStorage({ [ORG_KEY]: '974000001' });
    remember(`?side=terning&${BACK}`, s);
    assert.equal(s.getItem(ORG_KEY), '');
});

test('uten tilbake brukes det lagrede uendret', () => {
    const s = memoryStorage({ [ORG_KEY]: '974000001', [ORIGIN_KEY]: 'https://app.mpluss.no' });
    remember('?side=base10&org=999999999', s);
    assert.equal(s.getItem(ORG_KEY), '974000001');
    assert.equal(s.getItem(ORIGIN_KEY), 'https://app.mpluss.no');
});

test('adressen: lagret opprinnelse går foran verten', () => {
    const s = memoryStorage({ [ORIGIN_KEY]: 'https://app.mpluss.no' });
    assert.equal(statistikkUrl(s, '3dtest.mpluss.no'), 'https://app.mpluss.no/php/o_bruk3d.php');
});

test('adressen uten lagret opprinnelse: 3d → app, 3dtest → apptest, lokalt → ingenting', () => {
    const s = memoryStorage();
    assert.equal(statistikkUrl(s, '3d.mpluss.no'), 'https://app.mpluss.no/php/o_bruk3d.php');
    assert.equal(statistikkUrl(s, '3dtest.mpluss.no'), 'https://apptest.mpluss.no/php/o_bruk3d.php');
    assert.equal(statistikkUrl(s, 'localhost'), null);
    assert.equal(statistikkUrl(s, '127.0.0.1'), null);
});

/** Kjører fn med falske nettleserobjekter og gir kallene til sendBeacon. */
function withBrowser({ hostname = '3dtest.mpluss.no', stored = {}, beacon } = {}, fn) {
    const calls = [];
    const saved = {};
    for (const k of ['localStorage', 'location', 'navigator']) saved[k] = Object.getOwnPropertyDescriptor(globalThis, k);
    const define = (k, v) => Object.defineProperty(globalThis, k, { value: v, configurable: true, writable: true });
    define('localStorage', memoryStorage(stored));
    define('location', { hostname, search: '' });
    define('navigator', { sendBeacon: beacon ?? ((url, body) => { calls.push({ url, body: Object.fromEntries(body) }); return true; }) });
    try {
        fn();
    } finally {
        for (const [k, d] of Object.entries(saved)) {
            if (d) Object.defineProperty(globalThis, k, d); else delete globalThis[k];
        }
    }
    return calls;
}

test('sendStatistikk sender side, hendelse og orgnr', () => {
    const calls = withBrowser({ stored: { [ORG_KEY]: '974000001' } }, () => sendStatistikk('kulespill', 'pdf'));
    assert.deepEqual(calls, [{
        url: 'https://apptest.mpluss.no/php/o_bruk3d.php',
        body: { side: 'kulespill', hendelse: 'pdf', orgnr: '974000001' },
    }]);
});

test('sendStatistikk sender tom orgnr når skolen er ukjent', () => {
    const calls = withBrowser({}, () => sendStatistikk('terning', 'fil'));
    assert.equal(calls[0].body.orgnr, '');
});

test('lokalt uten lagret opprinnelse sendes ingenting', () => {
    const calls = withBrowser({ hostname: 'localhost' }, () => sendStatistikk('terning', 'fil'));
    assert.equal(calls.length, 0);
});

test('feil i sendBeacon stopper ikke nedlastingen', () => {
    withBrowser({ beacon: () => { throw new Error('nettet er nede'); } }, () => {
        assert.doesNotThrow(() => sendStatistikk('terning', 'fil'));
    });
});

test('sendForSider sender én hendelse per ulike side', () => {
    const calls = withBrowser({}, () => sendForSider(['terning', 'base10', 'terning', ''], 'pdf'));
    assert.deepEqual(calls.map((c) => c.body.side), ['terning', 'base10']);
});
