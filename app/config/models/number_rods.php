<?php
/**
 * Modelldefinisjon: tallstaver (Cuisenaire-staver).
 *
 * Eneste kilde til parametere, grenser, farger og standardverdier for
 * tallstavene. API, brukergrensesnitt (action=schema&model=rods), STL og PDF
 * bruker denne fila. Geometrien ligger i models/rods/rod.scad.
 */

declare(strict_types=1);

return [
    'id'       => 'number_rods',
    'version'  => 1,
    'title'    => 'Tallstaver',
    'scad'     => 'rods/rod.scad',
    'filename' => 'tallstav',             // tallstav_01.stl … tallstav_10.stl
    'archive'  => 'tallstaver',           // tallstaver-10mm.zip / .pdf

    'parameters' => [
        'unit'         => ['label' => 'Enhetslengde',  'unit' => 'mm', 'min' => 6,   'max' => 20,  'default' => 10,  'step' => 0.5,
                           'help' => 'Lengden på stav 1. Alle stavene har tverrsnitt enhet × enhet, og stav n er n enheter lang.'],
        'edge_radius'  => ['label' => 'Kantavrunding', 'unit' => 'mm', 'min' => 0,   'max' => 1.5, 'default' => 0.5, 'step' => 0.1,
                           'help' => 'Bare de fire langsgående kantene. Endeflatene er helt plane, så stavene kan legges ende mot ende.'],
        'groove_depth' => ['label' => 'Rilledybde',    'unit' => 'mm', 'min' => 0.2, 'max' => 0.8, 'default' => 0.3, 'step' => 0.1,
                           'group' => 'grid'],
        'groove_width' => ['label' => 'Rillebredde',   'unit' => 'mm', 'min' => 0.3, 'max' => 1.2, 'default' => 0.5, 'step' => 0.1,
                           'group' => 'grid'],
        'label_depth'  => ['label' => 'Merkedybde',    'unit' => 'mm', 'min' => 0.2, 'max' => 0.8, 'default' => 0.4, 'step' => 0.1,
                           'group' => 'label', 'help' => 'Hvor dypt tallet er senket ned i oversiden.'],
    ],

    'switches' => [
        'grid'   => ['label' => 'Enhetsinndeling', 'default' => false],
        'labels' => ['label' => 'Tallmerking',     'default' => false],
    ],

    // Grenser som hindrer at riller og avrunding svekker eller ødelegger små staver.
    'limits' => [
        'groove_depth_ratio' => 0.10,   // rilledybde høyst 10 % av enhetslengden
        'groove_width_ratio' => 0.15,   // rillebredde høyst 15 % av enhetslengden
        'edge_radius_ratio'  => 0.20,   // avrunding høyst 20 % av enhetslengden
    ],

    'lengths' => [
        'min'           => 1,
        'max'           => 10,
        'default'       => [1, 2, 3, 4, 5, 6, 7, 8, 9, 10],
        'count_default' => 10,
        'count_max'     => 100,
    ],

    // Fargene finnes bare i forhåndsvisningen og PDF-en (STL har ikke farger).
    'colors' => [
        'modes'   => ['classic' => 'Klassiske farger', 'single' => 'Én farge', 'custom' => 'Egne farger'],
        'default' => 'classic',
        'single'  => '#f36d21',
        // Klassiske Cuisenaire-farger
        'classic' => [
            1  => ['hex' => '#f2efe8', 'name' => 'hvit'],
            2  => ['hex' => '#d43d3d', 'name' => 'rød'],
            3  => ['hex' => '#8cc63f', 'name' => 'lysegrønn'],
            4  => ['hex' => '#8e4fb0', 'name' => 'lilla'],
            5  => ['hex' => '#f2c418', 'name' => 'gul'],
            6  => ['hex' => '#1e7b3c', 'name' => 'mørkegrønn'],
            7  => ['hex' => '#2a2a2a', 'name' => 'svart'],
            8  => ['hex' => '#8a5a3c', 'name' => 'brun'],
            9  => ['hex' => '#2e6db4', 'name' => 'blå'],
            10 => ['hex' => '#ef7d22', 'name' => 'oransje'],
        ],
    ],

    // Tallmerking (nedsenket tekst). Teksten skaleres til en eksakt boks
    // med resize(), så tallene under bestemmer bare proporsjonene.
    'labels' => [
        // Bredde/høyde for tallene i Liberation Sans Bold, målt fra OpenSCAD.
        'aspect'       => [1 => 0.6764, 2 => 0.6895, 3 => 0.7006, 4 => 0.7786, 5 => 0.7131,
                           6 => 0.6828, 7 => 0.6820, 8 => 0.6972, 9 => 0.6841, 10 => 1.4242],
        'height_ratio' => 0.60,   // teksthøyde høyst 60 % av enhetslengden
        'min_height'   => 3.0,    // lavere tekst blir utydelig med 0,4 mm dyse → utelates
        'margin'       => 0.8,    // minste avstand fra teksten til avrunding, rille eller ende (mm)
    ],

    // Omtrentlig materialforbruk (PLA), samme modell som base 10.
    'material' => [
        'density' => 1.24,    // g/cm³
        'shell'   => 0.85,    // mm
        'infill'  => 0.15,
    ],

    'scad_constants' => [
        'edge_segments' => 16,
        'text_segments' => 16,
    ],
];
