<?php
declare(strict_types=1);

/**
 * Brøksirkler: parametere, validering, geometri for kontroll, brøkmerking.
 *
 * Én STL-fil per nevner (alle brikkene i et brøksett er like). Brikka for
 * nevner n er en sektor på 360/n grader med sentrum i origo og
 * halveringslinja langs +Y (se models/fractions/sector.scad).
 */
final class FractionModel implements ParametricModel
{
    public function __construct(private array $definition)
    {
    }

    public static function load(string $configDir): self
    {
        return new self(require $configDir . '/models/fraction_circles.php');
    }

    public function key(): string
    {
        return 'fractions';
    }

    public function definition(): array
    {
        return $this->definition;
    }

    public function schema(): array
    {
        $def = $this->definition;
        $colors = [];
        $names = [];
        foreach (range($def['denominators']['min'], $def['denominators']['max']) as $n) {
            $colors[$n] = $def['colors'][$n];
            $names[$n] = $def['names'][$n];
        }
        return [
            'model'        => $def['id'],
            'version'      => $def['version'],
            'parameters'   => $def['parameters'],
            'denominators' => $def['denominators'],
            'labels'       => ['default' => $def['labels']['default'], 'styles' => $def['labels']['styles']],
            'colors'       => $colors,
            'names'        => $names,
            'defaults'     => $this->defaults(),
        ];
    }

    public function defaults(): array
    {
        $p = [];
        foreach ($this->definition['parameters'] as $key => $spec) {
            $p[$key] = (float) $spec['default'];
        }
        $p['labels'] = (bool) $this->definition['labels']['default'];
        $p['denominators'] = $this->definition['denominators']['default'];
        return $p;
    }

    /**
     * Godtar:
     *   diameter, thickness, clearance, edge_radius, label_depth   tall
     *   denominators   liste med heltall eller tekst "2,3,4"
     *   labels         true/false, 1/0, "on"/"off", "engraved"/"none"
     *
     * @throws ValidationException
     */
    public function validate(array $input): array
    {
        [$params, $errors] = ParameterValidator::numbers($this->definition['parameters'], $input);

        // Nevnere
        $spec = $this->definition['denominators'];
        $raw = $input['denominators'] ?? $spec['default'];
        if (is_string($raw)) {
            $raw = $raw === '' ? [] : explode(',', $raw);
        }
        $dens = [];
        if (!is_array($raw) || count($raw) > 24) {
            $errors[] = ['field' => 'denominators', 'message' => 'Ugyldig liste med nevnere.'];
        } else {
            foreach ($raw as $v) {
                $n = is_int($v) ? $v : (is_string($v) && preg_match('/^\s*\d{1,2}\s*$/', $v) ? (int) $v : null);
                if ($n === null || $n < $spec['min'] || $n > $spec['max']) {
                    $errors[] = ['field' => 'denominators', 'message' => sprintf(
                        'Nevnerne må være hele tall fra %d til %d.', $spec['min'], $spec['max']
                    )];
                    break;
                }
                $dens[$n] = $n;
            }
            if (!$dens && !$errors) {
                $errors[] = ['field' => 'denominators', 'message' => 'Velg minst én nevner.'];
            }
        }
        sort($dens);
        $params['denominators'] = array_values($dens);

        // Merking
        $labels = $input['labels'] ?? $this->definition['labels']['default'];
        $labels = match ($labels) { 'engraved' => true, 'none' => false, default => ParameterValidator::toBool($labels) };
        if ($labels === null) {
            $errors[] = ['field' => 'labels', 'message' => 'Ugyldig valg for brøkmerking.'];
        }
        $params['labels'] = (bool) $labels;

        if ($errors) {
            throw new ValidationException($errors);
        }

        // Kombinasjoner (holder alltid med dagens grenser, men gjør modellen robust
        // hvis grensene endres i konfigurasjonen).
        if ($params['label_depth'] > $params['thickness'] - 1) {
            $errors[] = ['field' => 'label_depth', 'message' => 'Merkingen må være minst 1 mm grunnere enn brikketykkelsen.'];
        }
        if ($params['edge_radius'] > $params['thickness'] / 2) {
            $errors[] = ['field' => 'edge_radius', 'message' => 'Kantavrundingen kan ikke være mer enn halve tykkelsen.'];
        }
        if ($errors) {
            throw new ValidationException($errors);
        }
        return $params;
    }

    /* ---------------------------------------------------------------- Geometri */

