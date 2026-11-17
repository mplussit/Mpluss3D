<?php
declare(strict_types=1);

/**
 * «Regn med Lego»: rammer som elevene fyller med standard LEGO®-klosser.
 * Antallet knottplasser i en full ramme er en mengde, og flere rammer kan
 * stå etter hverandre som et regnestykke (8 + 4 = 12).
 *
 * Et prosjekt har 1–12 rammer. Hver ramme har egen form (rutenett), egne
 * tykkelser, bunn, merkefelt og regnetegn etter seg. Én STL per ramme.
 *
 * Geometri (alt i mm, se models/lego/frame.scad):
 *   - Hver valgt rute er én knottplass, 8 × 8 mm. Åpningen er unionen av
 *     rutene utvidet med klaringen på alle sider (skarpe hjørner, ingen
 *     vegger mellom rutene).
 *   - Veggen bygges utover: ytterkanten er rutene utvidet med klaring + vegg.
 *     Tykkere vegg gir aldri mindre plass til klossene.
 *   - Innvendig høyde er alltid 9,6 mm over støtteflaten. Med bunn er total
 *     høyde bunn + 9,6 mm, uten bunn 9,6 mm og åpen gjennom.
 *   - Utsparinger i formen blir hull gjennom hele rammen, også bunnen.
 *   - Merkefeltet er en flat blokk utenfor veggen, like høy som rammen, langs
 *     den lengste rekken av ruter på valgt side. Teksten (innfelt eller
 *     opphøyd) eller papiretiketten ligger på toppen av feltet, aldri i åpningen.
 *
 * Inndata (JSON): ['frames' => [ramme, …], 'operator_cards' => bool]
 *   ramme: rows (liste med «0»/«1», øverste rad først), wall, bottom,
 *          bottom_thickness, clearance, field, field_side, field_depth,
 *          text_mode, text, text_style, text_depth, copies, operator
 * Med GET sendes hele prosjektet som JSON i parameteren «project».
 */
final class LegoModel implements ParametricModel
{
    public function __construct(private array $definition)
    {
    }

    public static function load(string $configDir): self
    {
        return new self(require $configDir . '/models/lego_frames.php');
    }

    public function key(): string
    {
        return 'lego';
    }

    public function definition(): array
    {
        return $this->definition;
    }

    public function schema(): array
    {
        $d = $this->definition;
        return [
            'model'        => $d['id'],
            'version'      => $d['version'],
            'module'       => $d['module'],
            'inner_height' => $d['inner_height'],
            'grid'         => $d['grid'],
            'max_frames'   => $d['max_frames'],
            'parameters'   => $d['parameters'],
            'switches'     => $d['switches'],
            'choices'      => $d['choices'],
            'text'         => $d['text'],
            'labels'       => $d['labels'],
            'defaults'     => $this->defaults(),
        ];
    }

    /** Standardprosjekt: én 4 × 4-ramme. */
    public function defaults(): array
    {
        return ['frames' => [$this->frameDefaults()], 'operator_cards' => true];
    }

    public function frameDefaults(): array
    {
        $d = $this->definition;
        $f = ['rows' => array_fill(0, $d['grid']['default_rows'], str_repeat('1', $d['grid']['default_cols']))];
        foreach ($d['parameters'] as $k => $spec) {
            $f[$k] = (float) $spec['default'];
        }
        foreach ($d['switches'] as $k => $spec) {
            $f[$k] = (bool) $spec['default'];
        }
        foreach ($d['choices'] as $k => $spec) {
            $f[$k] = $spec['default'];
        }
        $f['text'] = '';
        $f['copies'] = 1;
        return $f;
    }

    /* -------------------------------------------------------------- Validering */

