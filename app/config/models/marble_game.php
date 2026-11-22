<?php
/**
 * Kulespill: arkene som legges mellom bunnen og kulespillet.
 *
 * Kulespillet og bunnen er tegnet i SolidCraft (kulespill.sc, kulespill_B.sc,
 * kulespill_C.sc og kulespill_bunn.sc) og ligger ferdig som STL i
 * public/assets/kulespill/. Det finnes tre spillbrett, A, B og C, med ulike
 * lommer og pinner. Under hver lomme er det et gjennomgående hull på Ø20 mm,
 * så arket under syns gjennom hullene. Koordinatene under er hentet fra
 * modellene (mm, X mot høyre, Y mot toppen av spillet, origo i sentrum av
 * den runde toppen).
 *
 * Arket er 160 × 250 mm, nøyaktig spillets ytre mål, og ligger i en lomme
 * på 161 × 251 mm i bunnen. Det skrives ut på A4 med skjæremerker.
 */

declare(strict_types=1);

return [
    'id'      => 'marble_game',
    'version' => 1,

    // Spillet (fra SolidCraft-modellen).
    'board' => [
        'width'  => 160.0,          // X: −80 … 80
        'length' => 250.0,          // Y: −170 … 80
        'top_y'  => 80.0,           // Y-verdien til toppen av arket
        'arc_r'  => 80.0,           // radius på den runde toppen, sentrum i origo
        'height' => 18.0,
    ],
    'hole_diameter' => 20.0,

    // Tre spillbrett med ulik plassering og ulikt antall hull og pinner.
    // Alle er laget fra samme SolidCraft-modell (kulespill.sc, kulespill_B.sc,
    // kulespill_C.sc): lommene står i et rutenett med kolonner på x = −59,5,
    // −9,5 og 40,5 og rader på y = 12,5, −37,5, −87,5 og −137,5. Hullene står
    // i lesefølge på arket: rad for rad ovenfra, venstre mot høyre.
    // Brettene bruker samme bunn og samme ark (160 × 250 mm).
    'layouts' => [
        'A' => [
            'pins'  => 8,
            'holes' => [
                [-9.5, 12.5],
                [-59.5, -37.5], [40.5, -37.5],
                [-59.5, -87.5], [-9.5, -87.5], [40.5, -87.5],
                [-59.5, -137.5], [-9.5, -137.5], [40.5, -137.5],
            ],
            'file'  => ['path' => 'assets/kulespill/kulespill.stl', 'filename' => 'kulespill-a.stl'],
        ],
        'B' => [
            'pins'  => 12,
            'holes' => [
                [-9.5, 12.5],
                [-59.5, -37.5], [40.5, -37.5],
                [-9.5, -87.5],
                [-59.5, -137.5], [40.5, -137.5],
            ],
            'file'  => ['path' => 'assets/kulespill/kulespill-b.stl', 'filename' => 'kulespill-b.stl'],
        ],
        'C' => [
            'pins'  => 6,
            'holes' => [
                [-59.5, -37.5], [-9.5, -37.5], [40.5, -37.5],
                [-59.5, -87.5], [40.5, -87.5],
                [-59.5, -137.5], [-9.5, -137.5], [40.5, -137.5],
            ],
            'file'  => ['path' => 'assets/kulespill/kulespill-c.stl', 'filename' => 'kulespill-c.stl'],
        ],
    ],
    // Innholdet holdes innenfor denne diameteren, så det syns gjennom kula.
    'content_diameter' => 17.0,

    'files' => [
        'board' => ['label' => 'Kulespill', 'size' => '160 × 250 × 18 mm'],
        'base'  => ['path' => 'assets/kulespill/kulespill-bunn.stl', 'filename' => 'kulespill-bunn.stl',
            'label' => 'Bunn med arklomme', 'size' => '166 × 256 × 5 mm'],
    ],

    'contents' => [
        'numbers'     => 'Tall',
        'expressions' => 'Regnestykker',
        'letters'     => 'Bokstaver',
        'symbols'     => 'Symboler',
    ],

    // Bokstavene og symbolene i hullene, i lesefølge.
    'letters' => ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I'],
    'symbols' => [
        ['key' => 'star',     'name' => 'stjerne',  'color' => '#f2a516'],
        ['key' => 'circle',   'name' => 'sirkel',   'color' => '#2e6db4'],
        ['key' => 'triangle', 'name' => 'trekant',  'color' => '#2f9e5b'],
        ['key' => 'square',   'name' => 'kvadrat',  'color' => '#d43d3d'],
        ['key' => 'heart',    'name' => 'hjerte',   'color' => '#d6457f'],
        ['key' => 'diamond',  'name' => 'rombe',    'color' => '#7b4fc4'],
        ['key' => 'hexagon',  'name' => 'sekskant', 'color' => '#1c8c9a'],
        ['key' => 'cross',    'name' => 'kryss',    'color' => '#f36d21'],
        ['key' => 'moon',     'name' => 'måne',     'color' => '#5d5a55'],
    ],

    'operations' => ['+' => 'Addisjon', '-' => 'Subtraksjon', '*' => 'Multiplikasjon', '/' => 'Divisjon'],
    'expression_max' => [10 => 'Svar opptil 10', 20 => 'Svar opptil 20', 50 => 'Svar opptil 50', 100 => 'Svar opptil 100'],

    // Tall og valg. Grensene gjelder også i API-et.
    'parameters' => [
        'num_min'     => ['label' => 'Fra',  'min' => 0, 'max' => 999, 'default' => 1],
        'num_max'     => ['label' => 'Til',  'min' => 0, 'max' => 999, 'default' => 10],
        'value_min'   => ['label' => 'Fra',  'min' => 0, 'max' => 99, 'default' => 1],
        'value_max'   => ['label' => 'Til',  'min' => 0, 'max' => 99, 'default' => 10],
        'balls'       => ['label' => 'Kuler per runde', 'min' => 2, 'max' => 8, 'default' => 5],
        'calc_count'  => ['label' => 'Antall stykker', 'min' => 1, 'max' => 12, 'default' => 6],
        'calc_terms'  => ['label' => 'Ledd per stykke', 'min' => 2, 'max' => 5, 'default' => 3],
        'find_count'  => ['label' => 'Antall oppgaver', 'min' => 1, 'max' => 8, 'default' => 4],
        'find_size'   => ['label' => 'Hull per svar', 'min' => 2, 'max' => 3, 'default' => 2],
        'variants'    => ['label' => 'Antall sett', 'min' => 1, 'max' => 10, 'default' => 1],
    ],

    'defaults' => [
        'layout'         => 'A',
        'content'        => 'numbers',
        'unique'         => true,
        'ops'            => ['+', '-'],
        'expr_max'       => 20,
        'task_round'     => true,
        'task_calc'      => true,
        'calc_ops'       => ['+', '-'],
        'no_negative'    => true,
        'task_find'      => true,
        'answer_key'     => true,
        'seed'           => 1,
    ],
];