    /** Antall segmenter i ytterbuen. Partall, så det alltid er et punkt på +Y. */
    public function arcSegments(int $n, array $p): int
    {
        $step = (float) $this->definition['scad_constants']['arc_step_deg'];
        if ($n === 1) {
            return (int) (4 * ceil(360 / $step / 4));
        }
        $R = $p['diameter'] / 2;
        $alpha = rad2deg(asin(($p['clearance'] / 2) / $R));
        $arc = 360 / $n - 2 * $alpha;
        $seg = max(2, (int) ceil($arc / $step));
        return $seg % 2 ? $seg + 1 : $seg;
    }

    /**
     * Omrisset av brikka, samme formel som outline() i sector.scad.
     * @return list<array{float,float}>
     */
    public static function outline(int $n, float $off, float $rr, int $segments): array
    {
        $pts = [];
        if ($n === 1) {
            for ($i = 0; $i < $segments; $i++) {
                $a = deg2rad(90 + 360 * $i / $segments);
                $pts[] = [$rr * cos($a), $rr * sin($a)];
            }
            return $pts;
        }
        $phi = 180 / $n;
        $al = rad2deg(asin($off / $rr));
        $a0 = 90 - $phi + $al;
        $a1 = 90 + $phi - $al;
        if ($n > 2) {
            $pts[] = [0.0, $off / sin(deg2rad($phi))];
        }
        for ($i = 0; $i <= $segments; $i++) {
            $a = deg2rad($a0 + ($a1 - $a0) * $i / $segments);
            $pts[] = [$rr * cos($a), $rr * sin($a)];
        }
        return $pts;
    }

    /** Forventede ytre mål for brikka med nevner n. */
    public function expectedBounds(int $n, array $p): array
    {
        $pts = self::outline($n, $p['clearance'] / 2, $p['diameter'] / 2, $this->arcSegments($n, $p));
        $xs = array_column($pts, 0);
        $ys = array_column($pts, 1);
        return [
            'min' => [min($xs), min($ys), 0.0],
            'max' => [max($xs), max($ys), (float) $p['thickness']],
        ];
    }

    /**
     * Plassering og størrelse på brøkmerket for nevner n.
     *
     * Teksten skaleres til en boks B × H (B = H · tekstens proporsjon) med
     * sentrum på halveringslinja, i avstand «dist» fra sirkelsentrum. Boksen
     * må ligge på den flate toppen: minst (klaring/2 + avrunding + marg) fra
     * de radielle kantene og innenfor radius R − avrunding − marg. Smale
     * brikker får mindre tekst; er det plass til mer, kan teksten roteres 90°
     * så den går langs brikka. Blir teksten lavere enn min_height, utelates den.
     *
     * @return array{shown:bool,reduced:bool,reason:?string,text:string,width:float,height:float,dist:float,rotate:int}
     */
    public function labelLayout(int $n, array $p): array
    {
        $cfg = $this->definition['labels'];
        $text = $n === 1 ? '1' : "1/$n";
        $none = ['shown' => false, 'reduced' => false, 'reason' => null, 'text' => $text,
                 'width' => 0.0, 'height' => 0.0, 'dist' => 0.0, 'rotate' => 0];
        if (!$p['labels']) {
            return ['reason' => 'off'] + $none;
        }

        $R = $p['diameter'] / 2;
        $k = (float) $cfg['aspect'][$n];
        $cap = min((float) $cfg['max_height'], $p['diameter'] * $cfg['height_ratio']);

        $hu = $this->maxLabelHeight($n, $p, $k, 0);
        $hr = $n > 2 ? $this->maxLabelHeight($n, $p, $k, 90) : 0.0;
        $rotate = ($hr > $hu * 1.25 && $hu * $cfg['fill'] < $cap) ? 90 : 0;
        $hmax = $rotate ? $hr : $hu;

        $h = min($cap, $hmax * $cfg['fill']);
        if ($h < $cfg['min_height']) {
            if ($hmax < $cfg['min_height']) {
                return ['reason' => 'too_small'] + $none;
            }
            $h = (float) $cfg['min_height'];
        }
        $h = floor($h * 100) / 100;
        $w = floor($h * $k * 100) / 100;

        [$lo, $hi] = $this->labelRange($n, $p, $w, $h, $rotate);
        $phi = M_PI / $n;
        $centroid = $n === 1 ? 0.0 : 2 * $R * sin($phi) / (3 * $phi);
        $dist = round(max($lo, min($hi, $centroid)), 2);

        return [
            'shown'   => true,
            'reduced' => $h < $cap - 1e-6,
            'reason'  => null,
            'text'    => $text,
            'width'   => $w,
            'height'  => $h,
            'dist'    => $dist,
            'rotate'  => $rotate,
        ];
    }

