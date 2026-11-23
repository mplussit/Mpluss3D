<?php
/**
 * Sidene i M+ 3D og menyen.
 *
 * Menyen fungerer som i M+-appen: sidene vises gruppert under overskrifter
 * (sortert på tittel), og kan filtreres på tema. Et klikk viser tittel,
 * forhåndsbilde og beskrivelse. Dobbeltklikk, Enter eller klikk på bildet
 * åpner siden. Hver side hører til én modell i app/config/models.php.
 *
 * En side med 'draft' => true er ikke ferdig. Den vises bare når miljøvariabelen
 * M3D_SHOW_DRAFTS er 1 (testmiljøet, se docker/deploy.sh), ellers verken i menyen,
 * på hjelpesiden eller som side (404). Bruk published_pages() for å få sidene.
 */

declare(strict_types=1);

if (!function_exists('published_pages')) {
    /** Sidene som skal vises i dette miljøet (uten utkast i produksjon). */
    function published_pages(array $site): array
    {
        if (getenv('M3D_SHOW_DRAFTS') === '1') {
            return $site;
        }
        $site['pages'] = array_filter($site['pages'], static fn(array $p): bool => empty($p['draft']));
        return $site;
    }
}

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
                . 'og legg terningen på byggeplaten til 3D-printeren (3MF). PDF-en har etiketter med prikker eller tall i nøyaktig størrelse.',
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
        'regnmedlego' => [
            'model'       => 'lego',
            'title'       => 'Regn med Lego',
            'h1'          => 'Regn med Lego',
            'group'       => 'konkreter',
            'themes'      => ['tall', 'plassverdi'],
            'description' => 'Rammer som elevene fyller med LEGO®-klosser. Antallet knottplasser i en full ramme er en mengde, '
                . 'og flere rammer med regnetegn mellom blir et regnestykke, for eksempel 8 + 4 = 12. Tegn formen i et rutenett, '
                . 'velg vegg, bunn og klaring, og merk rammen med tall eller papiretikett. PDF-en har etiketter i faktisk størrelse.',
            'preview'     => 'assets/previews/regnmedlego.jpg',
            'template'    => 'lego.php',
            'script'      => 'js/pages/lego.js',
        ],
        'kulespill' => [
            // Ikke ferdig: vises bare der M3D_SHOW_DRAFTS=1 (testmiljøet), ikke i produksjon.
            'draft'       => true,
            'model'       => 'marble',
            'title'       => 'Kulespill',
            'h1'          => 'Kulespill med ark',
            'group'       => 'konkreter',
            'themes'      => ['tall', 'plassverdi', 'sannsynlighet'],
            'description' => 'Tre kulespill, A, B og C, med ulike lommer og pinner, og en bunn. Mellom bunnen og spillet ligger et ark '
                . 'som syns gjennom hullene i lommene. Velg tall, regnestykker, bokstaver eller symboler, og hvilke oppgaver elevene skal få. '
                . '«Lag stykker» gir nye verdier, og PDF-en har hullark, verdiark, oppgaver og fasit.',
            'preview'     => 'assets/previews/kulespill.jpg',
            'template'    => 'marble.php',
            'script'      => 'js/pages/marble.js',
        ],
    ],
];
