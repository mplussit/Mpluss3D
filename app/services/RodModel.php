<?php
declare(strict_types=1);

/**
 * Tallstaver (Cuisenaire-staver) med lengde 1–10 enheter.
 *
 * Én STL-fil per valgt lengde (alle eksemplarer er like). Alle stavene har
 * samme kvadratiske tverrsnitt (enhet × enhet), og stav n er nøyaktig
 * n · enhet lang. Geometrien ligger i models/rods/rod.scad.
 *
 * Parametere (i tillegg til tallene i konfigurasjonen):
 *   grid         true/false   enhetsinndeling (riller på oversiden)
 *   labels       true/false   nedsenket tall på oversiden
 *   lengths      liste eller "1,2,5" – hvilke staver som skal lages
 *   count_1 … count_10        antall som skal skrives ut
 *   color_mode   classic | single | custom   (bare forhåndsvisning og PDF)
 *   color        #rrggbb      fargen når color_mode = single
 *   color_1 … color_10        egne farger når color_mode = custom
 */
final class RodModel implements ParametricModel
{
    public function __construct(private array $definition)
    {
    }

    public static function load(string $configDir): self
    {
        return new self(require $configDir . '/models/number_rods.php');
    }

    public function key(): string
    {
        return 'rods';
    }

    public function definition(): array
    {
        return $this->definition;
    }

    public function schema(): array
    {
        $def = $this->definition;
        return [
            'model'      => $def['id'],
            'version'    => $def['version'],
            'parameters' => $def['parameters'],
            'switches'   => $def['switches'],
            'limits'     => $def['limits'],
            'lengths'    => $def['lengths'],
            'colors'     => $def['colors'],
            'defaults'   => $this->defaults(),
        ];
    }

    public function defaults(): array
    {
        $def = $this->definition;
        $p = [];
        foreach ($def['parameters'] as $key => $spec) {
            $p[$key] = (float) $spec['default'];
        }
        foreach ($def['switches'] as $key => $spec) {
            $p[$key] = (bool) $spec['default'];
        }
        $p['lengths'] = $def['lengths']['default'];
        $p['counts'] = [];
        foreach ($this->allLengths() as $n) {
            $p['counts'][$n] = $def['lengths']['count_default'];
        }
        $p['color_mode'] = $def['colors']['default'];
        $p['color'] = $def['colors']['single'];
        $p['colors'] = array_map(fn($c) => $c['hex'], $def['colors']['classic']);
        return $p;
    }

    /** @return list<int> 1 … 10 */
    public function allLengths(): array
    {
        return range($this->definition['lengths']['min'], $this->definition['lengths']['max']);
    }

