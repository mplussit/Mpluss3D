<?php
declare(strict_types=1);

/**
 * Terningmodellen: parametere, validering og modellbeskrivelse.
 *
 * Klassen vet ingenting om OpenSCAD eller PDF. Den gjør brukerens input om
 * til et validert, normalisert parametersett som både ScadGenerator og
 * PdfGenerator bruker, slik at STL og PDF alltid bygger på samme grunnlag.
 */
final class DiceModel implements ParametricModel
{
    /** @param array<string,mixed> $definition innholdet i app/config/models/dice_cube.php */
    public function __construct(private array $definition)
    {
    }

    public static function load(string $configDir): self
    {
        return new self(require $configDir . '/models/dice_cube.php');
    }

    public function id(): string
    {
        return $this->definition['id'];
    }

    public function key(): string
    {
        return 'dice';
    }

    /* ------------------------------------------- ParametricModel (felles API) */

    public function schema(): array
    {
        $def = $this->definition;
        return [
            'model'      => $def['id'],
            'version'    => $def['version'],
            'parameters' => $def['parameters'],
            'faces'      => $def['faces'],
            'label'      => [
                'min'   => $def['label']['min'],
                'max'   => $def['label']['max'],
                'step'  => $def['label']['step'],
                'decor' => $def['label']['decor'],
                'default_decor' => $def['label']['default_decor'],
            ],
            'defaults'   => $this->defaults(),
        ];
    }

    /** Terningen er én del. */
    public function parts(array $p): array
    {
        return [[
            'key'       => 'cube',
            'scad'      => $this->definition['scad'],
            'variables' => $this->scadVariables($p),
            'bounds'    => $this->expectedBounds($p),
            'filename'  => $this->filename($p) . '.stl',
            'count'     => 1,
            'cache_key' => $this->parameterKey($p),
            'info'      => [],
        ]];
    }

    public function renderPdf(array $p): string
    {
        return (new PdfGenerator($this))->render($p);
    }

    public function pdfFilename(array $p): string
    {
        return $this->filename($p) . '-dekor.pdf';
    }

    public function archiveName(array $p): string
    {
        return $this->filename($p);
    }

    public function archiveReadme(array $p, array $parts): string
    {
        return "M+ 3D – terning\r\n\r\nSkriv ut 1 eksemplar av " . $parts[0]['filename'] . ".\r\n";
    }

    public function queryKeys(): array
    {
        return array_merge(array_keys($this->definition['parameters']), ['size', 'label', 'decor']);
    }

    public function definition(): array
    {
        return $this->definition;
    }

    /** Standardverdier for alle parametere (inkludert dekor). */
    public function defaults(): array
    {
        $p = [];
        foreach ($this->definition['parameters'] as $key => $spec) {
            $p[$key] = (float) $spec['default'];
        }
        $p['label_size'] = $this->autoLabelSize($p);
        $p['label_auto'] = true;
        $p['decor'] = $this->definition['label']['default_decor'];
        return $p;
    }