    /** @throws ValidationException */
    public function validate(array $input): array
    {
        if (isset($input['project']) && is_string($input['project'])) {
            $decoded = strlen($input['project']) <= 60000 ? json_decode($input['project'], true) : null;
            if (!is_array($decoded)) {
                throw new ValidationException([['field' => 'project', 'message' => 'Ugyldig prosjekt.']]);
            }
            $input = $decoded;
        }
        $def = $this->definition;
        $frames = $input['frames'] ?? $this->defaults()['frames'];
        if (!is_array($frames) || !array_is_list($frames) || !$frames) {
            throw new ValidationException([['field' => 'frames', 'message' => 'Prosjektet må ha minst én ramme.']]);
        }
        if (count($frames) > $def['max_frames']) {
            throw new ValidationException([['field' => 'frames', 'message' => "Høyst {$def['max_frames']} rammer i ett prosjekt."]]);
        }
        $errors = [];
        $out = [];
        foreach ($frames as $i => $raw) {
            [$frame, $errs] = $this->validateFrame(is_array($raw) ? $raw : [], $i);
            $out[] = $frame;
            array_push($errors, ...$errs);
        }
        $cards = array_key_exists('operator_cards', $input) ? ParameterValidator::toBool($input['operator_cards']) : true;
        if ($cards === null) {
            $errors[] = ['field' => 'operator_cards', 'message' => 'Ugyldig valg for regnetegn-kort.'];
        }
        if ($errors) {
            throw new ValidationException($errors);
        }
        return ['frames' => $out, 'operator_cards' => (bool) $cards];
    }

    /** @return array{0:array,1:list<array>} */
    private function validateFrame(array $raw, int $i): array
    {
        $def = $this->definition;
        $err = function (string $field, string $msg) use ($i): array {
            return ['field' => $field, 'frame' => $i, 'message' => 'Ramme ' . ($i + 1) . ': ' . $msg];
        };
        $errors = [];
        [$frame, $numErrors] = ParameterValidator::numbers($def['parameters'], $raw);
        foreach ($numErrors as $e) {
            $errors[] = $err($e['field'], $e['message']);
        }
        foreach ($def['switches'] as $k => $spec) {
            $v = array_key_exists($k, $raw) ? ParameterValidator::toBool($raw[$k]) : (bool) $spec['default'];
            if ($v === null) {
                $errors[] = $err($k, "Ugyldig valg for «{$spec['label']}».");
            }
            $frame[$k] = (bool) $v;
        }
        foreach ($def['choices'] as $k => $spec) {
            $v = $raw[$k] ?? $spec['default'];
            if (!is_string($v) || !array_key_exists($v, $spec['options'])) {
                $errors[] = $err($k, "Ugyldig valg for «{$spec['label']}».");
                $v = $spec['default'];
            }
            $frame[$k] = $v;
        }

        // Form
        $rows = $raw['rows'] ?? $this->frameDefaults()['rows'];
        $max = $def['grid']['max'];
        $okRows = is_array($rows) && array_is_list($rows) && count($rows) >= 1 && count($rows) <= $max;
        if ($okRows) {
            $len = null;
            foreach ($rows as $r) {
                if (!is_string($r) || !preg_match('/^[01]{1,' . $max . '}$/', $r) || ($len !== null && strlen($r) !== $len)) {
                    $okRows = false;
                    break;
                }
                $len = strlen($r);
            }
        }
        if (!$okRows) {
            $errors[] = $err('cells', "Rutenettet er ugyldig (høyst $max × $max ruter).");
            $rows = ['1'];
        }
        $shape = new LegoShape($rows);
        foreach ($shape->problems() as $p) {
            $errors[] = $err('cells', $p);
        }
        if (!$frame['bottom'] && !$shape->problems() && $shape->holes() > 0) {
            $errors[] = $err('bottom', 'Formen har en utsparing. Uten bunn blir veggen rundt utsparingen en løs del. Slå på bunn, eller fjern utsparingen.');
        }
        $frame['rows'] = $rows;

        // Tekst
        $text = $raw['text'] ?? '';
        $text = is_string($text) ? LegoText::normalize($text) : '';
        $frame['text'] = $text;
        if ($frame['field'] !== 'none' && $frame['text_mode'] === 'custom') {
            if ($text === '') {
                $errors[] = $err('text', 'Skriv en kort tekst, for eksempel «8 + 8», eller velg «Mengden».');
            } elseif (mb_strlen($text) > $def['text']['max_length']) {
                $errors[] = $err('text', "Teksten kan ha høyst {$def['text']['max_length']} tegn.");
            } elseif (!preg_match(LegoText::PATTERN, $text)) {
                $errors[] = $err('text', 'Teksten kan bare ha tall, bokstaver, mellomrom og tegnene + − × ÷ = · : . , ( ) ? /');
            }
        }
        if ($frame['field'] === 'text' && $frame['text_mode'] === 'blank') {
            $errors[] = $err('text_mode', 'Tom tekst kan bare brukes på papiretiketter (til håndskrift).');
        }
        $copies = $raw['copies'] ?? 1;
        $copies = is_int($copies) ? $copies : (is_string($copies) && preg_match('/^\s*\d{1,3}\s*$/', $copies) ? (int) $copies : null);
        if ($copies === null || $copies < 1 || $copies > $def['labels']['copies_max']) {
            $errors[] = $err('copies', "Antall etiketter må være fra 1 til {$def['labels']['copies_max']}.");
            $copies = 1;
        }
        $frame['copies'] = $copies;

        // Teksten må få plass i feltet (bare når resten er gyldig).
        if (!$errors && $frame['field'] !== 'none') {
            $g = $this->geometry($frame);
            if ($g['text'] !== null && !$g['text']['fits']) {
                $errors[] = $err('text', sprintf(
                    'Teksten «%s» er for lang for merkefeltet (%s × %s mm). Bruk kortere tekst, en annen side av rammen eller større feltdybde.',
                    $g['text']['string'], self::fmt($g['field']['length']), self::fmt($g['field']['depth'])
                ));
            }
        }
        return [$frame, $errors];
    }

