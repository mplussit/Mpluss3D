# M+ 3D – parametriske matematiske konkreter

Et webverktøy der lærere lager matematiske konkreter for 3D-printing. Hver modell gir to typer filer:

* **STL**: 3D-modeller til en vanlig FDM-printer, generert av OpenSCAD.
* **PDF**: utskrift i nøyaktige mål som hører til modellen.

Modellene i dag:

| Side | Modell | STL | PDF |
|---|---|---|---|
| **Terning** (`?side=terning`) | `dice` | én terning | seks etiketter med prikker eller tall |
| **Brøksirkler** (`?side=broeksirkler`) | `fractions` | én fil per brøk + ZIP | oversikt, oppgaver og arbeidsflate (4 sider) |
| **Base 10** (`?side=base10`) | `base10` | én fil per objekttype + ZIP | oversikt, bygg tall, veksling, regning og plassverditabell (6 sider) |
| **Tallstaver** (`?side=tallstaver`) | `rods` | én fil per stavlengde + ZIP | lærerveiledning, tall, regning, brøk og arbeidsark (7 sider) |
| **Romfigurer** (`?side=romfigurer`) | `solids` | én figur (massiv eller hul) | utbrettingsfigur i 1:1 med limfliker, og oppgaver på tre nivåer med fasit (6 sider) |
| **Regn med Lego** (`?side=regnmedlego`) | `lego` | én fil per ramme + ZIP | oversikt over rammene og regnestykket, og papiretiketter i faktisk størrelse |
| **Kulespill** (`?side=kulespill`) | `marble` | kulespillet og bunnen (faste filer) | hullark i 1:1 under spillet, verdiark, oppgaver og fasit |

STL og PDF bygges fra **samme validerte parametersett**. Kulespillet er unntaket: spillet og bunnen er faste STL-filer tegnet i SolidCraft, og det læreren lager, er arkene. Forhåndsvisningen i nettleseren viser **de genererte STL-filene**, så det som vises, er nøyaktig det som lastes ned.

---

## Innhold

