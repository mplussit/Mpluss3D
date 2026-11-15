<?php
declare(strict_types=1);

/**
 * Oppgaver til en romfigur, tilpasset figur, mål og vanskelighetsgrad.
 *
 * Alle tall kommer fra den felles geometrimodellen (Solid::measures(),
 * counts(), faces(), baseArea(), surfaceArea(), volume()), så oppgavene og
 * fasiten stemmer med STL-fila og nettet. Mål vises i cm (1 desimal);
 * avrunding skjer bare i teksten.
 *
 *   Nivå 1  Utforsk: navn, former, flater, kanter og hjørner, telle og sammenligne
 *   Nivå 2  Mål og sammenlign: lengder, areal og enkle beregninger
 *   Nivå 3  Beregn: overflateareal, volum og formler
 *
 * pages() gir fem sider med seksjoner. Hver oppgave har tokens (se
 * WorksheetPdf) og et svar til fasiten.
 */
final class SolidTasks
{
    private array $m;
    private array $c;
    private bool $curved;
    private string $shape;

    public function __construct(private Solid $s, private int $level)
    {
        $this->m = $s->measures();
        $this->c = $s->counts();
        $this->shape = $s->shape();
        $this->curved = in_array($this->shape, ['cylinder', 'cone'], true);
    }

    /** @return list<array{title:string,lead:string,sections:list<array>}> */
    public function pages(): array
    {
        return [
            ['title' => 'Undersøk figuren', 'lead' => $this->lead(1), 'sections' => $this->explore()],
            ['title' => 'Mål figuren', 'lead' => $this->lead(2), 'sections' => $this->measure()],
            ['title' => 'Overflateareal', 'lead' => $this->lead(3), 'sections' => $this->surface()],
            ['title' => 'Volum', 'lead' => $this->lead(4), 'sections' => $this->volume()],
            ['title' => 'Fra papir til romfigur', 'lead' => $this->lead(5), 'sections' => $this->paper()],
        ];
    }

    private function lead(int $page): string
    {
        $name = mb_strtolower($this->s->name());
        return match ($page) {
            1 => "Du trenger 3D-modellen av figuren ($name). Se på den, snu den og kjenn på flatene og kantene.",
            2 => 'Du trenger linjal og 3D-modellen. Mål nøyaktig, og skriv svarene i mm og cm.',
            3 => $this->level === 1
                ? 'Overflaten er alle flatene til sammen, som papiret du trenger for å dekke figuren.'
                : 'Overflatearealet er summen av arealene til alle flatene. Bruk målene fra opplysningene. Svar i cm².',
            4 => $this->level === 1
                ? 'Volumet er hvor mye figuren rommer: hvor mye plass den tar.'
                : 'Volumet måles i cm³. En terning med sider på 1 cm har volumet 1 cm³, og 1 cm³ = 1 ml.',
            default => 'Skriv ut utbrettingsfiguren i 100 % (faktisk størrelse). Den har nøyaktig samme mål som 3D-modellen.',
        };
    }

    /* ------------------------------------------------------- Side 1 */

