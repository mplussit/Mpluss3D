<?php
declare(strict_types=1);

/**
 * Base 10 / plassverdimateriell: ener, tier, hundrer og tusener.
 *
 * Én STL-fil per valgt objekttype (alle eksemplarer av en type er like).
 * Alle klossene bygger på samme enhetslengde, så de har riktige relative
 * mål og volum. Geometrien ligger i models/base10/block.scad.
 *
 * Parametere (i tillegg til tallene i konfigurasjonen):
 *   grid      true/false   riller ved hver enhetsgrense
 *   hollow    true/false   hul tusenerkloss (åpen underside)
 *   types     liste eller "ener,tier" – hvilke objekter som skal lages
 *   count_ener, count_tier, count_hundrer, count_tusener   antall som skal skrives ut
 */
final class Base10Model implements ParametricModel
{
    public function __construct(private array $definition)
    {
    }

    public static function load(string $configDir): self
    {
        return new self(require $configDir . '/models/base10_blocks.php');
    }

    public function key(): string
    {
        return 'base10';
    }

    public function definition(): array
    {
        return $this->definition;
    }

    public function schema(): array
    {
        $def = $this->definition;
        $types = [];
        foreach ($def['types'] as $key => $t) {
            $types[$key] = [
                'value'  => $t['value'],
                'units'  => $t['units'],
                'names'  => $t['names'],
                'color'  => $t['color'],
                'max'    => $t['max'],
            ];
        }
        return [
            'model'      => $def['id'],
            'version'    => $def['version'],
            'parameters' => $def['parameters'],
            'switches'   => $def['switches'],
            'limits'     => $def['limits'],
            'types'      => $types,
            'defaults'   => $this->defaults(),
        ];
    }

    public function defaults(): array
    {
        $p = [];
        foreach ($this->definition['parameters'] as $key => $spec) {
            $p[$key] = (float) $spec['default'];
        }
        foreach ($this->definition['switches'] as $key => $spec) {
            $p[$key] = (bool) $spec['default'];
        }
        $p['types'] = [];
        $p['counts'] = [];
        foreach ($this->definition['types'] as $key => $t) {
            if ($t['enabled']) {
                $p['types'][] = $key;
            }
            $p['counts'][$key] = $t['count'];
        }
        return $p;
    }

    /** @return list<string> */
    public function typeKeys(): array
    {
        return array_keys($this->definition['types']);
    }

    /**
     * Validerer og normaliserer. Resultatet har counts for alle typer, der
     * typer som ikke er valgt, har antall 0.
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

        // Objekttyper
        $raw = $input['types'] ?? $this->defaults()['types'];
        if (is_string($raw)) {
            $raw = $raw === '' ? [] : explode(',', $raw);
        }
        $chosen = [];
        if (!is_array($raw) || count($raw) > 8) {
            $errors[] = ['field' => 'types', 'message' => 'Ugyldig liste med objekttyper.'];
        } else {
            foreach ($raw as $t) {
                $t = is_string($t) ? trim($t) : $t;
                if (!is_string($t) || !isset($def['types'][$t])) {
                    $errors[] = ['field' => 'types', 'message' => 'Ukjent objekttype. Velg blant ener, tier, hundrer og tusener.'];
                    break;
                }
                $chosen[$t] = true;
            }
            if (!$chosen && !$errors) {
                $errors[] = ['field' => 'types', 'message' => 'Velg minst én objekttype.'];
            }
        }
        $params['types'] = array_values(array_filter($this->typeKeys(), fn($k) => isset($chosen[$k])));

        // Antall
        $params['counts'] = [];
        foreach ($def['types'] as $key => $t) {
            $field = "count_$key";
            $raw = $input[$field] ?? ($input['counts'][$key] ?? null);
            if ($raw === null || $raw === '') {
                $n = $t['count'];
            } else {
                $n = is_int($raw) ? $raw : (is_string($raw) && preg_match('/^\s*\d{1,4}\s*$/', $raw) ? (int) $raw : null);
                if ($n === null || $n < 0 || $n > $t['max']) {
                    $errors[] = ['field' => $field, 'message' => sprintf('Antall %s må være et helt tall fra 0 til %d.', $t['names'][1], $t['max'])];
                    $n = 0;
                }
            }
            if (!isset($chosen[$key])) {
                $n = 0;
            } elseif ($n < 1 && !$this->hasError($errors, $field)) {
                $errors[] = ['field' => $field, 'message' => sprintf('Skriv inn hvor mange %s som skal skrives ut (minst 1), eller slå av %s.', $t['names'][1], $t['names'][0])];
            }
            $params['counts'][$key] = $n;
        }

        if ($errors) {
            throw new ValidationException($errors);
        }

        // Kombinasjoner: riller og vegger må ikke svekke klossene.
        $lim = $def['limits'];
        $u = $params['unit'];
        if ($params['grid'] && array_diff($params['types'], ['ener'])) {
            $maxD = floor($u * $lim['groove_depth_ratio'] * 10) / 10;
            if ($params['groove_depth'] > $maxD + 1e-9) {
                $errors[] = ['field' => 'groove_depth', 'message' => sprintf(
                    'Med enhetslengde %s mm kan rillene være høyst %s mm dype, ellers blir klossene for svake.',
                    self::fmt($u), self::fmt($maxD)
                )];
            }
            $maxW = floor($u * $lim['groove_width_ratio'] * 10) / 10;
            if ($params['groove_width'] > $maxW + 1e-9) {
                $errors[] = ['field' => 'groove_width', 'message' => sprintf(
                    'Med enhetslengde %s mm kan rillene være høyst %s mm brede.',
                    self::fmt($u), self::fmt($maxW)
                )];
            }
            if ($params['hollow'] && in_array('tusener', $params['types'], true)
                && $params['wall'] - $params['groove_depth'] < $lim['min_wall_left'] - 1e-9) {
                $errors[] = ['field' => 'wall', 'message' => sprintf(
                    'Veggen må være minst %s mm tykkere enn rilledybden (nå %s mm), så rillene ikke svekker den hule kuben.',
                    self::fmt($lim['min_wall_left']), self::fmt($params['groove_depth'])
                )];
            }
        }
        if ($errors) {
            throw new ValidationException($errors);
        }
        return $params;
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

    /* ---------------------------------------------------------------- Geometri */

