<?php
/**
 * Sidene i M+ 3D og menyen.
 *
 * Menyen fungerer som i M+-appen: sidene vises gruppert under overskrifter
 * (sortert på tittel), og kan filtreres på tema. Et klikk viser tittel,
 * forhåndsbilde og beskrivelse. Dobbeltklikk, Enter eller klikk på bildet
 * åpner siden. Hver side hører til én modell i app/config/models.php.
 */

declare(strict_types=1);

return [
    'groups' => [
        'konkreter' => 'Matematikk – konkreter',
    ],

    'themes' => [
        'tall'          => 'Tall og brøk',
        'sannsynlighet' => 'Sannsynlighet og spill',
        'plassverdi'    => 'Plassverdi og regning',
        'geometri'      => 'Geometri og måling',
    ],

    'pages' => [
        'terning' => [
            'model'       => 'dice',
            'title'       => 'Terning',
            'h1'          => 'Lag din egen terning',
            'group'       => 'konkreter',
            'themes'      => ['sannsynlighet', 'tall'],
            'description' => 'Seksidet terning med nedsenkede prikker. Velg størrelse, hjørneradius og prikker, '
                . 'og last ned STL-fila til 3D-printeren. PDF-en har etiketter med prikker eller tall i nøyaktig størrelse.',
            'preview'     => 'assets/previews/terning.jpg',
            'template'    => 'dice.php',
            'script'      => 'js/pages/dice.js',
        ],
        'broeksirkler' => [
            'model'       => 'fractions',
            'title'       => 'Brøksirkler',
            'h1'          => 'Brøksirkler',
            'group'       => 'konkreter',
            'themes'      => ['tall'],
            'description' => 'Brøkbrikker som legges sammen til hele sirkler. Velg nevnere fra 1 til 12, diameter, '
                . 'tykkelse og klaring. Alle sett har samme diameter, så brøkene kan sammenlignes. '
                . 'PDF-en har oppgaver tilpasset settet og en arbeidsflate i full størrelse.',
            'preview'     => 'assets/previews/broeksirkler.jpg',
            'template'    => 'fractions.php',
            'script'      => 'js/pages/fractions.js',
        ],
        'base10' => [
            'model'       => 'base10',
            'title'       => 'Base 10',
            'h1'          => 'Base 10 – plassverdimateriell',
            'group'       => 'konkreter',
            'themes'      => ['tall', 'plassverdi'],
            'description' => 'Enere, tiere, hundrere og tusenere med rutenett, bygd av samme enhet. Velg enhetslengde, '
                . 'objekttyper og antall. Tallbyggeren viser tall fra 0 til 9999 med klosser. '
                . 'PDF-en har oppgaver om å bygge tall, veksling og regning, tilpasset klossene i settet.',
            'preview'     => 'assets/previews/base10.jpg',
            'template'    => 'base10.php',
            'script'      => 'js/pages/base10.js',
        ],
        'tallstaver' => [
            'model'       => 'rods',
            'title'       => 'Tallstaver',
            'h1'          => 'Tallstaver – Cuisenaire-staver',
            'group'       => 'konkreter',
            'themes'      => ['tall', 'plassverdi'],
            'description' => 'Staver med lengde 1 til 10 enheter i klassiske Cuisenaire-farger, med eller uten tall og enhetsinndeling. '
                . 'Velg lengder og antall, og utforsk tallkombinasjoner i 3D. PDF-en har lærerveiledning, oppgaver om tall, '
                . 'regning, partall, forhold og brøk tilpasset stavene i settet, og et arbeidsark i full størrelse.',
            'preview'     => 'assets/previews/tallstaver.jpg',
            'template'    => 'rods.php',
            'script'      => 'js/pages/rods.js',
        ],
        'romfigurer' => [
            'model'       => 'solids',
            'title'       => 'Romfigurer',
            'h1'          => 'Romfigurer og utbrettingsmodeller',
            'group'       => 'konkreter',
            'themes'      => ['geometri'],
            'description' => 'Kube, rektangulært prisme, pyramide, trekantet prisme, sylinder og kjegle. Lag en 3D-modell og en '
                . 'utbrettingsfigur på papir med nøyaktig de samme målene. Se flater, kanter og hjørner, areal og volum. '
                . 'PDF-ene har utbrettingsfiguren i målestokk 1:1 med limfliker, og oppgaver på tre nivåer.',
            'preview'     => 'assets/previews/romfigurer.jpg',
            'template'    => 'solids.php',
            'script'      => 'js/pages/solids.js',
        ],
    ],
];
