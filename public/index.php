<?php
/**
 * M+ 3D – startside (meny) og modellsider.
 *
 *   index.php                   menyen
 *   index.php?side=terning      terninggeneratoren
 *   index.php?side=broeksirkler brøksirklene
 *   index.php?side=base10       base 10 / plassverdimateriell
 *   index.php?side=tallstaver   tallstaver (Cuisenaire-staver)
 *   index.php?side=romfigurer   romfigurer og utbrettingsmodeller
 *   index.php?side=regnmedlego  Regn med Lego (rammer til LEGO-klosser)
 *
 * Sidene er definert i app/config/pages.php. Bare sider som står der,
 * kan vises (side-parameteren brukes aldri som filsti).
 */
declare(strict_types=1);

header('X-Content-Type-Options: nosniff');
$v = '12';   // øk for å tvinge ny CSS/JS i nettleseren

$site = require __DIR__ . '/../app/config/pages.php';
$slug = $_GET['side'] ?? null;
$page = is_string($slug) && isset($site['pages'][$slug]) ? $site['pages'][$slug] : null;
if ($slug !== null && $page === null) {
    http_response_code(404);
}

$e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
$templates = __DIR__ . '/../templates/pages';
$title = $page ? $page['title'] : 'Velg modell';

// Åpnet fra menyen i M+? Da peker logoen og menylenken tilbake til M+.
// Bare http(s)-adresser på kjente M+-verter godtas (se mplus_return_hosts).
$mplusBack = null;
$back = $_GET['tilbake'] ?? null;
if ($page && is_string($back) && strlen($back) <= 500) {
    $parts = parse_url($back);
    $hosts = (require __DIR__ . '/../app/config/config.php')['mplus_return_hosts'];
    if (is_array($parts)
        && in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
        && in_array(strtolower($parts['host'] ?? ''), $hosts, true)
        && !isset($parts['user']) && !isset($parts['pass'])) {
        $mplusBack = $back;
    }
}
$menuHref = $mplusBack ?? './';
$menuTitle = $mplusBack ? 'Til menyen i M+' : 'Til menyen';
?>
<!doctype html>
<html lang="nb">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>M+ 3D – <?= $e($title) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,500..800&family=Figtree:wght@400..700&display=swap">
    <link rel="stylesheet" href="css/app.css?v=<?= $v ?>">
<?php if ($page): ?>
    <script type="importmap">
    {
        "imports": {
            "three": "https://cdn.jsdelivr.net/npm/three@0.170.0/build/three.module.js",
            "three/addons/": "https://cdn.jsdelivr.net/npm/three@0.170.0/examples/jsm/"
        }
    }
    </script>
<?php endif; ?>
</head>
<body data-page="<?= $e($page ? (string) $slug : 'meny') ?>">
<div id="app-frame" class="app-frame">
<script src="js/appscale.js?v=<?= $v ?>"></script>
<header class="topbar">
    <a class="logo-tile" href="<?= $e($menuHref) ?>" title="<?= $e($menuTitle) ?>">
        <img src="assets/mplus-logo.svg" alt="M+" width="86" height="64">
    </a>
    <div class="brand">M+ <span class="brand-3d">3D</span></div>
    <span class="topbar-rule" aria-hidden="true"></span>
    <h1><?= $e($page ? $page['h1'] : 'Velg modell') ?></h1>
    <nav class="model-nav<?= $mplusBack ? ' model-nav-single' : '' ?>" aria-label="Sider">
        <a href="<?= $e($menuHref) ?>" class="nav-menu"<?= $page ? '' : ' aria-current="page"' ?>>
            <svg width="16" height="16" viewBox="0 0 16 16" aria-hidden="true"><path d="M2 2h5v5H2zM9 2h5v5H9zM2 9h5v5H2zM9 9h5v5H9z" fill="none" stroke="currentColor" stroke-width="1.6"/></svg>
            <?= $mplusBack ? 'Meny i M+' : 'Meny' ?>
        </a>
<?php foreach ($mplusBack ? [] : $site['pages'] as $s => $p): ?>
        <a href="?side=<?= $e($s) ?>"<?= $s === $slug ? ' aria-current="page"' : '' ?>><?= $e($p['title']) ?></a>
<?php endforeach; ?>
    </nav>
<?php if ($page): ?>
    <button type="button" class="link-btn help-link" id="help-open" aria-haspopup="dialog">Hva er STL og PDF?</button>
<?php endif; ?>
</header>

<?php
if ($page) {
    require $templates . '/' . basename($page['template']);
} else {
    $notFound = $slug !== null;
    require $templates . '/menu.php';
}
?>
</div>

<?php if ($page): ?>
<script type="module" src="<?= $e($page['script']) ?>?v=<?= $v ?>"></script>
<?php else: ?>
<script type="module" src="js/pages/menu.js?v=<?= $v ?>"></script>
<?php endif; ?>
</body>
</html>
