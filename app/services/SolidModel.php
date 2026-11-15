<?php
declare(strict_types=1);

/**
 * Romfigurer og utbrettingsmodeller (kube, rektangulært prisme, firkantet
 * pyramide, trekantet prisme, sylinder og kjegle).
 *
 * Én STL per figur. Geometrien kommer fra den felles geometrimodellen
 * (Solid og underklassene): de samme tallene gir STL-fila, 3D-visningen,
 * utbrettingsnettet (SVG og PDF), egenskapene og oppgavene.
 *
 * Parametere (i tillegg til tallene i konfigurasjonen):
 *   shape       cube | cuboid | pyramid | triprism | cylinder | cone
 *   hollow      true/false   hul modell med veggtykkelse wall
 *   level       1–3          vanskelighetsgrad i oppgave-PDF-en
 *   net_print   tiles | scaled   store nett: flere ark i 1:1, eller skalert
 *   document    net | tasks  hvilken PDF (action=pdf)
 */
final class SolidModel implements ParametricModel
{
    /** @var array<string,array> hurtigbuffer for describe() innenfor én forespørsel */
    private array $memo = [];

    public function __construct(private array $definition)
    {
    }

    public static function load(string $configDir): self
    {
        return new self(require $configDir . '/models/solid_figures.php');
    }

    public function key(): string
    {
        return 'solids';
    }

    public function definition(): array
    {
        return $this->definition;
    }

    public function schema(): array
    {
        $def = $this->definition;
        $shapes = [];
        foreach ($def['shapes'] as $k => $s) {
            $shapes[$k] = ['title' => $s['title'], 'short' => $s['short'], 'params' => array_values($s['params'])];
        }
        return [
            'model'      => $def['id'],
            'version'    => $def['version'],
            'shapes'     => $shapes,
            'parameters' => $def['parameters'],
            'switches'   => $def['switches'],
            'choices'    => $def['choices'],
            'face_colors' => $def['face_colors'],
            'defaults'   => $this->defaults(),
        ];
    }

    public function defaults(): array
    {
        $def = $this->definition;
        $p = ['shape' => $def['default_shape']];
        foreach ($def['parameters'] as $k => $spec) {
            $p[$k] = (float) $spec['default'];
        }
        foreach ($def['switches'] as $k => $spec) {
            $p[$k] = (bool) $spec['default'];
        }
        foreach ($def['choices'] as $k => $spec) {
            $p[$k] = $k === 'level' ? (int) $spec['default'] : $spec['default'];
        }
        return $p;
    }

    /** @throws ValidationException */
    public function validate(array $input): array
    {
        $def = $this->definition;
        [$params, $errors] = ParameterValidator::numbers($def['parameters'], $input);

        $shape = $input['shape'] ?? $def['default_shape'];
        if (!is_string($shape) || !isset($def['shapes'][$shape])) {
            $errors[] = ['field' => 'shape', 'message' => 'Velg en av romfigurene.'];
            $shape = $def['default_shape'];
        }
        $params['shape'] = $shape;

        foreach ($def['switches'] as $key => $spec) {
            $v = array_key_exists($key, $input) ? ParameterValidator::toBool($input[$key]) : (bool) $spec['default'];
            if ($v === null) {
                $errors[] = ['field' => $key, 'message' => "Ugyldig valg for «{$spec['label']}»."];
            }
            $params[$key] = (bool) $v;
        }

        foreach ($def['choices'] as $key => $spec) {
            $raw = $input[$key] ?? $spec['default'];
            $v = is_int($raw) ? (string) $raw : (is_string($raw) ? trim($raw) : null);
            if ($v === null || !isset($spec['options'][$v])) {
                $errors[] = ['field' => $key, 'message' => "Ugyldig valg for «{$spec['label']}»."];
                $v = $spec['default'];
            }
            $params[$key] = $key === 'level' ? (int) $v : $v;
        }

        if ($errors) {
            throw new ValidationException($errors);
        }

        // Hul modell: hulrommet må få plass innenfor veggene.
        if ($params['hollow']) {
            $cavity = $this->solid($params)->cavity($params['wall']);
            if (!$cavity['ok']) {
                throw new ValidationException([['field' => 'wall', 'message' => sprintf(
                    'Med disse målene blir hulrommet for lite med %s mm vegg. Bruk tynnere vegg, større mål eller massiv modell.',
                    self::fmt($params['wall'])
                )]]);
            }
        }
        return $params;
    }

    /* --------------------------------------------------- Geometrimodellen */

    /** Den felles geometrimodellen for figuren i $p. */
    public function solid(array $p): Solid
    {
        $shape = $this->definition['shapes'][$p['shape']];
        $dims = [];
        foreach ($shape['params'] as $dim => $param) {
            $dims[$dim] = (float) $p[$param];
        }
        $class = $shape['class'];
        return new $class($dims, (int) $this->definition['segments'], $this->definition['face_colors']);
    }

