<?php
declare(strict_types=1);

/**
 * Kulespill: arkene som legges mellom bunnen og kulespillet.
 *
 * Kulespillet og bunnen er faste modeller (STL i public/assets/kulespill/),
 * så modellen har ingen OpenSCAD-deler. Det læreren lager, er arkene:
 * hullark, verdiark, oppgaver og fasit. Innholdet og oppsettet lages i
 * MarbleSheets. describe() sender sidene som tegneelementer til
 * forhåndsvisningen, og renderPdf() tegner de samme elementene.
 */
final class MarbleModel implements ParametricModel
{
    public function __construct(private array $definition)
    {
    }

    public static function load(string $configDir): self
    {
        return new self(require $configDir . '/models/marble_game.php');
    }

    public function key(): string
    {
        return 'marble';
    }

    public function definition(): array
    {
        return $this->definition;
    }

    public function schema(): array
    {
        $d = $this->definition;
        return [
            'model'          => $d['id'],
            'version'        => $d['version'],
            'parameters'     => $d['parameters'],
            'contents'       => $d['contents'],
            'operations'     => $d['operations'],
            'expression_max' => $d['expression_max'],
            'letters'        => $d['letters'],
            'symbols'        => $d['symbols'],
            'files'          => $d['files'],
            'defaults'       => $this->defaults(),
        ];
    }

    public function defaults(): array
    {
        $d = $this->definition;
        return $d['defaults'] + array_map(fn($s) => $s['default'], $d['parameters']);
    }

    public function validate(array $input): array
    {
        $d = $this->definition;
        $p = $this->defaults();
        $errors = [];
        $err = function (string $field, string $message) use (&$errors) {
            $errors[] = ['field' => $field, 'message' => $message];
        };

        foreach ($d['parameters'] as $key => $spec) {
            if (!array_key_exists($key, $input) || $input[$key] === '' || $input[$key] === null) {
                continue;
            }
            $v = ParameterValidator::toNumber($input[$key]);
            if ($v === null || floor($v) !== $v) {
                $err($key, "{$spec['label']} må være et helt tall.");
            } elseif ($v < $spec['min'] || $v > $spec['max']) {
                $err($key, "{$spec['label']} må være mellom {$spec['min']} og {$spec['max']}.");
            } else {
                $p[$key] = (int) $v;
            }
        }
        foreach ($d['parameters'] as $key => $spec) {
            $p[$key] = (int) $p[$key];
        }

        if (isset($input['content'])) {
            if (is_string($input['content']) && isset($d['contents'][$input['content']])) {
                $p['content'] = $input['content'];
            } else {
                $err('content', 'Ukjent innhold i hullene.');
            }
        }

        foreach (['unique', 'task_round', 'task_calc', 'no_negative', 'task_find', 'answer_key'] as $key) {
            if (array_key_exists($key, $input)) {
                $b = ParameterValidator::toBool($input[$key]);
                $b === null ? $err($key, 'Ugyldig valg.') : $p[$key] = $b;
            }
        }

        foreach (['ops' => array_keys($d['operations']), 'calc_ops' => ['+', '-']] as $key => $allowed) {
            if (!array_key_exists($key, $input)) {
                continue;
            }
            $list = is_string($input[$key]) ? ($input[$key] === '' ? [] : explode(',', $input[$key])) : $input[$key];
            if (!is_array($list) || array_diff($list, $allowed)) {
                $err($key, 'Ukjent regneart.');
                continue;
            }
            $p[$key] = array_values(array_intersect($allowed, $list));     // fast rekkefølge, uten duplikater
        }

        if (array_key_exists('expr_max', $input)) {
            $v = ParameterValidator::toNumber($input['expr_max']);
            $v !== null && isset($d['expression_max'][(int) $v]) && floor($v) === $v
                ? $p['expr_max'] = (int) $v
                : $err('expr_max', 'Ugyldig tallområde.');
        }

        if (array_key_exists('seed', $input)) {
            $s = $input['seed'];
            if ((is_int($s) || (is_string($s) && ctype_digit($s))) && (int) $s >= 1 && (int) $s <= 2147483647) {
                $p['seed'] = (int) $s;
            } else {
                $err('seed', 'Ugyldig frø.');
            }
        }

        // Regler som avhenger av flere valg.
        $n = count($d['holes']);
        if ($p['content'] === 'numbers') {
            $this->rangeRule($p, 'num_min', 'num_max', $n, $err);
        }
        if (in_array($p['content'], ['letters', 'symbols'], true)) {
            $this->rangeRule($p, 'value_min', 'value_max', $n, $err);
        }
        if ($p['content'] === 'expressions' && !$p['ops']) {
            $err('ops', 'Velg minst én regneart.');
        }
        if ($p['task_calc'] && in_array($p['content'], ['letters', 'symbols'], true) && !$p['calc_ops']) {
            $err('calc_ops', 'Velg pluss, minus eller begge.');
        }

        if ($errors) {
            throw new ValidationException($errors);
        }
        return $p;
    }

