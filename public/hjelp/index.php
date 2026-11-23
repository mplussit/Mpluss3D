<?php
/**
 * M+ 3D – hjelpesiden (Hjelp-knappen øverst til høyre lenker hit, se help_url i
 * app/config/config.php). Modellsidene legger til #side, f.eks. #broeksirkler,
 * så siden åpnes ved avsnittet om modellen.
 *
 * Skjermbildene i bilder/ lages med bin/help-screenshots.mjs. Nummermerkene på
 * bildene plasseres fra bilder/merker.json, som skriptet skriver samtidig.
 */
declare(strict_types=1);
header('X-Content-Type-Options: nosniff');
$v = '1';
?>
<!doctype html>
<html lang="nb">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>M+ 3D – hjelp</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,500..800&family=Figtree:wght@400..700&display=swap">
    <link rel="stylesheet" href="hjelp.css?v=<?= $v ?>">
</head>
<body>
<header class="help-top">
    <a class="help-brand" href="../"><img src="../assets/mplus-logo.svg" alt="M+" width="43" height="32"> <span>M+ <b>3D</b></span></a>
    <span class="help-top-title">Hjelp</span>
    <a class="back-btn" href="../" id="back">← Tilbake til M+ 3D</a>
</header>

<div class="help-wrap">
<nav class="toc" aria-label="Innhold">
    <p class="toc-head">Innhold</p>
    <ol>
        <li><a href="#kort">Kort fortalt</a></li>
        <li><a href="#siden">Slik er siden bygd opp</a></li>
        <li><a href="#steg">Steg for steg</a></li>
        <li><a href="#3mf">3MF: til 3D-printeren</a></li>
        <li><a href="#slicer">Fra 3MF-fil til printet figur</a></li>
        <li><a href="#pdf">PDF: ark til skriveren</a></li>
        <li><a href="#utskrift">Skriv ut i riktig størrelse</a></li>
        <li><a href="#modeller">Om hver modell</a></li>
        <li><a href="#ord">Ord og uttrykk</a></li>
        <li><a href="#sporsmal">Vanlige spørsmål</a></li>
    </ol>
</nav>

<main class="help-main">

<h1>Slik bruker du M+ 3D</h1>
<p class="lead">M+ 3D lager konkreter som du skriver ut på en 3D-printer, og ark med oppgaver som du skriver ut på papir.
    Du trenger ikke kunne noe om 3D-tegning. Du velger en modell, stiller inn det du vil, og trykker på en knapp.</p>