    /**
     * Validerer og normaliserer. Resultatet har counts for alle lengder, der
     * lengder som ikke er valgt, har antall 0.
     *
     * @throws ValidationException
     */
    public function validate(array $input): array
    {
        $def = $this->definition;
        [$params, $errors] = ParameterValidator::numbers($def['parameters'], $input);

        foreach ($def['switches'] as $key => $spec) {
            $v = array_key_exists($key, $input) ? ParameterValidator::toBool($input[$key]) : (bool) $spec['default'];
            if ($v === null) {
                $errors[] = ['field' => $key, 'message' => "Ugyldig valg for «{$spec['label']}»."];
            }
            $params[$key] = (bool) $v;
        }

        // Stavlengder
        $raw = $input['lengths'] ?? $def['lengths']['default'];
        if (is_string($raw)) {
            $raw = trim($raw) === '' ? [] : explode(',', $raw);
        }
        $chosen = [];
        if (!is_array($raw) || count($raw) > 20) {
            $errors[] = ['field' => 'lengths', 'message' => 'Ugyldig liste med stavlengder.'];
        } else {
            foreach ($raw as $v) {
                $n = is_int($v) ? $v : (is_string($v) && preg_match('/^\s*\d{1,2}\s*$/', $v) ? (int) $v : null);
                if ($n === null || $n < $def['lengths']['min'] || $n > $def['lengths']['max']) {
                    $errors[] = ['field' => 'lengths', 'message' => 'Stavlengdene må være hele tall fra 1 til 10.'];
                    break;
                }
                $chosen[$n] = true;
            }
            if (!$chosen && !$errors) {
                $errors[] = ['field' => 'lengths', 'message' => 'Velg minst én stavlengde.'];
            }
        }
        ksort($chosen);
        $params['lengths'] = array_keys($chosen);

        // Antall
        $max = $def['lengths']['count_max'];
        $params['counts'] = [];
        foreach ($this->allLengths() as $n) {
            $field = "count_$n";
            $raw = $input[$field] ?? ($input['counts'][$n] ?? null);
            if ($raw === null || $raw === '') {
                $c = $def['lengths']['count_default'];
            } else {
                $c = is_int($raw) ? $raw : (is_string($raw) && preg_match('/^\s*\d{1,4}\s*$/', $raw) ? (int) $raw : null);
                if ($c === null || $c < 0 || $c > $max) {
                    $errors[] = ['field' => $field, 'message' => "Antall staver med lengde $n må være et helt tall fra 0 til $max."];
                    $c = 0;
                }
            }
            if (!isset($chosen[$n])) {
                $c = 0;
            } elseif ($c < 1 && !$this->hasError($errors, $field)) {
                $errors[] = ['field' => $field, 'message' => "Skriv inn hvor mange staver med lengde $n som skal skrives ut (minst 1), eller fjern lengden."];
            }
            $params['counts'][$n] = $c;
        }

        // Farger (bare visning og PDF)
        $mode = $input['color_mode'] ?? $def['colors']['default'];
        if (!is_string($mode) || !isset($def['colors']['modes'][$mode])) {
            $errors[] = ['field' => 'color_mode', 'message' => 'Velg klassiske farger, én farge eller egne farger.'];
            $mode = $def['colors']['default'];
        }
        $params['color_mode'] = $mode;
        $params['color'] = $this->hexOrError($input['color'] ?? null, $def['colors']['single'], 'color', $errors);
        $params['colors'] = [];
        foreach ($this->allLengths() as $n) {
            $raw = $input["color_$n"] ?? ($input['colors'][$n] ?? null);
            $params['colors'][$n] = $this->hexOrError($raw, $def['colors']['classic'][$n]['hex'], "color_$n", $errors);
        }

        if ($errors) {
            throw new ValidationException($errors);
        }

        // Kombinasjoner: riller og avrunding må ikke svekke små staver.
        $lim = $def['limits'];
        $u = $params['unit'];
        $maxR = floor($u * $lim['edge_radius_ratio'] * 10) / 10;
        if ($params['edge_radius'] > $maxR + 1e-9) {
            $errors[] = ['field' => 'edge_radius', 'message' => sprintf(
                'Med enhetslengde %s mm kan kantavrundingen være høyst %s mm.', self::fmt($u), self::fmt($maxR)
            )];
        }
        if ($params['grid'] && max($params['lengths'] ?: [0]) > 1) {
            $maxD = floor($u * $lim['groove_depth_ratio'] * 10) / 10;
            if ($params['groove_depth'] > $maxD + 1e-9) {
                $errors[] = ['field' => 'groove_depth', 'message' => sprintf(
                    'Med enhetslengde %s mm kan rillene være høyst %s mm dype, ellers blir stavene for svake.',
                    self::fmt($u), self::fmt($maxD)
                )];
            }
            $maxW = floor($u * $lim['groove_width_ratio'] * 10) / 10;
            if ($params['groove_width'] > $maxW + 1e-9) {
                $errors[] = ['field' => 'groove_width', 'message' => sprintf(
                    'Med enhetslengde %s mm kan rillene være høyst %s mm brede.', self::fmt($u), self::fmt($maxW)
                )];
            }
        }
        if ($errors) {
            throw new ValidationException($errors);
        }
        return $params;
    }

    private function hexOrError(mixed $raw, string $default, string $field, array &$errors): string
    {
        if ($raw === null || $raw === '') {
            return $default;
        }
        if (!is_string($raw) || !preg_match('/^#?([0-9a-fA-F]{6})$/', trim($raw), $m)) {
            $errors[] = ['field' => $field, 'message' => 'Fargen må være en heksadesimal fargekode, for eksempel #d43d3d.'];
            return $default;
        }
        return '#' . strtolower($m[1]);
    }