    private function explore(): array
    {
        $c = $this->c;
        $basic = [
            $this->task('Hva heter figuren?', ['line'], $this->s->name()),
            $this->task('Hvor mange flater har den?', ['box'], $c['curved_faces']
                ? "{$c['faces']} ({$c['curved_faces']} krum)" : (string) $c['faces']),
        ];
        if ($c['curved_faces']) {
            $basic[] = $this->task('Hvor mange av flatene er krumme?', ['box'], (string) $c['curved_faces']);
        }
        $basic[] = $this->task('Hvor mange kanter har den?', ['box'], $c['curved_edges']
            ? "{$c['edges']} (sirkelformede)" : (string) $c['edges']);
        if ($this->shape === 'cone') {
            $basic[] = $this->task('Kjeglen har en spiss. Hva kaller vi den?', ['line'], 'Toppunktet (1)');
        } else {
            $basic[] = $this->task('Hvor mange hjørner har den?', ['box'], (string) $c['vertices']);
        }
        $basic[] = $this->task('Hvilke plane figurer består overflaten av?', ['line'], $this->surfaceText());

        $sections = [['title' => 'Flater, kanter og hjørner', 'intro' => null, 'tasks' => $basic]];

        $look = [];
        if ($this->level <= 2) {
            $look[] = $this->task('Hva er forskjellen på en plan figur og en romfigur?', ['line'],
                'En plan figur har bare lengde og bredde (2D). En romfigur har også høyde (3D).');
        }
        $look[] = $this->task('Hvilke flater er like store?', ['line'], $this->equalFaces());
        if ($this->level >= 2) {
            $look[] = $this->task('Tegn figuren sett rett ovenfra og rett forfra. Hvilke former ser du?', ['line'], $this->views());
            if (!$this->curved) {
                $look[] = $this->task('Hvor mange kanter møtes i hvert hjørne?', ['line'],
                    $this->shape === 'pyramid' ? '3 i hjørnene i grunnflaten, 4 i spissen' : '3');
            }
        }
        $sections[] = ['title' => 'Se nøye', 'intro' => null, 'tasks' => $look];

        if ($this->level === 3) {
            $more = [];
            if (!$this->curved) {
                $more[] = $this->task('Regn ut: antall flater − antall kanter + antall hjørner. Hva får du?', ['box'],
                    "{$c['faces']} − {$c['edges']} + {$c['vertices']} = 2 (Eulers formel gjelder alle slike figurer)");
            }
            $sym = $this->s->symmetryPlanes();
            $more[] = $this->task('Et symmetriplan deler figuren i to speilbilder. Hvor mange symmetriplan har figuren?', ['line'],
                is_infinite((float) $sym)
                    ? ($this->shape === 'cylinder' ? 'Uendelig mange plan gjennom aksen, og ett vannrett plan midt på' : 'Uendelig mange (alle loddrette plan gjennom spissen)')
                    : (string) $sym);
            $sections[] = ['title' => 'Sammenhenger', 'intro' => null, 'tasks' => $more];
        }
        return $sections;
    }

    private function surfaceText(): string
    {
        return match ($this->shape) {
            'cylinder' => '2 sirkler og en krum flate (et rektangel når den brettes ut)',
            'cone'     => '1 sirkel og en krum flate (en sirkelsektor når den brettes ut)',
            default    => $this->s->surfaceShapes(),
        };
    }

    private function equalFaces(): string
    {
        $groups = [];
        foreach ($this->s->faces() as $f) {
            $groups[(string) round($f['area'], 3) . $f['shape']][] = mb_strtolower($f['name']);
        }
        $same = array_filter($groups, fn($g) => count($g) > 1);
        if (!$same) {
            return 'Ingen flater er like store.';
        }
        if (count($same) === 1 && count(reset($same)) === count($this->s->faces())) {
            return 'Alle flatene er like store.';
        }
        return implode('; ', array_map(fn($g) => count($g) . ' like: ' . Solid::joinAnd($g), $same));
    }

    private function views(): string
    {
        return match ($this->shape) {
            'cube'     => 'Ovenfra: kvadrat. Forfra: kvadrat.',
            'cuboid'   => 'Ovenfra: rektangel. Forfra: rektangel.',
            'pyramid'  => 'Ovenfra: kvadrat med diagonaler. Forfra: trekant.',
            'triprism' => 'Ovenfra: rektangel med en linje på midten. Fra enden: trekant.',
            'cylinder' => 'Ovenfra: sirkel. Forfra: rektangel.',
            'cone'     => 'Ovenfra: sirkel med et punkt i midten. Forfra: trekant.',
            default    => '',
        };
    }

    /* ------------------------------------------------------- Side 2 */