    /* ---------------------------------------------------------------- Geometri */

    public function shape(array $frame): LegoShape
    {
        return new LegoShape($frame['rows']);
    }

    /** Teksten rammen viser eller etiketten har: mengden, egen tekst eller tom. */
    public function content(array $frame): string
    {
        return match ($frame['text_mode']) {
            'custom' => $frame['text'],
            'blank'  => '',
            default  => (string) $this->shape($frame)->count(),
        };
    }

    /**
     * All geometri for én ramme, i koordinatene til STL-fila (midt over
     * origo, undersiden på z = 0).
     */
    public function geometry(array $f): array
    {
        $def = $this->definition;
        $U = (float) $def['module'];
        $Hi = (float) $def['inner_height'];
        $shape = $this->shape($f);
        $C = $shape->cols();
        $R = $shape->rows();
        $cl = (float) $f['clearance'];
        $w = (float) $f['wall'];
        $e = $cl + $w;
        $bottom = $f['bottom'] ? (float) $f['bottom_thickness'] : 0.0;
        $H = $bottom + $Hi;

        // Ruter i et koordinatsystem med nederste venstre rute i (0, 0); y opp.
        $cells = [];
        $openRects = [];
        $outerRects = [];
        foreach ($shape->cells() as [$c, $r]) {
            $yi = $R - 1 - $r;
            $cells[] = [$c, $yi];
            $x0 = $c * $U;
            $y0 = $yi * $U;
            $openRects[] = [$x0 - $cl, $y0 - $cl, $x0 + $U + $cl, $y0 + $U + $cl];
            $outerRects[] = [$x0 - $e, $y0 - $e, $x0 + $U + $e, $y0 + $U + $e];
        }

        // Merkefelt langs den lengste rekken av ruter på valgt side.
        $field = null;
        if ($f['field'] !== 'none') {
            $side = $f['field_side'];
            [$a, $b] = $shape->longestRun($side);
            $D = (float) $f['field_depth'];
            $alongX = $side === 'front' || $side === 'back';
            // Rekkens utstrekning langs siden, med vegg på begge ender.
            if ($alongX) {
                $lo = $a * $U - $e;
                $hi = ($b + 1) * $U + $e;
            } else {
                // Kolonnene leses ovenfra; y opp.
                $lo = ($R - 1 - $b) * $U - $e;
                $hi = ($R - $a) * $U + $e;
            }
            $L = $hi - $lo;
            $rect = match ($side) {
                'front' => [$lo, -$e - $D, $hi, -$e + $w / 2],
                'back'  => [$lo, $R * $U + $e - $w / 2, $hi, $R * $U + $e + $D],
                'left'  => [-$e - $D, $lo, -$e + $w / 2, $hi],
                'right' => [$C * $U + $e - $w / 2, $lo, $C * $U + $e + $D, $hi],
            };
            // Den delen av feltet som ligger utenfor veggen (der teksten og etiketten er).
            $face = match ($side) {
                'front' => [$lo, -$e - $D, $hi, -$e],
                'back'  => [$lo, $R * $U + $e, $hi, $R * $U + $e + $D],
                'left'  => [-$e - $D, $lo, -$e, $hi],
                'right' => [$C * $U + $e, $lo, $C * $U + $e + $D, $hi],
            };
            $field = ['side' => $side, 'length' => $L, 'depth' => $D, 'rect' => $rect, 'face' => $face,
                      'rotation' => ['front' => 0, 'back' => 180, 'left' => -90, 'right' => 90][$side]];
            $outerRects[] = $rect;
        }

        // Midt over origo
        $all = $outerRects;
        $minX = min(array_column($all, 0));
        $minY = min(array_column($all, 1));
        $maxX = max(array_column($all, 2));
        $maxY = max(array_column($all, 3));
        $ox = -($minX + $maxX) / 2;
        $oy = -($minY + $maxY) / 2;
        $shift = fn(array $r) => [$r[0] + $ox, $r[1] + $oy, $r[2] + $ox, $r[3] + $oy];

        $text = null;
        if ($field) {
            $field['rect'] = $shift($field['rect']);
            $field['face'] = $shift($field['face']);
            $content = $f['field'] === 'text' ? $this->content($f) : null;
            if ($content !== null && $content !== '') {
                $tc = $def['text'];
                $size = LegoText::fit($content, $field['length'] - 2 * $tc['margin'], $field['depth'] - 2 * $tc['margin'], $tc['max_size']);
                $fc = $field['face'];
                $raised = $f['text_style'] === 'raised';
                $text = [
                    'string' => $content,
                    'size'   => round($size, 3),
                    'fits'   => $size >= $tc['min_size'] - 1e-9,
                    'center' => [($fc[0] + $fc[2]) / 2, ($fc[1] + $fc[3]) / 2],
                    'rotation' => $field['rotation'],
                    'style'  => $f['text_style'],
                    'depth'  => $raised ? (float) $f['text_depth'] : min((float) $f['text_depth'], $H - $tc['floor']),
                    'width'  => LegoText::width($content, $size),
                ];
            }
        }

        $openArea = LegoShape::unionArea($openRects);
        $outerArea = LegoShape::unionArea($outerRects);
        $volume = $outerArea * $H - $openArea * $Hi;     // med bunn: lommen er Hi dyp; uten bunn er H = Hi
        $holes = $shape->holes();
        $genus = $holes + ($f['bottom'] ? 0 : 1);
        $W = $maxX - $minX;
        $Dp = $maxY - $minY;
        $topZ = $H + ($text && $text['style'] === 'raised' ? $text['depth'] : 0.0);

        return [
            'module'   => $U,
            'cols'     => $C,
            'rows'     => $R,
            'count'    => $shape->count(),
            'rectangle' => $shape->isRectangle(),
            'holes'    => $holes,
            'cells'    => $cells,
            'origin'   => [$ox, $oy],
            'clearance' => $cl,
            'wall'     => $w,
            'bottom'   => $bottom,
            'height'   => $H,
            'top'      => $topZ,
            'inner_height' => $Hi,
            'inner'    => [$C * $U + 2 * $cl, $R * $U + 2 * $cl],          // største innvendige mål
            'frame_outer' => [$C * $U + 2 * $e, $R * $U + 2 * $e],         // rammen uten merkefelt
            'outer'    => [$W, $Dp],                                          // med merkefelt
            'field'    => $field,
            'label'    => $field && $f['field'] === 'label' ? [
                'width'  => $field['length'] - 2 * $def['labels']['margin'],
                'height' => $field['depth'] - 2 * $def['labels']['margin'],
            ] : null,
            'text'     => $text,
            'open_area'  => $openArea,
            'outer_area' => $outerArea,
            'volume'   => $volume,
            'euler'    => 2 - 2 * $genus,
            'bounds'   => ['min' => [-$W / 2, -$Dp / 2, 0.0], 'max' => [$W / 2, $Dp / 2, $topZ]],
            'studs'    => array_map(fn($c) => [round($c[0] * $U + $U / 2 + $ox, 4), round($c[1] * $U + $U / 2 + $oy, 4)], $cells),
        ];
    }