    private function hasError(array $errors, string $field): bool
    {
        foreach ($errors as $e) {
            if ($e['field'] === $field) {
                return true;
            }
        }
        return false;
    }

    /* ----------------------------------------------------------------- Farger */

    /**
     * Fargen til hver stav slik den vises: [n => ['hex' => …, 'name' => …]].
     * Egne farger som er lik en klassisk farge, får det klassiske navnet.
     */
    public function colors(array $p): array
    {
        $classic = $this->definition['colors']['classic'];
        $out = [];
        foreach ($this->allLengths() as $n) {
            $hex = match ($p['color_mode']) {
                'single' => $p['color'],
                'custom' => $p['colors'][$n],
                default  => $classic[$n]['hex'],
            };
            $out[$n] = ['hex' => $hex, 'name' => $this->colorName($hex)];
        }
        return $out;
    }

    public function colorName(string $hex): string
    {
        foreach ($this->definition['colors']['classic'] as $c) {
            if (strcasecmp($c['hex'], $hex) === 0) {
                return $c['name'];
            }
        }
        return 'egen farge';
    }

    /* ---------------------------------------------------------------- Geometri */

    /** Ytre mål i mm [x, y, z] slik staven ligger på byggeplaten. */
    public function size(int $n, array $p): array
    {
        return [$n * $p['unit'], (float) $p['unit'], (float) $p['unit']];
    }

    public function expectedBounds(int $n, array $p): array
    {
        [$x, $y, $z] = $this->size($n, $p);
        return ['min' => [-$x / 2, -$y / 2, 0.0], 'max' => [$x / 2, $y / 2, $z]];
    }

    private function segmentsPerQuarter(): int
    {
        return max(2, (int) ceil($this->definition['scad_constants']['edge_segments'] / 4));
    }

    /**
     * Plassering og størrelse på tallet på oversiden av stav n.
     *
     * Tallet må ligge på den flate delen av oversiden (minst marg fra
     * avrundingen og fra endene). Med enhetsinndeling legges det i én enhet
     * (midterste, eller den til venstre for midten når n er et partall), så
     * det aldri overlapper en rille. Høyden er høyst 60 % av enhetslengden;
     * blir den lavere enn min_height, utelates tallet på den staven.
     *
     * @return array{shown:bool,reason:?string,text:string,width:float,height:float,x:float,reduced:bool}
     */
    public function labelLayout(int $n, array $p): array
    {
        $cfg = $this->definition['labels'];
        $none = ['shown' => false, 'reason' => null, 'text' => (string) $n, 'width' => 0.0, 'height' => 0.0, 'x' => 0.0, 'reduced' => false];
        if (!$p['labels']) {
            return ['reason' => 'off'] + $none;
        }
        $u = $p['unit'];
        $m = (float) $cfg['margin'];
        $L = $n * $u;
        $k = (float) $cfg['aspect'][$n];

        $availY = $u - 2 * $p['edge_radius'] - 2 * $m;
        if ($p['grid'] && $n > 1) {
            $cell = intdiv($n - 1, 2);
            $x0 = -$L / 2 + $cell * $u;
            $a = $x0 + ($cell > 0 ? $p['groove_width'] / 2 : 0) + $m;
            $b = $x0 + $u - ($cell + 1 < $n ? $p['groove_width'] / 2 : 0) - $m;
        } else {
            $a = -$L / 2 + $m;
            $b = $L / 2 - $m;
        }
        $cap = $cfg['height_ratio'] * $u;
        $h = min($cap, $availY, ($b - $a) / $k);
        if ($h < $cfg['min_height'] - 1e-9) {
            return ['reason' => 'too_small'] + $none;
        }
        $h = floor($h * 100) / 100;
        return [
            'shown'   => true,
            'reason'  => null,
            'text'    => (string) $n,
            'width'   => floor($h * $k * 100) / 100,
            'height'  => $h,
            'x'       => round(($a + $b) / 2, 3),
            'reduced' => $h < $cap - 0.01,
        ];
    }