1. [Oppbygning](#oppbygning)
2. [Menyen](#menyen)
3. [Serveravhengigheter](#serveravhengigheter)
4. [Installere OpenSCAD](#installere-openscad)
5. [Starte utviklingsmiljøet](#starte-utviklingsmiljøet)
6. [Generere en terning](#generere-en-terning)
7. [STL-eksport](#stl-eksport)
8. [PDF-generering](#pdf-generering)
9. [Koordinatsystem og orientering](#koordinatsystem-og-orientering)
10. [Brøksirkler](#brøksirkler)
11. [Base 10 / plassverdimateriell](#base-10--plassverdimateriell)
12. [Tallstaver / Cuisenaire-staver](#tallstaver--cuisenaire-staver)
13. [Romfigurer og utbrettingsmodeller](#romfigurer-og-utbrettingsmodeller)
14. [Regn med Lego](#regn-med-lego)
15. [Kulespill](#kulespill)
16. [Modellbeskrivelse (JSON) for M+](#modellbeskrivelse-json-for-m)
17. [API](#api)
18. [Legge til nye modellparametere](#legge-til-nye-modellparametere)
19. [Legge til en ny modellgenerator](#legge-til-en-ny-modellgenerator)
20. [Tester](#tester)
21. [Drift på server](#drift-på-server)
22. [Kjente begrensninger](#kjente-begrensninger)
23. [Beslutninger og avvik fra spesifikasjonen](#beslutninger-og-avvik-fra-spesifikasjonen)

---

## Oppbygning

```text
mplus-3d/
├── public/                         Webrot (bare denne mappen skal eksponeres)
│   ├── index.php                   Meny og modellsider (?side=…)
│   ├── api.php                     Inngang til API-et
│   ├── css/app.css
│   ├── assets/                     M+-logo og forhåndsbilder til menyen
│   └── js/
│       ├── ui.js                   Felles: kontroller, meldinger, nedlasting, dialog
│       ├── viewer.js               Felles 3D-visning (ModelViewer) + DiceViewer
│       ├── fraction-viewer.js      FractionViewer: samlet / separert / én brikke
│       ├── base10-viewer.js        Base10Viewer: samlet / enkeltobjekt / tallbygger
│       ├── place-value.js          Tallbyggerens logikk (sifre, sum, tallord, sjekk mot settet)
│       ├── rods-viewer.js          RodsViewer: trapp / enkeltstav / tallkombinasjoner
│       ├── rod-combos.js           Tallkombinasjoner (samme regler som RodTasks i PHP)
│       ├── solids-viewer.js        SolidViewer: romfigur med flater, kanter og hjørner fra geometrimodellen
│       ├── lego-viewer.js          LegoViewer: valgt ramme / alle rammer, klosser som illustrasjon
│       ├── marble-viewer.js        MarbleViewer: kulespillet på bunnen, med hullarket som tekstur
│       ├── sheet-svg.js            Kulespill: hullarket som SVG, brukt som tekstur i MarbleViewer
│       ├── net-svg.js              Utbrettingsnettet som SVG (samme nett og A4-plassering som PDF-en)
│       └── pages/
│           ├── menu.js             Menyen
│           ├── dice.js             Terningsiden
│           ├── fractions.js        Brøksirkelsiden
│           ├── base10.js           Base 10-siden
│           ├── rods.js             Tallstavsiden
│           ├── solids.js           Romfigursiden
│           ├── lego.js             Regn med Lego (rutenett, rammer, regnetegn)
│           └── marble.js           Kulespill (innhold, oppgaver, varianter, «Lag stykker»)
├── app/
│   ├── bootstrap.php               Autoload, konfig, logging
│   ├── api/Api.php                 HTTP-endepunkter, felles for alle modeller
│   ├── config/
│   │   ├── config.php              Global konfig (miljøvariabler)
│   │   ├── models.php              Modellregister: id → klasse
│   │   ├── pages.php               Sidene og menyen (gruppe, tema, beskrivelse, bilde)
│   │   └── models/
│   │       ├── dice_cube.php       Terning: parametere, grenser, sideinnhold
│   │       ├── fraction_circles.php  Brøksirkler: parametere, farger, navn, merking
│   │       ├── base10_blocks.php   Base 10: parametere, objekttyper, antall, farger, material
│   │       ├── number_rods.php     Tallstaver: parametere, lengder, antall, farger, tallmerking
│   │       ├── solid_figures.php   Romfigurer: figurer, parametere, flatefarger, material
│   │       ├── lego_frames.php     Regn med Lego: modul, høyde, tykkelser, merkefelt, etiketter
│   │       └── marble_game.php     Kulespill: hullene fra SolidCraft-modellen, innhold, symboler, grenser
│   └── services/
│       ├── ParametricModel.php     Grensesnittet alle modeller implementerer
│       ├── ModelRegistry.php       Slår opp modeller fra models.php
│       ├── ParameterValidator.php  Felles tallvalidering og norsk tallformat
│       ├── ModelBuilder.php        Hurtigbuffer → OpenSCAD → kontroll, per del
│       ├── ScadGenerator.php       Sikker kjøring av OpenSCAD
│       ├── ProcessSlots.php        Begrenser samtidige OpenSCAD-prosesser
│       ├── StlInspector.php        Kontroll av STL (lukket, manifold, mål)
│       ├── GeneratedFiles.php      Midlertidige filer med tilfeldige navn
│       ├── LabelPdf.php            FPDF med sirkel, sektor, polygon, linjal, norske tegn
│       ├── WorksheetPdf.php        Felles for oppgave-PDF-ene: topptekst, bunntekst, oppgaver
│       ├── DiceModel.php           Terning: validering, utledede verdier, beskrivelse
│       ├── PdfGenerator.php        Terning: etikettark
│       ├── FractionModel.php       Brøksirkler: validering, geometri, merking
│       ├── FractionTasks.php       Brøksirkler: oppgaver ut fra valgte nevnere
│       ├── FractionPdfGenerator.php  Brøksirkler: PDF på fire sider
│       ├── Base10Model.php         Base 10: validering, mål, material, beskrivelse
│       ├── Base10Tasks.php         Base 10: oppgaver kontrollert mot klossene i settet
│       ├── Base10PdfGenerator.php  Base 10: PDF på seks sider
│       ├── RodModel.php            Tallstaver: validering, mål, tallmerking, farger, material
│       ├── RodTasks.php            Tallstaver: kombinasjoner og oppgavegenerator
│       ├── RodPdfGenerator.php     Tallstaver: PDF på sju sider
│       ├── Solid.php               Romfigurer: felles geometrimodell (abstrakt)
│       ├── SolidCube.php, SolidCuboid.php, SolidPyramid.php,
│       │   SolidTriPrism.php, SolidCylinder.php, SolidCone.php   én klasse per figur
│       ├── SolidNet.php            Utbrettingsnett: flater, limfliker, klipp/brett, overlappkontroll
│       ├── NetLayout.php           Nettet på A4: ett ark 1:1, flere ark eller skalert
│       ├── SolidModel.php          Romfigurer: validering, STL-deler, beskrivelse
│       ├── SolidNetPdf.php         Romfigurer: PDF med utbrettingsfiguren
│       ├── SolidTasks.php          Romfigurer: oppgaver etter figur og nivå
│       ├── SolidTasksPdf.php       Romfigurer: oppgave-PDF med fasit
│       ├── SolidDrawing.php        Romfigurer: strektegning av figuren i PDF
│       ├── LegoShape.php           Regn med Lego: formen (sammenheng, utsparinger, arealer)
│       ├── LegoText.php            Regn med Lego: tillatte tegn og tekstbredde
│       ├── LegoModel.php           Regn med Lego: validering, geometri, STL-deler, beskrivelse
│       ├── LegoPdf.php             Regn med Lego: oversikt og etiketter
│       ├── MarbleModel.php         Kulespill: validering, beskrivelse (sidene), PDF
│       ├── MarbleSheets.php        Kulespill: innhold (frø), oppgaver, fasit og sideoppsett som tegneelementer
│       └── MarblePdf.php           Kulespill: tegner sidene i PDF
├── models/
│   ├── dice/cube.scad              Terninggeometrien
│   ├── base10/block.scad           Én base 10-kloss (ener, tier, hundrer, tusener)
│   ├── rods/rod.scad               Én tallstav (lengde 1–10, inndeling, tall)
│   ├── solids/solid.scad           Romfigurene (massive eller hule), bygd av tall fra Solid-klassene
│   ├── lego/frame.scad             Én Lego-ramme (form som tall, tekst som tegnkoder)
│   └── fractions/
│       ├── sector.scad             Én brøkbrikke (sektor)
│       └── fonts/LiberationSans-Bold.ttf  Font til brøkmerkingen og tallstavene (SIL OFL)
├── templates/
│   ├── pages/                      HTML for menyen og hver modellside
│   └── dice-labels/a4-portrait.php Tekster og layout for terning-PDF-en
├── storage/                        Genererte filer, hurtigbuffer, låser, logger (skrivbar)
├── bin/
│   ├── generate.php                Lag en modell fra kommandolinjen
│   └── cleanup.php                 Slett utløpte filer (cron)
└── tests/
    ├── run.php                     Automatiserte tester
    ├── solids.php                  Tester for romfigurene (tas inn av run.php)
    ├── lego.php                    Tester for Regn med Lego (tas inn av run.php)
    └── marble.php                  Tester for kulespillet (tas inn av run.php)
```

Prinsipper:

* **Modellmal** (`.scad`), **parameterdefinisjon** (`app/config/models/*.php`), **modellklasse**, **backend** og **frontend** er adskilt.
* Hver modell har sin egen definisjon og klasse, men bruker **felles tjenester**: OpenSCAD-kjøring, STL-kontroll, hurtigbuffer, midlertidige filer, API, feilhåndtering, PDF-verktøy og 3D-visning.
* PDF-generatorene kjenner ikke til OpenSCAD. De bruker bare de validerte parameterne og modellbeskrivelsen.
* Millimeter brukes overalt: i SCAD, STL, Three.js og PDF.
* Løsningen bruker ikke database eller rammeverk. Eneste PHP-avhengighet er FPDF.

## Menyen

Startsiden (`public/index.php` uten parameter) er en meny som fungerer som menyen i M+-appen:

* Til venstre står sidelista, gruppert under overskrifter (for eksempel «Matematikk – konkreter») og sortert på tittel. Over lista er et temafilter. Overskrifter uten sider i temaet skjules.
* Ett klikk på en side viser tittel, forhåndsbilde og beskrivelse til høyre.
* Dobbeltklikk, Enter eller klikk på bildet åpner siden. Piltastene flytter valget.
* Sist valgte side og tema huskes i nettleseren.
* På modellsidene har toppfeltet menyknappen, sidetittelen og Hjelp-knappen (se «Toppfelt, arkfaner og forhåndsvisning av PDF-en»).

Sidene, gruppene og temaene står i `app/config/pages.php`. Forhåndsbildene (`public/assets/previews/`) er skjermbilder av 3D-visningen.

### Fast appflate (samme størrelse som M+)

M+ har en fast flate på 840 × 640 som forstørres jevnt til vinduet (skala 1–2) og midtstilles vannrett. M+ 3D bruker nøyaktig samme rektangel (`public/js/appscale.js`), så appen har samme høyde og bredde når man går mellom M+ og en 3D-side.

* Innholdet er laget for en flate på 1120 × 853 (samme proporsjoner) og forstørres med CSS `zoom`.
* Panelet og innholdet under 3D-visningen ruller inne i flaten, og vinduet selv ruller ikke.
* 3D-visningen tegnes med pikseltetthet × forstørrelse, så den er skarp også når flaten er forstørret.
* Vinduer smalere enn 840 px (mobil) får den vanlige, responsive siden.

### I menyen til M+

M+ 3D er også et produkt i produktvelgeren i M+ (fag `3d`, fra `menu__24.js` i M+-repoet):

* Sidene står i `o_pages` med `subject = "3d"`. `page` er det samme som `side` her (for eksempel `terning`), og tittel og beskrivelse er UI-teksten `<page>3d`. Radene og tekstene ligger i `docs/mpluss3d-o_pages.sql` i M+-repoet.
* Når man åpner en side, sender M+ brukeren til `index.php?side=<page>&tilbake=<adressen til M+-menyen>`. Menyknappen heter da «Meny i M+» og fører tilbake dit.
* `tilbake` godtas bare for http(s)-adresser på vertene i `mplus_return_hosts` i `app/config/config.php` (eller i miljøvariabelen `MPLUS_RETURN_HOSTS`).
* Forhåndsbildene i M+ er WebP 900 × 670 i `www/media/task_previews/{nbNO,nnNO}/<page>.webp`, laget av bildene her.
* En ny modell må derfor legges inn både i `pages.php` og som rad og tekst i M+.

### Toppfelt, arkfaner og forhåndsvisning av PDF-en

* **Toppfeltet** har menyknappen helt til venstre («Meny i M+» når siden er åpnet fra M+, ellers «Meny»), sidetittelen og en **Hjelp**-knapp helt til høyre. Hjelp åpner `help_url` fra `app/config/config.php` (eller miljøvariabelen `M3D_HELP_URL`) i en ny fane, med `#<side>` på modellsidene (for eksempel `#base10`).
* **Arkfanene** under toppfeltet (`public/js/sheets.js`) deler siden i 3D-faner og PDF-faner. 3D-fanene er visningsvalgene siden har fra før (for eksempel «Samlet | Enkeltobjekt | Tallbygger»). Radiogruppen skjules, og en fane velger det tilsvarende valget.
* **PDF-fanene** er én fane per side i PDF-en(e). Forhåndsvisningen henter den samme PDF-en som nedlastingen (`api.php?action=pdf`) og tegner siden med pdf.js (fra jsDelivr, som three.js). Det er altså nøyaktig den fila læreren får. PDF-ene hentes på nytt når innstillingene endres.
* **Fanenavnene** settes i PHP med `LabelPdf::SetPageTitle()` (i `WorksheetPdf::header()` er det tittelen, eller `tab:`) og sendes i svarhodet `X-Page-Titles` (URL-kodet JSON).
* **Ingen ZIP i grensesnittet.** «Last ned alle STL-filene» laster ned hver del som en vanlig .stl-fil, den ene etter den andre (`downloadAll()` i `public/js/ui.js`). Hver rad i utskriftslista har også sin egen STL-knapp. Nettleseren kan spørre én gang om siden får laste ned flere filer. `api.php?action=zip` finnes fortsatt, men brukes ikke av sidene.
* **PDF-knappen og oppsamlings-PDF** (`public/js/collect.js`, som i M+ matematikk). PDF-knappen i toppfeltet åpner en dialog med sidene i PDF-fanene som avkrysningsbokser. «Lag PDF» laster ned de valgte sidene direkte. «Legg til i oppsamlings-PDF» kopierer dem ut med pdf-lib (fra jsDelivr) og lagrer dem i nettleseren (IndexedDB, databasen `mplus3d`), så læreren kan gå til en annen modell og legge til flere sider. Tallet på knappen viser hvor mange sider som er samlet. «Lag oppsamlings-PDF» slår alt sammen til én fil og tømmer oppsamlingen. «Merk ark med» skriver en tekst langs høyre kant på hver side, og filnavnet kan endres. Alt skjer i nettleseren, serveren lager bare de vanlige PDF-ene. De vanlige «Last ned PDF»-knappene i panelet virker som før.
* En ny modellside trenger `<nav id="sheet-tabs" class="sheet-tabs"></nav>` øverst i `.viewer-wrap` og et kall til `initSheets()` i sidens skript, med `sheets.refresh()` etter hver endring.

### Liggende oppgaveark

Oppgavesidene er liggende A4, som i M+ matematikk. `WorksheetPdf::header()` lager liggende sider som standard, og `drawSections()` fordeler oppgavene i to spalter. Lærerveiledningene (side 1), sider i målestokk 1:1 (arbeidsflate, etiketter, utbrettingsnett, hullarket i kulespillet og terningdekoren) er stående. I kulespillet er verdiark, oppgaver og fasit liggende med to spalter.

## Serveravhengigheter

| Avhengighet | Versjon | Merknad |
|---|---|---|
| PHP | ≥ 8.1 (testet med 8.4) | Utvidelser: `json`, `mbstring`, `iconv`, `zlib`, `gd` (kreves av FPDF-pakken). `proc_open` må være tillatt. |
| Composer | 2.x | Installerer FPDF |
| OpenSCAD | Anbefalt ≥ 2025 / utviklingsversjon med **Manifold** | 2021.01 fungerer, men bruker 6–30 s per terning mot ca. 0,2 s med Manifold |
| Nettleser | Med WebGL | Three.js 0.170.0 lastes fra jsDelivr (låst versjon) |

## Installere OpenSCAD

Appen finner OpenSCAD automatisk. Den slår også på `--backend=manifold` når versjonen støtter det.

**Windows**

* Stabil versjon: `winget install OpenSCAD.OpenSCAD` (2021.01, treg).
* Anbefalt: last ned zip-filen med siste utviklingsversjon fra <https://files.openscad.org/snapshots/> (`OpenSCAD-ÅÅÅÅ.MM.DD-x86-64.zip`), kontroller SHA256 mot `.sha256`-filen og pakk ut til `%LOCALAPPDATA%\Programs\`. Appen leter der automatisk.

**Linux (server)**

* `apt install openscad` gir ofte 2021.01, som er tregt.
* Anbefalt: AppImage eller utviklingsversjon fra <https://openscad.org/downloads.html>. Kjører serveren uten skjerm, trengs ingen X-server for STL-eksport.

**macOS:** Installer fra <https://openscad.org/downloads.html>.

Sjekk installasjonen:

```bash
openscad --version
openscad --help | grep backend     # vises bare i versjoner med Manifold
```

Overstyring med miljøvariabler (ingen lokale stier i koden):

| Variabel | Betydning |
|---|---|
| `OPENSCAD_BIN` | Full sti til binæren |
| `OPENSCAD_ARGS` | Ekstra argumenter, f.eks. `--backend=manifold`. Tom streng = ingen. |
| `OPENSCAD_TIMEOUT` | Maks kjøretid i sekunder (standard 60) |
| `MPLUS3D_STORAGE` | Annen plassering av `storage/` |

## Starte utviklingsmiljøet

```bash
composer install
php -S 127.0.0.1:8080 -t public      # eller: composer serve
```

Åpne <http://127.0.0.1:8080>.

## Generere en terning

**I nettleseren:** Juster kontrollene. Modellen genereres på nytt ca. 0,35 s etter siste endring og vises i 3D. Trykk «Last ned STL» eller «Last ned PDF-dekor».

**Fra kommandolinjen** (samme validering og kontroll som API-et):

```bash
php bin/generate.php size=30 corner_radius=2 pip_diameter=3 pip_depth=0.5
```

**Direkte med OpenSCAD:**

```bash
openscad -o terning.stl -D width=30 -D height=30 -D depth=30 \
         -D corner_radius=2 -D pip_diameter=3 -D pip_depth=0.5 models/dice/cube.scad
```

Parametere (definert i `app/config/models/dice_cube.php`):

| Parameter | Standard | Tillatt | SCAD-variabel |
|---|---:|---|---|
| Bredde (X) | 30 mm | 15–50 | `width` |
| Høyde (Z) | 30 mm | 15–50 | `height` |
| Dybde (Y) | 30 mm | 15–50 | `depth` |
| Hjørneradius | 2 mm | 0–4 | `corner_radius` |
| Prikkdiameter | 3 mm | 2–5 | `pip_diameter` |
| Prikkdybde | 0,5 mm | 0–1 (0 = blanke sider) | `pip_depth` |

Grensesnittet viser én kontroll, «Terningstørrelse», som setter alle tre målene. API-et og SCAD-filen støtter allerede rektangulære varianter.

**Kryssvalidering.** Enkelte kombinasjoner avvises med en forklarende melding:

* Avstanden mellom to prikker må være minst 0,6 mm.
* Prikkene må ligge minst 0,2 mm innenfor den flate delen av siden, altså ikke på avrundingen.

Standardverdiene er gyldige for alle størrelser fra 15 til 50 mm.

## STL-eksport

1. Frontend sender parameterne (JSON) til `api.php?action=generate`.
2. `DiceModel::validate()` godtar bare kjente parametere og tall innenfor grensene. Alt annet avvises (422) eller ignoreres.
3. `ModelBuilder` sjekker hurtigbufferen. Nøkkelen er parametere + SCAD-filens hash + binær.
4. `ScadGenerator` starter OpenSCAD med `proc_open()` og en **argumentliste**, uten shell:
   * Bare `.scad`-filer under `models/` kan brukes. Brukeren kan aldri sende egen kode.
   * `-D`-variabler har validerte navn og er alltid tall eller flate lister med tall (formen i Regn med Lego, og tekst som tegnkoder).
   * Kjøretiden er begrenset (`OPENSCAD_TIMEOUT`). Ved tidsavbrudd drepes prosessen med eventuelle underprosesser.
   * Høyst 2 samtidige prosesser (`ProcessSlots`, fillåser). Overskytende forespørsler venter inntil 20 s og får så 503.
5. STL-filen skrives i en mappe med tilfeldig navn (`storage/generated/<32 hex>/`).
6. `StlInspector` kontrollerer resultatet. Filen kan bare lastes ned hvis alt er i orden:
   * lukket nett (hver kant deles av nøyaktig to trekanter)
   * konsistent orientering og positivt volum
   * ingen degenererte trekanter
   * Euler-karakteristikk = 2 (ett legeme uten hull)
   * ytre mål innenfor ±0,02 mm av forventet
7. Svaret inneholder bare et token. Nedlasting skjer via `api.php?action=stl&id=<token>&download=1` med filnavn som `mplus-terning-30mm.stl`.
8. Filer slettes etter 1 time (`generated_ttl`), automatisk av API-et og/eller av `bin/cleanup.php`.

Tekniske feildetaljer, som OpenSCAD-logg og stier, skrives til `storage/logs/` og vises aldri til brukeren.

**Geometri** (`models/dice/cube.scad`):

* Avrundet boks som `hull()` av åtte kuler. Kulene er bygget slik at poler og ekvator har hjørnepunkter, og ytre mål blir dermed **eksakte**. OpenSCADs `sphere()` ville gitt en terning som er ca. 0,03 mm for liten.
* Prikker er kulekalotter trukket fra med `difference()`, så de er en del av samme volum. Dybden er eksakt. Diameteren blir ca. 2 % mindre enn oppgitt på grunn av tessellering (2,93 mm i stedet for 3,0 mm), langt under det en FDM-printer kan gjengi.
* En 30 mm terning har 4 230 trekanter.
* Terningen står på z = 0 og er sentrert i X og Y. Den kan printes uten support: prikkene i bunnen er grunne kalotter som brokobles uten problemer.

## PDF-generering

`PdfGenerator` bruker FPDF med millimeter som enhet. Arket er nøyaktig 210 × 297 mm og inneholder:

* seks kvadratiske etiketter (1 TOPP, 2 FRONT, 3 HØYRE, 4 VENSTRE, 5 BAK, 6 BUNN) med heltrukket klippelinje og hjørnemerker
* en **OPP**-markering over hver etikett og en tekst om hvilken side OPP-kanten skal vende mot
* en monteringsveiledning med utbrettet terning (kors) og en isometrisk tegning av hvordan terningen holdes
* en tydelig beskjed om å skrive ut med «Faktisk størrelse» / 100 %
* en målestokk på 100 mm med millimeterinndeling
* en bunnlinje med modell, mål og etikettstørrelse

Dekortyper: `pips` (prikker), `numbers` (tall) og `blank` (tomme etiketter).

Etikettstørrelsen er `auto` som standard: den flate delen av siden (`størrelse − 2 × hjørneradius`) minus 0,3 mm klaring per side. Det gir 25,4 mm for en 30 mm terning med radius 2, og 29,4 mm med skarpe hjørner. Etiketten kan settes manuelt fra 5 til 50 mm. Er den større enn den flate delen, vises en advarsel både i grensesnittet og på PDF-en.

Koordinater skrives med tre desimaler i punkter (±0,0004 mm). Testene kontrollerer at etikettene har eksakt størrelse og at målestokken er nøyaktig 100 mm.

Tekster og layoutkonstanter ligger i `templates/dice-labels/a4-portrait.php`. Senere kan bilder, symboler og tekst fra M+ legges inn i `PdfGenerator::drawContent()`.

## Koordinatsystem og orientering

| Side | Normal | «Opp» på etiketten peker mot | Innhold |
|---|---|---|---|
| TOPP | +Z | BAK (+Y) | 1 |
| BUNN | −Z | FRONT (−Y) | 6 |
| FRONT | −Y | TOPP | 2 |
| BAK | +Y | TOPP | 5 |
| HØYRE | +X | TOPP | 3 |
| VENSTRE | −X | TOPP | 4 |

Hver side har et lokalt system (u = høyre, v = opp) sett utenfra. SCAD-filen, PDF-en (`PIP_PATTERNS`) og knappene i 3D-visningen bruker samme definisjon. I det utbrettede korset (VENSTRE–FRONT–HØYRE–BAK med TOPP over og BUNN under FRONT) står derfor alle sider rett opp. En test sjekker at prikkmønstrene i PDF og SCAD er identiske.

## Brøksirkler

En brøksirkel består av sektorformede brikker som til sammen danner én hel sirkel. Læreren velger én eller flere nevnere fra 1 til 12. For hver nevner *n* skal det skrives ut *n* brikker på 1/*n*. Alle brikkene har samme ytre diameter, så brikker fra ulike sett kan sammenlignes og kombineres.

### Parametere

Parameterne står i `app/config/models/fraction_circles.php`.

| Parameter | Nøkkel | Standard | Område |
|---|---|---|---|
| Sirkeldiameter | `diameter` | 100 mm | 50–150 mm |
| Brikketykkelse | `thickness` | 3 mm | 2–8 mm |
| Klaring mellom brikker | `clearance` | 0,3 mm | 0–0,8 mm |
| Kantavrunding (bare øvre kanter) | `edge_radius` | 0,5 mm | 0–1 mm |
| Brøkmerking | `labels` | på | på/av (nedsenket) |
| Merkedybde | `label_depth` | 0,4 mm | 0,2–0,8 mm |
| Nevnere | `denominators` | 2, 3, 4, 6, 8 | 1–12, flere samtidig |

Eksempel: med nevnerne 2, 4 og 8 blir det 2 + 4 + 8 = 14 brikker, men bare tre STL-filer.

### Geometri (`models/fractions/sector.scad`)

* Brikka for nevner *n* er en sektor på 360/*n* grader. Sirkelsentrum ligger i origo, undersiden på z = 0, og halveringslinja peker langs +Y. Forhåndsvisningen roterer den samme STL-fila 360/*n* grader for hver brikke.
* **Klaring:** hver radielle kant flyttes parallelt innover med klaring/2. To nabobrikker får dermed en spalte på nøyaktig «klaring» mm langs hele kanten. Ytterradiusen beholdes (diameter/2), så sirkelen har samme nominelle diameter i alle sett. Spissen havner klaring/2 / sin(180°/*n*) fra sentrum. Testene måler spalten i den genererte STL-fila.
* **Kantavrunding:** bare de øvre kantene avrundes. Undersiden er helt flat. Alle brikker er konvekse, så avrundingen lages som `hull()` av et prisme og små kuppler. Kupplene har punkter på ekvator og i toppen, slik at tykkelsen blir eksakt.
* **Nevner 1** gir en hel skive uten klaring, merket «1».
* **Utskrift:** brikkene ligger flatt og har ingen overheng. Testene sjekker at alle flater som peker nedover, ligger på byggeplaten.

### Brøkmerking

* Merket er `1/n` i Liberation Sans Bold. Fonten ligger i `models/fractions/fonts/` (SIL Open Font License) og lastes med `use <…ttf>`, så den finnes på alle servere uansett hvilke fonter som er installert. Unicode-brøktegn brukes ikke.
* Merket senkes ned i toppflaten (`difference()`), så det er en del av brikka og ikke et eget objekt. Opphøyd merking er ikke med i versjon 1.
* Plassering og størrelse regnes ut i `FractionModel::labelLayout()`. Teksten skaleres til en eksakt boks med `resize()`. Boksen må ligge minst klaring/2 + avrunding + 1 mm fra de radielle kantene og innenfor radius R − avrunding − 1 mm. Ønsket høyde er 12 % av diameteren, høyst 14 mm.
* Smale brikker får mindre tekst. Gir det mer plass, roteres teksten 90° så den går langs brikka. Blir teksten lavere enn 4 mm, utelates merkingen på den brikka, og læreren får beskjed. Modellen blir aldri ugyldig. Testene sjekker at tekstboksen alltid ligger på den flate toppen, for alle nevnere og ytterverdier.

### STL og ZIP

* Én STL per nevner: `broeksirkel_1-2.stl`, `broeksirkel_1-3.stl`, … Identiske brikker lages ikke flere ganger.
* Hver fil bufres for seg. Slår læreren på én nevner til, lages bare den nye brikka (ca. 0,15 s med Manifold).
* Hver brikke kontrolleres som terningen: lukket, manifold, Euler = 2 og ytre mål beregnet fra det samme omrisset som i SCAD-fila (`FractionModel::outline()`).
* «Last ned alle STL (ZIP)» gir `broeksirkler-100mm.zip` med alle STL-filene og `oversikt.txt` (fil, brøk, antall og forslag til filamentfarge). Enkeltfiler kan lastes ned fra lista «Dette skal skrives ut». ZIP krever PHP-utvidelsen `zip`.

### Forhåndsvisning

* **Brøksett:** Alle, eller én nevner.
* **Visning:** Samlet (hel sirkel), Separert (brikkene skjøvet litt ut fra sentrum) eller Én brikke.
* **Kamera:** Standard og Ovenfra. Rotasjon og zoom fungerer som for terningen.
* **Farger:** hver nevner har sin farge (1/2 rød, 1/3 blå, 1/4 grønn, 1/6 gul, 1/8 lilla …). Fargene finnes bare i forhåndsvisningen og PDF-en. Grensesnittet foreslår filament i tilsvarende farger.
* **Merking:** brøkmerkingen tegnes litt mørkere i forhåndsvisningen, så den er lesbar ovenfra. Geometrien er den samme.

### PDF (`FractionPdfGenerator`)

Fire A4-sider:

1. **Oversikt:** illustrasjon av settene, tabell med farge, brøk, STL-fil og antall, råd til læreren og innstillinger.
2. **Sammenlign brøker:** «Hvilken er størst?», «Hvor mange 1/8 trenger du for å dekke 1/4?», «Lag en hel sirkel med både halvdeler og firedeler», og addisjon/subtraksjon.
3. **Likeverdige brøker:** illustrerte oppgaver som 1/2 = ?/4 og 2/4 = ?/8. Har settet ingen nevnere som går opp i hverandre, blir siden «Flere sammenligninger» i stedet.
4. **Arbeidsflate:** tomme sirkler i full størrelse, med skrivelinjer og 100 mm-linjal. Hvis en diameter ikke får plass på A4, skaleres sirklene, og siden sier tydelig hvor mye. Med dagens maksimum (150 mm) skjer det ikke.

Oppgavene lages i `FractionTasks` og bruker bare brikker som finnes i settet. Hver oppgave oppgir hvilke brikker den trenger, og testene sjekker dette for mange kombinasjoner. Oppgavene er deterministiske, så samme sett gir samme PDF.

## Base 10 / plassverdimateriell

Klosser som viser titallsystemet: **ener** (1 × 1 × 1 enhet), **tier** (10 × 1 × 1), **hundrer** (10 × 10 × 1) og **tusener** (10 × 10 × 10). Alle bygger på samme enhetslengde, så de har riktige relative mål og volum: ti enere er like lange som en tier, ti tiere dekker en hundrer, og ti hundrere er like store som en tusener.

### Parametere

Parameterne står i `app/config/models/base10_blocks.php`.

| Parameter | Nøkkel | Standard | Område |
|---|---|---|---|
| Enhetslengde (enerkuben) | `unit` | 10 mm | 6–15 mm |
| Kantavrunding | `edge_radius` | 0,3 mm | 0–1 mm |
| Rutenett / inndeling | `grid` | på | på/av |
| Rilledybde | `groove_depth` | 0,4 mm | 0,2–0,8 mm |
| Rillebredde | `groove_width` | 0,6 mm | 0,3–1,2 mm |
| Hul tusenerkloss | `hollow` | på | på/av |
| Veggtykkelse tusener | `wall` | 2 mm | 1,2–4 mm |
| Objekttyper | `types` | ener, tier, hundrer | hvilke som helst, minst én |
| Antall | `count_ener`, `count_tier`, `count_hundrer`, `count_tusener` | 20, 10, 10, 0 | 0–200 / 100 / 50 / 20 |

Ekstra regler, så rillene ikke svekker små klosser:

* Rilledybden kan være høyst 10 % av enhetslengden (0,6 mm ved 6 mm), og rillebredden høyst 15 % (0,9 mm ved 6 mm).
* For hul tusener må veggen være minst 0,8 mm tykkere enn rilledybden. Da er det minst to strenger igjen under hver rille.
* Reglene gjelder bare når verdiene brukes. Rilledybden kontrolleres for eksempel ikke når rutenettet er av, eller når bare enere er valgt.
* En valgt objekttype må ha antall minst 1. Slår læreren på tusener, settes antallet til 1.

### Geometri (`models/base10/block.scad`)

* **Én SCAD-fil for alle fire typene.** Klossen er `nx × ny × nz` enheter, står midt over origo og har undersiden på z = 0, slik den skrives ut. Tieren ligger ned, og hundreren ligger flatt.
* **Kantavrunding:** de øvre og de loddrette kantene avrundes med radius r. Kantene mot byggeplaten får i stedet en 45°-fas av samme størrelse. Det gir ingen overheng, og fasen motvirker «elefantfot». Klossen lages som `hull()` av fire sylindre, fire kupler og et rektangel i bunnen. Det er mye raskere enn `minkowski()`, og de ytre målene endres ikke.
* **Rutenett:** ved hver enhetsgrense skjæres det en rille med bredde w og dybde d inn fra alle ytterflatene som krysser grensen. Rillen er en skive med tykkelse w, minus en kjerne som er d mindre på alle sider. Da går rillene rundt tieren (ni inndelinger, altså ti enere), danner 10 × 10-rutenett på begge flatene av hundreren og på alle sidene av tusenerkuben, og kan aldri gå gjennom modellen. Enerkuben har ingen riller.
* **Hul tusener:** fem lukkede sider og én åpen. Fila står **med åpningen opp, som en kopp**. Med åpningen ned måtte skriveren ha bygd et tak på nesten 10 cm uten noe under, og det krever støtte. Med åpningen opp trengs verken støtte eller broer. Kuben snus etter utskrift, og de fem synlige sidene har rutenett. Kanten rundt åpningen har ingen riller.
* **Massiv tusener:** en lukket kube med rutenett på alle seks sider. Den skrives ut med lite fyll (10–15 %).
* **Utskrift:** de eneste flatene som peker nedover og ikke ligger på byggeplaten, er 45°-fasen og taket i smale riller (høyst 1,2 mm brede). Det klarer en vanlig FDM-printer uten støtte, og en test sjekker det.

### STL og ZIP

* Én STL per valgt type: `base10_ener.stl`, `base10_tier.stl`, `base10_hundrer.stl` og `base10_tusener.stl`. Det lages aldri én fil per eksemplar.
* Hver fil bufres for seg. Verdier som ikke påvirker en kloss, er ikke med i hurtigbuffernøkkelen. Veggtykkelsen gjelder for eksempel bare tusenerkuben, og rillene påvirker ikke enerkuben.
* Hver kloss kontrolleres som de andre modellene: lukket, manifold, Euler = 2 og eksakte ytre mål.
* «Last ned alle STL (ZIP)» gir `base10-10mm.zip`. Den inneholder STL-filene og `oversikt.txt`, med parameterne og en utskriftsoversikt (fil, objekt, verdi, mål, antall og omtrentlig filament).
* **Materialforbruk:** `Base10Model::estimate()` regner ut volum og overflate fra målene: boks minus avrunding, riller og hulrom. Overslaget avviker under 1 % fra STL-fila, og en test sjekker det. Filamentet anslås for PLA (1,24 g/cm³) med et skall på ca. 0,85 mm (2 vegger) og 15 % fyll. Det er et overslag, og sliceren gir nøyaktige tall.

Tid med Manifold: under 1 s for alle fire klossene. Med OpenSCAD 2021.01 tar tusenerkuben ca. 15 s.

### Forhåndsvisning og tallbygger

* **Samlet:** én av hver valgt type ved siden av hverandre, i riktig størrelse og med navn og verdi. Tieren ligger langs dybden, og den hule tusenerkuben er snudd med åpningen ned, slik klossene brukes.
* **Enkeltobjekt:** én type, slik den ligger på byggeplaten. Den hule kuben vises med åpningen opp.
* **Tallbygger:** læreren skriver et tall fra 0 til 9999 (standard 243). Visningen bygger tallet med grupper av tusenere, hundrere, tiere og enere, med navn under hver gruppe. Under feltet står tallet som `243 = 200 + 40 + 3` og `243 = 2 × 100 + 4 × 10 + 3 × 1`, med tallord («to hundre og førtitre»).
  * Trenger tallet en type som ikke er valgt i settet, vises **ingen** klosser. Visningen sier i stedet hva som mangler, for eksempel «Tallet 1234 trenger 1 tusener, men tusener er ikke valgt i settet». Visningen viser aldri et ufullstendig tall som om det var riktig.
  * Har settet for få av en type, vises tallet likevel. Visningen sier fra, for eksempel «Settet har bare 1 tusener (trenger 9)».
  * Tallbyggeren lager ingen nye filer. Alle eksemplarer av en type deler den samme geometrien, så også 9999 (36 klosser) vises raskt.
  * Logikken ligger i `public/js/place-value.js`. Tallordene er identiske med `Base10Tasks::words()` i PHP. Det er kontrollert for alle tall fra 0 til 9999.
* **Farger** (bare i visningen og PDF-en): ener gul, tier grønn, hundrer blå, tusener oransje. Rillene tegnes litt mørkere, så rutenettet syns også rett ovenfra.

### PDF (`Base10PdfGenerator`)

Seks A4-sider i samme stil som brøksirklene. Topptekst, bunntekst og oppgaveoppsett er felles (`WorksheetPdf`).

1. **Oversikt til læreren:** tegninger av klossene i riktig innbyrdes størrelse, en tabell med farge, objekt, verdi, mål, STL-fil, antall og filament, en kort forklaring av plassverdisystemet (10 enere = 1 tier …), forslag til bruk og utskriftsråd.
2. **Bygg tall:** «Bygg tallet 136», med ruter for hvor mange av hver type, og tall skrevet med ord.
3. **Hvilket tall?:** tegninger av klosser som eleven skal skrive tallet til. Én oppgave er merket «Utfordring» og har flere enn ni av én type, for eksempel 1 hundrer, 12 tiere og 3 enere.
4. **Veksling:** grupper i tiere, bytt 10 enere mot 1 tier (og videre), vis 120 på to måter, og veksle til færrest mulig klosser.
5. **Addisjon og subtraksjon:** oppgaver med og uten veksling, og ruter for oppstilling.
6. **Plassverditabell** (liggende A4): kolonnene Tusener, Hundrere, Tiere og Enere, med et stort felt til klossene og fire rader for sifre.

**Oppgavene passer alltid til settet.** `Base10Tasks` velger fra faste lister, ikke tilfeldig, og kontrollerer hver kandidat:

* *Bygg tall:* hvert siffer må være høyst antallet av den typen.
* *Addisjon:* begge tallene må kunne ligge på bordet samtidig, pluss klossen man veksler til.
* *Subtraksjon:* vekslingen simuleres steg for steg. 302 − 7 veksler for eksempel en hundrer til ti tiere og en tier til ti enere. Det må finnes nok klosser til hvert steg. Med 10 tiere er derfor 231 − 145 ikke mulig, fordi den trenger 12 tiere på bordet.

Har settet for få klosser, utelates oppgaven, eller den skrives om til en papiroppgave som er tydelig merket **PAPIROPPGAVE**. Et sett uten tusenere får for eksempel «Tegn 10 hundrere og én tusener …». Testene sjekker dette for åtte sett, fra 5 enere til 200/100/50/20, og sjekker også at all tekst ligger innenfor arket.

### Anbefalte utskriftsinnstillinger

* PLA, 0,4 mm dyse, 0,2 mm laghøyde, 2–3 vegger og 15 % fyll.
* Klossene ligger slik de skal skrives ut. Ingen støtte trengs.
* Tusener: hul kube med åpningen opp, eller massiv kube med 10 % fyll.
* Bruk gjerne én farge per type: ener gul, tier grønn, hundrer blå og tusener oransje.
* Rillene på 0,4 × 0,6 mm syns godt med 0,2 mm laghøyde. Grunnere enn 0,3 mm kan bli utydelig.

## Tallstaver / Cuisenaire-staver

Staver med lengde 1 til 10 enheter. Alle har samme kvadratiske tverrsnitt (enhet × enhet), og stav n er nøyaktig n · enhet lang. Stavene kan legges ende mot ende og sammenlignes nøyaktig: stav 2 og stav 3 etter hverandre er like lange som stav 5. Som standard har stavene glatte sider og ingen tall, slik tradisjonelle Cuisenaire-staver har, så elevene må resonnere om lengdene.

### Parametere

Parameterne står i `app/config/models/number_rods.php`.

| Parameter | Nøkkel | Standard | Område |
|---|---|---|---|
| Enhetslengde (stav 1) | `unit` | 10 mm | 6–20 mm |
| Kantavrunding | `edge_radius` | 0,5 mm | 0–1,5 mm |
| Enhetsinndeling | `grid` | av | på/av |
| Rilledybde | `groove_depth` | 0,3 mm | 0,2–0,8 mm |
| Rillebredde | `groove_width` | 0,5 mm | 0,3–1,2 mm |
| Tallmerking | `labels` | av | på/av |
| Merkedybde | `label_depth` | 0,4 mm | 0,2–0,8 mm |
| Stavlengder | `lengths` | 1–10 | hvilke som helst, minst én |
| Antall | `count_1` … `count_10` | 10 av hver | 0–100 |
| Farger | `color_mode` | `classic` | `classic`, `single` (`color`), `custom` (`color_1` … `color_10`) |

Ekstra regler, så små staver ikke blir svake eller ødelagt:

* Kantavrundingen kan være høyst 20 % av enhetslengden (1,2 mm ved 6 mm).
* Med inndeling kan rilledybden være høyst 10 % og rillebredden høyst 15 % av enhetslengden. Reglene gjelder bare når rillene brukes.
* En valgt lengde må ha antall minst 1. Slår læreren på en lengde med antall 0, settes antallet til 1.
* Farger må være heksadesimale fargekoder (`#d43d3d`). De påvirker ikke geometrien eller hurtigbufferen.

### Geometri (`models/rods/rod.scad`)

* **Én SCAD-fil for alle lengdene.** Staven ligger langs X, midt over origo, med undersiden på z = 0, slik den skrives ut.
* **Kantavrunding bare på de fire langsgående kantene.** Tverrsnittet (avrundet oppe, 45°-fas nede) ekstruderes langs staven. Da er endeflatene helt plane og hele, og lengden er nøyaktig n · enhet. Spesifikasjonen åpnet for å runde alle kanter, men det gir V-formede spalter når stavene ligger ende mot ende. Fasen mot byggeplaten gir ingen overheng.
* **Enhetsinndeling:** n − 1 grunne riller på tvers av oversiden, nøyaktig ved k · enhet. Ingen riller på sidene, i bunnen eller på endene. Stav 1 har ingen riller.
* **Tallmerking:** tallet senkes ned i oversiden med samme skrift som brøksirklene (Liberation Sans Bold, lagt ved i `models/fractions/fonts/`). `RodModel::labelLayout()` regner ut en boks som ligger på den flate delen av oversiden, minst 0,8 mm fra avrundingen og endene. Tallet er høyst 60 % av enhetslengden.
  * Uten inndeling står tallet midt på staven.
  * Med inndeling står tallet i den midterste enheten, eller i enheten til venstre for midten når n er et partall. Da overlapper det aldri en rille.
  * Er det ikke plass til et tall som er minst 3 mm høyt, utelates tallet på den staven, og læreren får en advarsel. Det skjer for eksempel med «10» på 6 mm staver med inndeling.

### STL og ZIP

* Én STL per valgt lengde: `tallstav_01.stl` … `tallstav_10.stl`. Det lages aldri én fil per eksemplar.
* Hver stav bufres for seg. Antall og farger er ikke med i hurtigbuffernøkkelen, og stav 1 påvirkes ikke av rillene.
* Hver stav kontrolleres som de andre modellene: lukket, manifold, Euler = 2 og eksakte ytre mål.
* «Last ned alle STL (ZIP)» gir `tallstaver-10mm.zip`. Den inneholder:
  * STL-filene.
  * `oversikt.txt`: parameterne, utskriftslista (fil, stav, mål, antall, filamentfarge og omtrentlig PLA) og antall staver per filamentfarge.
* Tid med Manifold: ca. 0,15 s per stav. Med OpenSCAD 2021.01 tar en stav med tall og riller under 0,5 s.

### Forhåndsvisning

Visningen bruker STL-filene fra serveren, og alle eksemplarer av en lengde deler den samme geometrien.

* **Trapp (standard):** én stav av hver valgt lengde ved siden av hverandre, med venstre ende på linje og nummer til venstre.
* **Enkeltstav:** én valgt stav, slik den ligger på byggeplaten. Riller og tall vises mørkere, så de syns også ovenfra.
* **Tallkombinasjoner:** læreren velger en lengde fra 2 til 20. Øverst ligger staven med den lengden, eller en grå referansestav når lengden er over 10 eller ikke finnes i settet. Under ligger opptil seks kombinasjoner av to eller flere staver, med venstre ende på linje og en oransje strek der lengden starter og slutter.
  * Kombinasjonene finnes systematisk (alle oppdelinger) og tar hensyn til antall: 3 + 1 + 1 vises bare når settet har minst to staver 1.
  * Utvalget er variert: én kombinasjon med to staver, én med tre og så videre, spredt over alle mulighetene.
  * Under feltet står hvor mange måter det finnes, og regnestykkene, for eksempel «5 = 4 + 1».
  * Finnes ingen kombinasjon, vises en melding i stedet for tomme rader.
  * Logikken ligger i `public/js/rod-combos.js` og er identisk med `RodTasks::partitions()` og `RodTasks::showcase()` i PHP. En test sammenligner dem for fire sett og alle lengder fra 2 til 20.
* **Farger** (bare i visningen og PDF-en):
  * **Klassiske:** 1 hvit, 2 rød, 3 lysegrønn, 4 lilla, 5 gul, 6 mørkegrønn, 7 svart, 8 brun, 9 blå og 10 oransje.
  * **Én farge:** samme farge på alle stavene.
  * **Egne farger:** én fargevelger per lengde.

  Panelet forklarer at ulike farger krever ulike filamenter eller maling, fordi STL ikke har farger.

### PDF (`RodPdfGenerator`)

Sju A4-sider i samme stil som de andre modellene (`WorksheetPdf`). Stavene i oppgavene er tegnet i riktig farge og lengde, med tallet på.

1. **Lærerveiledning:** tabell med stavene tegnet som en trapp, med farge, mål, STL-fil, antall og filament. Siden har også en forklaring av fargene, et avsnitt om å bruke stavene uten tall, forslag til aktiviteter og utskriftsråd.
2. **Bygg tall:** finn staven, hvilken stav er like lang som to staver, bygg med to staver, bygg på tre måter, og lengder over 10.
3. **Addisjon:** to staver, tre staver og summer over 10, med 10-staven som referanse.
4. **Subtraksjon:** forskjellen mellom to staver, hvilken stav mangler, hvor mye lengre og tre staver.
5. **Multiplikasjon og divisjon:** like staver etter hverandre, vis k × a, to måter å bygge en lengde på med like staver, deling med og uten rest, partall og oddetall, og forhold (a : b = c : ?).
6. **Brøk:** en brøkvegg med den beste helen (helst stav 10). Deretter følger oppgaver med den helen, en ny hel (for eksempel stav 8) med spørsmålet om hvorfor samme stav nå har en annen verdi, likeverdige brøker og fra del til hel.
7. **Arbeidsark** (liggende A4): baner i full størrelse, like brede som stavene og delt i enheter (opptil 20), med skrivelinje under hver bane og en 100 mm-linjal for å kontrollere utskriften. Med 6–20 mm enhet får ti enheter alltid plass. Skulle de ikke få plass, skaleres arket og merkes tydelig «SKALERT».

**Oppgavegeneratoren (`RodTasks`).** `tasks(type, nivå, antall)` lager oppgaver systematisk fra stavlengdene og antallene i settet. Den går gjennom alle par, tripler og oppdelinger og beholder bare dem som kan løses. Typene er `find`, `build`, `add`, `sub`, `mul`, `div`, `parity`, `ratio`, `fraction`, `equiv` og `whole`, og nivåene er `lett`, `middels` og `vanskelig`.

* Hver oppgave har fasit (`answer`) og en liste over stavene som må ligge på bordet samtidig (`rods`). Generatoren sjekker selv lista mot settet før oppgaven godtas.
* Utvalget er jevnt fordelt og deterministisk, så samme sett gir samme PDF.
* Brøk lages bare der brøken kan vises nøyaktig med stavene i settet. Uten stav 5 får stav 10 ingen halvdel.
* **Kan en oppgavetype ikke lages med settet,** lages i stedet tegneoppgaver fra et fullt sett, tydelig merket **TEGNEOPPGAVE**. Ingressen forklarer at de løses ved å tegne stavene på arbeidsarket. Et sett med bare stav 3 og 7 får for eksempel tegneoppgaver i addisjon og brøk.

Testene kontrollerer alle typer og nivåer for åtte sett, fra én stav 10 til 100 enere. De sjekker at stavene finnes i riktig antall, at merkingen er riktig, og at fasiten stemmer med stavene i oppgaven.

### Anbefalte utskriftsinnstillinger

* PLA, 0,4 mm dyse, 0,2 mm laghøyde, 2–3 vegger og 15 % fyll.
* Stavene ligger slik de skal skrives ut. Ingen støtte trengs. Riller og tall er på oversiden.
* Unngå «brim» på endene, så de holder seg plane.
* Bytt filament mellom lengdene for klassiske farger. Ett sett med ti av hver lengde bruker ca. 310 g PLA med 10 mm enhet.

## Romfigurer og utbrettingsmodeller

Læreren lager en 3D-printet romfigur og en utbrettingsfigur på papir fra **nøyaktig de samme målene**. Elevene kan klippe, brette, lime, måle og sammenligne de to.

### Figurer og parametere

Parameterne står i `app/config/models/solid_figures.php`. Alle mål er i mm og valideres på serveren.

| Figur | Nøkkel (`shape`) | Parametere (standard, område) |
|---|---|---|
| Kube | `cube` | `cube_side` 40 (20–100) |
| Rektangulært prisme | `cuboid` | `cuboid_length` 60 (20–150), `cuboid_width` 40 (20–100), `cuboid_height` 30 (20–100) |
| Firkantet pyramide | `pyramid` | `pyramid_base` 50 (20–100), `pyramid_height` 50 (20–100), loddrett høyde |
| Trekantet prisme | `triprism` | `prism_base` 50 (20–100), `prism_height` 40 (20–100), `prism_length` 70 (20–150). Grunnflaten er en likebeint trekant |
| Sylinder | `cylinder` | `cylinder_diameter` 50 (20–100), `cylinder_height` 70 (20–150) |
| Kjegle | `cone` | `cone_diameter` 60 (20–100), `cone_height` 70 (20–150), loddrett høyde |

Andre parametere:

| Parameter | Nøkkel | Standard | Område |
|---|---|---|---|
| Hul modell | `hollow` | av | på/av |
| Veggtykkelse | `wall` | 2 mm | 1,2–4 mm |
| Limflikbredde | `tab_width` | 7 mm | 5–12 mm |
| Vanskelighetsgrad (oppgaver) | `level` | 2 | 1, 2, 3 |
| Store nett | `net_print` | `tiles` | `tiles` (flere ark i 1:1) eller `scaled` (ett ark, forminsket) |
| PDF | `document` | `net` | `net` (utbrettingsfigur) eller `tasks` (oppgaver) |

### Felles geometrimodell

Hver figur er én klasse som utvider `Solid` (`app/services/Solid.php`): `SolidCube`, `SolidCuboid`, `SolidPyramid`, `SolidTriPrism`, `SolidCylinder` og `SolidCone`. Klassen er **den eneste kilden** til figurens geometri:

* mål og utledede mål (`measures()`), for eksempel skråhøyden. Hvert mål regnes ut ett sted (`SolidPyramid::slant()`) og gjenbrukes overalt.
* flater (`faces()`): navn, form, areal, plan (normal og avstand) eller krum flate (sylinder- eller kjegleflate)
* kanter (`edges()`): linjestykker, eller sirkler for sylinder og kjegle
* hjørner (`vertices()`), og toppunktet for kjeglen
* antall flater, kanter og hjørner (`counts()`), grunnflateareal, overflateareal og volum
* utbrettingsnett (`nets()`), som `SolidNet`
* tallene til OpenSCAD (`scad()`) og hulrommet (`cavity()`)

Alt annet bygger på denne modellen:

```
                      Solid (felles geometrimodell)
   ┌──────────┬────────────┬───────────────┬──────────────┬─────────────┐
 scad()    faces/edges   nets() ──► NetLayout  measures()   counts()
   │       vertices         │         (A4)       │             │
solid.scad    │        ┌────┴─────┐               └──── SolidTasks (oppgaver)
   │       3D-visning  SVG-nettet  PDF-nettet
  STL      (flater,    (net-svg.js) (SolidNetPdf)
           kanter)
```

Nettleseren regner ikke ut geometri selv. `describe()` sender modellen som JSON (`geometry`, `net`, `layout`). 3D-visningen, SVG-nettet og egenskapsboksen viser derfor nøyaktig de samme tallene og flatene som PDF-ene.

**Utvidbar:** en ny figur er en ny underklasse av `Solid` som oppføres under `shapes` i konfigurasjonen, med sine parametere og et nummer i `solid.scad`. API, nett-PDF, A4-plassering, SVG, 3D-visning og oppgavegenerator virker uten endring.

### Geometriske beregninger

Alle beregninger bruker flyttall. Avrunding skjer bare når tallene vises.

| Figur | Flater / kanter / hjørner | Overflate | Volum | Nettet |
|---|---|---|---|---|
| Kube | 6 / 12 / 8 | 6s² | s³ | seks kvadrater (kors) |
| Rektangulært prisme | 6 / 12 / 8 | 2(lb + lh + bh) | lbh | seks rektangler |
| Firkantet pyramide | 5 / 8 / 5 | g² + 2gs | g²h / 3 | kvadrat og fire trekanter med høyde **s = √(h² + (g/2)²)**, ikke h |
| Trekantet prisme | 5 / 9 / 6 | gh + (g + 2a)l | (gh / 2) · l | tre rektangler og to trekanter, skrå side **a = √((g/2)² + h²)** |
| Sylinder | 3 (1 krum) / 2 sirkler / 0 | 2πr² + πdh | πr²h | rektangel **πd × h** og to sirkler |
| Kjegle | 2 (1 krum) / 1 sirkel / 1 toppunkt | πr² + πrs | πr²h / 3 | sektor med radius **s = √(h² + r²)** og vinkel **2πr / s**, og én sirkel. Buelengden er πd |

For sylinder og kjegle følger tellingen vanlig skolegeometri. Den krumme sideflaten regnes som én flate, og sirkelgrensene regnes som kanter. Grensesnittet forklarer dette ved egenskapene. Eulers formel (F − K + H = 2) kontrolleres for alle mangeflatene.

### STL-eksport (`models/solids/solid.scad`)

* Én STL per figur, stående slik den skal skrives ut, med skarpe kanter (ingen avrunding, så modellen kan sammenlignes med nettet).
* Trekantprismet ligger på det største rektangelet, med trekantene som ender. Da peker ingen flater nedover.
* SCAD-fila regner ikke ut mål. Den bygger figuren av tallene fra geometrimodellen.
* Sylinder og kjegle er mangekanter med 128 sider. Avviket fra den runde flaten er under 0,02 mm ved 50 mm radius.
* **Hul modell** finnes for alle seks figurene. Hulrommet er figuren forskjøvet innover med veggtykkelsen. Taket i hulrommet skrår alltid minst 45°, så figuren kan skrives ut uten støtte:
  * Kube, prisme og sylinder har flatt tak. Hulrommet får derfor valmtak eller kjegletak, med toppen én veggtykkelse under toppen av figuren.
  * Pyramide, kjegle og trekantprisme med sideflater brattere enn 45° får et hulrom som følger sideflatene. Er sideflatene slakere, får hulrommet en 45°-topp under dem.

  Hulrommet er lukket, så STL-fila har to skall. `StlInspector` godtar to skall bare når delen sier det (`shells`). Er figuren for liten til hulrommet, får læreren en feilmelding ved veggtykkelsen.
* Hver STL kontrolleres som de andre modellene: lukket, manifold, riktig Euler-tall og eksakte ytre mål. Testene sammenligner i tillegg volumet med geometrimodellen (innen 0,1 %).

### Forhåndsvisning

* **3D-modell**, **Utbrettingsfigur** eller **Begge** ved siden av hverandre. På smale skjermer ligger de under hverandre.
* 3D-visningen har knappene Standard (tilbakestiller visningen), Forfra, Fra siden og Ovenfra, og kan roteres og zoomes.
* **Vis flater:** hver geometrisk flate får sin farge. Nettet bruker de samme fargene, så elevene ser hvilken flate i nettet som blir hvilken flate på figuren. STL-trekantene tilordnes flatene i geometrimodellen (plan, sylinder- eller kjegleflate). Den krumme sideflaten blir derfor én flate med glatt skygge.
* **Vis kanter og hjørner:** kantene tegnes fra modellens kantliste, aldri fra trianguleringen. Kuben viser 12 kanter, sylinderen to sirkler og kjeglen én sirkel og toppunktet.
* Egenskapsboksen viser navn, flater, kanter, hjørner, grunnflateareal, overflateareal og volum. Under «Flere mål og formler» står skråhøyde, omkrets, sektorvinkel og liknende med formler, hvilke plane figurer overflaten består av, og antall symmetriplan.
* 2D-nettet tegnes som SVG fra det samme nettet og den samme A4-plasseringen som PDF-en.

### PDF-nett (`SolidNetPdf`)

* A4 i **målestokk 1:1**. Øverst står «Målestokk 1:1. Skriv ut i 100 % («Faktisk størrelse»), ikke «Tilpass til siden»», og nederst er det en **kontrollinje på 50 mm**.
* Heltrukne klippelinjer, stiplede brettelinjer og grå limfliker, med forklaring nederst. Flatene har lyse farger, navn og mål.
* Klippe- og brettelinjene regnes ut av regionene i nettet (`SolidNet::lines()`). En kant som deles av to regioner er en brettelinje, resten klippes. Linjene kan derfor ikke bli uenige med flatene.
* Den trykte siden blir utsiden. Alt brettes bakover, og limflikene havner på innsiden.
* **Limfliker** (standard 7 mm) har skrå hjørner. Det er én flik per kant som skal limes, og aldri to mot hverandre. Sylinderen har limtenner langs rektangelets lange kanter, og kjeglen langs sektorens bue. Der sirklene limes på. Kjeglens og viftepyramidens flik ved spissen spisses til så den holder seg i åpningen. Flikene ligger utenfor flatene og endrer ikke målene.
* **Store nett** (`NetLayout`):
  1. Første nettvariant som får plass på ett A4-ark i 1:1, stående eller liggende.
  2. Andre varianter og rotasjoner: tre kors og tre rekker for prismet, stjerne og vifte for pyramiden, sektoren rotert i trinn på 15° for kjeglen, og ulik plassering av sirklene for sylinderen.
  3. Får nettet fortsatt ikke plass, deles det på flere ark i 1:1 (færrest mulig). Først kommer en tydelig merket, forminsket oversikt med arkene (A1, A2 …). Hvert ark har 12 mm overlapp, en heltrukken skjøtekant («Klipp her og legg over A1»), en stiplet linje der neste ark skal ligge, passmerker og et lite kart over arkene.
  4. Alternativt (`net_print=scaled`): ett ark, forminsket til en pen målestokk (1 : 1,25, 1 : 1,5, 1 : 2 …), merket i rødt med «SKALERT – målestokk 1 : x. Ikke 1:1: papirfiguren blir mindre enn 3D-modellen.»

  Nettet skaleres aldri ned uten beskjed. Grensesnittet sier om nettet får plass på ett ark, og viser valget mellom flere ark og skalert bare når det trengs.

### PDF-oppgaver (`SolidTasks`, `SolidTasksPdf`)

Seks A4-sider i samme stil som de andre oppgave-PDF-ene:

1. **Undersøk figuren:** navn, flater, kanter og hjørner, plane figurer, like flater, figuren sett ovenfra og forfra, Eulers formel og symmetriplan. Siden har en strektegning av figuren der skjulte kanter er stiplet.
2. **Mål figuren:** måling med linjal, grunnflaten og omkretsen, og Pytagoras (skråhøyde, sidelinje, skrå side, romdiagonal). Tegningen viser målene, og boksen «Opplysninger på arket» har tallene eleven skal sammenligne med.
3. **Overflateareal:** tell og sammenlign flater og ruter (nivå 1), arealet av hver flate og summen (nivå 2), og formel og dobling av målene (nivå 3).
4. **Volum:** terninger på 1 cm³ for kube og prisme, «tre pyramider eller kjegler fyller prismet eller sylinderen», og to trekantprismer blir en eske (nivå 1). Grunnflate · høyde og ml (nivå 2), formel, dobling og samme volum (nivå 3).
5. **Fra papir til romfigur:** klipp, brett, lim, sammenlign og undersøk. Spørsmålene er figurspesifikke, for eksempel hvorfor rektangelet er π · d langt, hvorfor kjeglens flate er en sektor, sektorvinkelen og skråhøyden.
6. **Fasit** for læreren.

Oppgavene velges etter figur og nivå. For eksempel får sylinder og kjegle ikke Euler-oppgaver, og kjeglen spør om toppunktet i stedet for hjørner. Alle svar regnes ut fra geometrimodellen og vises i cm med én desimal. Tekst med π, √ og ≈ skrives med Symbol-fonten, fordi standardfontene i FPDF ikke har disse tegnene.

### Polygonal STL og eksakt nett

Utbrettingsnettet er matematisk eksakt: sylinderens rektangel er π · d langt, og kjeglens sektor har eksakt radius og vinkel. STL-fila tilnærmer de krumme flatene med 128 plane sider. STL-volumet blir derfor litt mindre enn πr²h, ca. 0,04 % (testen kontrollerer at forskjellen er positiv og under 0,05 %). Omkretsen av mangekanten er ca. 0,01 % kortere enn π · d, under 0,02 mm for en sylinder med diameter 50 mm. Det er langt under nøyaktigheten til en FDM-printer og til papir som brettes. Plane figurer (kube, prismer, pyramide) har ingen slik forskjell.

### Anbefalte utskriftsinnstillinger

* PLA, 0,4 mm dyse, 0,2 mm laghøyde og 2–3 vegger. Massiv modell: 15 % fyll.
* Figurene står slik de skal skrives ut. Verken massive eller hule figurer trenger støtte.
* Spissen på kjegle og pyramide blir litt avrundet av dysen.
* Skriv ut PDF-nettet på tykt papir (160–200 g/m²) i 100 %, og kontroller at kontrollinjen er 50 mm.

## Regn med Lego

Rammer som elevene fyller med standard LEGO®-klosser. Antallet knottplasser i en full ramme er en mengde. Flere rammer med regnetegn mellom blir et regnestykke, for eksempel 8 + 4 = 12.

LEGO® er et varemerke for LEGO-gruppen, som ikke står bak eller godkjenner M+ 3D. Det står på siden, i PDF-en og i ZIP-oversikten.

### Form og størrelse

* Hver rute i rutenettet er én knottplass, 8 × 8 mm. Læreren angir bredde og lengde (1–20 knottplasser) og trykker «Lag rektangel», eller tegner formen ved å klikke og dra i rutenettet.
* Mengden er antallet valgte ruter og vises med en gang («Plass til 16 knotter»).
* Rutenettet er bare et redigeringsverktøy. Det blir aldri vegger mellom rutene, bare langs formens kant.
* **Gyldige former** henger sammen langs sidene. Utsparinger (tomme ruter helt omsluttet av formen) er tillatt og blir hull gjennom rammen og bunnen.
* **Ugyldige former** gir en forklaring og kan ikke lastes ned:
  * tom form
  * flere deler
  * ruter som bare møtes i et hjørne
  * en utsparing uten bunn, fordi veggen rundt utsparingen da blir en løs del
* Formen sier ikke noe om hvilke klosser som får plass. En L-form kan for eksempel kreve smale klosser eller 1 × 1-klosser.

### Mål (`app/config/models/lego_frames.php`)

| Innstilling | Standard | Område | Betydning |
|---|---|---|---|
| Modulmål | 8 mm | fast | én knottplass |
| Innvendig høyde | 9,6 mm | fast | fra klossenes støtteflate til overkant ramme |
| Veggtykkelse (`wall`) | 2 mm | 1,2–3 mm | bygges utover |
| Bunn (`bottom`) | på | på/av | flat, uten knotter |
| Bunntykkelse (`bottom_thickness`) | 1,5 mm | 0,8–4 mm | vises bare med bunn |
| Ekstra klaring (`clearance`) | 0,2 mm | 0–0,5 mm | per side, langs alle kanter mot klossene |
| Feltdybde (`field_depth`) | 12 mm | 8–25 mm | hvor langt merkefeltet stikker ut |
| Tekstdybde (`text_depth`) | 0,6 mm | 0,4–1,2 mm | innfelt dybde eller opphøyd høyde |

Læreren skriver tallene i millimeter (desimalkomma går fint). Serveren validerer dem. Veggtykkelse og klaring er startverdier som skal justeres etter prøveutskrift.

### Geometri (`models/lego/frame.scad`)

* **Åpningen** er unionen av rutene utvidet med klaringen på alle sider (`offset(delta = …)`, skarpe hjørner, også innvendig). For et rektangel blir innvendig bredde `kolonner · 8 + 2 · klaring`. 4 × 4 gir 32,4 × 32,4 mm.
* **Veggen bygges utover.** Ytterkanten er rutene utvidet med klaring + vegg. Tykkere vegg endrer bare utvendige mål.
* **Høyde:** med bunn er total høyde bunntykkelse + 9,6 mm (1,5 mm bunn gir 11,1 mm), og klossene står på bunnen. Uten bunn er høyden 9,6 mm, og rammen er åpen gjennom.
* **Bunnen** er flat og en del av samme legeme som veggene. Den følger rammens form, og utsparinger er åpne gjennom den.
* **Merkefelt** (valgfritt): en flat blokk utenfor veggen, like høy som rammen, langs den lengste rekken av ruter på valgt side (foran, bak, venstre, høyre). Feltet slås sammen med ytterkanten i 2D før ekstrudering, så det er én del. Det ligger alltid utenfor åpningen.
* **Tallmerking:** mengden eller en kort egen tekst (høyst 12 tegn, for eksempel «8 + 8»). Teksten er innfelt (standard) eller opphøyd på toppen av feltet, og står rett vei sett fra feltets side.
  * Størrelsen tilpasses feltet. Får teksten ikke plass med minst 3,5 mm høye tegn, får læreren en melding, og rammen kan ikke lastes ned før teksten er kortere eller feltet større.
  * Innfelt tekst er høyst så dyp at det er 1,5 mm materiale under.
* **Ingen brukertekst til OpenSCAD:** formen sendes som en liste med tall (rutenes kolonne og rad), og teksten som en liste med tegnkoder (`chr()` i SCAD). `ScadGenerator` godtar bare tall og flate lister med tall.
* **Tekstbredde:** Liberation Sans Bold har samme bredder som Helvetica Bold, så `LegoText` regner ut bredden fra FPDFs metrikkfil før OpenSCAD kjøres. Målt i OpenSCAD er sifrene ca. 0,98 · `size` høye, og blekkbredden er høyst ca. 1,42 · bredde i em · `size` (beregningen bruker 1,45). Testene kontrollerer i STL-fila at teksten ligger innenfor feltet.
* **Kontroll av hver STL:** lukket og manifold, eksakte ytre mål og riktig Euler-tall (2 − 2 · antall gjennomgående hull: én åpning uten bunn pluss én per utsparing).

### Forhåndsvisning

* **Valgt ramme** eller **alle rammer** på rad med regnetegnene mellom. Valgt ramme er mørkere.
* Gjennomsiktige 1 × 1-klosser med knotter i hver knottplass viser hvor mye rammen rommer. De lages i nettleseren og er aldri med i STL-fila. De kan slås av.
* Under visningen står rammestripa (form, mengde, regnetegn mellom rammene, Ny ramme, Dupliser, Slett) og målene for valgt ramme: kapasitet, innvendig, utvendig (med og uten merkefelt), total og innvendig høyde, og etikett- og tekststørrelse.

### Flere rammer og regnestykker

* Opptil 12 rammer per prosjekt. Hver ramme har egen form, tykkelser, bunn, merkefelt og tekst.
* «Dupliser» kopierer alle innstillingene til en ny ramme rett etter.
* Regnetegnet etter hver ramme (+, −, ×, ÷, =) velges i stripa. Tegnene vises i stripa, i 3D-visningen, i PDF-oversikten og eventuelt som papirkort på 30 × 30 mm. De lages ikke som 3D-modeller.

### Eksport

* **STL for valgt ramme:** `lego-ramme-01-4x4-16.stl` (nummer, mål for rektangler, mengde). Uregelmessige former får bare nummer og mengde: `lego-ramme-02-10.stl`.
* **Alle rammer (ZIP):** `regn-med-lego-3-rammer.zip` med én STL per ramme og `oversikt.txt`.
* **PDF med etiketter og oversikt** (`LegoPdf`, samme stil som de andre PDF-ene):
  * **Oversikt:** regnestykket, og for hver ramme formen, mengden, innvendige og utvendige mål, høyden, veggen, klaringen, merkefeltet, STL-fila og etikettene. Til slutt står en merknad om at passformen er uprøvd.
  * **Etiketter i faktisk størrelse:** én per eksemplar, nøyaktig feltets mål minus 1 mm marg på hver side, med klippelinjer og klippemerker.
    * Innholdet er mengden, egen tekst eller tom etikett til håndskrift.
    * Øverst står et tydelig 100 %-varsel, og nederst er det en kontrollinje på 100 mm.
    * Regnetegnene kan tas med som papirkort.
* Store prosjekter får ikke plass i en adresse. ZIP og PDF hentes derfor med POST og prosjektet som JSON. GET med `project=<JSON>` virker også.

### Passform

Passformen mot ekte klosser er **uprøvd** til en ramme er skrevet ut og testet. 8 mm per knottplass og 0,2 mm klaring per side er startverdier. Klossene har 0,1 mm luft per side i seg selv, og printeren kan gi for store eller for små mål. Skriv ut én 2 × 2- og én 4 × 4-ramme først, og juster klaringen.

## Kulespill

Et kulespill med ni lommer, tegnet i SolidCraft (`kulespill.sc`), og en bunn med lomme til et ark (`kulespill_bunn.sc`). Under hver lomme er det et gjennomgående hull på Ø20 mm. Arket mellom bunnen og spillet syns derfor gjennom hullene, og læreren bestemmer hva som står der. En klinkekule i glass blir liggende i lommen, og innholdet kan leses gjennom den.

### Spillet og bunnen

| Del | Fil | Mål |
|---|---|---|
| Kulespill | `public/assets/kulespill/kulespill.stl` | 160 × 250 × 18 mm, rund topp (R80), utskytingsbane til høyre |
| Bunn | `public/assets/kulespill/kulespill-bunn.stl` | 166 × 256 × 5 mm: 2 mm gulv, 3 mm kant, lomme 161 × 251 mm og to grephakk |

* Filene er eksportert fra SolidCraft og er ikke parametriske i appen. Endres modellen, eksporteres STL-ene på nytt, og hullkoordinatene i `app/config/models/marble_game.php` oppdateres fra modellen.
* Kanten på bunnen (5 mm over bordet) slutter under stempelhullet foran i spillet, som starter ca. 6,6 mm over bordet.
* Hullsentrene (mm, origo i sentrum av den runde toppen): (−9,5; 12,5), (−59,5; −37,5), (40,5; −37,5), (−59,5 / −9,5 / 40,5; −87,5) og (−59,5 / −9,5 / 40,5; −137,5).

### Arkene

Læreren velger:

* **I hullene:** tall (fra–til, eventuelt ulike), regnestykker (regnearter + − × :, svar opptil 10, 20, 50 eller 100, bare hele og positive svar, gangetabellen opptil 10), bokstaver A–I eller ni symboler i farger. Bokstaver og symboler får verdier (fra–til) på et eget **verdiark**.
* **Oppgaver:**
  * *Kulerunde:* to tabeller (Runde 1 og 2) med 2–8 kuler, der eleven skriver det som står i hullet, verdien og summen.
  * *Regn med verdiene* (bokstaver og symboler): stykker som A + B – C med 2–5 ledd, en rute under hvert ledd til verdien og en svarrute. Kan begrenses til at summen aldri er under null underveis.
  * *Finn hullene:* «[ ] + [ ] = 13» med to eller tre hull. Summene er ulike, og fasiten har alle løsningene.
  * *Fasit* for læreren.
* **Varianter:** 1–10 sett med ulike verdier. Med tall og regnestykker får hver variant sitt eget hullark. Med bokstaver og symboler er hullarket det samme for alle, og variantene har hvert sitt verdiark og oppgaveark.
* **Lag stykker** gir et nytt tilfeldig frø (`seed`). Alt innhold lages deterministisk av frøet (`MarbleRandom`, xorshift32), så samme valg gir alltid de samme arkene.

**Hullarket** er A4 i 1:1. Arket på 160 × 250 mm ligger midt på siden med hjørnemerker, omrisset av den runde toppen og en kontrollinje på 100 mm. Innholdet står midt over hvert hull, innenfor Ø17 mm. Alle hullene på et ark har samme tekststørrelse: den største som får plass i alle.

### Forhåndsvisning = PDF

`MarbleSheets` legger ut alle sidene som enkle tegneelementer i mm (rektangel, linje, sirkel, mangekant, tekst med størrelse og justering). Tekstbredder måles med FPDFs Helvetica-metrikk. `MarblePdf` tegner elementene i PDF-en, og PDF-fanene viser den samme PDF-en (se «Toppfelt, arkfaner og forhåndsvisning av PDF-en»). Hullarket er stående; verdiark, oppgaver og fasit er liggende med to spalter. Minus skrives som tankestrek (–), som har samme bredde som sifrene og finnes i PDF-fontene.

I **3D-visningen** står spillet på bunnen, og hullarket for siden som er valgt, er lagt som tekstur på arket. «Løft spillet og se arket» løfter spillet 45 mm.

API: `POST api.php?action=describe&model=marble` og `POST api.php?action=pdf&model=marble` med valgene som JSON (GET med de samme nøklene virker også).

## Modellbeskrivelse (JSON) for M+

`DiceModel::describe()` gir en selvstendig beskrivelse av modellen. Den returneres fra `generate` og fra `api.php?action=describe&…`, og kan senere sendes til M+ for å lage tilpasset dekor:

```json
{
  "model": "dice_cube",
  "version": 1,
  "units": "mm",
  "dimensions": { "width": 30, "height": 30, "depth": 30 },
  "corner_radius": 2,
  "pips": { "style": "recessed", "diameter": 3, "depth": 0.5 },
  "faces": { "top": "1", "bottom": "6", "front": "2", "back": "5", "right": "3", "left": "4" },
  "label": { "width": 25.4, "height": 25.4, "decor": "pips" }
}
```

* `version` økes når betydningen av feltene endres.
* `faces` er tekst, slik at senere innhold som tall, symboler og bilde-ID-er får plass uten å endre strukturen.
* `pips.style` er `recessed` eller `none`. Senere kan `raised` komme til.
* Det finnes ingen direkte avhengighet til eksisterende M+.

## API

Modellen velges med `model=` (`dice`, `fractions`, `base10`, `rods`, `solids`, `lego` eller `marble`, se `app/config/models.php`). Uten `model` brukes terningen, så alle eldre kall virker som før.

| Metode og URL | Beskrivelse |
|---|---|
| `GET api.php?action=schema[&model=…]` | Parametere, grenser og standardverdier (+ dekortyper, farger, navn) |
| `POST api.php?action=generate[&model=…]` | Lager alle STL-delene. Svar: `parts` (token, `stl_url`, filnavn, antall, kontrollrapport per del), modellbeskrivelse og advarsler. Terningen har i tillegg `id`, `stl_url`, `filename` og `check` på toppnivå som før |
| `GET api.php?action=stl&id=…[&download=1]` | Én STL-fil |
| `GET api.php?action=zip&model=…&…` | Alle STL-delene og `oversikt.txt` i én ZIP |
| `GET api.php?action=pdf[&model=…]&…[&download=1]` | PDF |
| `GET api.php?action=describe[&model=…]&…` | Modellbeskrivelse (også POST med JSON) |

Eksempler:

```text
POST api.php?action=generate&model=fractions
     {"diameter":100,"thickness":3,"clearance":0.3,"edge_radius":0.5,"labels":true,"label_depth":0.4,"denominators":[2,3,4]}
GET  api.php?action=zip&model=fractions&diameter=100&denominators=2,3,4&labels=1
GET  api.php?action=pdf&model=fractions&diameter=100&denominators=2,3,4

POST api.php?action=generate&model=base10
     {"unit":10,"edge_radius":0.3,"grid":true,"groove_depth":0.4,"groove_width":0.6,"hollow":true,"wall":2,
      "types":["ener","tier","hundrer","tusener"],"count_ener":20,"count_tier":10,"count_hundrer":10,"count_tusener":1}
GET  api.php?action=zip&model=base10&unit=10&types=ener,tier,hundrer&count_ener=20&count_tier=10&count_hundrer=10
GET  api.php?action=pdf&model=base10&types=ener,tier,hundrer,tusener&count_tusener=2

POST api.php?action=generate&model=rods
     {"unit":10,"edge_radius":0.5,"grid":true,"groove_depth":0.3,"groove_width":0.5,"labels":true,"label_depth":0.4,
      "lengths":[1,2,3,4,5],"count_1":20,"count_2":10,"count_3":10,"count_4":10,"count_5":10,"color_mode":"classic"}
GET  api.php?action=zip&model=rods&lengths=1,2,5,10&count_1=10&count_2=5&count_5=4&count_10=2
GET  api.php?action=pdf&model=rods&lengths=2,4,8&color_mode=single&color=%23f36d21

POST api.php?action=generate&model=solids
     {"shape":"cone","cone_diameter":60,"cone_height":70,"hollow":true,"wall":2,"tab_width":7}
GET  api.php?action=pdf&model=solids&shape=cylinder&cylinder_diameter=100&cylinder_height=150&document=net
GET  api.php?action=pdf&model=solids&shape=cylinder&cylinder_diameter=100&cylinder_height=150&document=net&net_print=scaled
GET  api.php?action=pdf&model=solids&shape=pyramid&document=tasks&level=3
GET  api.php?action=describe&model=solids&shape=triprism

POST api.php?action=generate&model=lego
     {"frames":[{"rows":["1111","1111"],"operator":"+"},{"rows":["11","11"],"operator":"="},
                {"rows":["1111","1111","1111"],"field":"label","copies":2}],"operator_cards":true}
POST api.php?action=zip&model=lego   (samme JSON)
POST api.php?action=pdf&model=lego   (samme JSON)
GET  api.php?action=describe&model=lego&project=%7B%22frames%22%3A%5B%7B%22rows%22%3A%5B%2211%22%5D%7D%5D%7D
```

`zip` og `pdf` godtar også POST med parameterne som JSON (alle modeller). Det trengs for prosjekter som er for store for en adresse.

Feil returneres som `{"ok":false,"error":"…","errors":[{"field":"…","message":"…"}]}` med statuskode 400, 404, 422, 500, 501, 503 eller 504.

## Legge til nye modellparametere

1. Legg variabelen inn i `models/dice/cube.scad` med en standardverdi.
2. Legg den inn i `app/config/models/dice_cube.php` under `parameters`, med `label`, `unit`, `min`, `max`, `default`, `step` og `scad` (variabelnavnet i SCAD).
3. Trengs regler som avhenger av flere parametere, legges de i `DiceModel::validateCombination()`.
4. Påvirker parameteren ytre mål, oppdateres `DiceModel::expectedBounds()`.
5. Legg til tester i `tests/run.php`.

Brukergrensesnittet lager kontrollen automatisk fra `schema`. Det samme gjelder for brøksirklene (`fraction_circles.php`, `FractionModel`). Der får parametere med `'group' => 'label'` plass under «Brøkmerking».

## Legge til en ny modellgenerator

Eksempel: en modell `blocks` (tierstaver) på siden `?side=tierstaver`.

1. **Geometri:** lag `models/blocks/model.scad`. Alle variabler skal ha standardverdier og bare være tall. Tekst lages inne i SCAD-fila, slik som brøkmerkingen.
2. **Definisjon:** lag `app/config/models/blocks.php` med `id`, `version`, `scad`, `filename` og `parameters` (`label`, `unit`, `min`, `max`, `default`, `step`, `scad`).
3. **Modellklasse:** lag `app/services/BlocksModel.php` som implementerer `ParametricModel`, med en statisk `load(string $configDir)`. Viktigst er:
   * `validate()`: bruk `ParameterValidator::numbers()` for tallene og legg til egne regler.
   * `parts()`: én oppføring per STL-fil, med `variables`, `bounds` (forventede ytre mål), `filename`, `count` og `cache_key`.
   * `renderPdf()`: arv fra `WorksheetPdf` for oppgavesider i samme stil (topptekst, bunntekst, nummererte oppgaver med svarruter og -linjer, merket papiroppgave), og bruk `LabelPdf` til tegning (sirkel, sektor, polygon, eksakte rektangler, 100 mm-linjal, norske tegn).
4. **Registrer modellen** i `app/config/models.php`: `'blocks' => BlocksModel::class`. Kjør `composer dump-autoload`.
5. **Siden:** legg den inn i `app/config/pages.php` (tittel, gruppe, tema, beskrivelse, forhåndsbilde), og lag `templates/pages/blocks.php` og `public/js/pages/blocks.js`. Gjenbruk `ui.js` (kontroller, meldinger, nedlasting) og `ModelViewer` fra `viewer.js` (scene, lys, rutenett, kamera, visninger). Underklassen trenger bare å laste STL-ene og plassere dem.
6. **Tester** i `tests/run.php`: validering, at STL-ene er gyldige og har riktige mål, og PDF.

Alt annet er felles og trenger ingen endring: API-endepunktene (`schema`, `generate`, `stl`, `zip`, `pdf`, `describe`), OpenSCAD-kjøringen med tidsgrense og kø, STL-kontrollen, hurtigbufferen, opprydding og menyen.

## Tester

```bash
php tests/run.php          # alle tester (ca. 40 s)
php tests/run.php brøk     # bare tester med «brøk» i navnet
```

Testene dekker 172 tilfeller.

**Kulespill (12, i `tests/marble.php`):**

* hullene ligger på arket og innenfor spillet, og arket står midt på A4
* validering: standardverdier, grenser, områder med plass til ulike verdier, regnearter, frø og farlige verdier
* samme frø gir samme ark, mens nytt frø og andre varianter gir nye verdier
* tall i området, regnestykker med riktige, hele og positive svar (alle regnearter og områder), «Regn med verdiene» med riktige svar og aldri under null, og «Finn hullene» med ulike summer og alle løsningene
* sidene for alle innholdstyper og varianter, også oppgaver som går over flere sider
* all tekst og alle ruter ligger innenfor A4 og over bunnteksten, og teksten og symbolene i hullene får plass innenfor Ø17 mm
* PDF-en har like mange sider og de samme tekstene som forhåndsvisningen
* STL-filene er hele binære STL-er

**Regn med Lego (16, i `tests/lego.php`):**

* standardverdier, og 4 × 4: 16 knottplasser, 32,4 × 32,4 mm innvendig og 11,1 mm høyde (9,6 mm uten bunn)
* veggtykkelse endrer bare utvendige mål, bunntykkelse bare total høyde, og innvendig bredde = kolonner · 8 + 2 · klaring
* former: trimming, L-form, utsparing og bukt; ugyldige former (tom, flere deler, bare hjørnekontakt, utsparing uten bunn, ugyldig rutenett) med forståelige meldinger
* validering av tall (grenser, desimalkomma, farlige verdier), tekst, valg, antall, prosjekt som JSON, og hvilken ramme en feil gjelder
* merkefeltet overlapper aldri åpningen (fire sider, fire former), og for lang tekst avvises med melding
* flere rammer: regnestykket «8 + 4 = 12», filnavn, hurtigbuffer og ZIP-oversikt
* SCAD-variablene er bare tall og lister med tall; strenger i lister avvises
* STL fra OpenSCAD:
  * 4 × 4 med og uten bunn: mål, volum lik beregningen, åpen gjennom uten bunn, bunnen ved 1,5 mm og åpningen ved ±16,2 mm
  * L-form uten punkter inne i åpningen (ingen skillevegger), med klaring i det innvendige hjørnet
  * utsparing: hull gjennom, med vegg 0,2 mm fra knottplassene
  * ytterverdier for vegg, klaring og bunn
  * innfelt og opphøyd tekst innenfor feltet på alle sider, også lange uttrykk og «Åtte»; innfelt tekst går aldri gjennom
* PDF: oversikt, 100 %-varsel, 100 mm-linjal, etiketter med nøyaktig feltets mål minus 2 mm, regnetegn-kort av og på, og tekst innenfor arket for 1 og 12 rammer

**Romfigurer (31, i `tests/solids.php`):**

* validering: standardverdier fra spesifikasjonen, grenser, ugyldige figurer, valg og farlige verdier
* geometri for hver figur:
  * kube: 6 kvadrater, 12 kanter på 40 mm, 8 hjørner
  * prisme: overflate og volum
  * pyramide: skråhøyden √(h² + (g/2)²), og trekantene er g · s / 2 (ikke g · h / 2)
  * trekantprisme: skrå sider, rektangler og trekanter
  * sylinder og kjegle: 3/2/0 og 2/1/1, omkrets, sektorvinkel og buelengde = π · d
* Eulers formel for alle mangeflatene ved alle ytterverdier, og at normalene peker ut og hjørnene ligger i planet
* nett:
  * alle varianter har alle flatene, med samme areal og sidelengder som i 3D
  * kubens korsnett
  * sylinderens rektangel π · d × h og kjeglens sektor (radius s, vinkel 2πr/s)
  * pyramidetrekantene har høyden s
* limfliker: ingen overlapp med flater eller andre fliker (20 målsett, flik 5 og 12 mm). De endrer ikke flatene, og hver flik har nøyaktig én brettelinje.
* A4: standardmålene får plass på ett ark i 1:1. Store nett deles på ark i 1:1 som dekker hele nettet, eller skaleres bare når det er valgt, med varsel.
* PDF-nett:
  * A4, 1:1-tekst, kontrollinje på nøyaktig 50 mm, kubens kanter 40 mm og sylinderens rektangel π · d
  * flersidig utskrift med oversikt og skjøter, og skalert utskrift tydelig merket
* PDF-oppgaver: seks sider for alle figurer og nivåer, tekst innenfor arket, oppgaver som passer til figur og nivå, og fasit fra geometrimodellen
* STL fra OpenSCAD:
  * alle figurer ved ytterverdiene, massive og hule: lukket, manifold, riktige mål, to skall når hul, og volum lik geometrimodellen innen 0,1 %
  * ingen overheng, heller ikke i hulrommet
  * veggtykkelse
  * mangekantavviket for sylinder og kjegle

**Tallstaver (23):**

* validering:
  * standardverdier og grenser
  * stavlengder og antall, også farlige verdier
  * farger: de tre modusene og ugyldige fargekoder
  * reglene som hindrer at avrunding og riller svekker små staver
* deler: filnavn, antall og mål n · enhet, og at antall og farger ikke påvirker hurtigbufferen
* tallmerking: for 5 enhetslengder, 3 avrundinger, med og uten inndeling og alle 10 staver ligger tallet på den flate toppen, utenfor rillene, eller det utelates med advarsel
* ZIP-oversikt og modellbeskrivelse
* STL fra OpenSCAD:
  * alle ti stavene er lukkede og manifold, med eksakte mål
  * 6 og 20 mm enhet med største avrunding, inndeling og tall
  * endeflatene er plane og hele (arealet måles i fila), og 2 + 3 er like lang som 5
  * n − 1 riller på oversiden ved k · enhet, og ingen andre steder
  * tallet ligger innenfor boksen og utenfor rillene
  * ingen overheng
  * volumoverslaget, innen 0,5 %
* kombinasjoner:
  * alle oppdelinger, og at antall tas hensyn til
  * nettleserens `rod-combos.js` gir det samme som PHP (krever Node.js, ellers hoppes testen over)
* oppgavegeneratoren:
  * alle typer og nivåer for åtte sett: stavene finnes i riktig antall, tegneoppgaver er merket, og fasiten stemmer med stavene
  * brøk bare der den kan vises nøyaktig
* PDF:
  * sju sider, der side 7 er liggende
  * tekst innenfor arket for ti sett
  * tegneoppgaver bare når de trengs
  * banene på arbeidsarket er like brede som stavene

**Base 10 (22):**

* validering:
  * standardverdier og grenser
  * objekttyper og antall
  * reglene som hindrer at riller og vegger svekker klossene
* deler: filnavn, antall og relative mål (volum 1 : 10 : 100 : 1000)
* hurtigbuffernøkkel og ZIP-oversikt
* STL fra OpenSCAD:
  * alle fire klossene er lukkede og manifold, med eksakte mål
  * ener og tier med 6 og 15 mm enhetslengde
  * ni riller rundt tieren, målt på alle fire sidene
  * 10 × 10-rutenett på begge flatene av hundreren
  * rutenett på alle sidene av den massive tusenerkuben
  * hul tusener: åpen overside og fem sider med rutenett
  * rutenett av
  * kantavrunding 0, 0,5 og 1 mm
  * volumoverslaget, innen 1 %
  * ingen overheng
* tallbygger: plassverdier for 0, 7, 24, 105, 243, 999, 1234 og 9999, og tallord
* regning: simulert veksling, inkludert når det mangler klosser
* oppgaver i åtte sett: at klossene finnes, riktige svar, og at papiroppgaver er merket
* PDF:
  * seks sider, der side 6 er liggende
  * tekst innenfor arket for små og store sett
  * innholdet på oppgavesidene

**Brøksirkler og felles arkitektur (27):**

* modellregisteret og terningens felles grensesnitt
* at alle menysider har modell, mal, skript og bilde
* validering: standardverdier, grenser, nevnerlister og merking på/av
* geometri: segmenter, ytre mål, og plassering av brøkmerket for alle nevnere ved ytterverdier
* STL fra OpenSCAD:
  * 1/2, 1/3, 1/4 og 1/12
  * minste og største diameter og tykkelse
  * spalten ved klaring 0 og 0,8 mm, målt i fila
  * volum
  * merking på og av
  * ingen overheng
  * hurtigbuffer per brikke
* oppgaver: bruker bare brikker i settet (11 kombinasjoner), riktige svar på likeverdige brøker, hel sirkel av to sett
* PDF: fire A4-sider og tekst innenfor arket for alle kombinasjoner, sirkeldiameter i full størrelse, og at erstatningssiden brukes ved behov
* ZIP-oversikten

**Terning (40):**

* standardverdier og parametergrenser
* ugyldige og farlige verdier
* kryssvalidering og etikettstørrelse
* JSON-strukturen
* STL-kontroll med lukkede, åpne, feilorienterte og degenererte nett
* STL-generering: mål, topologi og antall prikker målt via volum
* rektangulær variant og ytterpunkter
* hurtigbuffer
* feil fra OpenSCAD: binær mangler, syntaksfeil, tom modell og tidsavbrudd
* stier utenfor `models/`
* PDF: A4-størrelse, eksakt etikettstørrelse, 100 mm målestokk, sidenavn, antall prikker, alle dekortyper, og at prikkmønstrene er identiske med SCAD

Tester som krever OpenSCAD, hoppes over hvis det ikke er installert.

### Manuell testplan (må gjennomføres fysisk)

0,4 mm dyse og 0,2 mm laghøyde.

- [ ] Generer en 30 mm terning og last ned STL.
- [ ] Åpne i Bambu Studio, PrusaSlicer og Cura: ingen feil eller reparasjonsvarsler, og mål 30 × 30 × 30 mm.
- [ ] Print og kontroller at alle prikkene er tydelige, også i bunnen.
- [ ] Skriv ut PDF-en med 100 % og mål målestokken (100 mm) og etikettene.
- [ ] Klipp ut, lim på og kontroller at etikettene ikke dekker de avrundede kantene.
- [ ] Kontroller at monteringsveiledningen stemmer: 1 opp, 2 mot deg, 3 til høyre.

Brøksirkler:

- [ ] Last ned ZIP med standardsettet. Åpne alle STL-filene i Bambu Studio, PrusaSlicer og Cura: ingen reparasjonsvarsler, og brikkene ligger flatt uten støtte.
- [ ] Print ett komplett sett (for eksempel 4 × 1/4) og legg brikkene sammen: sirkelen skal bli 100 mm, og brikkene skal gli inn uten å presse. Gjenta med klaring 0 og 0,8 mm for å finne beste standard for skolens printere.
- [ ] Kontroller at brøkmerkingen er lesbar, også på 1/8 og 1/12.
- [ ] Skriv ut PDF-en med 100 %. Mål linjalen, og legg de printede brikkene på sirklene på side 4.
- [ ] Gå gjennom oppgavene på side 2 og 3 med de printede brikkene.

Base 10:

- [ ] Last ned ZIP med alle fire typene. Åpne STL-filene i Bambu Studio, PrusaSlicer og Cura: ingen reparasjonsvarsler, og ingen støtte foreslås.
- [ ] Print en ener, en tier og en hundrer med 10 mm enhet. Mål 10 × 10 × 10, 100 × 10 × 10 og 100 × 100 × 10 mm, og legg ti enere langs tieren.
- [ ] Kontroller at rillene (0,4 × 0,6 mm) syns tydelig og ikke fylles igjen. Prøv også 6 mm enhet med 0,6 mm riller.
- [ ] Print den hule tusenerkuben med åpningen opp: ingen støtte, rette vegger, og rutenettet syns på alle fem sidene. Kontroller at hjørnene ikke løfter seg.
- [ ] Sammenlign filamentforbruket i sliceren med overslaget i PDF-en og ZIP-oversikten.
- [ ] Skriv ut PDF-en og gå gjennom side 2–5 med de printede klossene. Legg klossene i plassverditabellen på side 6.

Tallstaver:

- [ ] Last ned ZIP med alle ti lengdene. Åpne STL-filene i Bambu Studio, PrusaSlicer og Cura: ingen reparasjonsvarsler, og ingen støtte foreslås.
- [ ] Print stav 1, 2, 3 og 5 med 10 mm enhet. Legg 2 og 3 ende mot ende ved siden av 5: de skal være like lange. Kontroller at endene er plane.
- [ ] Print med enhetsinndeling og tallmerking: rillene (0,3 × 0,5 mm) og tallene skal være tydelige og ikke fylles igjen. Prøv også 6 mm enhet.
- [ ] Skriv ut PDF-en med 100 %. Mål linjalen, og legg de printede stavene i banene på side 7.
- [ ] Gå gjennom oppgavene på side 2–6 med stavene. Prøv også et lite sett, for eksempel bare 2, 4 og 8, og kontroller at oppgavene passer.
- [ ] Sammenlign filamentforbruket i sliceren med overslaget.

Romfigurer:

- [ ] Last ned STL for alle seks figurene, massive og hule. Åpne dem i Bambu Studio, PrusaSlicer og Cura: ingen reparasjonsvarsler, og ingen støtte foreslås.
- [ ] Print kube, pyramide og kjegle. Mål dem med skyvelære (±0,3 mm).
- [ ] Print en hul kube og en hul sylinder med 2 mm vegg: taket i hulrommet skal skrives ut uten å henge ned.
- [ ] Skriv ut PDF-nettet for alle seks figurene i 100 % på tykt papir. Mål kontrollinjen (50 mm), klipp, brett og lim. Papirfiguren skal ha samme mål som 3D-modellen, og limflikene skal ligge pent på innsiden.
- [ ] Skriv ut et stort nett (sylinder 100 × 150 mm) på flere ark. Sett arkene sammen etter skjøtekantene og passmerkene, og kontroller målene.
- [ ] Gå gjennom oppgave-PDF-en på alle tre nivåer for én figur, sammen med 3D-modellen og papirfiguren.

Regn med Lego:

- [ ] Skriv ut en 2 × 2- og en 4 × 4-ramme med standardverdier, med og uten bunn. Prøv ekte LEGO-klosser: de skal gå lett ned og ikke falle ut når rammen snus. Juster klaringen (0,1–0,3 mm) etter resultatet, og noter verdien for printeren.
- [ ] Mål innvendig 32,4 × 32,4 mm og total høyde 11,1 mm (9,6 mm uten bunn) med skyvelære.
- [ ] Skriv ut en L-form og en ring med utsparing: klossene skal få plass i det innvendige hjørnet, og utsparingen skal være åpen gjennom bunnen.
- [ ] Kontroller at innfelt og opphøyd tekst er lesbar, også på en 1 × 6-ramme.
- [ ] Skriv ut PDF-en i 100 %, mål linjalen, klipp ut en etikett og legg den på etikettfeltet: den skal ha 1 mm luft på hver side.
- [ ] Åpne ZIP-filene i en slicer: ingen reparasjonsvarsler, og ingen støtte trengs.

Kulespill:

- [ ] Skriv ut kulespillet og bunnen. Arket (160 × 250 mm) skal ligge flatt i lomma, og spillet skal stå stødig oppå.
- [ ] Skriv ut hullarket i 100 %, mål kontrollinja (100 mm) og klipp langs hjørnemerkene. Innholdet skal stå midt i alle ni hullene.
- [ ] Kontroller at innholdet kan leses gjennom en klinkekule i glass, også lange regnestykker.
- [ ] Kontroller at stempelet ikke tar borti kanten på bunnen.

## Drift på server

### Docker på M+-serveren

M+ 3D kjører som to Docker Swarm-stakker på samme server som M+. HAProxy sender domenet til porten:

| Domene | Stakk | Port | Tilbake-lenker godtas fra |
|---|---|---|---|
| `3d.mpluss.no` | `mplus3d` | 8085 | `app.mpluss.no` |
| `3dtest.mpluss.no` | `mplus3d-test` | 8086 | `apptest.mpluss.no`, `test.mpluss.no` |

* `docker/Dockerfile` lager bildet `mplus3d/php-fpm:1`: PHP 8.2-FPM med `gd` og `zip`, Composer og OpenSCAD (AppImage, låst versjon kontrollert mot SHA256, Manifold).
* `docker-compose.yml` har nginx, php-fpm (maks 4 CPU og 4 GB) og `cleanup`, som kjører `bin/cleanup.php` hvert kvarter. Koden monteres inn fra git-klonen, så `git pull` oppdaterer appen direkte.
* Hvert miljø har sin egen klone på serveren, for eksempel `~/mplus3d` (greinen `main`) og `~/mplus3d-test` (greinen `test`). Oppdatering: `docker/deploy.sh prod` eller `docker/deploy.sh test` fra klonen. Skriptet kjører `git pull`, bygger bildet hvis det mangler, installerer avhengigheter, gir PHP skrivetilgang til `storage/` og ruller ut stakken.
* Ny OpenSCAD-versjon: endre `OPENSCAD_VERSION` og `OPENSCAD_SHA256` i `docker/Dockerfile`, øk taggen (`:2`) der og i `docker-compose.yml` og `deploy.sh`, og kjør `deploy.sh` på nytt.
* Teste lokalt: `MPLUS3D_PORT=8095 MPLUS_RETURN_HOSTS=localhost docker compose -p mplus3d-local up -d` (etter `docker build -t mplus3d/php-fpm:1 docker`), og åpne <http://127.0.0.1:8095>. Stopp med `docker compose -p mplus3d-local down`.

### Generelt

* Webroten skal være `public/`. `app/`, `models/`, `storage/` og `vendor/` skal ikke kunne nås fra nett. `storage/.htaccess` stopper dette på Apache som en ekstra sikring.
* Webserveren må kunne skrive til `storage/`.
* Cron: `*/15 * * * * php /sti/til/mplus-3d/bin/cleanup.php`
* Ressursbegrensning på Linux: sett `openscad.wrapper` i `app/config/config.php`, for eksempel `['nice', '-n', '10', 'prlimit', '--as=2000000000', '--']`.
* `display_errors` er slått av i `public/api.php`.

## Kjente begrensninger

* **Bare nedsenket merking.** Opphøyd merking er ikke med i versjon 1. Modellen og merkeberegningen er laget slik at den kan legges til.
* **Smale brikker kan mangle merking.** Ved 50 mm diameter blir 1/10 og 1/12 for smale til lesbar tekst (minst 4 mm). Læreren får beskjed, og brikkene er ellers gyldige.
* **Forhåndsvisningens farger finnes ikke i STL.** STL har ikke farger. Fargen velges med filamentet.
* **Den ytre diameteren følger STL-modellen.** Klaringen tas fra de radielle kantene, ikke fra ytterbuen. Hvis printeren gir litt større deler, blir sirkelen tilsvarende større. Avvik fra printeren kompenseres i sliceren (for eksempel med «XY-kompensasjon»).
* **Oppgavene er faste maler.** De velges ut fra nevnerne, men er ikke tilpasset trinn. Det er heller ikke laget fasit.
* **Genereringstid.** Med OpenSCAD 2021.01 (CGAL) tar en brikke med merking flere sekunder. Med Manifold tar den ca. 0,15 s. Et nytt sett med fem nevnere lages på rundt ett sekund med Manifold.
* **Base 10: hundrere og tusenere er større enn rutene i plassverditabellen.** Med 10 mm enhet er en hundrer 100 mm bred, mens en kolonne på liggende A4 er 54 mm. PDF-en sier at de kan legges over kolonnen eller tegnes.
* **Base 10: materialforbruket er et overslag.** Det bygger på 2 vegger og 15 % fyll. Sliceren gir nøyaktige tall.
* **Base 10: tallbyggeren er bare en visning.** Den viser tall med ett siffer per plass, altså færrest mulig klosser. Andre måter å legge tallet på, som 12 tiere for 120, finnes på papiret (PDF side 4), men ikke i 3D-visningen.
* **Tallstaver: tegneoppgaver er generelle.** Kan en oppgavetype ikke lages med settet, hentes tegneoppgavene fra et fullt sett. De handler derfor om staver settet ikke har, og må løses på arbeidsarket.
* **Tallstaver: tallkombinasjonene viser høyst seks måter.** Antallet måter står under feltet (for eksempel 500 for lengde 20), men bare et variert utvalg vises i 3D.
* **Tallstaver: ingen fasit i PDF-en.** Generatoren har fasit for hver oppgave (`answer`), og testene bruker den, men den skrives ikke ut ennå.
* **Romfigurer: krumme flater i STL er mangekanter** med 128 sider. Avviket fra nettet er under 0,05 % (se «Polygonal STL og eksakt nett»).
* **Romfigurer: hulrommet er lukket.** Det kan ikke fylles med vann eller sand, og taket i det er avrundet til 45°. Volumoppgavene gjelder figurens ytre mål.
* **Romfigurer: papirets tykkelse er ikke tatt med i nettet.** Med vanlig papir er feilen under 0,5 mm.
* **Romfigurer: limflikene har fast vinkel.** Er en kant kort, blir flikens skrå hjørner brattere. Testene kontrollerer at fliker aldri overlapper.
* **Regn med Lego: passformen er uprøvd.** Den må testes med ekte klosser på den aktuelle printeren (se «Passform»).
* **Regn med Lego: merkefeltet følger den lengste rekken av ruter på valgt side.** På uregelmessige former kan feltet derfor bli kort. Velg en annen side om teksten ikke får plass.
* **Regn med Lego: regnetegn finnes bare på papir og i visningen,** ikke som 3D-modeller (første versjon).
* **Regn med Lego: ingen etikettlomme.** Etikettene limes eller tapes på det flate feltet.
* **Menyen er statisk.** Sidene står i `pages.php`. Det finnes ingen innlogging, lisens eller database.

## Beslutninger og avvik fra spesifikasjonen

* **Merkedybde i stedet for «merkehøyde».** Spesifikasjonen sier «merkehøyde 0,4 mm». Ved nedsenket merking er det dybden som styres, så kontrollen heter «Merkedybde» (0,2–0,8 mm).
* **Felles API med `model=`** i stedet for egne endepunkter per modell. Terningens svar har de samme feltene som før, så ingenting i terninggeneratoren måtte endres.
* **Filnavn:** `broeksirkel_1-4.stl` som i spesifikasjonen. ZIP og PDF heter `broeksirkler-<diameter>mm`.
* **Base 10: hul tusener skrives ut med åpningen opp.** Spesifikasjonen foreslo åpen side mot byggeplaten «dersom geometrien tillater det uten støtte». Det gjør den ikke, fordi taket ville blitt en bro på nesten 10 cm. Med åpningen opp blir kuben en kopp uten overheng, og den snus etter utskrift.
* **Base 10: fas mot byggeplaten i stedet for avrunding.** Alle de andre kantene avrundes. Fasen gir ingen overheng, og de ytre målene endres ikke.
* **Base 10: rillene skjæres rundt hele klossen,** også på undersiden og på kantflatene. Da ser klossen lik ut uansett hvordan den snus. Taket i en rille i bunnen er en bro på høyst 1,2 mm, og det er uproblematisk.
* **Base 10: papiroppgaver.** Oppgaver som krever flere klosser enn settet har, merkes «PAPIROPPGAVE» i stedet for å utelates, når de fortsatt er nyttige. Et standardsett uten tusenere får derfor én merket oppgave om veksling til tusener.
* **Felles PDF-grunnlag (`WorksheetPdf`).** Topptekst, bunntekst og oppgaveoppsett er flyttet ut av `FractionPdfGenerator`, så Base 10 gjenbruker dem. Brøk-PDF-ene er kontrollert byte for byte før og etter flyttingen (fire sett), og de var identiske.
* **Modellnavn i API-et** kan nå inneholde sifre (`base10`). Kontrollen er `^[a-z][a-z0-9_]{0,31}$`.
* **Regn med Lego: veggtykkelse høyst 3 mm.** Med klaring opptil 0,5 mm er en utsparing eller bukt på én knottplass da alltid minst 1 mm åpen, og formens topologi er kjent på forhånd (kontrolleres med Euler-tallet).
* **Regn med Lego: ruter som bare møtes i et hjørne avvises,** også inne i en ellers sammenhengende form. Veggen i et slikt hjørne blir så tynn at den ikke kan skrives ut.
* **Regn med Lego: en utsparing krever bunn.** Uten bunn henger veggen rundt utsparingen ikke sammen med resten av rammen.
* **Regn med Lego: merkefeltet er like høyt som rammen.** Da er toppen flat og i samme høyde som rammen, sterk og lett å skrive ut, og den passer til både tekst og etikett. Teksten kan være innfelt (standard) eller opphøyd.
* **Regn med Lego: etiketten er 1 mm mindre enn feltet på hver side,** så den er lett å legge på. Målene står under hver etikett i PDF-en.
* **Regn med Lego: navnet på siden** er «Regn med Lego», som bestilt. Varemerkemerknaden står på siden, i PDF-en og i ZIP-oversikten.
* **Romfigurer: hul modell for alle seks figurene.** Spesifikasjonen åpnet for å begrense hul modell til figurer som kan printes uten støtte. Med 45°-tak i hulrommet går det for alle seks. Ulempen er at veggen blir tykkere øverst i kube, prisme og sylinder. Det står i panelet når hul modell er valgt.
* **Romfigurer: trekantprismet ligger på det største rektangelet.** Da peker ingen flater nedover. Trekantene er likevel grunnflatene i oppgavene og egenskapene.
* **Romfigurer: sirklene i sylinder- og kjeglenettet er egne deler.** Limtennene sitter på rektangelet og sektoren, ikke på sirklene. Det er lettere å lime, og «Utbrettingsfigur» viser fortsatt alle flatene.
* **Romfigurer: valg av visning er avkrysninger.** «Vis flater» og «Vis kanter og hjørner» kan kombineres. Vanlig romfigur er begge av, som spesifikasjonens tre visningsmodi.
* **Romfigurer: fasit i oppgave-PDF-en** som side 6, i tillegg til de fem oppgavesidene i spesifikasjonen.
* **Romfigurer: «Hjørner / toppunkt» for kjeglen.** Spesifikasjonen skiller mellom hjørner og toppunkt. Kjeglen vises med «1 (toppunktet)».
* **Tallstaver: avrunding bare langs staven.** Spesifikasjonen åpnet for dette. Plane, hele endeflater gir nøyaktige lengder når stavene ligger ende mot ende. Kantene mot byggeplaten får fas i stedet for avrunding, som i Base 10.
* **Tallstaver: tallet står i den midterste enheten når inndelingen er på.** For partall er det ingen midterste enhet, så tallet står i enheten til venstre for midten. Da overlapper det aldri en rille, som spesifikasjonen krever.
* **Tallstaver: «Merkedybde»,** som for brøksirklene. Spesifikasjonen kaller den «merkehøyde», men beskriver dybden på nedsenket merking.
* **Tallstaver: tegneoppgaver.** Spesifikasjonen ble avbrutt ved «Dersom en oppgavetype ikke kan genereres med det valgte settet,». Løsningen følger Base 10: oppgavene utelates ikke stille, men erstattes av tydelig merkede tegneoppgaver (TEGNEOPPGAVE), og ingressen forklarer hvorfor.
* **Tallstaver: tallkombinasjoner bruker to eller flere staver.** Staven selv vises øverst som referanse, så «5 = 5» telles ikke som en kombinasjon.

* **Standard etikettstørrelse er 25,4 mm, ikke 29,4 mm (30 mm terning).** Med 2 mm hjørneradius er bare de midterste 26 mm av hver side flate. En etikett på 29,4 mm ville dekket avrundingen, noe den manuelle testen sier ikke skal skje. Med `auto` blir etiketten 29,4 mm når hjørneradius er 0. Etiketten kan fortsatt settes fritt.
* **HØYRE = 3 og VENSTRE = 4** (eksempelet i spesifikasjonen hadde motsatt). Spesifikasjonens krav om motstående sider (1–6, 2–5, 3–4) er oppfylt i begge tilfeller. Valget gir vestlig standardterning, der 1-2-3 møtes mot klokka. Innholdet kan endres under `faces` i `dice_cube.php`.
* **OpenSCAD med Manifold** anbefales. Med 2021.01 tar en terning ca. 25 s, noe som er for tregt til direkte forhåndsvisning. Hurtigbufferen gjør gjentatte verdier umiddelbare uansett versjon.
* **Three.js fra CDN** (jsDelivr, låst til 0.170.0). For bruk uten internett kan filene legges i `public/assets/` og importkartet i `index.php` oppdateres.
* **Ingen testrammeverk.** `tests/run.php` er selvstendig for å unngå ekstra avhengigheter.