    /** Ytre mål i mm [x, y, z] slik klossen ligger på byggeplaten. */
    public function size(string $type, array $p): array
    {
        return array_map(fn($n) => $n * $p['unit'], $this->definition['types'][$type]['units']);
    }

    public function isHollow(string $type, array $p): bool
    {
        return $type === 'tusener' && $p['hollow'];
    }

    public function expectedBounds(string $type, array $p): array
    {
        [$x, $y, $z] = $this->size($type, $p);
        return ['min' => [-$x / 2, -$y / 2, 0.0], 'max' => [$x / 2, $y / 2, (float) $z]];
    }

    /**
     * Omtrentlig volum og overflate (mm³, mm²) beregnet fra målene: boks minus
     * avrunding, riller og hulrom. Avviker under 1 % fra STL-fila (se testene).
     * @return array{volume:float,area:float}
     */
    public function estimate(string $type, array $p): array
    {
        [$nx, $ny, $nz] = $this->definition['types'][$type]['units'];
        [$X, $Y, $Z] = $this->size($type, $p);
        $r = $p['edge_radius'];
        $hollow = $this->isHollow($type, $p);
        $t = $p['wall'];

        $V = $X * $Y * $Z;
        $A = 2 * ($X * $Y + $Y * $Z + $X * $Z);
        // Avrunding: 4 toppkanter og 4 loddrette kanter; fas mot byggeplaten.
        $V -= (1 - M_PI / 4) * $r * $r * (2 * $X + 2 * $Y + 4 * $Z) + 0.5 * $r * $r * (2 * $X + 2 * $Y);

        if ($hollow) {
            $V -= ($X - 2 * $t) * ($Y - 2 * $t) * ($Z - $t);
            $A += 2 * ($X - 2 * $t) * ($Z - $t) + 2 * ($Y - 2 * $t) * ($Z - $t);
        }
        if ($p['grid']) {
            $w = $p['groove_width'];
            $d = $p['groove_depth'];
            $per = ($nx - 1) * ($hollow ? 2 * $Z + $Y : 2 * ($Y + $Z))
                 + ($ny - 1) * ($hollow ? 2 * $Z + $X : 2 * ($X + $Z))
                 + ($nz - 1) * 2 * ($X + $Y);
            $V -= $w * $d * $per;
            $A += 2 * $d * $per;
        }
        return ['volume' => $V, 'area' => $A];
    }

    /**
     * Omtrentlig filament i gram: skall + 15 % fyll (og massiv som øvre grense).
     * @return array{solid_g:float,print_g:float}
     */
    public function material(string $type, array $p): array
    {
        $m = $this->definition['material'];
        ['volume' => $V, 'area' => $A] = $this->estimate($type, $p);
        $shell = min($V, $A * $m['shell']);
        $print = $shell + $m['infill'] * max(0.0, $V - $shell);
        return [
            'solid_g' => round($V / 1000 * $m['density'], 1),
            'print_g' => round($print / 1000 * $m['density'], 1),
        ];
    }