    /**
     * Omtrentlig volum og overflate (mm³, mm²) fra målene: boks minus
     * avrunding og fas, riller og tall (tallet som ca. 45 % av boksen).
     * @return array{volume:float,area:float}
     */
    public function estimate(int $n, array $p): array
    {
        [$L, $u] = $this->size($n, $p);
        $r = $p['edge_radius'];
        $q = $this->segmentsPerQuarter();
        // Tverrsnitt: to avrundede hjørner (innskrevet mangekant) og to fasede hjørner.
        $roundLoss = $r * $r - $q * 0.5 * $r * $r * sin(M_PI / (2 * $q));
        $profile = $u * $u - 2 * $roundLoss - $r * $r;
        // Omkrets: fire rette sider (u − 2r), to faser (r√2) og to buer (q korder)
        $perimeter = 4 * ($u - 2 * $r) + 2 * $r * M_SQRT2 + 2 * $q * 2 * $r * sin(M_PI / (4 * $q));
        $V = $L * $profile;
        $A = $L * $perimeter + 2 * $profile;
        if ($p['grid'] && $n > 1) {
            $V -= ($n - 1) * $p['groove_width'] * $p['groove_depth'] * $u;
            $A += ($n - 1) * 2 * $p['groove_depth'] * $u;
        }
        $lab = $this->labelLayout($n, $p);
        if ($lab['shown']) {
            $V -= 0.45 * $lab['width'] * $lab['height'] * $p['label_depth'];
        }
        return ['volume' => $V, 'area' => $A];
    }

    /** @return array{solid_g:float,print_g:float} */
    public function material(int $n, array $p): array
    {
        $m = $this->definition['material'];
        ['volume' => $V, 'area' => $A] = $this->estimate($n, $p);
        $shell = min($V, $A * $m['shell']);
        $print = $shell + $m['infill'] * max(0.0, $V - $shell);
        return [
            'solid_g' => round($V / 1000 * $m['density'], 1),
            'print_g' => round($print / 1000 * $m['density'], 1),
        ];
    }

    /* ------------------------------------------------------------------- Deler */

    public function filename(int $n): string
    {
        return sprintf('%s_%02d.stl', $this->definition['filename'], $n);
    }

    public function parts(array $p): array
    {
        $def = $this->definition;
        $c = $def['scad_constants'];
        $colors = $this->colors($p);
        $parts = [];
        foreach ($p['lengths'] as $n) {
            $grid = $p['grid'] && $n > 1;
            $label = $this->labelLayout($n, $p);
            // Verdier som ikke påvirker denne staven settes fast, så like staver deler hurtigbuffer.
            $vars = [
                'unit'          => (float) $p['unit'],
                'n'             => $n,
                'edge_radius'   => (float) $p['edge_radius'],
                'grid_on'       => $grid ? 1 : 0,
                'groove_depth'  => $grid ? (float) $p['groove_depth'] : 0.0,
                'groove_width'  => $grid ? (float) $p['groove_width'] : 0.0,
                'label_on'      => $label['shown'] ? 1 : 0,
                'label_depth'   => $label['shown'] ? (float) $p['label_depth'] : 0.0,
                'label_width'   => $label['shown'] ? $label['width'] : 1.0,
                'label_height'  => $label['shown'] ? $label['height'] : 1.0,
                'label_x'       => $label['shown'] ? $label['x'] : 0.0,
                'edge_segments' => (int) $c['edge_segments'],
                'text_segments' => (int) $c['text_segments'],
            ];
            $parts[] = [
                'key'       => (string) $n,
                'scad'      => $def['scad'],
                'variables' => $vars,
                'bounds'    => $this->expectedBounds($n, $p),
                'filename'  => $this->filename($n),
                'count'     => $p['counts'][$n],
                'cache_key' => json_encode([$def['id'], $def['version'], $vars]),
                'info'      => [
                    'length'     => $n,
                    'name'       => "stav $n",
                    'color'      => $colors[$n]['hex'],
                    'color_name' => $colors[$n]['name'],
                    'size_mm'    => $this->size($n, $p),
                    'unit'       => (float) $p['unit'],
                    'grid'       => $grid,
                    'groove'     => ['depth' => $vars['groove_depth'], 'width' => $vars['groove_width']],
                    'label'      => $label['shown']
                        ? ['text' => $label['text'], 'width' => $label['width'], 'height' => $label['height'], 'x' => $label['x'], 'depth' => $vars['label_depth']]
                        : null,
                    'material'   => $this->material($n, $p),
                ],
            ];
        }
        return $parts;
    }