    /** Største teksthøyde som får plass (halveringsmetode). */
    private function maxLabelHeight(int $n, array $p, float $k, int $rotate): float
    {
        $lo = 0.0;
        $hi = (float) $p['diameter'];
        for ($i = 0; $i < 40; $i++) {
            $mid = ($lo + $hi) / 2;
            [$a, $b] = $this->labelRange($n, $p, $mid * $k, $mid, $rotate);
            if ($a <= $b) {
                $lo = $mid;
            } else {
                $hi = $mid;
            }
        }
        return $lo;
    }

    /**
     * Gyldige avstander fra sentrum for en tekstboks B × H. Tomt intervall (lo > hi)
     * betyr at boksen ikke får plass.
     * @return array{0:float,1:float}
     */
    public function labelRange(int $n, array $p, float $w, float $h, int $rotate): array
    {
        $m = (float) $this->definition['labels']['margin'];
        $r = (float) $p['edge_radius'];
        $rIn = $p['diameter'] / 2 - $r - $m;
        $e = $p['clearance'] / 2 + $r + $m;
        // halve utstrekningen langs halveringslinja (as) og på tvers (aq)
        [$as, $aq] = $rotate ? [$w / 2, $h / 2] : [$h / 2, $w / 2];
        if ($aq >= $rIn) {
            return [1.0, 0.0];
        }
        if ($n === 1) {
            return ($as * $as + $aq * $aq <= $rIn * $rIn) ? [0.0, 0.0] : [1.0, 0.0];
        }
        $phi = M_PI / $n;
        $lo = ($e + $aq * cos($phi)) / sin($phi) + $as;
        $hi = sqrt($rIn * $rIn - $aq * $aq) - $as;
        return [$lo, $hi];
    }

    /* ------------------------------------------------------------------- Deler */

    public function parts(array $p): array
    {
        $c = $this->definition['scad_constants'];
        $parts = [];
        foreach ($p['denominators'] as $n) {
            $label = $this->labelLayout($n, $p);
            $vars = [
                'diameter'      => (float) $p['diameter'],
                'thickness'     => (float) $p['thickness'],
                'clearance'     => (float) $p['clearance'],
                'edge_radius'   => (float) $p['edge_radius'],
                'denominator'   => $n,
                'label_on'      => $label['shown'] ? 1 : 0,
                'label_depth'   => (float) $p['label_depth'],
                'label_width'   => $label['shown'] ? $label['width'] : 1.0,
                'label_height'  => $label['shown'] ? $label['height'] : 1.0,
                'label_dist'    => $label['dist'],
                'label_rotate'  => $label['rotate'],
                'arc_segments'  => $this->arcSegments($n, $p),
                'edge_segments' => (int) $c['edge_segments'],
                'text_segments' => (int) $c['text_segments'],
            ];
            if (!$label['shown']) {
                $vars['label_depth'] = 0.0;    // påvirker ikke geometrien; gir felles hurtigbuffer
            }
            $parts[] = [
                'key'       => "1-$n",
                'scad'      => $this->definition['scad'],
                'variables' => $vars,
                'bounds'    => $this->expectedBounds($n, $p),
                'filename'  => $this->definition['filename'] . "_1-$n.stl",
                'count'     => $n,
                'cache_key' => json_encode([$this->definition['id'], $this->definition['version'], $vars]),
                'info'      => [
                    'denominator' => $n,
                    'fraction'    => $n === 1 ? '1' : "1/$n",
                    'name'        => $this->definition['names'][$n][0],
                    'name_plural' => $this->definition['names'][$n][1],
                    'color'       => $this->definition['colors'][$n]['hex'],
                    'color_name'  => $this->definition['colors'][$n]['name'],
                    'angle'       => round(360 / $n, 4),
                    'label'       => $label,
                ],
            ];
        }
        return $parts;
    }

    public function warnings(array $p): array
    {
        $w = [];
        if ($p['labels']) {
            $omitted = [];
            $reduced = [];
            foreach ($p['denominators'] as $n) {
                $l = $this->labelLayout($n, $p);
                if (!$l['shown']) {
                    $omitted[] = $l['text'];
                } elseif ($l['reduced']) {
                    $reduced[] = $l['text'];
                }
            }
            if ($omitted) {
                $w[] = sprintf(
                    'Brikkene for %s er for smale til brøkmerking, så merkingen er utelatt der. Velg større diameter for å få merking.',
                    self::joinList($omitted)
                );
            }
            if ($reduced) {
                $w[] = sprintf('Merkingen er gjort mindre på %s fordi brikkene er smale.', self::joinList($reduced));
            }
        }
        if ($p['clearance'] == 0.0) {
            $w[] = 'Med 0 mm klaring kan brikkene bli trange å legge sammen, fordi printede deler ofte blir litt større enn modellen.';
        }
        return $w;
    }