<!-- ------------------------------------------------------------------ Kort fortalt -->
<section id="kort">
    <h2>Kort fortalt: to slags filer</h2>
    <div class="cards">
        <div class="card card-3mf">
            <p class="card-icon" aria-hidden="true"><svg viewBox="0 0 16 16"><path d="M8 1.5 14 4.75v6.5L8 14.5 2 11.25v-6.5Z M2 4.75 8 8l6-3.25 M8 8v6.5" fill="none" stroke="currentColor" stroke-width="1.2" stroke-linejoin="round"/></svg></p>
            <h3>3MF – til 3D-printeren</h3>
            <p>En 3MF-fil er en <strong>byggeplate</strong> med figurene ferdig plassert. Du åpner den i programmet som hører til
                3D-printeren, og printer.</p>
            <p class="card-btn">Knappen <span class="kbd">3MF</span> øverst på siden</p>
        </div>
        <div class="card card-pdf">
            <p class="card-icon" aria-hidden="true"><svg viewBox="0 0 20 14"><rect x="0.75" y="0.75" width="18.5" height="12.5" rx="1.5" fill="none" stroke="currentColor" stroke-width="1.1"/><text x="10" y="9.6" text-anchor="middle" font-family="Arial, Helvetica, sans-serif" font-size="6.6" font-weight="700" fill="currentColor">PDF</text></svg></p>
            <h3>PDF – til vanlig skriver</h3>
            <p>PDF-en har arkene som hører til: oppgaver, fasit, etiketter, utbrettingsfigurer og arbeidsark. Du skriver dem ut
                på papir, slik du skriver ut andre dokumenter.</p>
            <p class="card-btn">Knappen <span class="kbd">PDF</span> øverst på siden</p>
        </div>
    </div>

    <figure class="flow" aria-label="Fra M+ 3D til ferdig konkret og ark">
        <svg viewBox="0 0 760 210" role="img" aria-labelledby="flow-title">
            <title id="flow-title">M+ 3D lager en 3MF-fil som åpnes i sliceren og printes på 3D-printeren, og en PDF som skrives ut på vanlig skriver.</title>
            <defs><marker id="arr" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto"><path d="M0 0 10 5 0 10z" fill="#5d5a55"/></marker></defs>
            <g font-family="Figtree, system-ui, sans-serif" font-size="15" text-anchor="middle">
                <rect x="10" y="70" width="150" height="70" rx="6" fill="#fffdf9" stroke="#161616" stroke-width="1.5"/>
                <text x="85" y="101" font-weight="700">M+ 3D</text><text x="85" y="122" fill="#5d5a55" font-size="13">velg og still inn</text>

                <path d="M160 92 C200 92 200 45 238 45" fill="none" stroke="#5d5a55" stroke-width="1.6" marker-end="url(#arr)"/>
                <path d="M160 118 C200 118 200 165 238 165" fill="none" stroke="#5d5a55" stroke-width="1.6" marker-end="url(#arr)"/>

                <rect x="240" y="15" width="130" height="60" rx="6" fill="#fde3d2" stroke="#f36d21" stroke-width="1.5"/>
                <text x="305" y="42" font-weight="700">3MF-fil</text><text x="305" y="61" fill="#5d5a55" font-size="13">byggeplaten</text>
                <path d="M370 45 H418" stroke="#5d5a55" stroke-width="1.6" marker-end="url(#arr)"/>
                <rect x="420" y="15" width="150" height="60" rx="6" fill="#fffdf9" stroke="#161616" stroke-width="1.5"/>
                <text x="495" y="42" font-weight="700">Slicer</text><text x="495" y="61" fill="#5d5a55" font-size="13">programmet til printeren</text>
                <path d="M570 45 H618" stroke="#5d5a55" stroke-width="1.6" marker-end="url(#arr)"/>
                <rect x="620" y="15" width="130" height="60" rx="6" fill="#fffdf9" stroke="#161616" stroke-width="1.5"/>
                <text x="685" y="42" font-weight="700">3D-printer</text><text x="685" y="61" fill="#5d5a55" font-size="13">konkretene</text>

                <rect x="240" y="135" width="130" height="60" rx="6" fill="#e7eef8" stroke="#2e6db4" stroke-width="1.5"/>
                <text x="305" y="162" font-weight="700">PDF-fil</text><text x="305" y="181" fill="#5d5a55" font-size="13">arkene</text>
                <path d="M370 165 H618" stroke="#5d5a55" stroke-width="1.6" marker-end="url(#arr)"/>
                <rect x="620" y="135" width="130" height="60" rx="6" fill="#fffdf9" stroke="#161616" stroke-width="1.5"/>
                <text x="685" y="162" font-weight="700">Skriver</text><text x="685" y="181" fill="#5d5a55" font-size="13">ark på papir</text>
            </g>
        </svg>
    </figure>
</section>