    public function warnings(array $p): array
    {
        $w = [];
        if ($p['labels']) {
            $missing = array_values(array_filter($p['lengths'], fn($n) => !$this->labelLayout($n, $p)['shown']));
            if ($missing) {
                $w[] = sprintf(
                    'Tallmerkingen er utelatt på %s fordi det ikke er plass til et lesbart tall (minst %s mm høyt). Bruk større enhetslengde, mindre avrunding eller slå av enhetsinndelingen.',
                    count($missing) === 1 ? 'stav ' . $missing[0] : 'stav ' . self::joinAnd($missing),
                    self::fmt($this->definition['labels']['min_height'])
                );
            }
        }
        if ($p['grid'] && $p['groove_depth'] < 0.3) {
            $w[] = 'Svært grunne riller kan bli utydelige etter printing. 0,3–0,4 mm er et godt utgangspunkt.';
        }
        if ($p['color_mode'] === 'single') {
            $w[] = 'Med én farge må elevene sammenligne lengdene direkte. Det er bra for resonnering, men gjør det vanskeligere å finne en bestemt stav.';
        }
        return $w;
    }

    public function describe(array $p): array
    {
        $rods = [];
        $pieces = 0;
        $print = 0.0;
        $solid = 0.0;
        foreach ($this->parts($p) as $part) {
            $i = $part['info'];
            $pieces += $part['count'];
            $print += $part['count'] * $i['material']['print_g'];
            $solid += $part['count'] * $i['material']['solid_g'];
            $rods[] = [
                'length'     => $i['length'],
                'size_mm'    => $i['size_mm'],
                'count'      => $part['count'],
                'stl'        => $part['filename'],
                'color'      => $i['color'],
                'color_name' => $i['color_name'],
                'label'      => $i['label'] !== null,
                'material_g' => $i['material'],
            ];
        }
        $tasks = new RodTasks($p['counts']);
        return [
            'model'   => $this->definition['id'],
            'version' => $this->definition['version'],
            'units'   => 'mm',
            'rods_spec' => [
                'unit'        => $p['unit'],
                'edge_radius' => $p['edge_radius'],
                'grid'        => $p['grid'] ? ['depth' => $p['groove_depth'], 'width' => $p['groove_width']] : null,
                'labels'      => $p['labels'] ? ['style' => 'engraved', 'depth' => $p['label_depth']] : null,
                'color_mode'  => $p['color_mode'],
            ],
            'rods'          => $rods,
            'total_pieces'  => $pieces,
            'total_length'  => $tasks->totalLength(),
            'fraction_whole' => $tasks->mainWhole(),
            'material_g'    => ['print' => round($print), 'solid' => round($solid)],
        ];
    }

    /* ------------------------------------------------------- PDF og nedlasting */

    public function renderPdf(array $p): string
    {
        return (new RodPdfGenerator($this))->render($p);
    }

    public function pdfFilename(array $p): string
    {
        return $this->archiveName($p) . '.pdf';
    }

    public function archiveName(array $p): string
    {
        return $this->definition['archive'] . '-' . str_replace(',', '_', self::fmt($p['unit'])) . 'mm';
    }

