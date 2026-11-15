/**
 * Menyen i M+ 3D, med samme oppførsel som menyen i M+-appen:
 *
 *   klikk på en side          viser tittel, forhåndsbilde og beskrivelse
 *   dobbeltklikk / Enter      åpner siden
 *   klikk på bildet / knappen åpner siden
 *   piltastene                flytter valget i lista
 *   temafilteret              viser bare sider i temaet (overskrifter uten
 *                             sider skjules)
 *
 * Valgt side og tema huskes i nettleseren til neste besøk.
 */
const STORE = 'mplus3d.menu';

const items = [...document.querySelectorAll('.menu-item')];
const filter = document.querySelector('#theme-filter');

function load() {
    try { return JSON.parse(localStorage.getItem(STORE) || '{}'); } catch { return {}; }
}
function save(data) {
    try { localStorage.setItem(STORE, JSON.stringify({ ...load(), ...data })); } catch { /* privat modus o.l. */ }
}

function select(item, remember = true) {
    if (!item) return;
    items.forEach((i) => i.setAttribute('aria-current', String(i === item)));
    const { slug, title, description, preview } = item.dataset;
    document.querySelector('#preview-title').textContent = title;
    document.querySelector('#preview-description').textContent = description;
    document.querySelector('#preview-start-title').textContent = title;
    document.querySelector('#preview-img').src = preview;
    const href = `?side=${encodeURIComponent(slug)}`;
    const open = document.querySelector('#preview-open');
    open.href = href;
    open.setAttribute('aria-label', `Åpne ${title}`);
    document.querySelector('#preview-start').href = href;
    if (remember) save({ selected: slug });
}

function visibleItems() {
    return items.filter((i) => !i.closest('li').hidden);
}

function applyFilter(theme, remember = true) {
    for (const item of items) {
        item.closest('li').hidden = !!theme && !item.dataset.themes.split(' ').includes(theme);
    }
    document.querySelectorAll('.menu-group').forEach((h) => {
        const list = h.nextElementSibling;
        const any = [...list.querySelectorAll('li')].some((li) => !li.hidden);
        h.hidden = !any;
        list.hidden = !any;
    });
    const visible = visibleItems();
    document.querySelector('#menu-empty').hidden = visible.length > 0;
    const current = items.find((i) => i.getAttribute('aria-current') === 'true');
    if (visible.length && !visible.includes(current)) select(visible[0], remember);
    if (remember) save({ theme });
}

for (const item of items) {
    item.addEventListener('click', (e) => {
        e.preventDefault();          // ett klikk velger, dobbeltklikk åpner
        select(item);
    });
    item.addEventListener('dblclick', () => { window.location.href = item.href; });
    item.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            window.location.href = item.href;
        } else if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            const visible = visibleItems();
            const next = visible[visible.indexOf(item) + (e.key === 'ArrowDown' ? 1 : -1)];
            if (next) {
                select(next);
                next.focus();
            }
        }
    });
    item.addEventListener('focus', () => select(item));
}

filter.addEventListener('change', () => applyFilter(filter.value));

const saved = load();
if (saved.theme && [...filter.options].some((o) => o.value === saved.theme)) {
    filter.value = saved.theme;
    applyFilter(saved.theme, false);
}
const remembered = items.find((i) => i.dataset.slug === saved.selected && !i.closest('li').hidden);
if (remembered) select(remembered, false);