<!-- ------------------------------------------------------------------ Siden -->
<section id="siden">
    <h2>Slik er siden bygd opp</h2>
    <p>Alle modellene i M+ 3D ser like ut. Her er brøksirklene:</p>
    <figure class="shot" data-shot="side">
        <img src="bilder/side.webp" width="1280" height="900" alt="En modellside i M+ 3D: Meny øverst til venstre, faner under, 3D-visning, innstillinger til høyre og knappene 3MF, PDF og Hjelp øverst til høyre.">
        <span class="pin" data-key="meny">1</span>
        <span class="pin" data-key="faner" data-pos="left">2</span>
        <span class="pin" data-key="panel" data-pos="left" data-outline>3</span>
        <span class="pin" data-key="mf">4</span>
        <span class="pin" data-key="pdf">5</span>
        <span class="pin" data-key="hjelp">6</span>
    </figure>
    <ol class="legend">
        <li><strong>Meny</strong> – tilbake til lista over modellene.</li>
        <li><strong>Faner</strong> – fanene med terning-ikon viser figurene i 3D. Fanene med ark-ikon viser arkene i PDF-en.</li>
        <li><strong>Innstillinger</strong> – her bestemmer du størrelse, antall og innhold. Alt du endrer, vises med en gang.</li>
        <li><strong>3MF</strong> – bygg en byggeplate og lagre den som fil til 3D-printeren.</li>
        <li><strong>PDF</strong> – lag en PDF med arkene du vil skrive ut.</li>
        <li><strong>Hjelp</strong> – denne siden.</li>
    </ol>
    <p class="tip"><strong>Tips:</strong> I 3D-visningen kan du dra med musa for å snu figuren, og rulle for å zoome. Knappen
        «Standard» setter visningen tilbake.</p>
</section>

<!-- ------------------------------------------------------------------ Steg for steg -->
<section id="steg">
    <h2>Steg for steg</h2>
    <ol class="steps">
        <li>
            <h3>Velg en modell</h3>
            <p>Klikk på en modell i menyen for å se bilde og beskrivelse. Dobbeltklikk, eller klikk på bildet, for å åpne den.</p>
            <figure class="shot small"><img src="bilder/meny.webp" width="1280" height="900" loading="lazy" alt="Menyen i M+ 3D med lista over modellene til venstre og bilde og beskrivelse til høyre."></figure>
        </li>
        <li>
            <h3>Still inn</h3>
            <p>Bruk innstillingene til høyre. Du kan prøve deg fram: ingenting lagres eller sendes noe sted før du selv trykker
                på 3MF eller PDF. Lista «Dette skal skrives ut» viser hvor mange av hver brikke settet trenger.</p>
        </li>
        <li>
            <h3>Se på arkene</h3>
            <p>Klikk på fanene med ark-ikon for å se arkene. Står det <span class="kbd">Oppgave</span>
                <span class="kbd">Fasit</span> over arket, kan du også se fasiten. Det du ser, er nøyaktig det som kommer i PDF-en.</p>
            <figure class="shot" data-shot="ark">
                <img src="bilder/ark.webp" width="1280" height="900" loading="lazy" alt="Et oppgaveark vises i forhåndsvisningen, med knappene Oppgave og Fasit over arket.">
                <span class="pin" data-key="faner">1</span>
                <span class="pin" data-key="variant" data-pos="right">2</span>
            </figure>
            <ol class="legend">
                <li>Fanen for arket.</li>
                <li>Bytt mellom oppgaven og fasiten.</li>
            </ol>
        </li>
        <li>
            <h3>Lag filene</h3>
            <p>Trykk på <span class="kbd">3MF</span> for å lage filen til 3D-printeren (<a href="#3mf">se under</a>), og på
                <span class="kbd">PDF</span> for å lage arkene (<a href="#pdf">se under</a>). Filene havner i mappen
                <strong>Nedlastinger</strong> på datamaskinen.</p>
        </li>
    </ol>
</section>