    /**
     * Validerer og normaliserer input fra API-et.
     *
     * Godtar:
     *   size              kortform som setter width, height og depth
     *   width/height/depth/corner_radius/pip_diameter/pip_depth
     *   label             "auto" eller tall i mm
     *   decor             pips | numbers | blank
     * Ukjente nøkler ignoreres. Tekst sendes aldri videre til OpenSCAD.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     * @throws ValidationException
     */
    public function validate(array $input): array
    {
        $errors = [];
        $params = [];

        if (array_key_exists('size', $input)) {
            foreach (['width', 'height', 'depth'] as $k) {
                if (!array_key_exists($k, $input)) {
                    $input[$k] = $input['size'];
                }
            }
        }

        foreach ($this->definition['parameters'] as $key => $spec) {
            if (!array_key_exists($key, $input) || $input[$key] === '' || $input[$key] === null) {
                $params[$key] = (float) $spec['default'];
                continue;
            }
            $value = self::toNumber($input[$key]);
            if ($value === null) {
                $errors[] = ['field' => $key, 'message' => "{$spec['label']} må være et tall."];
                continue;
            }
            if ($value < $spec['min'] || $value > $spec['max']) {
                $errors[] = ['field' => $key, 'message' => sprintf(
                    '%s må være mellom %s og %s %s.',
                    $spec['label'], self::fmt($spec['min']), self::fmt($spec['max']), $spec['unit']
                )];
                continue;
            }
            $params[$key] = round($value, 2);
        }

        if ($errors) {
            throw new ValidationException($errors);
        }

        $errors = $this->validateCombination($params);

        // Dekor
        $decor = $input['decor'] ?? $this->definition['label']['default_decor'];
        if (!is_string($decor) || !array_key_exists($decor, $this->definition['label']['decor'])) {
            $errors[] = ['field' => 'decor', 'message' => 'Ukjent dekortype.'];
        }
        $params['decor'] = is_string($decor) ? $decor : '';

        // Etikettstørrelse
        $label = $input['label'] ?? 'auto';
        $labelSpec = $this->definition['label'];
        if ($label === 'auto' || $label === '' || $label === null) {
            $params['label_size'] = $this->autoLabelSize($params);
            $params['label_auto'] = true;
        } else {
            $value = self::toNumber($label);
            if ($value === null || $value < $labelSpec['min'] || $value > $labelSpec['max']) {
                $errors[] = ['field' => 'label', 'message' => sprintf(
                    'Etikettstørrelsen må være «auto» eller mellom %s og %s mm.',
                    self::fmt($labelSpec['min']), self::fmt($labelSpec['max'])
                )];
            } else {
                $params['label_size'] = round($value, 1);
                $params['label_auto'] = false;
            }
        }

        if ($errors) {
            throw new ValidationException($errors);
        }
        return $params;
    }

    /**
     * Kontroller som avhenger av flere parametere samtidig.
     * @return list<array{field:string,message:string}>
     */
    private function validateCombination(array $p): array
    {
        $errors = [];
        $rules = $this->definition['rules'];
        $spread = $this->definition['scad_constants']['pip_spread'];
        $minDim = min($p['width'], $p['height'], $p['depth']);

        if ($p['corner_radius'] > $minDim / 2 - 1) {
            $errors[] = ['field' => 'corner_radius', 'message' => 'Hjørneradiusen er for stor for denne terningen.'];
        }

        if ($p['pip_depth'] > 0) {
            $a = $spread * $minDim;           // avstand fra sentrum til ytre prikk
            $maxPip = floor(($a - $rules['min_pip_gap']) * 10) / 10;
            if ($p['pip_diameter'] > $maxPip) {
                $errors[] = ['field' => 'pip_diameter', 'message' => sprintf(
                    'Prikkene blir for store for en terning på %s mm. Største prikkdiameter er %s mm.',
                    self::fmt($minDim), self::fmt($maxPip)
                )];
            }
            $maxRadius = floor(($minDim / 2 - $a - $p['pip_diameter'] / 2 - $rules['min_edge_margin']) * 10) / 10;
            if ($p['corner_radius'] > $maxRadius) {
                $errors[] = ['field' => 'corner_radius', 'message' => sprintf(
                    'Prikkene havner på de avrundede kantene. Velg hjørneradius på høyst %s mm, eller mindre prikker.',
                    self::fmt(max(0, $maxRadius))
                )];
            }
        }
        return $errors;
    }

    /** Største flate område på den minste siden, minus klaring. */
    public function autoLabelSize(array $p): float
    {
        $flat = $this->flatFaceSize($p);
        return floor(($flat - 2 * $this->definition['label']['clearance']) * 10 + 1e-9) / 10;
    }

    /** Sidelengden av den flate (ikke avrundede) delen av den minste siden. */
    public function flatFaceSize(array $p): float
    {
        return min($p['width'], $p['height'], $p['depth']) - 2 * $p['corner_radius'];
    }

