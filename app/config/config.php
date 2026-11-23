<?php
/**
 * Global konfigurasjon for M+ 3D.
 *
 * Verdier kan overstyres med miljøvariabler, slik at det ikke er nødvendig
 * å legge lokale stier i koden:
 *
 *   OPENSCAD_BIN        Full sti til openscad-binæren. Hvis ikke satt, letes
 *                       det etter vanlige installasjonssteder og så i PATH.
 *   OPENSCAD_ARGS       Ekstra argumenter, skilt med mellomrom
 *                       (f.eks. "--backend=manifold"). Settes automatisk
 *                       når binæren støtter Manifold.
 *   OPENSCAD_TIMEOUT    Maks kjøretid i sekunder (standard 60).
 *   MPLUS3D_STORAGE     Mappe for genererte filer (standard storage/).
 */

declare(strict_types=1);

$root = dirname(__DIR__, 2);

return [
    'root'        => $root,
    'models_dir'  => $root . '/models',
    'storage_dir' => getenv('MPLUS3D_STORAGE') ?: $root . '/storage',

    'openscad' => [
        'binary'          => getenv('OPENSCAD_BIN') ?: null,    // null = autodetekter
        'args'            => getenv('OPENSCAD_ARGS') !== false ? array_values(array_filter(explode(' ', (string) getenv('OPENSCAD_ARGS')))) : null,
        'timeout'         => (int) (getenv('OPENSCAD_TIMEOUT') ?: 60),
        'max_concurrent'  => 2,       // samtidige OpenSCAD-prosesser
        'queue_wait'      => 20,      // sekunder å vente på ledig plass
        // Valgfritt prefiks for ressursbegrensning på Linux, f.eks.
        // ['nice', '-n', '10', 'prlimit', '--as=2000000000', '--'].
        'wrapper'         => [],
    ],

    'stl' => [
        'max_triangles'   => 200000,
        'dimension_tolerance' => 0.02,   // mm
    ],

    // Hvor lenge genererte filer beholdes (sekunder).
    'generated_ttl' => 3600,

    // Når en side åpnes fra menyen i M+ (index.php?side=…&tilbake=<url>), fører logoen
    // og menylenken tilbake dit. Bare adresser på disse vertene godtas, så parameteren
    // ikke kan brukes til å sende folk videre til andre nettsteder.
    // Kan overstyres med MPLUS_RETURN_HOSTS (kommaseparert).
    'mplus_return_hosts' => getenv('MPLUS_RETURN_HOSTS') !== false
        ? array_values(array_filter(array_map('trim', explode(',', (string) getenv('MPLUS_RETURN_HOSTS')))))
        : ['app.mpluss.no', 'apptest.mpluss.no', 'test.mpluss.no', 'localhost', '127.0.0.1'],

    // Hjelp-knappen øverst til høyre lenker hit (public/hjelp/: hvordan 3MF- og
    // PDF-filene lages og brukes). På modellsidene legges #side til (f.eks. #base10),
    // så hjelpesiden åpnes ved avsnittet om modellen. Kan overstyres med M3D_HELP_URL.
    'help_url' => getenv('M3D_HELP_URL') !== false ? (string) getenv('M3D_HELP_URL') : 'hjelp/',
];