<!-- ------------------------------------------------------------------ 3MF -->
<section id="3mf">
    <h2>3MF: til 3D-printeren</h2>
    <p>I en 3D-printer lages figurene på en flat plate, <strong>byggeplaten</strong>. Med 3MF-knappen legger du figurene på en
        byggeplate som har samme størrelse som platen i din printer. M+ 3D plasserer dem for deg, med litt avstand mellom.</p>

    <h3>1. Velg printer og legg til figurer</h3>
    <figure class="shot" data-shot="3mf-tom">
        <img src="bilder/3mf-tom.webp" width="940" height="940" loading="lazy" alt="3MF-dialogen med tom byggeplate til venstre, og valg av printer, avstand og lista over figurene som kan legges til.">
        <span class="pin" data-key="printer" data-pos="left">1</span>
        <span class="pin" data-key="avstand" data-pos="left">2</span>
        <span class="pin" data-key="leggtil" data-pos="left">3</span>
        <span class="pin" data-key="plate" data-pos="left">4</span>
    </figure>
    <ol class="legend">
        <li><strong>Byggeplate</strong> – velg printeren dere har. Finner du den ikke, velger du <em>Egendefinert størrelse</em>
            nederst i lista og skriver inn bredde, dybde og høyde. Målene står i bruksanvisningen til printeren.
            M+ 3D husker valget til neste gang.</li>
        <li><strong>Avstand</strong> – hvor langt det skal være mellom figurene. 5 mm passer nesten alltid.</li>
        <li><strong>Legg til fra denne siden</strong> – figurene på siden du står på. Antallet er det settet trenger, men du kan
            endre det. Trykk <span class="kbd">Legg til</span>, eller <span class="kbd">Legg til alle</span>.</li>
        <li><strong>Byggeplaten sett ovenfra</strong> – rutene er 1 cm. Her ser du hvordan figurene blir liggende.</li>
    </ol>

    <h3>2. Juster og lagre</h3>
    <figure class="shot" data-shot="3mf-plate">
        <img src="bilder/3mf-plate.webp" width="940" height="940" loading="lazy" alt="3MF-dialogen med ni brøkbrikker plassert på byggeplaten, og lista over figurene med knapper for antall, drei og fjern.">
        <span class="pin" data-key="plate" data-pos="left">1</span>
        <span class="pin" data-key="antall">2</span>
        <span class="pin" data-key="drei">3</span>
        <span class="pin" data-key="fjern">4</span>
        <span class="pin" data-key="filnavn" data-pos="left">5</span>
        <span class="pin" data-key="lagre" data-pos="left">6</span>
    </figure>
    <ol class="legend">
        <li>Figurene er plassert automatisk. Hver figurtype har sin farge. Holder du musa over en figur i lista, blir den
            markert på platen.</li>
        <li><span class="kbd">−</span> og <span class="kbd">+</span> endrer hvor mange du vil printe.</li>
        <li><span class="kbd">⟳</span> dreier figuren en kvart omdreining. Det kan gi plass til flere.</li>
        <li><span class="kbd">✕</span> tar figuren bort fra platen.</li>
        <li>Gi gjerne filen et navn du kjenner igjen, for eksempel <em>Brøksirkler 5B</em>.</li>
        <li><span class="kbd">Lagre 3MF</span> lagrer filen i Nedlastinger.</li>
    </ol>

    <h3>Figurer fra flere modeller på samme plate</h3>
    <p>Byggeplaten huskes mens du går mellom modellene. Legg for eksempel til terninger, gå til Brøksirkler og legg til brikker
        der, og lagre alt i én 3MF-fil. Tallet på knappene viser hvor mange figurer som ligger på byggeplaten, og hvor mange
        sider som ligger i oppsamlings-PDF-en.</p>
    <figure class="shot"><img src="bilder/toppfelt.webp" width="1182" height="58" loading="lazy" alt="Knappene øverst: 3MF med tallet 9 og PDF med tallet 5."></figure>
    <p>Byggeplaten blir liggende til du tømmer den (<span class="kbd">Tøm byggeplaten</span>). Den lagres bare i nettleseren på
        denne maskinen.</p>

    <h3>Når figurene ikke får plass</h3>
    <figure class="shot narrow"><img src="bilder/3mf-full.webp" width="488" height="494" loading="lazy" alt="En liten byggeplate der bare 6 av 47 figurer får plass, med en rød melding under."></figure>
    <p>Står det med rødt at noen figurer ikke får plass, kan du</p>
    <ul>
        <li>velge færre figurer, eller dreie noen av dem med <span class="kbd">⟳</span>,</li>
        <li>gjøre avstanden mindre (ikke under 2–3 mm),</li>
        <li>eller dele opp: lagre én fil med det som får plass, ta bort de figurene, og lagre en fil til med resten.</li>
    </ul>
    <p>Figurer som ikke får plass, blir ikke med i filen. M+ 3D spør deg før du lagrer.</p>

    <p class="tip"><strong>Tips om farger:</strong> En printer med én dyse printer hele platen i én farge. Vil du ha brøksirkler eller
        tallstaver i ulike farger, lager du én 3MF-fil per farge.</p>