    /** Ikke-blokkerende advarsler (vises i grensesnittet og PDF-en). */
    public function warnings(array $p): array
    {
        $w = [];
        $flat = $this->flatFaceSize($p);
        if ($p['label_size'] > $flat + 1e-9) {
            $w[] = sprintf(
                'Etiketten (%s mm) er større enn den flate delen av siden (%s mm) og vil dekke de avrundede kantene.',
                self::fmt($p['label_size']), self::fmt($flat)
            );
        }
        return $w;
    }

    /**
     * Variabler som sendes til OpenSCAD med -D. Bare tall.
     * @return array<string,float|int>
     */
    public function scadVariables(array $p): array
    {
        $vars = [];
        foreach ($this->definition['parameters'] as $key => $spec) {
            $vars[$spec['scad']] = (float) $p[$key];
        }
        foreach ($this->definition['scad_constants'] as $key => $value) {
            $vars[$key] = $value;
        }
        foreach ($this->definition['faces'] as $face => $content) {
            $vars['face_' . $face] = (int) $content;
        }
        return $vars;
    }

    /** Forventede ytre mål (mm) for kontroll av STL: [x, y, z]. */
    public function expectedBounds(array $p): array
    {
        return [
            'min' => [-$p['width'] / 2, -$p['depth'] / 2, 0.0],
            'max' => [$p['width'] / 2, $p['depth'] / 2, $p['height']],
        ];
    }

    /** Lesbart filnavn uten endelse, f.eks. "mplus-terning-30mm". */
    public function filename(array $p): string
    {
        $w = self::fmt($p['width']);
        $h = self::fmt($p['height']);
        $d = self::fmt($p['depth']);
        $size = ($w === $h && $h === $d) ? "{$w}mm" : "{$w}x{$d}x{$h}mm";
        return $this->definition['filename'] . '-' . str_replace(',', '_', $size);
    }

    /**
     * Modellbeskrivelse (JSON-struktur) for senere integrasjon med M+.
     * Se README.md, avsnittet «Modellbeskrivelse».
     */
    public function describe(array $p): array
    {
        return [
            'model'   => $this->definition['id'],
            'version' => $this->definition['version'],
            'units'   => 'mm',
            'dimensions' => [
                'width'  => $p['width'],
                'height' => $p['height'],
                'depth'  => $p['depth'],
            ],
            'corner_radius' => $p['corner_radius'],
            'pips' => [
                'style'    => $p['pip_depth'] > 0 ? 'recessed' : 'none',
                'diameter' => $p['pip_diameter'],
                'depth'    => $p['pip_depth'],
            ],
            'faces' => $this->definition['faces'],
            'label' => [
                'width'  => $p['label_size'],
                'height' => $p['label_size'],
                'decor'  => $p['decor'],
            ],
        ];
    }

    public function parameterKey(array $p): string
    {
        $geo = [];
        foreach (array_keys($this->definition['parameters']) as $k) {
            $geo[$k] = $p[$k];
        }
        return json_encode([$this->definition['id'], $this->definition['version'], $geo, $this->scadVariables($p)]);
    }

    /** Godtar int, float og numeriske strenger (også med desimalkomma). */
    public static function toNumber(mixed $v): ?float
    {
        if (is_int($v) || is_float($v)) {
            return is_finite((float) $v) ? (float) $v : null;
        }
        if (is_string($v)) {
            $v = str_replace(',', '.', trim($v));
            if (preg_match('/^-?\d{1,4}(\.\d{1,4})?$/', $v)) {
                return (float) $v;
            }
        }
        return null;
    }

    /** Norsk tallformat uten unødvendige desimaler: 2.5 → "2,5". */
    public static function fmt(float|int $v): string
    {
        $s = rtrim(rtrim(number_format((float) $v, 2, ',', ''), '0'), ',');
        return $s === '-0' ? '0' : $s;
    }
}
