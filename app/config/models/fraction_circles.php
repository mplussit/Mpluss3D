<?php
/**
 * Modelldefinisjon: brøksirkler.
 *
 * Eneste kilde til parametere, grenser og standardverdier for brøksirklene.
 * API, brukergrensesnitt (action=schema&model=fractions), STL og PDF bruker
 * denne fila. Geometrien ligger i models/fractions/sector.scad.
 */

declare(strict_types=1);

return [
    'id'       => 'fraction_circles',
    'version'  => 1,
    'title'    => 'Brøksirkler',
    'scad'     => 'fractions/sector.scad',
    'filename' => 'broeksirkel',          // broeksirkel_1-4.stl
    'archive'  => 'broeksirkler',         // broeksirkler-100mm.zip / .pdf

    'parameters' => [
        'diameter'    => ['label' => 'Sirkeldiameter',          'unit' => 'mm', 'min' => 50, 'max' => 150, 'default' => 100, 'step' => 1,    'scad' => 'diameter'],
        'thickness'   => ['label' => 'Brikketykkelse',          'unit' => 'mm', 'min' => 2,  'max' => 8,   'default' => 3,   'step' => 0.5,  'scad' => 'thickness'],
        'clearance'   => ['label' => 'Klaring mellom brikker',  'unit' => 'mm', 'min' => 0,  'max' => 0.8, 'default' => 0.3, 'step' => 0.05, 'scad' => 'clearance',
                          'help' => 'Spalten mellom to brikker som ligger inntil hverandre.'],
        'edge_radius' => ['label' => 'Kantavrunding',           'unit' => 'mm', 'min' => 0,  'max' => 1,   'default' => 0.5, 'step' => 0.1,  'scad' => 'edge_radius',
                          'help' => 'Bare de øvre kantene. Undersiden er flat.'],
        'label_depth' => ['label' => 'Merkedybde',              'unit' => 'mm', 'min' => 0.2, 'max' => 0.8, 'default' => 0.4, 'step' => 0.1, 'scad' => 'label_depth',
                          'group' => 'label'],
    ],

    'denominators' => [
        'min'     => 1,
        'max'     => 12,
        'default' => [2, 3, 4, 6, 8],
    ],

    // Brøkmerking (nedsenket tekst). Opphøyd merking er ikke med i versjon 1.
    'labels' => [
        'default'      => true,
        'styles'       => ['none' => 'Ingen merking', 'engraved' => 'Nedsenket'],
        // Bredde/høyde for teksten "1/n" (n = 1 gir "1") i Liberation Sans Bold,
        // målt fra OpenSCAD. Teksten skaleres til en eksakt boks med resize(),
        // så disse tallene bestemmer bare proporsjonene.
        'aspect'       => [1 => 0.6764, 2 => 1.7286, 3 => 1.7338, 4 => 1.7752, 5 => 1.7450, 6 => 1.7338,
                           7 => 1.7233, 8 => 1.7411, 9 => 1.7325, 10 => 2.4742, 11 => 2.4178, 12 => 2.4755],
        'height_ratio' => 0.12,   // ønsket teksthøyde som andel av diameteren …
        'max_height'   => 14,     // … men høyst så mye (mm)
        'min_height'   => 4,      // lavere tekst blir utydelig med 0,4 mm dyse → utelates
        'margin'       => 1.0,    // minste avstand fra teksten til avrundingen/kanten (mm)
        'fill'         => 0.92,   // bruk høyst denne andelen av plassen som er tilgjengelig
    ],

    'scad_constants' => [
        'arc_step_deg'  => 3,     // grader per segment i ytterbuen
        'edge_segments' => 12,
        'text_segments' => 16,
    ],

    // Farger i forhåndsvisningen og PDF-en (STL har ikke farger).
    // Rekkefølgen følger spesifikasjonen: 1/2 rød, 1/3 blå, 1/4 grønn, 1/6 gul, 1/8 lilla.
    'colors' => [
        1  => ['hex' => '#8c857b', 'name' => 'grå'],
        2  => ['hex' => '#d64541', 'name' => 'rød'],
        3  => ['hex' => '#2e6db4', 'name' => 'blå'],
        4  => ['hex' => '#2e8b57', 'name' => 'grønn'],
        5  => ['hex' => '#e07b24', 'name' => 'oransje'],
        6  => ['hex' => '#e8b923', 'name' => 'gul'],
        7  => ['hex' => '#8a5a3c', 'name' => 'brun'],
        8  => ['hex' => '#7b4fa8', 'name' => 'lilla'],
        9  => ['hex' => '#d06aa0', 'name' => 'rosa'],
        10 => ['hex' => '#2aa3a3', 'name' => 'turkis'],
        11 => ['hex' => '#6b7a2e', 'name' => 'oliven'],
        12 => ['hex' => '#4d5fa8', 'name' => 'indigo'],
    ],

    // Navn på delene: [entall, flertall, bestemt flertall]
    'names' => [
        1  => ['hel', 'hele', 'de hele'],
        2  => ['halvdel', 'halvdeler', 'halvdelene'],
        3  => ['tredjedel', 'tredjedeler', 'tredjedelene'],
        4  => ['firedel', 'firedeler', 'firedelene'],
        5  => ['femtedel', 'femtedeler', 'femtedelene'],
        6  => ['sjettedel', 'sjettedeler', 'sjettedelene'],
        7  => ['sjuendedel', 'sjuendedeler', 'sjuendedelene'],
        8  => ['åttendedel', 'åttendedeler', 'åttendedelene'],
        9  => ['niendedel', 'niendedeler', 'niendedelene'],
        10 => ['tidel', 'tideler', 'tidelene'],
        11 => ['ellevtedel', 'ellevtedeler', 'ellevtedelene'],
        12 => ['tolvtedel', 'tolvtedeler', 'tolvtedelene'],
    ],
];