    private function measure(): array
    {
        $tasks = [];
        foreach ($this->mainMeasures() as $k) {
            $mm = $this->m[$k];
            $tasks[] = $this->task('Mål ' . mb_strtolower($mm['label']) . " ({$mm['symbol']}).", ['line', 'text:mm  =', 'line', 'text:cm'],
                Solid::fmt($mm['value']) . ' mm = ' . $this->cm($mm['value']));
        }
        if ($this->level === 1 && !$this->curved) {
            $tasks[] = $this->task('Er alle kantene like lange?', ['line'], match ($this->shape) {
                'cube'     => 'Ja, alle 12 kantene er like lange.',
                'cuboid'   => 'Nei. 4 kanter er lengden, 4 er bredden og 4 er høyden.',
                'pyramid'  => 'Nei. De 4 kantene i grunnflaten er like lange, og de 4 sidekantene er like lange.',
                'triprism' => 'Nei. Trekantene har én kort og to lange sider, og de 3 lange kantene er like lange.',
                default    => '',
            });
        }
        $tasks[] = $this->task('Stemmer målene dine med opplysningene på arket? Hvor stor er forskjellen?', ['line'],
            'De skal stemme. Forskjellen bør være mindre enn 1 mm.');
        $sections = [['title' => 'Mål med linjal', 'intro' => null, 'tasks' => $tasks]];

        if ($this->level >= 2) {
            $base = [];
            $baseFace = $this->baseFace();
            $base[] = $this->task('Hvilken form har grunnflaten?', ['line'], $baseFace['shape']);
            $per = $this->m['base_perimeter'] ?? $this->m['circumference'];
            if ($this->curved) {
                $base[] = $this->task('Mål omkretsen av grunnflaten med en papirstrimmel. Hvor lang er den?', ['line', 'text:cm'],
                    'ca. ' . $this->cm($per['value']));
                $base[] = $this->task('Regn ut omkretsen: O = π · d. Bruk π ≈ 3,14.', ['line', 'text:cm'],
                    '3,14 · ' . $this->cm($this->m['diameter']['value']) . ' ≈ ' . $this->cm($per['value']));
            } else {
                $base[] = $this->task('Hvor lang er omkretsen av grunnflaten?', ['line', 'text:cm'], $this->cm($per['value']));
            }
            $sections[] = ['title' => 'Grunnflaten', 'intro' => null, 'tasks' => $base];
        }

        if ($this->level === 3) {
            $calc = match ($this->shape) {
                'pyramid'  => ['Regn ut skråhøyden (høyden i sideflatene): s = √(h² + (g/2)²).', $this->m['slant']],
                'cone'     => ['Regn ut sidelinjen: s = √(h² + r²).', $this->m['slant']],
                'triprism' => ['Regn ut den skrå siden i trekanten: a = √((g/2)² + h²).', $this->m['side']],
                'cube'     => ['Regn ut romdiagonalen (fra et hjørne til det motsatte): d = s · √3.', $this->m['diagonal']],
                'cuboid'   => ['Regn ut romdiagonalen: d = √(l² + b² + h²).', $this->m['diagonal']],
                'cylinder' => ['Hvor lang er den lengste rette pinnen som får plass inni sylinderen? Bruk √(d² + h²).',
                               ['value' => sqrt($this->m['diameter']['value'] ** 2 + $this->m['height']['value'] ** 2)]],
            };
            $t = [$this->task($calc[0], ['line', 'text:cm'], $this->cm($calc[1]['value']))];
            if ($this->shape === 'pyramid' || $this->shape === 'cone') {
                $t[] = $this->task('Hvorfor er ' . ($this->shape === 'cone' ? 'sidelinjen' : 'skråhøyden') . ' lengre enn høyden h?', ['line'],
                    'Den heller, og er hypotenusen i en rettvinklet trekant der h er en katet.');
            }
            $sections[] = ['title' => 'Pytagoras', 'intro' => null, 'tasks' => $t];
        }
        return $sections;
    }

    /** Målene som er parametere (dem eleven kan måle direkte). */
    private function mainMeasures(): array
    {
        return array_values(array_filter(['side', 'length', 'width', 'base', 'diameter', 'height'], fn($k) => isset($this->m[$k])));
    }

    private function baseFace(): array
    {
        foreach ($this->s->faces() as $f) {
            if ($f['role'] === 'grunnflate') {
                return $f;
            }
        }
        return $this->s->faces()[0];
    }

    /* ------------------------------------------------------- Side 3 */

