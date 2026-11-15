<?php
/**
 * Menyen (startsiden). Tas inn av public/index.php med $site, $e og $notFound.
 *
 * Som i M+-appen: sidelista til venstre, gruppert under overskrifter og
 * sortert på tittel, med temafilter over. Valgt side vises til høyre med
 * tittel, forhåndsbilde og beskrivelse. Dobbeltklikk, Enter eller klikk på
 * bildet åpner siden. Lista virker også uten JavaScript (vanlige lenker).
 */
$byGroup = [];
foreach ($site['pages'] as $slug => $p) {
    $byGroup[$p['group']][$slug] = $p;
}
foreach ($byGroup as &$list) {
    uasort($list, fn($a, $b) => strcoll($a['title'], $b['title']));
}
unset($list);
$first = array_key_first(reset($byGroup) ?: []);
?>
<main class="menu-layout">
    <aside class="panel menu-panel" aria-label="Sider">
        <label class="control-label" for="theme-filter">Tema</label>
        <select id="theme-filter" class="select">
            <option value="">Alle tema</option>
<?php foreach ($site['themes'] as $id => $label): ?>
            <option value="<?= $e($id) ?>"><?= $e($label) ?></option>
<?php endforeach; ?>
        </select>

        <div id="menu-list" class="menu-list">
<?php foreach ($byGroup as $group => $pages): ?>
            <h2 class="menu-group" data-group="<?= $e($group) ?>"><?= $e($site['groups'][$group] ?? $group) ?></h2>
            <ul>
<?php foreach ($pages as $slug => $p): ?>
                <li><a class="menu-item" href="?side=<?= $e($slug) ?>"
                       data-slug="<?= $e($slug) ?>"
                       data-group="<?= $e($p['group']) ?>"
                       data-themes="<?= $e(implode(' ', $p['themes'])) ?>"
                       data-title="<?= $e($p['title']) ?>"
                       data-description="<?= $e($p['description']) ?>"
                       data-preview="<?= $e($p['preview']) ?>"
                       aria-current="<?= $slug === $first ? 'true' : 'false' ?>"><?= $e($p['title']) ?></a></li>
<?php endforeach; ?>
            </ul>
<?php endforeach; ?>
            <p id="menu-empty" class="muted menu-empty" hidden>Ingen sider i dette temaet.</p>
        </div>
        <p class="hint menu-hint">Klikk for å se en side. Dobbeltklikk eller trykk Enter for å åpne den.</p>
    </aside>

    <section class="menu-preview" aria-live="polite">
<?php if ($notFound): ?>
        <p class="message">Fant ikke siden. Velg en side i lista.</p>
<?php endif; ?>
<?php $fp = $site['pages'][$first]; ?>
        <h2 id="preview-title" class="preview-title"><?= $e($fp['title']) ?></h2>
        <a id="preview-open" class="preview-image" href="?side=<?= $e($first) ?>" aria-label="Åpne <?= $e($fp['title']) ?>">
            <img id="preview-img" src="<?= $e($fp['preview']) ?>" alt="" width="1200" height="800">
        </a>
        <div class="preview-text">
            <p id="preview-description"><?= $e($fp['description']) ?></p>
            <a id="preview-start" class="btn btn-primary btn-start" href="?side=<?= $e($first) ?>">
                <span class="btn-title">Åpne <span id="preview-start-title"><?= $e($fp['title']) ?></span></span>
            </a>
        </div>
    </section>
</main>