</section>

<!-- ------------------------------------------------------------------ Slicer -->
<section id="slicer">
    <h2>Fra 3MF-fil til printet figur</h2>
    <p>3D-printeren kan ikke lese 3MF-filen direkte. Filen åpnes først i programmet som hører til printeren, en
        <strong>slicer</strong>. Den deler figurene i tynne lag og lager instruksjonene printeren følger. Vanlige slicere er
        <em>Bambu Studio</em> (Bambu Lab), <em>PrusaSlicer</em> (Prusa), <em>Creality Print</em>, <em>OrcaSlicer</em> og
        <em>UltiMaker Cura</em>. Ofte har skolen allerede satt opp sliceren med printeren og filamentet dere bruker.</p>
    <ol class="steps">
        <li><h3>Åpne filen</h3>
            <p>Start sliceren. Velg <em>Fil → Importer</em> (eller <em>Åpne</em>) og velg 3MF-filen i Nedlastinger. Du kan
                også dra filen inn i vinduet.</p></li>
        <li><h3>Svar på spørsmålet om filen</h3>
            <p>Noen slicere, for eksempel Bambu Studio, kan si at filen ikke er laget i det samme programmet, og at bare
                figurene lastes inn. Det er riktig – trykk <em>OK</em>. Figurene ligger der M+ 3D plasserte dem.</p></li>
        <li><h3>Velg printer og filament</h3>
            <p>Sjekk at riktig printer og filament (for eksempel PLA) er valgt. Standardinnstillingene passer for alle
                modellene i M+ 3D.</p></li>
        <li><h3>Slice og print</h3>
            <p>Trykk <em>Slice</em> (eller <em>Del opp</em>). Da ser du hvor lang tid utskriften tar og hvor mye filament
                som går med. Send jobben til printeren, eller lagre den på minnekortet som printeren leser.</p></li>
    </ol>
    <p class="tip"><strong>Første gang:</strong> Skriv ut noen få brikker først og sjekk at de passer sammen, før du printer
        et helt klassesett. Printere er litt forskjellige. Ofte kan du justere klaringen i M+ 3D hvis brikkene er for trange
        eller for løse.</p>
</section>

