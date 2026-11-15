<?php
/**
 * Modelldefinisjon: romfigurer og utbrettingsmodeller.
 *
 * Eneste kilde til figurer, parametere, grenser og standardverdier. API,
 * brukergrensesnitt (action=schema&model=solids), STL, nett og PDF bruker
 * denne fila. Geometrien regnes ut av klassene under 'shapes' (felles
 * geometrimodell, se app/services/Solid.php) og bygges i models/solids/solid.scad.
 *
 * Ny figur: lag en klasse som utvider Solid, legg den inn under 'shapes'
 * med parameterne sine, og gi den et nummer i solid.scad.
 */

declare(strict_types=1);

return [
    'id'       => 'solid_figures',
    'version'  => 1,
    'title'    => 'Romfigurer',
    'scad'     => 'solids/solid.scad',
    'filename' => 'romfigur',

    // Figurene i rekkefølgen de vises. 'params' kobler figurens mål til parameterne under.
    'shapes' => [
        'cube'     => ['title' => 'Kube',                'short' => 'Kube',          'class' => SolidCube::class,
                       'params' => ['side' => 'cube_side']],
        'cuboid'   => ['title' => 'Rektangulært prisme', 'short' => 'Prisme',        'class' => SolidCuboid::class,
                       'params' => ['length' => 'cuboid_length', 'width' => 'cuboid_width', 'height' => 'cuboid_height']],
        'pyramid'  => ['title' => 'Firkantet pyramide',  'short' => 'Pyramide',      'class' => SolidPyramid::class,
                       'params' => ['base' => 'pyramid_base', 'height' => 'pyramid_height']],
        'triprism' => ['title' => 'Trekantet prisme',    'short' => 'Trekantprisme', 'class' => SolidTriPrism::class,
                       'params' => ['base' => 'prism_base', 'height' => 'prism_height', 'length' => 'prism_length']],
        'cylinder' => ['title' => 'Sylinder',            'short' => 'Sylinder',      'class' => SolidCylinder::class,
                       'params' => ['diameter' => 'cylinder_diameter', 'height' => 'cylinder_height']],
        'cone'     => ['title' => 'Kjegle',              'short' => 'Kjegle',        'class' => SolidCone::class,
                       'params' => ['diameter' => 'cone_diameter', 'height' => 'cone_height']],
    ],
    'default_shape' => 'cube',

    'parameters' => [
        'cube_side'         => ['label' => 'Sidelengde',            'unit' => 'mm', 'min' => 20, 'max' => 100, 'default' => 40, 'step' => 1, 'shape' => 'cube'],
        'cuboid_length'     => ['label' => 'Lengde',                'unit' => 'mm', 'min' => 20, 'max' => 150, 'default' => 60, 'step' => 1, 'shape' => 'cuboid'],
        'cuboid_width'      => ['label' => 'Bredde',                'unit' => 'mm', 'min' => 20, 'max' => 100, 'default' => 40, 'step' => 1, 'shape' => 'cuboid'],
        'cuboid_height'     => ['label' => 'Høyde',                 'unit' => 'mm', 'min' => 20, 'max' => 100, 'default' => 30, 'step' => 1, 'shape' => 'cuboid'],
        'pyramid_base'      => ['label' => 'Grunnside',             'unit' => 'mm', 'min' => 20, 'max' => 100, 'default' => 50, 'step' => 1, 'shape' => 'pyramid'],
        'pyramid_height'    => ['label' => 'Høyde (loddrett)',      'unit' => 'mm', 'min' => 20, 'max' => 100, 'default' => 50, 'step' => 1, 'shape' => 'pyramid'],
        'prism_base'        => ['label' => 'Grunnlinje i trekanten', 'unit' => 'mm', 'min' => 20, 'max' => 100, 'default' => 50, 'step' => 1, 'shape' => 'triprism'],
        'prism_height'      => ['label' => 'Høyde i trekanten',     'unit' => 'mm', 'min' => 20, 'max' => 100, 'default' => 40, 'step' => 1, 'shape' => 'triprism'],
        'prism_length'      => ['label' => 'Lengde',                'unit' => 'mm', 'min' => 20, 'max' => 150, 'default' => 70, 'step' => 1, 'shape' => 'triprism'],
        'cylinder_diameter' => ['label' => 'Diameter',              'unit' => 'mm', 'min' => 20, 'max' => 100, 'default' => 50, 'step' => 1, 'shape' => 'cylinder'],
        'cylinder_height'   => ['label' => 'Høyde',                 'unit' => 'mm', 'min' => 20, 'max' => 150, 'default' => 70, 'step' => 1, 'shape' => 'cylinder'],
        'cone_diameter'     => ['label' => 'Diameter',              'unit' => 'mm', 'min' => 20, 'max' => 100, 'default' => 60, 'step' => 1, 'shape' => 'cone'],
        'cone_height'       => ['label' => 'Høyde (loddrett)',      'unit' => 'mm', 'min' => 20, 'max' => 150, 'default' => 70, 'step' => 1, 'shape' => 'cone'],

        'wall'      => ['label' => 'Veggtykkelse', 'unit' => 'mm', 'min' => 1.2, 'max' => 4, 'default' => 2, 'step' => 0.1, 'group' => 'hollow',
                        'help' => 'Hulrommet er lukket og har et tak som skrår minst 45°, så figuren kan skrives ut uten støtte.'],
        'tab_width' => ['label' => 'Limflikbredde', 'unit' => 'mm', 'min' => 5, 'max' => 12, 'default' => 7, 'step' => 0.5, 'group' => 'net',
                        'help' => 'Limflikene endrer ikke målene på figuren.'],
    ],

    'switches' => [
        'hollow' => ['label' => 'Hul modell', 'default' => false],
    ],

    'choices' => [
        'level'     => ['label' => 'Vanskelighetsgrad', 'default' => '2', 'options' => [
            '1' => 'Nivå 1 – Utforsk',
            '2' => 'Nivå 2 – Mål og sammenlign',
            '3' => 'Nivå 3 – Beregn',
        ]],
        'net_print' => ['label' => 'Store nett', 'default' => 'tiles', 'options' => [
            'tiles'  => 'Flere A4-ark i målestokk 1:1',
            'scaled' => 'Skalert ned til ett A4-ark',
        ]],
        'document'  => ['label' => 'PDF', 'default' => 'net', 'options' => [
            'net'   => 'Utbrettingsfigur',
            'tasks' => 'Oppgaver',
        ]],
    ],

    // Fargene til flatene i 3D-visningen («Vis flater») og i nettet. Samme rekkefølge som flatene.
    'face_colors' => ['#f36d21', '#2e6db4', '#f2c418', '#1e7b3c', '#8e4fb0', '#d43d3d'],

    // STL-oppløsning for sylinder og kjegle (sider i mangekanten).
    'segments' => 128,

    // Omtrentlig materialforbruk (PLA). Hul modell: alt er vegg.
    'material' => [
        'density' => 1.24,    // g/cm³
        'shell'   => 0.85,    // mm vegg i sliceren (2 omkretser)
        'infill'  => 0.15,
    ],
];