    /* ------------------------------------------------------------------- Deler */

    public function parts(array $p): array
    {
        $def = $this->definition;
        $parts = [];
        foreach ($p['types'] as $type) {
            $t = $def['types'][$type];
            [$nx, $ny, $nz] = $t['units'];
            $grid = $p['grid'] && $type !== 'ener';
            $hollow = $this->isHollow($type, $p);
            // Verdier som ikke påvirker denne klossen settes fast, så like klosser deler hurtigbuffer.
            $vars = [
                'unit'          => (float) $p['unit'],
                'nx'            => $nx,
                'ny'            => $ny,
                'nz'            => $nz,
                'edge_radius'   => (float) $p['edge_radius'],
                'grid_on'       => $grid ? 1 : 0,
                'groove_depth'  => $grid ? (float) $p['groove_depth'] : 0.0,
                'groove_width'  => $grid ? (float) $p['groove_width'] : 0.0,
                'hollow'        => $hollow ? 1 : 0,
                'wall'          => $hollow ? (float) $p['wall'] : 0.0,
                'edge_segments' => (int) $def['scad_constants']['edge_segments'],
            ];
            $size = $this->size($type, $p);
            $parts[] = [
                'key'       => $type,
                'scad'      => $def['scad'],
                'variables' => $vars,
                'bounds'    => $this->expectedBounds($type, $p),
                'filename'  => $def['filename'] . "_$type.stl",
                'count'     => $p['counts'][$type],
                'cache_key' => json_encode([$def['id'], $def['version'], $vars]),
                'info'      => [
                    'type'        => $type,
                    'value'       => $t['value'],
                    'units'       => $t['units'],
                    'name'        => $t['names'][0],
                    'name_plural' => $t['names'][1],
                    'color'       => $t['color']['hex'],
                    'color_name'  => $t['color']['name'],
                    'size_mm'     => $size,
                    'unit'        => (float) $p['unit'],
                    'grid'        => $grid,
                    'groove'      => ['depth' => $vars['groove_depth'], 'width' => $vars['groove_width']],
                    'hollow'      => $hollow,
                    'material'    => $this->material($type, $p),
                ],
            ];
        }
        return $parts;
    }

    public function warnings(array $p): array
    {
        $w = [];
        if (in_array('tusener', $p['types'], true)) {
            $w[] = $p['hollow']
                ? 'Den hule tusenerkuben skrives ut med den åpne siden opp, slik at den ikke trenger støtte. Snu den etter utskrift.'
                : sprintf('Den massive tusenerkuben er stor (%s mm). Bruk lite fyll, for eksempel 10 %%, ellers går det mye filament.',
                    self::fmt(10 * $p['unit']));
        }
        if ($p['grid'] && $p['groove_depth'] < 0.3) {
            $w[] = 'Svært grunne riller kan bli utydelige etter printing. 0,4 mm er et godt utgangspunkt.';
        }
        if ($p['edge_radius'] > 0 && $p['unit'] < 8 && $p['edge_radius'] > 0.6) {
            $w[] = 'Stor kantavrunding på små klosser gjør at enerne ser runde ut. Vurder mindre avrunding.';
        }
        return $w;
    }

    public function describe(array $p): array
    {
        $objects = [];
        $pieces = 0;
        $value = 0;
        $solid = 0.0;
        $print = 0.0;
        foreach ($this->parts($p) as $part) {
            $i = $part['info'];
            $pieces += $part['count'];
            $value += $part['count'] * $i['value'];
            $solid += $part['count'] * $i['material']['solid_g'];
            $print += $part['count'] * $i['material']['print_g'];
            $objects[] = [
                'type'     => $i['type'],
                'value'    => $i['value'],
                'size_mm'  => $i['size_mm'],
                'hollow'   => $i['hollow'],
                'count'    => $part['count'],
                'stl'      => $part['filename'],
                'color'    => $i['color'],
                'material_g' => $i['material'],
            ];
        }
        return [
            'model'   => $this->definition['id'],
            'version' => $this->definition['version'],
            'units'   => 'mm',
            'blocks'  => [
                'unit'         => $p['unit'],
                'edge_radius'  => $p['edge_radius'],
                'grid'         => $p['grid'] ? ['depth' => $p['groove_depth'], 'width' => $p['groove_width']] : null,
                'thousand'     => in_array('tusener', $p['types'], true)
                    ? ($p['hollow'] ? ['style' => 'hollow', 'wall' => $p['wall']] : ['style' => 'solid'])
                    : null,
            ],
            'objects'      => $objects,
            'total_pieces' => $pieces,
            'total_value'  => $value,
            'largest_number' => (new Base10Tasks($this->countsByValue($p)))->largestCanonical(),
            'material_g'   => ['print' => round($print), 'solid' => round($solid)],
        ];
    }

