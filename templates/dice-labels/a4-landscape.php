<?php
/**
 * Mal for etikettark: A4 liggende (som de andre PDF-ene i M+ 3D).
 *
 * Tekster og layoutkonstanter er samlet her, slik at innhold kan endres
 * (eller oversettes) uten å endre PdfGenerator. Alle mål i millimeter.
 * {plassholdere} fylles inn av PdfGenerator.
 */
declare(strict_types=1);

return [
    'page'   => ['format' => 'A4', 'orientation' => 'L', 'width' => 297, 'height' => 210],
    'margin' => 15,

    'face_names' => [
        'top'    => 'TOPP',
        'bottom' => 'BUNN',
        'front'  => 'FRONT',
        'back'   => 'BAK',
        'left'   => 'VENSTRE',
        'right'  => 'HØYRE',
    ],
    // Hvilken side «OPP»-kanten på etiketten skal vende mot.
    'up_towards' => [
        'top'    => 'back',
        'bottom' => 'front',
        'front'  => 'top',
        'back'   => 'top',
        'left'   => 'top',
        'right'  => 'top',
    ],

    'text' => [
        'title'          => 'Dekor til terning {dims}',
        'print_warning'  => 'Skriv ut med «Faktisk størrelse» / 100 %. Velg IKKE «Tilpass til side».',
        'labels_heading' => 'Etiketter – klipp langs den heltrukne linjen',
        'caption_up'     => 'OPP-kanten mot {towards}',
        'assembly_heading' => 'Montering',
        'steps_pips' => [
            'Kontroller målestokken nederst på arket. Linjen skal være nøyaktig 100 mm.',
            'Hold terningen slik at siden med 1 prikk vender opp og siden med 2 prikker vender mot deg. Da har høyre side 3 prikker.',
            'Klipp ut én etikett om gangen og lim den på siden med samme navn, slik figuren viser.',
            'Kanten merket OPP skal vende mot toppen. For TOPP vender den mot BAK, og for BUNN mot FRONT.',
            'Etikettene er litt mindre enn sidene, så de ikke dekker de avrundede kantene.',
        ],
        'steps_blank_die' => [
            'Kontroller målestokken nederst på arket. Linjen skal være nøyaktig 100 mm.',
            'Terningen har blanke sider. Bestem hvilken side som er TOPP, og hvilken som er FRONT.',
            'Klipp ut én etikett om gangen og lim den på siden med samme navn, slik figuren viser.',
            'Kanten merket OPP skal vende mot toppen. For TOPP vender den mot BAK, og for BUNN mot FRONT.',
            'Etikettene er litt mindre enn sidene, så de ikke dekker de avrundede kantene.',
        ],
        'step_blank_labels' => 'Tomme etiketter: Tegn eller skriv på etikettene før du klipper dem ut.',
        'net_caption'    => 'Utbrettet terning',
        'iso_caption'    => 'Slik holder du terningen',
        'ruler_caption'  => 'Kontrollmål: 100 mm',
        'ruler_help'     => 'Er linjen kortere eller lengre, ble arket skalert ved utskrift. Skriv ut på nytt med 100 %.',
        'footer'         => 'M+ 3D · {model} v{version} · terning {dims} · hjørneradius {radius} mm · etikett {label} mm · {date}',
    ],
];