    private function rangeRule(array $p, string $min, string $max, int $n, callable $err): void
    {
        if ($p[$min] > $p[$max]) {
            $err($max, '«Til» må være minst like stor som «Fra».');
        } elseif ($p['unique'] && $p[$max] - $p[$min] + 1 < $n) {
            $err($max, "Med ulike verdier i alle $n hullene må området ha minst $n tall (for eksempel {$p[$min]} til " . ($p[$min] + $n - 1) . ').');
        }
    }

    public function sheets(array $params): MarbleSheets
    {
        return new MarbleSheets($this->definition, $params);
    }

    /** Ingen OpenSCAD-deler: spillet og bunnen er faste STL-filer. */
    public function parts(array $params): array
    {
        return [];
    }

    public function warnings(array $params): array
    {
        return $this->sheets($params)->build()['warnings'];
    }

    public function describe(array $params): array
    {
        $d = $this->definition;
        $built = $this->sheets($params)->build();
        $round = fn($v) => is_float($v) ? round($v, 3) : $v;
        $pages = array_map(fn($pg) => $pg + ['items' => array_map(function ($it) use ($round) {
            if (isset($it['p'])) {
                $it['p'] = array_map(fn($q) => [round($q[0], 3), round($q[1], 3)], $it['p']);
            }
            return array_map($round, $it);
        }, $pg['items'])], $built['pages']);

        return [
            'model'    => $d['id'],
            'version'  => $d['version'],
            'units'    => 'mm',
            'page'     => ['width' => MarbleSheets::PAGE_W, 'height' => MarbleSheets::PAGE_H],
            'sheet'    => ['x' => MarbleSheets::SHEET_X, 'y' => MarbleSheets::SHEET_Y,
                'width' => $d['board']['width'], 'height' => $d['board']['length']],
            'board'    => $d['board'],
            'holes'    => $d['holes'],
            'hole_diameter' => $d['hole_diameter'],
            'content'  => $params['content'],
            'seed'     => $params['seed'],
            'variants' => array_map(fn($v) => [
                'number' => $v['number'],
                'holes'  => array_map(fn($h) => ['label' => $h['label'], 'value' => $h['value']] + (isset($h['symbol']) ? ['symbol' => $h['symbol']] : []), $v['holes']),
                'calc'   => count($v['calc']),
                'find'   => count($v['find']),
            ], $built['variants']),
            'pages'    => $pages,
            'warnings' => $built['warnings'],
        ];
    }

    public function renderPdf(array $params): string
    {
        return (new MarblePdf())->render($this->sheets($params)->build()['pages'], 'Kulespill – ark');
    }

    public function pdfFilename(array $params): string
    {
        $c = ['numbers' => 'tall', 'expressions' => 'regnestykker', 'letters' => 'bokstaver', 'symbols' => 'symboler'][$params['content']];
        return "kulespill-ark-$c.pdf";
    }

    public function archiveName(array $params): string
    {
        return 'kulespill';
    }

    public function archiveReadme(array $params, array $parts): string
    {
        return '';
    }

    public function queryKeys(): array
    {
        return array_merge(array_keys($this->definition['parameters']), [
            'content', 'unique', 'ops', 'expr_max', 'task_round', 'task_calc', 'calc_ops',
            'no_negative', 'task_find', 'answer_key', 'seed',
        ]);
    }
}