    public function describe(array $p): array
    {
        $sets = [];
        $total = 0;
        foreach ($this->parts($p) as $part) {
            $i = $part['info'];
            $total += $part['count'];
            $sets[] = [
                'denominator' => $i['denominator'],
                'fraction'    => $i['fraction'],
                'name'        => $i['name'],
                'pieces'      => $part['count'],
                'angle_deg'   => $i['angle'],
                'stl'         => $part['filename'],
                'color'       => $i['color'],
                'label'       => $i['label']['shown']
                    ? ['text' => $i['label']['text'], 'height' => $i['label']['height'], 'rotated' => $i['label']['rotate'] === 90]
                    : null,
            ];
        }
        return [
            'model'   => $this->definition['id'],
            'version' => $this->definition['version'],
            'units'   => 'mm',
            'circle'  => [
                'diameter'    => $p['diameter'],
                'thickness'   => $p['thickness'],
                'clearance'   => $p['clearance'],
                'edge_radius' => $p['edge_radius'],
            ],
            'labels'  => [
                'style' => $p['labels'] ? 'engraved' : 'none',
                'depth' => $p['labels'] ? $p['label_depth'] : 0,
            ],
            'sets'         => $sets,
            'total_pieces' => $total,
        ];
    }

    /* ------------------------------------------------------- PDF og nedlasting */

    public function renderPdf(array $p): string
    {
        return (new FractionPdfGenerator($this))->render($p);
    }

    public function pdfFilename(array $p): string
    {
        return $this->archiveName($p) . '.pdf';
    }

    public function archiveName(array $p): string
    {
        return $this->definition['archive'] . '-' . str_replace(',', '_', self::fmt($p['diameter'])) . 'mm';
    }

    public function archiveReadme(array $p, array $parts): string
    {
        $lines = [
            'M+ 3D – Brøksirkler',
            '',
            sprintf('Diameter %s mm, tykkelse %s mm, klaring %s mm, kantavrunding %s mm.',
                self::fmt($p['diameter']), self::fmt($p['thickness']), self::fmt($p['clearance']), self::fmt($p['edge_radius'])),
            'Brøkmerking: ' . ($p['labels'] ? 'nedsenket, ' . self::fmt($p['label_depth']) . ' mm' : 'ingen'),
            '',
            'Hver STL-fil er én brikke. Skriv ut så mange eksemplarer som står under «Antall».',
            '',
            str_pad('Fil', 26) . str_pad('Brøk', 8) . str_pad('Antall', 9) . 'Forslag til filamentfarge',
        ];
        $total = 0;
        foreach ($parts as $part) {
            $i = $part['info'];
            $total += $part['count'];
            $lines[] = str_pad($part['filename'], 26) . str_pad($i['fraction'], 8) . str_pad((string) $part['count'], 9) . $i['color_name'];
        }
        $lines[] = '';
        $lines[] = "Totalt: $total brikker.";
        $lines[] = '';
        $lines[] = 'Brikkene ligger flatt og trenger ikke støtte. Bruk gjerne ulike farger for hvert brøksett.';
        $lines[] = 'Skriv ut PDF-en med «Faktisk størrelse» / 100 %, så sirklene på arbeidsarket passer til brikkene.';
        return "\u{FEFF}" . implode("\r\n", $lines) . "\r\n";
    }

    public function queryKeys(): array
    {
        return array_merge(array_keys($this->definition['parameters']), ['denominators', 'labels']);
    }

    /* ------------------------------------------------------- Hjelpefunksjoner */

    /** Navn på delen: form 0 = entall, 1 = flertall, 2 = bestemt flertall. */
    public function name(int $n, int $form = 0): string
    {
        return $this->definition['names'][$n][$form];
    }

    public function color(int $n): array
    {
        return $this->definition['colors'][$n];
    }

    public static function fmt(float|int $v): string
    {
        return ParameterValidator::fmt($v);
    }

    /** «1/2, 1/3 og 1/4» */
    public static function joinList(array $items): string
    {
        if (count($items) < 2) {
            return (string) ($items[0] ?? '');
        }
        $last = array_pop($items);
        return implode(', ', $items) . ' og ' . $last;
    }
}
