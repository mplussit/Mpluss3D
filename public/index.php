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
 *   index.php?side=kulespill    Kulespill med ark (hullark, verdiark, oppgaver)
 *
 * Sidene er definert i app/config/pages.php. Bare sider som står der,
 * kan vises (side-parameteren brukes aldri som filsti).
 */
declare(strict_types=1);

header('X-Content-Type-Options: nosniff');
$v = '16';   // øk for å tvinge ny CSS/JS i nettleseren

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

// Hjelp-knappen: sidene på nettstedet som forklarer STL og PDF (app/config/config.php).
$helpHref = (string) ((require __DIR__ . '/../app/config/config.php')['help_url'] ?? '');
if ($page && $helpHref !== '') {
    $helpHref .= '#' . rawurlencode((string) $slug);
}
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
            "three/addons/": "https://cdn.jsdelivr.net/npm/three@0.170.0/examples/jsm/",
            "pdfjs-dist": "https://cdn.jsdelivr.net/npm/pdfjs-dist@6.2.108/build/pdf.min.mjs",
            "pdf-lib": "https://cdn.jsdelivr.net/npm/pdf-lib@1.17.1/dist/pdf-lib.esm.min.js"
        }
    }
    </script>
<?php endif; ?>
</head>
<body data-page="<?= $e($page ? (string) $slug : 'meny') ?>">
<div id="app-frame" class="app-frame">
<script src="js/appscale.js?v=<?= $v ?>"></script>
<header class="topbar">
<?php if ($page): ?>
    <a class="menu-btn" href="<?= $e($menuHref) ?>" title="<?= $e($menuTitle) ?>">
        <svg width="16" height="16" viewBox="0 0 16 16" aria-hidden="true"><path d="M2 2h5v5H2zM9 2h5v5H9zM2 9h5v5H2zM9 9h5v5H9z" fill="none" stroke="currentColor" stroke-width="1.6"/></svg>
        <?= $mplusBack ? 'Meny i M+' : 'Meny' ?>
    </a>
    <h1><?= $e($page['h1']) ?></h1>
    <button type="button" class="help-btn pdf-btn" id="pdf-open" title="Lag PDF av sidene, eller samle sider fra flere modeller i én PDF">
        <svg width="20" height="14" viewBox="0 0 20 14" aria-hidden="true"><rect x="0.75" y="0.75" width="18.5" height="12.5" rx="1.5" fill="none" stroke="currentColor" stroke-width="1.3"/><text x="10" y="9.6" text-anchor="middle" font-family="Arial, Helvetica, sans-serif" font-size="6.6" font-weight="700" fill="currentColor">PDF</text></svg>
        PDF <span class="pdf-count" id="pdf-count" hidden>0</span>
    </button>
<?php else: ?>
    <span class="menu-brand"><img src="assets/mplus-logo.svg" alt="M+" width="43" height="32"> <span class="brand">M+ <span class="brand-3d">3D</span></span></span>
    <h1>Velg modell</h1>
<?php endif; ?>
    <a class="help-btn" href="<?= $e($helpHref) ?>" target="_blank" rel="noopener" title="Slik bruker du STL- og PDF-filene (åpnes i ny fane)">
        <svg width="18" height="18" viewBox="0 0 18 18" aria-hidden="true"><circle cx="9" cy="9" r="7.6" fill="none" stroke="currentColor" stroke-width="1.6"/><path d="M6.9 7.1a2.2 2.2 0 1 1 3 2c-.6.3-.9.7-.9 1.3v.4" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><circle cx="9" cy="13.2" r="1" fill="currentColor"/></svg>
        Hjelp
    </a>
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