    private function surface(): array
    {
        $A = $this->s->surfaceArea();
        $faces = $this->s->faces();
        if ($this->level === 1) {
            $big = array_reduce($faces, fn($c, $f) => $c === null || $f['area'] > $c['area'] + 1e-9 ? $f : $c);
            $bigs = array_filter($faces, fn($f) => abs($f['area'] - $big['area']) < 1e-6);
            return [[
                'title' => 'Tell og sammenlign', 'intro' => null, 'tasks' => [
                    $this->task('Hvor mange flater må dekkes med papir for å dekke hele figuren?', ['box'], (string) count($faces)),
                    $this->task('Hvilken flate er størst?', ['line'], count($bigs) === 1 ? $big['name']
                        : (count($bigs) === count($faces) ? 'Alle er like store' : Solid::joinAnd(array_map(fn($f) => mb_strtolower($f['name']), $bigs)) . ' (like store)')),
                    $this->task('Legg utbrettingsfiguren på et ark med 1 cm-ruter. Omtrent hvor mange ruter dekker flatene (uten limflikene)?', ['line'],
                        'ca. ' . Solid::fmt(round($A / 100), 0) . ' ruter (' . $this->cm2($A) . ')'),
                    $this->task('Hva er størst: arealet av grunnflaten eller arealet av alle de andre flatene til sammen?', ['line'],
                        'De andre flatene (' . $this->cm2($A - $this->s->baseArea()) . ')'),
                ],
            ]];
        }

        $tasks = [];
        $seen = [];
        foreach ($faces as $f) {
            $key = $f['shape'] . round($f['area'], 3);
            if (isset($seen[$key])) {
                $seen[$key]['n']++;
                continue;
            }
            $seen[$key] = ['f' => $f, 'n' => 1];
        }
        foreach ($seen as ['f' => $f, 'n' => $n]) {
            $q = match ($f['shape']) {
                'sirkel'  => 'Regn ut arealet av ' . mb_strtolower($f['name']) . ' (' . $f['dims'] . '): A = π · r².',
                'krum'    => $this->shape === 'cylinder'
                    ? 'Den krumme flaten er et rektangel når den brettes ut: π · d langt og h høyt. Regn ut arealet.'
                    : 'Den krumme flaten er en sirkelsektor. Regn ut arealet: A = π · r · s.',
                'trekant' => 'Regn ut arealet av én trekant (' . $f['dims'] . '): A = g · h / 2.',
                default   => 'Regn ut arealet av ' . mb_strtolower($f['name']) . ' (' . $f['dims'] . ').',
            };
            $answer = $this->cm2($f['area']) . ($n > 1 ? " (det er $n slike)" : '');
            $tasks[] = $this->task($q, ['line', 'text:cm²'], $answer);
        }
        $tasks[] = $this->task('Legg sammen arealene av alle flatene. Hvor stort er overflatearealet?', ['line', 'text:cm²'], $this->cm2($A));
        $sections = [['title' => 'Areal av flatene', 'intro' => $this->curved ? 'Bruk π ≈ 3,14.' : null, 'tasks' => $tasks]];

        if ($this->level === 3) {
            $formula = match ($this->shape) {
                'cube'     => 'O = 6 · s²',
                'cuboid'   => 'O = 2 · (l · b + l · h + b · h)',
                'pyramid'  => 'O = g² + 2 · g · s',
                'triprism' => 'O = g · h + (g + 2 · a) · l',
                'cylinder' => 'O = 2 · π · r² + π · d · h',
                'cone'     => 'O = π · r² + π · r · s',
            };
            $sections[] = ['title' => 'Formel', 'intro' => null, 'tasks' => [
                $this->task("Regn ut overflatearealet med formelen $formula. Får du det samme som over?", ['line', 'text:cm²'], $this->cm2($A)),
                $this->task('Hva skjer med overflatearealet hvis alle målene dobles?', ['line'],
                    'Det blir 4 ganger så stort: ' . $this->cm2(4 * $A)),
            ]];
        }
        return $sections;
    }

    /* ------------------------------------------------------- Side 4 */