<!-- ------------------------------------------------------------------ PDF -->
<section id="pdf">
    <h2>PDF: ark til skriveren</h2>
    <p>Med PDF-knappen velger du hvilke ark du vil ha med. Du kan lage en PDF med én gang, eller samle ark fra flere modeller
        i én PDF.</p>
    <figure class="shot narrow" data-shot="pdf">
        <img src="bilder/pdf.webp" width="560" height="685" loading="lazy" alt="PDF-dialogen med lista over sidene med avkryssing for Oppgave og Fasit, knappene Lag PDF og Legg til i oppsamlings-PDF, og feltene Merk ark med og Filnavn.">
        <span class="pin" data-key="sider" data-pos="left">1</span>
        <span class="pin" data-key="lag" data-pos="left">2</span>
        <span class="pin" data-key="samle">3</span>
        <span class="pin" data-key="oppsamling" data-pos="left">4</span>
        <span class="pin" data-key="merk" data-pos="left">5</span>
        <span class="pin" data-key="filnavn" data-pos="left">6</span>
    </figure>
    <ol class="legend">
        <li><strong>Sidene</strong> – kryss av for arkene du vil ha. Kolonnen <em>Fasit</em> gir fasiten til arket. Fasitene
            kommer til slutt i PDF-en, så de er lette å ta ut.</li>
        <li><span class="kbd">Lag PDF</span> lager en PDF med de valgte arkene med én gang.</li>
        <li><span class="kbd">Legg til i oppsamlings-PDF</span> legger arkene i en samling, slik at du kan gå til en annen
            modell og legge til flere.</li>
        <li><strong>Oppsamlings-PDF</strong> – her ser du hva som er samlet. <span class="kbd">Lag oppsamlings-PDF</span> lager
            én PDF med alt. Etterpå tømmes samlingen.</li>
        <li><strong>Merk ark med</strong> – skriver en tekst langs kanten på hvert ark, for eksempel navnet på klassen.</li>
        <li><strong>Filnavn</strong> – navnet på PDF-filen i Nedlastinger.</li>
    </ol>
</section>

<!-- ------------------------------------------------------------------ Utskrift -->
<section id="utskrift">
    <h2>Skriv ut i riktig størrelse</h2>
    <div class="warn-box">
        <p><strong>Velg «Faktisk størrelse» eller «100 %» når du skriver ut.</strong> Ikke velg «Tilpass til siden».</p>
        <p>Mange ark skal passe nøyaktig til figurene: utbrettingsfigurer, etiketter, arbeidsflater, hullark og
            arbeidsark. Blir arket forminsket, passer ikke figurene lenger.</p>
    </div>
    <p>Ark i nøyaktig størrelse har en <strong>kontrollinje</strong> med lengden skrevet ved siden av (for eksempel 50 mm eller
        100 mm). Mål den med en linjal etter utskrift. Er den kortere, er arket forminsket.</p>
    <p>Arkene er liggende A4. Skriveren snur dem selv.</p>
</section>