    /* ------------------------------------------------------------------- Deler */

    public function filename(array $f, int $i): string
    {
        $g = $this->geometry($f);
        $dims = $g['rectangle'] ? "{$g['cols']}x{$g['rows']}-" : '';
        return sprintf('%s-%02d-%s%d.stl', $this->definition['filename'], $i + 1, $dims, $g['count']);
    }

    public function scadVariables(array $f): array
    {
        $g = $this->geometry($f);
        $t = $g['text'];
        $fr = $g['field']['rect'] ?? [0.0, 0.0, 1.0, 1.0];
        return [
            'u'           => (float) $g['module'],
            'cells'       => array_merge(...array_map(fn($c) => [$c[0], $c[1]], $g['cells'])),
            'ox'          => (float) $g['origin'][0],
            'oy'          => (float) $g['origin'][1],
            'clearance'   => (float) $g['clearance'],
            'wall'        => (float) $g['wall'],
            'inner_h'     => (float) $g['inner_height'],
            'bottom_on'   => $g['bottom'] > 0 ? 1 : 0,
            'bottom_t'    => (float) $g['bottom'],
            'field_on'    => $g['field'] ? 1 : 0,
            'fx0'         => (float) $fr[0],
            'fy0'         => (float) $fr[1],
            'fx1'         => (float) $fr[2],
            'fy1'         => (float) $fr[3],
            'text_on'     => $t ? 1 : 0,
            'text_codes'  => $t ? LegoText::codes($t['string']) : [32],
            'text_size'   => $t ? (float) $t['size'] : 1.0,
            'text_x'      => $t ? (float) $t['center'][0] : 0.0,
            'text_y'      => $t ? (float) $t['center'][1] : 0.0,
            'text_rot'    => $t ? (int) $t['rotation'] : 0,
            'text_raised' => $t && $t['style'] === 'raised' ? 1 : 0,
            'text_depth'  => $t ? (float) $t['depth'] : 0.0,
            'text_segments' => (int) $this->definition['scad_constants']['text_segments'],
        ];
    }