    public function archiveReadme(array $p, array $parts): string
    {
        $modes = $this->definition['colors']['modes'];
        $lines = [
            'M+ 3D – Tallstaver (Cuisenaire-staver)',
            '',
            'Parametere',
            '  Enhetslengde:    ' . self::fmt($p['unit']) . ' mm (stav 1 er ' . self::fmt($p['unit']) . ' × ' . self::fmt($p['unit']) . ' × ' . self::fmt($p['unit']) . ' mm)',
            '  Kantavrunding:   ' . self::fmt($p['edge_radius']) . ' mm (bare langs staven, fas mot byggeplaten)',
            '  Inndeling:       ' . ($p['grid'] ? sprintf('på, riller %s mm dype og %s mm brede', self::fmt($p['groove_depth']), self::fmt($p['groove_width'])) : 'av (glatte sider)'),
            '  Tallmerking:     ' . ($p['labels'] ? 'nedsenket, ' . self::fmt($p['label_depth']) . ' mm' : 'av'),
            '  Farger:          ' . mb_strtolower($modes[$p['color_mode']]),
            '',
            'Utskriftsliste',
            'Hver STL-fil er én stav. Skriv ut så mange eksemplarer som står under «Antall».',
            '',
            self::pad('Fil', 18) . self::pad('Stav', 6) . self::pad('Mål (mm)', 16) . self::pad('Antall', 8) . self::pad('Filament', 20) . 'Ca. PLA',
        ];
        $pieces = 0;
        $grams = 0.0;
        $byColor = [];
        foreach ($parts as $part) {
            $i = $part['info'];
            $pieces += $part['count'];
            $g = $part['count'] * $i['material']['print_g'];
            $grams += $g;
            $color = $i['color_name'] === 'egen farge' ? $i['color'] : "{$i['color_name']} ({$i['color']})";
            $byColor[$color] = ($byColor[$color] ?? 0) + $part['count'];
            $lines[] = self::pad($part['filename'], 18) . self::pad((string) $i['length'], 6)
                . self::pad(implode(' × ', array_map([self::class, 'fmt'], $i['size_mm'])), 16)
                . self::pad((string) $part['count'], 8) . self::pad($color, 20) . self::grams($g);
        }
        $lines[] = '';
        $lines[] = "Totalt: $pieces staver, ca. " . self::grams($grams) . ' PLA.';
        $lines[] = '';
        $lines[] = 'Filamentfarger';
        foreach ($byColor as $color => $count) {
            $lines[] = '  ' . self::pad($color, 24) . "$count staver";
        }
        $lines[] = '  STL-filer har ingen farge. Bytt filament mellom lengdene, eller mal stavene etterpå.';
        $lines[] = '';
        $lines[] = 'Anbefalte utskriftsinnstillinger';
        $lines[] = '  - PLA, 0,2 mm laghøyde, 0,4 mm dyse, 2–3 vegger, 15 % fyll.';
        $lines[] = '  - Stavene ligger slik de skal skrives ut. Ingen støtte trengs.';
        $lines[] = '  - Endeflatene er plane, så stavene kan legges ende mot ende. Unngå «brim» på endene.';
        $lines[] = '  - Materialforbruket er et overslag. Sliceren gir nøyaktig tall.';
        return "\u{FEFF}" . implode("\r\n", $lines) . "\r\n";
    }

    public function queryKeys(): array
    {
        $keys = array_merge(array_keys($this->definition['parameters']), array_keys($this->definition['switches']),
            ['lengths', 'color_mode', 'color']);
        foreach ($this->allLengths() as $n) {
            $keys[] = "count_$n";
            $keys[] = "color_$n";
        }
        return $keys;
    }

    /* ------------------------------------------------------- Hjelpefunksjoner */

    public static function fmt(float|int $v): string
    {
        return ParameterValidator::fmt($v);
    }

    public static function grams(float $g): string
    {
        if ($g >= 1000) {
            return self::fmt(round($g / 1000, 2)) . ' kg';
        }
        return self::fmt(round($g)) . ' g';
    }

    /** «1, 2 og 3» */
    public static function joinAnd(array $items): string
    {
        $items = array_map('strval', $items);
        if (count($items) < 2) {
            return implode('', $items);
        }
        return implode(', ', array_slice($items, 0, -1)) . ' og ' . end($items);
    }

    /** str_pad som teller tegn, ikke byte (æøå). */
    private static function pad(string $s, int $len): string
    {
        return $s . str_repeat(' ', max(1, $len - mb_strlen($s)));
    }
}
