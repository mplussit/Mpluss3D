<?php
/**
 * Modelldefinisjon: Base 10 / plassverdimateriell.
 *
 * Eneste kilde til parametere, grenser og standardverdier for base 10-klossene.
 * API, brukergrensesnitt (action=schema&model=base10), STL og PDF bruker
 * denne fila. Geometrien ligger i models/base10/block.scad.
 */

declare(strict_types=1);

return [
    'id'       => 'base10_blocks',
    'version'  => 1,
    'title'    => 'Base 10',
    'scad'     => 'base10/block.scad',
    'filename' => 'base10',               // base10_ener.stl
    'archive'  => 'base10',               // base10-10mm.zip / .pdf

    'parameters' => [
        'unit'         => ['label' => 'Enhetslengde',         'unit' => 'mm', 'min' => 6,   'max' => 15,  'default' => 10,  'step' => 0.5,
                           'help' => 'Sidelengden på enerkuben. Alle de andre klossene er bygd av denne enheten.'],
        'edge_radius'  => ['label' => 'Kantavrunding',        'unit' => 'mm', 'min' => 0,   'max' => 1,   'default' => 0.3, 'step' => 0.1,
                           'help' => 'Kantene mot byggeplaten får en like stor fas i stedet, så det ikke blir overheng.'],
        'groove_depth' => ['label' => 'Rilledybde',           'unit' => 'mm', 'min' => 0.2, 'max' => 0.8, 'default' => 0.4, 'step' => 0.1,
                           'group' => 'grid'],
        'groove_width' => ['label' => 'Rillebredde',          'unit' => 'mm', 'min' => 0.3, 'max' => 1.2, 'default' => 0.6, 'step' => 0.1,
                           'group' => 'grid'],
        'wall'         => ['label' => 'Veggtykkelse tusener', 'unit' => 'mm', 'min' => 1.2, 'max' => 4,   'default' => 2,   'step' => 0.1,
                           'group' => 'thousand'],
    ],

    'switches' => [
        'grid'   => ['label' => 'Rutenett / inndeling', 'default' => true],
        'hollow' => ['label' => 'Hul tusenerkloss',     'default' => true],
    ],

    // Grenser som hindrer at riller svekker små klosser (se Base10Model::validate()).
    'limits' => [
        'groove_depth_ratio' => 0.10,   // rilledybde høyst 10 % av enhetslengden
        'groove_width_ratio' => 0.15,   // rillebredde høyst 15 % av enhetslengden
        'min_wall_left'      => 0.8,    // minst 0,8 mm vegg igjen under en rille (to strenger)
    ],

    // Objekttypene, i rekkefølge fra minst til størst.
    // units = [x, y, z] i enheter, slik klossen ligger på byggeplaten.
    'types' => [
        'ener'    => ['value' => 1,    'units' => [1, 1, 1],    'enabled' => true,  'count' => 20, 'max' => 200,
                      'names' => ['ener', 'enere', 'enerne'],          'color' => ['hex' => '#e8b923', 'name' => 'gul']],
        'tier'    => ['value' => 10,   'units' => [10, 1, 1],   'enabled' => true,  'count' => 10, 'max' => 100,
                      'names' => ['tier', 'tiere', 'tierne'],          'color' => ['hex' => '#2e8b57', 'name' => 'grønn']],
        'hundrer' => ['value' => 100,  'units' => [10, 10, 1],  'enabled' => true,  'count' => 10, 'max' => 50,
                      'names' => ['hundrer', 'hundrere', 'hundrerne'], 'color' => ['hex' => '#2e6db4', 'name' => 'blå']],
        'tusener' => ['value' => 1000, 'units' => [10, 10, 10], 'enabled' => false, 'count' => 0,  'max' => 20,
                      'names' => ['tusener', 'tusenere', 'tusenerne'], 'color' => ['hex' => '#e07b24', 'name' => 'oransje']],
    ],

    // Omtrentlig materialforbruk (PLA). Utskriften antas å ha et skall på
    // ca. 0,85 mm (2 vegger / 4 bunn- og topplag) og 15 % fyll innenfor.
    'material' => [
        'density' => 1.24,    // g/cm³
        'shell'   => 0.85,    // mm
        'infill'  => 0.15,
    ],

    'scad_constants' => [
        'edge_segments' => 16,
    ],
];