    public function parts(array $p): array
    {
        $def = $this->definition;
        $parts = [];
        foreach ($p['frames'] as $i => $f) {
            $g = $this->geometry($f);
            $vars = $this->scadVariables($f);
            $parts[] = [
                'key'       => (string) ($i + 1),
                'scad'      => $def['scad'],
                'variables' => $vars,
                'bounds'    => $g['bounds'],
                'euler'     => $g['euler'],
                'filename'  => $this->filename($f, $i),
                'count'     => 1,
                'cache_key' => json_encode([$def['id'], $def['version'], $vars]),
                'info'      => $this->info($f, $i),
            ];
        }
        return $parts;
    }

    /** Det grensesnittet trenger om én ramme. */
    public function info(array $f, int $i): array
    {
        $g = $this->geometry($f);
        $r = fn(array $a) => array_map(fn($v) => round($v, 3), $a);
        return [
            'index'      => $i,
            'name'       => 'Ramme ' . ($i + 1),
            'count'      => $g['count'],
            'rectangle'  => $g['rectangle'],
            'cols'       => $g['cols'],
            'rows'       => $g['rows'],
            'holes'      => $g['holes'],
            'inner_mm'   => $r($g['inner']),
            'frame_outer_mm' => $r($g['frame_outer']),
            'outer_mm'   => $r($g['outer']),
            'height_mm'  => round($g['height'], 3),
            'top_mm'     => round($g['top'], 3),
            'inner_height_mm' => $g['inner_height'],
            'bottom_mm'  => $g['bottom'],
            'field'      => $g['field'] ? ['side' => $g['field']['side'], 'length' => round($g['field']['length'], 3),
                                           'depth' => $g['field']['depth'], 'face' => $r($g['field']['face'])] : null,
            'field_use'  => $f['field'],
            'text'       => $g['text'] ? ['string' => $g['text']['string'], 'size' => $g['text']['size'], 'style' => $g['text']['style']] : null,
            'label'      => $g['label'] ? $r([$g['label']['width'], $g['label']['height']]) : null,
            'label_text' => $f['field'] === 'label' ? $this->content($f) : null,
            'copies'     => $f['field'] === 'label' ? $f['copies'] : 0,
            'operator'   => $f['operator'],
            'studs'      => $g['studs'],
            'material_g' => round($g['volume'] / 1000 * $this->definition['material']['density'], 1),
        ];
    }