    private function volume(): array
    {
        $V = $this->s->volume();
        $G = $this->s->baseArea();
        $h = $this->m['height']['value'] ?? $this->m['side']['value'];
        if ($this->level === 1) {
            $tasks = match ($this->shape) {
                'cube', 'cuboid' => $this->cubesTasks(),
                'pyramid' => [
                    $this->task('Tenk deg en eske (et prisme) med samme grunnflate og samme høyde som pyramiden. Hvor mange pyramider med sand trengs for å fylle esken?', ['box'], '3'),
                    $this->task('Rommer pyramiden mer eller mindre enn halvparten av esken?', ['line'], 'Mindre: en tredjedel.'),
                ],
                'cone' => [
                    $this->task('Tenk deg en sylinder med samme grunnflate og samme høyde som kjeglen. Hvor mange kjegler med sand trengs for å fylle sylinderen?', ['box'], '3'),
                    $this->task('Rommer kjeglen mer eller mindre enn halvparten av sylinderen?', ['line'], 'Mindre: en tredjedel.'),
                ],
                'triprism' => [
                    $this->task('Hvor mange slike trekantprismer trengs for å lage en eske med samme lengde, bredde g og høyde h?', ['box'], '2'),
                ],
                'cylinder' => [
                    $this->task('Tenk deg en eske som sylinderen så vidt får plass i (d × d × h). Rommer sylinderen mer eller mindre enn esken?', ['line'],
                        'Mindre, omtrent 79 % (π/4) av esken.'),
                ],
            };
            $tasks[] = $this->task('Hvilken figur på siden ville du helst fylt med saft? Forklar hvorfor.', ['line'], 'Fritt svar.');
            return [['title' => 'Hvor mye rommer figuren?', 'intro' => null, 'tasks' => $tasks]];
        }

        $third = in_array($this->shape, ['pyramid', 'cone'], true);
        $tasks = [
            $this->task('Regn ut grunnflatearealet G.' . ($this->curved ? ' Bruk π ≈ 3,14.' : ''), ['line', 'text:cm²'], $this->cm2($G)),
            $this->task($third ? 'Regn ut volumet: V = G · h / 3.' : 'Regn ut volumet: V = G · ' . ($this->shape === 'triprism' ? 'l' : 'h') . '.', ['line', 'text:cm³'], $this->cm3($V)),
            $this->task('Hvor mange ml vann ville figuren rommet hvis den var hul og tynn? (1 cm³ = 1 ml)', ['line', 'text:ml'],
                'ca. ' . Solid::fmt($V / 1000, 0) . ' ml'),
        ];
        $sections = [['title' => 'Regn ut volumet', 'intro' => null, 'tasks' => $tasks]];
        if ($this->level === 3) {
            $formula = match ($this->shape) {
                'cube'     => 'V = s · s · s = s³',
                'cuboid'   => 'V = l · b · h',
                'pyramid'  => 'V = g² · h / 3',
                'triprism' => 'V = g · h / 2 · l',
                'cylinder' => 'V = π · r² · h',
                'cone'     => 'V = π · r² · h / 3',
            };
            $more = [
                $this->task("Bruk formelen $formula. Får du det samme?", ['line', 'text:cm³'], $this->cm3($V)),
                $this->task('Hva skjer med volumet hvis alle målene dobles?', ['line'], 'Det blir 8 ganger så stort: ' . $this->cm3(8 * $V)),
            ];
            if ($third) {
                $more[] = $this->task(($this->shape === 'cone' ? 'En sylinder' : 'Et prisme') . ' har samme grunnflate og samme volum. Hvor høy er den?', ['line', 'text:cm'],
                    $this->cm($h / 3) . ' (en tredjedel av h)');
            }
            $sections[] = ['title' => 'Formel', 'intro' => null, 'tasks' => $more];
        }
        return $sections;
    }

    /** Nivå 1 for kube og prisme: terninger på 1 cm³. */
    private function cubesTasks(): array
    {
        [$L, $W, $H] = $this->s->size();
        $whole = fmod($L, 10) == 0 && fmod($W, 10) == 0 && fmod($H, 10) == 0;
        $layer = ($L / 10) * ($W / 10);
        return [
            $this->task('Hvor mange terninger på 1 cm³ får plass i ett lag på bunnen?', ['box'],
                ($whole ? '' : 'ca. ') . Solid::fmt($layer, 1) . ' (' . Solid::fmt($L / 10) . ' · ' . Solid::fmt($W / 10) . ')'),
            $this->task('Hvor mange lag blir det?', ['box'], ($whole ? '' : 'ca. ') . Solid::fmt($H / 10)),
            $this->task('Hvor mange terninger trengs til sammen?', ['box'],
                ($whole ? '' : 'ca. ') . Solid::fmt($layer * $H / 10, 1) . ' (' . $this->cm3($this->s->volume()) . ')'),
        ];
    }

    /* ------------------------------------------------------- Side 5 */

