<?php
/**
 * Modelldefinisjon: «Regn med Lego» – rammer som fylles med LEGO®-klosser.
 *
 * Eneste kilde til mål, grenser og standardverdier. API, brukergrensesnitt
 * (action=schema&model=lego), STL og PDF bruker denne fila. Geometrien
 * regnes ut i LegoModel og bygges i models/lego/frame.scad.
 *
 * Alle mål i mm. Én knottplass er 8 × 8 mm (LEGO-modulen).
 */

declare(strict_types=1);

return [
    'id'       => 'lego_frames',
    'version'  => 1,
    'title'    => 'Regn med Lego',
    'scad'     => 'lego/frame.scad',
    'filename' => 'lego-ramme',          // lego-ramme-01-4x4-16.stl
    'archive'  => 'regn-med-lego',       // regn-med-lego-3-rammer.zip / .pdf

    'module'       => 8.0,               // mm per knottplass (fast)
    'inner_height' => 9.6,               // fra klossenes støtteflate til overkant ramme (fast)

    'grid' => [
        'max'          => 20,            // høyst 20 × 20 ruter (160 × 160 mm innvendig)
        'default_cols' => 4,
        'default_rows' => 4,
    ],
    'max_frames' => 12,

    // Tall per ramme (læreren skriver dem i mm; desimalkomma godtas).
    'parameters' => [
        'wall'             => ['label' => 'Veggtykkelse',   'unit' => 'mm', 'min' => 1.2, 'max' => 3,   'default' => 2,   'step' => 0.1,
                               'help' => 'Bygges utover. Plassen til klossene endres ikke.'],
        'bottom_thickness' => ['label' => 'Bunntykkelse',   'unit' => 'mm', 'min' => 0.8, 'max' => 4,   'default' => 1.5, 'step' => 0.1,
                               'help' => 'Innvendig høyde er alltid 9,6 mm over bunnen.'],
        'clearance'        => ['label' => 'Ekstra klaring', 'unit' => 'mm', 'min' => 0,   'max' => 0.5, 'default' => 0.2, 'step' => 0.05,
                               'help' => 'Per side, langs alle kanter mot klossene. Juster etter prøveutskrift.'],
        'field_depth'      => ['label' => 'Feltdybde',      'unit' => 'mm', 'min' => 8,   'max' => 25,  'default' => 12,  'step' => 0.5,
                               'help' => 'Hvor langt merkefeltet stikker ut fra rammen.'],
        'text_depth'       => ['label' => 'Tekstdybde',     'unit' => 'mm', 'min' => 0.4, 'max' => 1.2, 'default' => 0.6, 'step' => 0.1,
                               'help' => 'Hvor dypt teksten er senket ned (eller hvor høy den er når den er opphøyd).'],
    ],

    'switches' => [
        'bottom' => ['label' => 'Bunn', 'default' => true],
    ],

    'choices' => [
        'field'      => ['label' => 'Merkefelt', 'default' => 'text', 'options' => [
            'none'  => 'Ingen',
            'text'  => 'Tall i rammen',
            'label' => 'Papiretikett',
        ]],
        'field_side' => ['label' => 'Side', 'default' => 'front', 'options' => [
            'front' => 'Foran',
            'back'  => 'Bak',
            'left'  => 'Venstre',
            'right' => 'Høyre',
        ]],
        'text_mode'  => ['label' => 'Innhold', 'default' => 'auto', 'options' => [
            'auto'   => 'Mengden',
            'custom' => 'Egen tekst',
            'blank'  => 'Tom (håndskrift)',
        ]],
        'text_style' => ['label' => 'Tekst', 'default' => 'engraved', 'options' => [
            'engraved' => 'Innfelt',
            'raised'   => 'Opphøyd',
        ]],
        'operator'   => ['label' => 'Regnetegn etter rammen', 'default' => '', 'options' => [
            ''  => 'Ingen',
            '+' => '+',
            '−' => '−',
            '×' => '×',
            '÷' => '÷',
            '=' => '=',
        ]],
    ],

    'text' => [
        'max_length' => 12,
        'margin'     => 1.5,      // mm fra feltkanten til teksten
        'min_size'   => 3.5,      // mindre tekst blir utydelig med 0,4 mm dyse
        'max_size'   => 9,
        'floor'      => 1.5,      // minst så mye materiale under innfelt tekst
    ],

    'labels' => [
        'margin'     => 1.0,      // etiketten er 1 mm mindre enn feltet på hver side
        'copies_max' => 50,
        'card'       => 30,       // regnetegn-kort: 30 × 30 mm
    ],

    // Omtrentlig materialforbruk (PLA)
    'material' => [
        'density' => 1.24,
        'shell'   => 0.85,
        'infill'  => 0.15,
    ],

    'scad_constants' => [
        'text_segments' => 16,
    ],
];