    /** Mål for figuren i $p, for filnavn: «40mm», «60x40x30mm». */
    private function dimsTag(array $p): string
    {
        $vals = array_map(fn($k) => str_replace(',', '_', self::fmt($p[$k])), $this->definition['shapes'][$p['shape']]['params']);
        return implode('x', $vals) . 'mm';
    }

    private function slug(array $p): string
    {
        $slug = ['cube' => 'kube', 'cuboid' => 'prisme', 'pyramid' => 'pyramide', 'triprism' => 'trekantprisme',
                 'cylinder' => 'sylinder', 'cone' => 'kjegle'][$p['shape']] ?? $p['shape'];
        return $this->definition['filename'] . '-' . $slug . '-' . $this->dimsTag($p);
    }

    /** Utbrettingsnett og A4-plassering (samme for SVG og PDF). */
    public function net(array $p, ?string $mode = null): array
    {
        $key = 'net|' . json_encode($p) . '|' . $mode;
        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }
        $nets = $this->solid($p)->nets((float) $p['tab_width']);
        $plan = NetLayout::plan($nets, $mode ?? $p['net_print']);
        return $this->memo[$key] = ['net' => $nets[$plan['variant']], 'plan' => $plan];
    }

    /** @return array{solid_g:float,print_g:float,volume_mm3:float} */
    public function material(array $p): array
    {
        $m = $this->definition['material'];
        $solid = $this->solid($p);
        $V = $solid->polygonalVolume();
        if ($p['hollow']) {
            $Vp = $V - $solid->cavity($p['wall'])['volume'];
            $print = $Vp;
        } else {
            $Vp = $V;
            $shell = min($V, $solid->surfaceArea() * $m['shell']);
            $print = $shell + $m['infill'] * ($V - $shell);
        }
        return [
            'volume_mm3' => round($Vp, 1),
            'solid_g'    => round($Vp / 1000 * $m['density'], 1),
            'print_g'    => round($print / 1000 * $m['density'], 1),
        ];
    }

    /* ------------------------------------------------------------------- Deler */

    public function scadVariables(array $p): array
    {
        $solid = $this->solid($p);
        $vars = $solid->scad() + [
            'hollow'  => $p['hollow'] ? 1 : 0,
            'wall'    => $p['hollow'] ? (float) $p['wall'] : 0.0,
            'c_x' => 0.0, 'c_y' => 0.0, 'c_top' => 0.0, 'c_half' => 0.0, 'c_len' => 0.0, 'c_r' => 0.0,
            'ridge_x' => 0.0, 'ridge_y' => 0.0,
            'segments' => (int) $this->definition['segments'],
        ];
        if ($p['hollow']) {
            foreach ($solid->cavity($p['wall'])['vars'] as $k => $v) {
                $vars[$k] = (float) $v;
            }
        }
        foreach (['sx', 'sy', 'sz'] as $k) {
            $vars[$k] = (float) $vars[$k];
        }
        return $vars;
    }

    public function parts(array $p): array
    {
        $def = $this->definition;
        $solid = $this->solid($p);
        [$x, $y, $z] = $solid->size();
        $vars = $this->scadVariables($p);
        $cavity = $p['hollow'] ? $solid->cavity($p['wall']) : null;
        return [[
            'key'       => $p['shape'],
            'scad'      => $def['scad'],
            'variables' => $vars,
            'bounds'    => ['min' => [-$x / 2, -$y / 2, 0.0], 'max' => [$x / 2, $y / 2, (float) $z]],
            'shells'    => $p['hollow'] ? 2 : 1,
            'filename'  => $this->slug($p) . ($p['hollow'] ? '-hul' : '') . '.stl',
            'count'     => 1,
            'cache_key' => json_encode([$def['id'], $def['version'], $vars]),
            'info'      => [
                'shape'    => $p['shape'],
                'name'     => $solid->name(),
                'size_mm'  => $solid->size(),
                'hollow'   => $p['hollow'],
                'wall'     => $p['hollow'] ? $p['wall'] : null,
                'roof'     => $cavity['roof'] ?? false,
                'volume_mm3' => round($solid->polygonalVolume() - ($cavity['volume'] ?? 0.0), 1),
                'segments' => in_array($p['shape'], ['cylinder', 'cone'], true) ? (int) $def['segments'] : null,
            ],
        ]];
    }

    public function warnings(array $p): array
    {
        $w = [];
        $plan = $this->net($p)['plan'];
        if ($plan['mode'] === 'scaled') {
            $w[] = sprintf('Utbrettingsfiguren i PDF-en er forminsket til målestokk %s for å få plass på ett ark. Papirfiguren blir mindre enn 3D-modellen.', $plan['scale_label']);
        } elseif ($plan['mode'] === 'tiles') {
            $w[] = sprintf('Utbrettingsfiguren er for stor for ett A4-ark i målestokk 1:1. PDF-en deler den på %d ark (%d × %d) som limes sammen.',
                count($plan['pages']), $plan['rows'], $plan['cols']);
        }
        return $w;
    }

    public function describe(array $p): array
    {
        $key = 'describe|' . json_encode($p);
        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }
        $solid = $this->solid($p);
        ['net' => $net, 'plan' => $plan] = $this->net($p);
        $cavity = $p['hollow'] ? $solid->cavity($p['wall']) : null;
        $dims = [];
        foreach ($this->definition['shapes'][$p['shape']]['params'] as $dim => $param) {
            $dims[$dim] = $p[$param];
        }
        return $this->memo[$key] = [
            'model'    => $this->definition['id'],
            'version'  => $this->definition['version'],
            'units'    => 'mm',
            'shape'    => $p['shape'],
            'title'    => $solid->name(),
            'dimensions' => $dims,
            'geometry' => $solid->toArray(),
            'hollow'   => $cavity ? ['wall' => $p['wall'], 'roof_45' => $cavity['roof'], 'cavity_mm3' => round($cavity['volume'], 1)] : null,
            'net'      => $net->toArray() + ['tab_width' => $p['tab_width']],
            'layout'   => $plan,
            'stl'      => $this->parts($p)[0]['filename'],
            'material_g' => $this->material($p),
        ];
    }

    /* ------------------------------------------------------- PDF og nedlasting */

    public function renderPdf(array $p): string
    {
        return $p['document'] === 'tasks'
            ? (new SolidTasksPdf($this))->render($p)
            : (new SolidNetPdf($this))->render($p);
    }

    public function pdfFilename(array $p): string
    {
        return $this->slug($p) . ($p['document'] === 'tasks' ? '-oppgaver-niva' . $p['level'] : '-nett') . '.pdf';
    }

    public function archiveName(array $p): string
    {
        return $this->slug($p);
    }

    public function archiveReadme(array $p, array $parts): string
    {
        $solid = $this->solid($p);
        $mat = $this->material($p);
        $lines = [
            'M+ 3D – Romfigur: ' . $solid->name(),
            '',
            'Mål',
        ];
        foreach ($solid->measures() as $m) {
            $lines[] = '  ' . self::pad($m['label'] . " ({$m['symbol']})", 40) . Solid::fmt($m['value'], 2) . ' ' . $m['unit'];
        }
        $lines[] = '  ' . self::pad('Grunnflateareal', 40) . Solid::fmt($solid->baseArea(), 1) . ' mm²';
        $lines[] = '  ' . self::pad('Overflateareal', 40) . Solid::fmt($solid->surfaceArea(), 1) . ' mm²';
        $lines[] = '  ' . self::pad('Volum', 40) . Solid::fmt($solid->volume(), 1) . ' mm³';
        $lines[] = '';
        $lines[] = 'STL';
        $lines[] = '  Fil:     ' . $parts[0]['filename'];
        $lines[] = '  Modell:  ' . ($p['hollow'] ? 'hul, ' . self::fmt($p['wall']) . ' mm vegg (lukket hulrom med 45°-tak, ingen støtte)' : 'massiv (sliceren bestemmer fyllgraden)');
        $lines[] = '  Plast:   ca. ' . Solid::fmt($mat['print_g'], 0) . ' g PLA';
        if (in_array($p['shape'], ['cylinder', 'cone'], true)) {
            $lines[] = '  Krumme flater er tilnærmet med ' . $this->definition['segments'] . ' plane sider.';
        }
        $lines[] = '';
        $lines[] = 'Utskrift';
        $lines[] = '  - Figuren står slik den skal skrives ut. Ingen støtte trengs.';
        $lines[] = '  - PLA, 0,2 mm laghøyde, 2–3 vegger. Massiv modell: 15 % fyll.';
        $lines[] = '  - Skriv ut PDF-ene i 100 % (faktisk størrelse). Kontrollinjen skal være 50 mm.';
        return "\u{FEFF}" . implode("\r\n", $lines) . "\r\n";
    }

    public function queryKeys(): array
    {
        $def = $this->definition;
        return array_merge(['shape'], array_keys($def['parameters']), array_keys($def['switches']), array_keys($def['choices']));
    }

    /* ------------------------------------------------------- Hjelpefunksjoner */

    public static function fmt(float|int $v): string
    {
        return ParameterValidator::fmt($v);
    }

    private static function pad(string $s, int $len): string
    {
        return $s . str_repeat(' ', max(1, $len - mb_strlen($s)));
    }
}