    private function paper(): array
    {
        $steps = [
            $this->task('Klipp ut utbrettingsfiguren langs de heltrukne linjene. Ikke klipp av limflikene.', [], null),
            $this->task('Brett langs alle de stiplede linjene, bakover (bort fra deg).', [], null),
            $this->task('Lim limflikene på innsiden, én kant om gangen.', [], null),
            $this->task('Sett papirfiguren ved siden av 3D-modellen og sammenlign dem.', [], null),
            $this->task('Undersøk om de har samme mål og samme form.', [], null),
        ];
        $sections = [['title' => 'Slik gjør du', 'intro' => null, 'tasks' => $steps, 'steps' => true]];

        $cmp = [
            $this->task('Har papirfiguren og 3D-modellen samme form?', ['line'], 'Ja.'),
            $this->task('Tell flatene på papirfiguren. Er det like mange som på 3D-modellen?', ['box'], "Ja, {$this->c['faces']}"),
        ];
        if ($this->level === 1) {
            $cmp[] = $this->task('Hvilken flate i utbrettingsfiguren blir bunnen?', ['line'], $this->baseFace()['name']);
        }
        if ($this->level >= 2) {
            $main = $this->m[$this->mainMeasures()[0]];
            $cmp[] = $this->task('Mål ' . mb_strtolower($main['label']) . ' på papirfiguren og på 3D-modellen. Er de like?', ['line'],
                'Ja, ' . Solid::fmt($main['value']) . ' mm (forskjell under 1 mm)');
            $cmp[] = match ($this->shape) {
                'cylinder' => $this->task('Hvorfor må rektangelet være like langt som omkretsen av sirkelen?', ['line'],
                    'Rektangelet rulles rundt sirkelen: π · d = ' . Solid::fmt($this->m['circumference']['value']) . ' mm.'),
                'cone' => $this->task('Hvorfor blir den krumme flaten en sirkelsektor når den brettes ut?', ['line'],
                    'Den rulles rundt toppunktet, og buen blir omkretsen av grunnflaten (' . Solid::fmt($this->m['arc_length']['value']) . ' mm).'),
                'pyramid' => $this->task('Hvorfor er høyden i trekantene lengre enn høyden i pyramiden?', ['line'],
                    'Trekantene heller innover: s = ' . Solid::fmt($this->m['slant']['value']) . ' mm, h = ' . Solid::fmt($this->m['height']['value']) . ' mm.'),
                'triprism' => $this->task('Hvilke kanter i utbrettingsfiguren blir limt sammen til den øverste kanten (mønet)?', ['line'],
                    'De ytterste langsidene av de to skrå rektanglene.'),
                default => $this->task('Finn et annet nett for figuren. Tegn det på rutepapir.', ['line'],
                    $this->shape === 'cube' ? 'En kube har 11 forskjellige nett.' : 'Mange muligheter, for eksempel fire sideflater på rad.'),
            };
        }
        $sections[] = ['title' => 'Sammenlign', 'intro' => null, 'tasks' => $cmp];

        if ($this->level === 3) {
            $calc = match ($this->shape) {
                'cone' => $this->task('Regn ut vinkelen i sirkelsektoren: v = 360° · r / s. Mål vinkelen på nettet.', ['line', 'text:°'],
                    Solid::fmt($this->m['sector_angle']['value']) . '°'),
                'cylinder' => $this->task('Regn ut lengden av rektangelet: π · d. Mål på nettet.', ['line', 'text:mm'],
                    Solid::fmt($this->m['circumference']['value']) . ' mm'),
                'pyramid' => $this->task('Regn ut skråhøyden s og mål den på nettet.', ['line', 'text:mm'], Solid::fmt($this->m['slant']['value']) . ' mm'),
                'triprism' => $this->task('Regn ut den skrå siden a og mål den på nettet.', ['line', 'text:mm'], Solid::fmt($this->m['side']['value']) . ' mm'),
                'cube' => $this->task('Hvor mange forskjellige nett finnes det for en kube? Prøv å finne flere.', ['box'], '11'),
                default => $this->task('Hvor mange cm² papir går med til limflikene, omtrent?', ['line'], 'Fritt svar (mål og regn ut).'),
            };
            $sections[] = ['title' => 'Beregn og kontroller', 'intro' => null, 'tasks' => [
                $calc,
                $this->task('Hvorfor er papirfiguren og 3D-modellen ikke helt like?', ['line'],
                    'Papiret har tykkelse, og bretting og liming gir små avvik.'
                    . ($this->curved ? ' I STL-fila er den krumme flaten laget av mange små plane flater.' : '')),
            ]];
        }
        return $sections;
    }

    /* ------------------------------------------------------- Hjelpere */

    /**
     * En oppgave: tekst etterfulgt av svarfelt. 'text:x' gir en kort tekst
     * etter svarlinja (enhet).
     */
    private function task(string $text, array $fields, ?string $answer): array
    {
        $tokens = [['text', $text . ' ']];
        foreach ($fields as $f) {
            $tokens[] = str_starts_with($f, 'text:') ? ['text', ' ' . substr($f, 5) . ' '] : [$f];
        }
        return ['tokens' => $tokens, 'answer' => $answer];
    }

    private function cm(float $mm): string
    {
        return Solid::fmt($mm / 10, 1) . ' cm';
    }

    private function cm2(float $mm2): string
    {
        return Solid::fmt($mm2 / 100, 1) . ' cm²';
    }

    private function cm3(float $mm3): string
    {
        return Solid::fmt($mm3 / 1000, 1) . ' cm³';
    }
}