    /* ------------------------------------------------------- PDF og nedlasting */

    public function renderPdf(array $p): string
    {
        return (new Base10PdfGenerator($this))->render($p);
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
        $lines = [
            'M+ 3D – Base 10 / plassverdimateriell',
            '',
            'Parametere',
            '  Enhetslengde:    ' . self::fmt($p['unit']) . ' mm (enerkuben)',
            '  Kantavrunding:   ' . self::fmt($p['edge_radius']) . ' mm (fas mot byggeplaten)',
            '  Rutenett:        ' . ($p['grid'] ? sprintf('på, riller %s mm dype og %s mm brede', self::fmt($p['groove_depth']), self::fmt($p['groove_width'])) : 'av'),
        ];
        if (in_array('tusener', $p['types'], true)) {
            $lines[] = '  Tusenerkloss:    ' . ($p['hollow'] ? 'hul, veggtykkelse ' . self::fmt($p['wall']) . ' mm, åpen side opp ved utskrift' : 'massiv');
        }
        $lines[] = '';
        $lines[] = 'Utskriftsoversikt';
        $lines[] = 'Hver STL-fil er én kloss. Skriv ut så mange eksemplarer som står under «Antall».';
        $lines[] = '';
        $lines[] = str_pad('Fil', 22) . str_pad('Objekt', 10) . str_pad('Verdi', 7) . str_pad('Mål (mm)', 18) . str_pad('Antall', 8) . 'Ca. filament';
        $pieces = 0;
        $grams = 0.0;
        foreach ($parts as $part) {
            $i = $part['info'];
            $pieces += $part['count'];
            $g = $part['count'] * $i['material']['print_g'];
            $grams += $g;
            $lines[] = str_pad($part['filename'], 22) . self::pad($i['name'], 10) . str_pad((string) $i['value'], 7)
                . self::pad(implode(' × ', array_map([self::class, 'fmt'], $i['size_mm'])), 18)
                . str_pad((string) $part['count'], 8) . self::grams($g);
        }
        $lines[] = '';
        $lines[] = "Totalt: $pieces klosser, ca. " . self::grams($grams) . ' PLA.';
        $lines[] = '';
        $lines[] = 'Anbefalte utskriftsinnstillinger';
        $lines[] = '  - PLA, 0,2 mm laghøyde, 0,4 mm dyse, 2–3 vegger, 15 % fyll.';
        $lines[] = '  - Klossene ligger slik de skal skrives ut. Ingen støtte trengs.';
        $lines[] = '  - Bruk gjerne én farge per objekttype: ener gul, tier grønn, hundrer blå, tusener oransje.';
        $lines[] = '  - Materialforbruket er et overslag for 2 vegger og 15 % fyll. Sliceren gir nøyaktig tall.';
        return "\u{FEFF}" . implode("\r\n", $lines) . "\r\n";
    }

    public function queryKeys(): array
    {
        $keys = array_merge(array_keys($this->definition['parameters']), array_keys($this->definition['switches']), ['types']);
        foreach ($this->typeKeys() as $t) {
            $keys[] = "count_$t";
        }
        return $keys;
    }

    /* ------------------------------------------------------- Hjelpefunksjoner */

    public function type(string $key): array
    {
        return $this->definition['types'][$key];
    }

    /** Navn: form 0 = entall, 1 = flertall, 2 = bestemt flertall. */
    public function name(string $type, int $form = 0): string
    {
        return $this->definition['types'][$type]['names'][$form];
    }

    /** «1 tier», «3 tiere» */
    public function countName(string $type, int $n): string
    {
        return $n . ' ' . $this->name($type, $n === 1 ? 0 : 1);
    }

    /** Typen med en gitt verdi (1, 10, 100, 1000). */
    public function typeForValue(int $value): string
    {
        foreach ($this->definition['types'] as $key => $t) {
            if ($t['value'] === $value) {
                return $key;
            }
        }
        throw new InvalidArgumentException("Ingen type med verdi $value.");
    }

    /** Antall per plassverdi: [1 => …, 10 => …, 100 => …, 1000 => …]. */
    public function countsByValue(array $p): array
    {
        $out = [];
        foreach ($this->definition['types'] as $key => $t) {
            $out[$t['value']] = $p['counts'][$key] ?? 0;
        }
        return $out;
    }

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

    /** str_pad som teller tegn, ikke byte (æøå). */
    private static function pad(string $s, int $len): string
    {
        return $s . str_repeat(' ', max(1, $len - mb_strlen($s)));
    }
}