    public function warnings(array $p): array
    {
        $w = [];
        foreach ($p['frames'] as $i => $f) {
            $g = $this->geometry($f);
            if ($g['text'] && $g['text']['size'] < 5) {
                $w[] = sprintf('Ramme %d: teksten blir bare %s mm høy. Den kan bli utydelig; prøv kortere tekst eller større felt.',
                    $i + 1, self::fmt(round($g['text']['size'], 1)));
            }
        }
        if (count($p['frames']) > 1 && end($p['frames'])['operator'] !== '') {
            $w[] = 'Den siste rammen har et regnetegn etter seg, men ingen ramme etter tegnet.';
        }
        return $w;
    }

    /** Regnestykket rammene viser, f.eks. «8 + 4 = 12». */
    public function equation(array $p): string
    {
        $s = '';
        foreach ($p['frames'] as $i => $f) {
            $s .= $this->shape($f)->count();
            if ($f['operator'] !== '' && $i < count($p['frames']) - 1) {
                $s .= ' ' . $f['operator'] . ' ';
            } elseif ($i < count($p['frames']) - 1) {
                $s .= '   ';
            }
        }
        return trim($s);
    }

    public function describe(array $p): array
    {
        $frames = [];
        foreach ($p['frames'] as $i => $f) {
            $info = $this->info($f, $i);
            unset($info['studs']);
            $frames[] = $info + ['stl' => $this->filename($f, $i), 'shape' => $this->shape($f)->rowsText()];
        }
        return [
            'model'    => $this->definition['id'],
            'version'  => $this->definition['version'],
            'units'    => 'mm',
            'module'   => $this->definition['module'],
            'frames'   => $frames,
            'equation' => $this->equation($p),
            'total'    => array_sum(array_column($frames, 'count')),
            'labels'   => array_sum(array_column($frames, 'copies')),
            'operator_cards' => $p['operator_cards'],
        ];
    }

    /* ------------------------------------------------------- PDF og nedlasting */

    public function renderPdf(array $p): string
    {
        return (new LegoPdf($this))->render($p);
    }

    public function pdfFilename(array $p): string
    {
        return $this->archiveName($p) . '-etiketter.pdf';
    }

    public function archiveName(array $p): string
    {
        $n = count($p['frames']);
        return $this->definition['archive'] . '-' . $n . ($n === 1 ? '-ramme' : '-rammer');
    }

    public function archiveReadme(array $p, array $parts): string
    {
        $lines = [
            'M+ 3D – Regn med Lego',
            '',
            'Regnestykket: ' . $this->equation($p),
            '',
            'Rammer (én STL per ramme, skrives ut slik de ligger):',
        ];
        foreach ($p['frames'] as $i => $f) {
            $g = $this->geometry($f);
            $lines[] = sprintf('  %s  %d knottplasser  innvendig %s × %s mm  utvendig %s × %s mm  høyde %s mm  vegg %s  klaring %s  %s',
                $parts[$i]['filename'], $g['count'],
                self::fmt($g['inner'][0]), self::fmt($g['inner'][1]), self::fmt($g['outer'][0]), self::fmt($g['outer'][1]),
                self::fmt($g['height']), self::fmt($g['wall']), self::fmt($g['clearance']),
                $f['bottom'] ? 'bunn ' . self::fmt($g['bottom']) . ' mm' : 'uten bunn');
        }
        $lines[] = '';
        $lines[] = 'Passformen mot klossene er uprøvd til den er testet med ekte klosser.';
        $lines[] = 'Skriv ut én ramme først, og juster klaringen (standard 0,2 mm per side) etter behov.';
        $lines[] = '';
        $lines[] = 'Utskrift: PLA, 0,2 mm laghøyde, 0,4 mm dyse, 2–3 vegger. Ingen støtte trengs.';
        $lines[] = 'LEGO® er et varemerke for LEGO-gruppen, som ikke står bak eller godkjenner M+ 3D.';
        return "\u{FEFF}" . implode("\r\n", $lines) . "\r\n";
    }

    public function queryKeys(): array
    {
        return ['project'];
    }

    public static function fmt(float|int $v): string
    {
        return ParameterValidator::fmt($v);
    }
}