<!-- ------------------------------------------------------------------ Modellene -->
<section id="modeller">
    <h2>Om hver modell</h2>

    <article id="terning" class="model">
        <h3>Terning</h3>
        <p>En seksidet terning med prikker. Velg størrelse og runde hjørner. PDF-en har etiketter med prikker eller tall som
            kan limes på – fint når elevene skal lage egne terninger med andre tall.</p>
    </article>

    <article id="broeksirkler" class="model">
        <h3>Brøksirkler</h3>
        <p>Brøkbrikker som legges sammen til hele sirkler. Velg hvilke nevnere dere trenger. Alle sett har samme størrelse, så
            brikkene kan sammenlignes. PDF-en har oppgaver tilpasset settet og en arbeidsflate med sirklene i full størrelse.</p>
        <ul><li>Print gjerne hver brøk i sin farge: lag én 3MF-fil per farge.</li>
            <li>Brikkene passer på arbeidsflaten bare når den skrives ut i 100 %.</li></ul>
    </article>

    <article id="base10" class="model">
        <h3>Base 10</h3>
        <p>Enere, tiere, hundrere og tusenere til plassverdi. Lista «Dette skal skrives ut» viser hvor mange av hver kloss settet
            trenger. «Tallbygger»-fanen viser tall med klosser. PDF-en har oppgaver om å bygge tall, veksling og regning.</p>
        <ul><li>Tusenerklossen kan lages hul. Det sparer mye filament.</li></ul>
    </article>

    <article id="tallstaver" class="model">
        <h3>Tallstaver</h3>
        <p>Staver med lengde 1 til 10 i klassiske farger (Cuisenaire). Velg lengder og antall. PDF-en har lærerveiledning,
            oppgaver og et arbeidsark i full størrelse.</p>
        <ul><li>Fargene vises bare på skjermen og i PDF-en. Vil du ha fargede staver, lager du én 3MF-fil per farge.</li></ul>
    </article>

    <article id="romfigurer" class="model">
        <h3>Romfigurer</h3>
        <p>Kube, prisme, pyramide, sylinder og kjegle – både som 3D-figur og som utbrettingsfigur på papir med nøyaktig de samme
            målene. PDF-en har utbrettingsfiguren med limfliker og oppgaver på tre nivåer.</p>
        <ul><li>Skriv ut utbrettingsfiguren i 100 % på tykt papir (160–200 g).</li>
            <li>Er figuren for stor for ett ark, kan du velge å dele den på flere ark eller forminske den.</li></ul>
    </article>

    <article id="regnmedlego" class="model">
        <h3>Regn med Lego</h3>
        <p>Rammer som elevene fyller med LEGO-klosser. Antallet plasser i rammen er et tall, og flere rammer med regnetegn mellom
            blir et regnestykke. Tegn formen i rutenettet og velg tall eller papiretikett på rammen.</p>
        <p>Her kan du legge rammen på byggeplaten rett fra siden. Endrer du målene, kan du legge den nye rammen på byggeplaten
            også – hver versjon kommer som en egen figur med mål i navnet.</p>
        <figure class="shot tiny"><img src="bilder/lego-byggeplate.webp" loading="lazy" alt="Knappene «Legg valgt ramme på byggeplaten» og «Legg alle rammene på byggeplaten», med en melding om at rammen er lagt på byggeplaten."></figure>
        <ul><li>Print én liten ramme først og prøv med ekte klosser. Er det for trangt, øker du klaringen.</li></ul>
    </article>

    <article id="kulespill" class="model">
        <h3>Kulespill</h3>
        <p>Tre kulespill, A, B og C, og en bunn. Et ark legges mellom bunnen og spillet og syns gjennom hullene. Velg tall,
            regnestykker, bokstaver eller symboler. «Lag stykker» gir nye verdier. PDF-en har hullark, verdiark, oppgaver og fasit.</p>
        <ul><li>Spillet (160 × 250 mm) og bunnen (166 × 256 mm) er så store at de må printes hver for seg, og printeren må ha
                en byggeplate på minst 256 mm. Lag én 3MF-fil for spillet og én for bunnen.</li>
            <li>Hullarket skal skrives ut i 100 %, ellers står ikke tallene midt i hullene.</li></ul>
    </article>
</section>

<!-- ------------------------------------------------------------------ Ord -->
<section id="ord">
    <h2>Ord og uttrykk</h2>
    <dl class="words">
        <div><dt>3D-printer</dt><dd>Maskin som bygger en figur lag for lag av smeltet plast.</dd></div>
        <div><dt>Byggeplate</dt><dd>Den flate platen i printeren som figurene printes på.</dd></div>
        <div><dt>3MF</dt><dd>Filtype for 3D-utskrift. Inneholder figurene og hvor de ligger på byggeplaten.</dd></div>
        <div><dt>STL</dt><dd>En eldre filtype med én figur. M+ 3D bruker STL inni 3MF-filen.</dd></div>
        <div><dt>Slicer</dt><dd>Programmet som gjør 3MF-filen om til instruksjoner for printeren.</dd></div>
        <div><dt>Filament / PLA</dt><dd>Plasttråden printeren bruker. PLA er den vanligste, og passer til alt i M+ 3D.</dd></div>
        <div><dt>Klaring</dt><dd>En liten luft mellom deler som skal passe sammen, så de ikke blir for trange.</dd></div>
        <div><dt>PDF</dt><dd>Filtype for dokumenter som skal skrives ut. Ser lik ut på alle maskiner.</dd></div>
        <div><dt>Oppsamlings-PDF</dt><dd>En samling ark fra flere modeller, som blir én PDF.</dd></div>
    </dl>
