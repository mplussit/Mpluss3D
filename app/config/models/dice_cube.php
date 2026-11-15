<?php
/**
 * Modelldefinisjon: seksidet terning.
 *
 * Dette er den eneste kilden til parametere, grenser og standardverdier.
 * Både API, brukergrensesnitt (via /api.php?action=schema), STL-generering
 * og PDF-generering bruker denne filen.
 *
 * Nye geometriparametere legges til slik:
 *   1. Legg variabelen inn i models/dice/cube.scad.
 *   2. Legg den inn under 'parameters' her, med 'scad' => variabelnavn.
 *   3. (Valgfritt) Legg til kryssvalidering i DiceModel::validateCombination().
 * Brukergrensesnittet lager kontrollen automatisk.
 */

declare(strict_types=1);

return [
    'id'       => 'dice_cube',
    'version'  => 1,
    'title'    => 'Terning',
    'scad'     => 'dice/cube.scad',   // relativt til models/
    'filename' => 'mplus-terning',

    // Geometriparametere. Rekkefølgen styrer rekkefølgen i skjemaet.
    'parameters' => [
        'width'         => ['label' => 'Bredde',        'unit' => 'mm', 'min' => 15, 'max' => 50, 'default' => 30,  'step' => 1,   'scad' => 'width',         'group' => 'size'],
        'height'        => ['label' => 'Høyde',         'unit' => 'mm', 'min' => 15, 'max' => 50, 'default' => 30,  'step' => 1,   'scad' => 'height',        'group' => 'size'],
        'depth'         => ['label' => 'Dybde',         'unit' => 'mm', 'min' => 15, 'max' => 50, 'default' => 30,  'step' => 1,   'scad' => 'depth',         'group' => 'size'],
        'corner_radius' => ['label' => 'Hjørneradius',  'unit' => 'mm', 'min' => 0,  'max' => 4,  'default' => 2,   'step' => 0.5, 'scad' => 'corner_radius'],
        'pip_diameter'  => ['label' => 'Prikkdiameter', 'unit' => 'mm', 'min' => 2,  'max' => 5,  'default' => 3,   'step' => 0.5, 'scad' => 'pip_diameter'],
        'pip_depth'     => ['label' => 'Prikkdybde',    'unit' => 'mm', 'min' => 0,  'max' => 1,  'default' => 0.5, 'step' => 0.1, 'scad' => 'pip_depth',
                            'help' => '0 gir blanke sider uten prikker.'],
    ],

    // Innhold per side. Standard vestlig terning: motstående sider summerer
    // til 7, og 1-2-3 møtes mot klokka.
    'faces' => [
        'top'    => '1',
        'bottom' => '6',
        'front'  => '2',
        'back'   => '5',
        'right'  => '3',
        'left'   => '4',
    ],

    // Faste SCAD-innstillinger (ikke styrt av brukeren).
    'scad_constants' => [
        'pip_spread'      => 0.25,
        'corner_segments' => 32,
        'pip_segments'    => 24,
    ],

    // Dekor/etiketter (bare PDF).
    'label' => [
        'min'       => 5,
        'max'       => 50,
        'step'      => 0.1,
        'clearance' => 0.3,   // mm klaring per side mot den flate delen av sideflaten
        'decor'     => [
            'pips'    => 'Prikker',
            'numbers' => 'Tall',
            'blank'   => 'Tomme etiketter',
        ],
        'default_decor' => 'pips',
    ],

    // Kryssvalidering (mm)
    'rules' => [
        'min_pip_gap'      => 0.6,  // minste avstand mellom to prikker (≈ 1,5 ekstruderingsbredder)
        'min_edge_margin'  => 0.2,  // prikkene skal ligge minst så langt innenfor den flate delen
    ],
];