</section>

<!-- ------------------------------------------------------------------ Spørsmål -->
<section id="sporsmal">
    <h2>Vanlige spørsmål</h2>
    <details><summary>Hvor finner jeg filen jeg lagret?</summary>
        <p>I mappen <strong>Nedlastinger</strong> (Downloads) på datamaskinen. I de fleste nettlesere kan du også klikke på
            nedlastingsikonet øverst til høyre i nettleseren.</p></details>
    <details><summary>Byggeplaten eller oppsamlings-PDF-en er tom</summary>
        <p>De lagres i nettleseren på maskinen du bruker. Bytter du maskin eller nettleser, eller bruker et privat vindu,
            starter du med tom byggeplate. Lagre filen før du går fra maskinen.</p></details>
    <details><summary>Printeren vår står ikke på lista</summary>
        <p>Velg <em>Egendefinert størrelse</em> nederst i lista og skriv inn målene på byggeplaten (bredde, dybde og høyde i
            millimeter). De står i bruksanvisningen eller på nettsiden til produsenten.</p></details>
    <details><summary>Figurene ligger et annet sted i sliceren enn i M+ 3D</summary>
        <p>Noen slicere flytter figurene når filen åpnes. Det gjør ingenting. Bruk knappen for å ordne platen automatisk
            (<em>Arrange</em> / <em>Ordne</em>) hvis du vil.</p></details>
    <details><summary>Arkene ble for små</summary>
        <p>Skriv ut på nytt med <strong>Faktisk størrelse</strong> eller <strong>100 %</strong>. Mål kontrollinjen på arket
            for å sjekke. Se <a href="#utskrift">Skriv ut i riktig størrelse</a>.</p></details>
    <details><summary>Brikkene passer ikke sammen</summary>
        <p>Printere er litt forskjellige. Øk <em>klaringen</em> litt hvis delene er for trange, eller gjør den mindre hvis de
            er for løse. Print noen få brikker og prøv før du printer et helt sett.</p></details>
</section>

<p class="help-foot"><a href="../">← Tilbake til M+ 3D</a></p>
</main>
</div>

<script>
// Nummermerkene på skjermbildene: plasseringen kommer fra bilder/merker.json.
fetch('bilder/merker.json?v=<?= $v ?>').then((r) => r.json()).then((marks) => {
    document.querySelectorAll('.shot[data-shot]').forEach((fig) => {
        const m = marks[fig.dataset.shot] || {};
        fig.querySelectorAll('.pin').forEach((pin) => {
            const r = m[pin.dataset.key];
            if (!r) { pin.hidden = true; return; }
            const pos = pin.dataset.pos || 'top';
            const x = pos === 'left' ? r.x : pos === 'right' ? r.x + r.w : r.x + r.w / 2;
            const y = pos === 'top' ? r.y : r.y + Math.min(r.h, 6) / 2;
            pin.style.left = x + '%';
            pin.style.top = y + '%';
            pin.classList.add('pin-' + pos, 'placed');
            if (pin.hasAttribute('data-outline')) {
                const box = document.createElement('span');
                box.className = 'pin-box';
                Object.assign(box.style, { left: r.x + '%', top: r.y + '%', width: r.w + '%', height: r.h + '%' });
                fig.appendChild(box);
            }
        });
    });
}).catch(() => document.querySelectorAll('.pin').forEach((p) => (p.hidden = true)));

// «Tilbake» går tilbake til modellsiden læreren kom fra, hvis den var i M+ 3D.
const back = document.getElementById('back');
if (document.referrer && new URL(document.referrer).origin === location.origin && !/\/hjelp\//.test(document.referrer)) {
    back.href = document.referrer;
}
</script>
</body>
</html>
