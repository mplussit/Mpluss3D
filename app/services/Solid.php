<?php
declare(strict_types=1);

/**
 * Felles geometrimodell for én romfigur.
 *
 * Dette er den eneste kilden til figurens geometri. Alt annet bygger på den:
 *
 *   STL            scad() og cavity() gir tallene til models/solids/solid.scad
 *   3D-visning     faces() (plan og krumme flater), edges() og vertices()
 *   2D-nett / PDF  nets() gir utbrettingsnettene som SolidNet
 *   egenskaper     counts(), measures(), baseArea(), surfaceArea(), volume()
 *   oppgaver       SolidTasks bruker measures() og counts()
 *
 * Koordinater i mm. Figuren står på byggeplaten (z = 0), midt over origo,
 * slik STL-fila skrives ut. Mål beregnes én gang her og avrundes bare ved
 * visning.
 *
 * Nye figurer: lag en underklasse og legg den inn under 'shapes' i
 * app/config/models/solid_figures.php.
 */
abstract class Solid
{
    /** @var list<array<string,mixed>>|null */
    private ?array $faceCache = null;

    /**
     * @param array<string,float> $d mål i mm (nøklene er figurens egne, f.eks. 'side')
     * @param list<string> $palette farger til flatene i 3D og i nettet
     */
    public function __construct(protected array $d, protected int $segments = 128, protected array $palette = [])
    {
    }

    /** Figurens id, f.eks. "cube". */
    abstract public function shape(): string;

    /** Navn på norsk, f.eks. «Kube». */
    abstract public function name(): string;

    /** Ytre mål [x, y, z] slik figuren står på byggeplaten. */
    abstract public function size(): array;

    /**
     * Flatene uten farge og indeks:
     *   id, name, shape (kvadrat|rektangel|trekant|sirkel|krum), role (grunnflate|sideflate|toppflate),
     *   area, dims (tekst), polygon (3D-punkter, plane mangekanter) eller
     *   disk ['center','r','normal'] eller surface ['type' => 'cylinder'|'cone', …]
     * @return list<array<string,mixed>>
     */
    abstract protected function buildFaces(): array;

    abstract public function volume(): float;

    abstract public function baseArea(): float;

    /**
     * Mål med symbol, navn, verdi, enhet og eventuell formel. Både visningen,
     * PDF-ene og oppgavene bruker disse.
     * @return array<string,array{symbol:string,label:string,value:float,unit:string,formula?:string}>
     */
    abstract public function measures(): array;

    /**
     * Utbrettingsnett, det foretrukne først. Alle er gyldige nett av samme
     * figur; NetLayout velger det første som får plass på A4.
     * @return list<SolidNet>
     */
    abstract public function nets(float $tab): array;

    /** Variabler til models/solids/solid.scad for den massive figuren. */
    abstract public function scad(): array;

    /**
     * Hulrommet for en hul figur med veggtykkelse $wall.
     *
     * Hulrommet er figuren forskjøvet innover med $wall, avgrenset av et tak
     * som skrår minst 45°, så figuren kan skrives ut uten støtte. Er
     * hulrommet for lite, er 'ok' false.
     *
     * @return array{ok:bool,vars:array<string,float>,volume:float,roof:bool,reason:?string}
     */
    abstract public function cavity(float $wall): array;

    /** Linjer med mål til tegningene i PDF-en: [a, b, symbol, side]. */
    abstract public function annotations(): array;

    /** Hvor mange symmetriplan figuren har (INF = uendelig mange). */
    abstract public function symmetryPlanes(): float|int;

    /* ------------------------------------------------------------------ Felles */

    public function dims(): array
    {
        return $this->d;
    }

    /**
     * Flatene med indeks, farge og plan (normal og avstand) for plane flater.
     * @return list<array<string,mixed>>
     */
    public function faces(): array
    {
        if ($this->faceCache !== null) {
            return $this->faceCache;
        }
        $faces = $this->buildFaces();
        $center = [0.0, 0.0, $this->size()[2] / 2];
        foreach ($faces as $i => &$f) {
            $f['index'] = $i;
            $f['color'] = $this->palette[$i % max(1, count($this->palette))] ?? '#f36d21';
            $f['curved'] = isset($f['surface']);
            if (isset($f['polygon'])) {
                $n = self::newell($f['polygon']);
                $c = self::centroid3($f['polygon']);
                // Punktene skal gå mot klokka sett utenfra (normalen peker ut).
                if (self::dot($n, self::sub($c, $center)) < 0) {
                    $f['polygon'] = array_reverse($f['polygon']);
                    $n = self::scale($n, -1);
                }
                $f['plane'] = ['n' => $n, 'd' => self::dot($n, $f['polygon'][0])];
            } elseif (isset($f['disk'])) {
                $n = $f['disk']['normal'];
                $f['plane'] = ['n' => $n, 'd' => self::dot($n, $f['disk']['center'])];
            }
        }
        unset($f);
        return $this->faceCache = $faces;
    }

    public function face(string $id): array
    {
        foreach ($this->faces() as $f) {
            if ($f['id'] === $id) {
                return $f;
            }
        }
        throw new LogicException("Ukjent flate: $id");
    }

    public function surfaceArea(): float
    {
        return array_sum(array_column($this->faces(), 'area'));
    }

    /**
     * Kantene. For mangeflater regnes de ut fra flatene: hver kant er felles
     * for to flater. Krumme figurer overstyrer.
     * @return list<array<string,mixed>>
     */
    public function edges(): array
    {
        $edges = [];
        foreach ($this->faces() as $f) {
            $pts = $f['polygon'] ?? null;
            if (!$pts) {
                continue;
            }
            $n = count($pts);
            for ($i = 0; $i < $n; $i++) {
                $a = $pts[$i];
                $b = $pts[($i + 1) % $n];
                $key = self::edgeKey($a, $b);
                if (!isset($edges[$key])) {
                    $edges[$key] = ['kind' => 'line', 'a' => $a, 'b' => $b, 'length' => self::dist($a, $b), 'faces' => []];
                }
                $edges[$key]['faces'][] = $f['index'];
            }
        }
        return array_values($edges);
    }

    /** Hjørnene (punkter der kanter møtes). Kjeglen overstyrer (toppunktet). */
    public function vertices(): array
    {
        $out = [];
        foreach ($this->edges() as $e) {
            if ($e['kind'] !== 'line') {
                continue;
            }
            foreach ([$e['a'], $e['b']] as $p) {
                $out[self::pointKey($p)] = ['p' => $p, 'name' => 'hjørne'];
            }
        }
        return array_values($out);
    }

    /**
     * Antall flater, kanter og hjørner etter vanlig skolegeometri.
     * @return array{faces:int,curved_faces:int,edges:int,curved_edges:int,vertices:int,vertex_word:string,note:?string}
     */
    public function counts(): array
    {
        $faces = $this->faces();
        $edges = $this->edges();
        return [
            'faces'        => count($faces),
            'curved_faces' => count(array_filter($faces, fn($f) => $f['curved'])),
            'edges'        => count($edges),
            'curved_edges' => count(array_filter($edges, fn($e) => $e['kind'] === 'circle')),
            'vertices'     => count($this->vertices()),
            'vertex_word'  => 'hjørner',
            'note'         => null,
        ];
    }

    /** Volumet til STL-fila (mangekant-tilnærming av krumme flater). */
    public function polygonalVolume(): float
    {
        return $this->volume();
    }

    /** Hvilke plane figurer overflaten består av, f.eks. «6 kvadrater». */
    public function surfaceShapes(): string
    {
        $count = [];
        foreach ($this->faces() as $f) {
            $count[$f['shape']] = ($count[$f['shape']] ?? 0) + 1;
        }
        $words = [
            'kvadrat'   => ['kvadrat', 'kvadrater'],
            'rektangel' => ['rektangel', 'rektangler'],
            'trekant'   => ['trekant', 'trekanter'],
            'sirkel'    => ['sirkel', 'sirkler'],
            'krum'      => ['krum flate', 'krumme flater'],
        ];
        $parts = [];
        foreach ($words as $k => [$one, $many]) {
            if (!empty($count[$k])) {
                $parts[] = $count[$k] . ' ' . ($count[$k] === 1 ? $one : $many);
            }
        }
        return self::joinAnd($parts);
    }

    /** Alt grensesnittet trenger (JSON). */
    public function toArray(): array
    {
        $round = fn($v) => is_array($v) ? array_map(fn($x) => is_float($x) ? round($x, 4) : $x, $v) : $v;
        $faces = array_map(function ($f) use ($round) {
            $out = [
                'index' => $f['index'], 'id' => $f['id'], 'name' => $f['name'], 'shape' => $f['shape'],
                'role' => $f['role'], 'curved' => $f['curved'], 'color' => $f['color'],
                'area' => round($f['area'], 4), 'dims' => $f['dims'],
            ];
            if (isset($f['plane'])) {
                $out['plane'] = ['n' => $round($f['plane']['n']), 'd' => round($f['plane']['d'], 4)];
            }
            if (isset($f['surface'])) {
                $out['surface'] = $f['surface'];
            }
            return $out;
        }, $this->faces());
        $edges = array_map(fn($e) => $e['kind'] === 'line'
            ? ['kind' => 'line', 'a' => $round($e['a']), 'b' => $round($e['b'])]
            : ['kind' => 'circle', 'center' => $round($e['center']), 'r' => round($e['r'], 4)], $this->edges());
        $measures = [];
        foreach ($this->measures() as $k => $m) {
            $measures[$k] = array_merge($m, ['value' => round($m['value'], 6)]);
        }
        return [
            'shape'        => $this->shape(),
            'name'         => $this->name(),
            'size_mm'      => $this->size(),
            'counts'       => $this->counts(),
            'surface_shapes' => $this->surfaceShapes(),
            'base_area'    => round($this->baseArea(), 4),
            'surface_area' => round($this->surfaceArea(), 4),
            'volume'       => round($this->volume(), 4),
            'measures'     => $measures,
            'symmetry_planes' => is_infinite((float) $this->symmetryPlanes()) ? 'uendelig' : $this->symmetryPlanes(),
            'faces'        => $faces,
            'edges'        => $edges,
            'vertices'     => array_map(fn($v) => ['p' => $round($v['p']), 'name' => $v['name']], $this->vertices()),
        ];
    }

    /* --------------------------------------------------- Felles nett-hjelpere */

    /** Etikett i nettet: navn og mål. */
    protected function label(string $faceId): array
    {
        $f = $this->face($faceId);
        return [$f['name'], $f['dims']];
    }

    /** Rektangel med øvre venstre hjørne (x, y), bredde w og høyde h (y nedover). */
    protected static function rect(float $x, float $y, float $w, float $h): array
    {
        return [[$x, $y], [$x + $w, $y], [$x + $w, $y + $h], [$x, $y + $h]];
    }

    /**
     * Nettvarianter rotert i tillegg til de opprinnelige (først alle i
     * opprinnelig retning, så rotert 90°, så eventuelle andre vinkler).
     * @param list<SolidNet> $nets
     * @param list<float> $angles
     * @return list<SolidNet>
     */
    protected static function withRotations(array $nets, array $angles = [90]): array
    {
        $out = array_map(fn(SolidNet $n) => $n->normalized(), $nets);
        foreach ($angles as $a) {
            foreach ($nets as $n) {
                $out[] = $n->rotated($a)->normalized();
            }
        }
        return $out;
    }

    /* ------------------------------------------------------------ Vektorer */

    public static function sub(array $a, array $b): array
    {
        return [$a[0] - $b[0], $a[1] - $b[1], ($a[2] ?? 0) - ($b[2] ?? 0)];
    }

    public static function dot(array $a, array $b): float
    {
        return $a[0] * $b[0] + $a[1] * $b[1] + ($a[2] ?? 0) * ($b[2] ?? 0);
    }

    public static function scale(array $a, float $s): array
    {
        return array_map(fn($x) => $x * $s, $a);
    }

    public static function dist(array $a, array $b): float
    {
        return sqrt(self::dot(self::sub($a, $b), self::sub($a, $b)));
    }

    /** Enhetsnormal for en plan mangekant (Newells metode). */
    public static function newell(array $pts): array
    {
        $n = [0.0, 0.0, 0.0];
        $c = count($pts);
        for ($i = 0; $i < $c; $i++) {
            [$x1, $y1, $z1] = $pts[$i];
            [$x2, $y2, $z2] = $pts[($i + 1) % $c];
            $n[0] += ($y1 - $y2) * ($z1 + $z2);
            $n[1] += ($z1 - $z2) * ($x1 + $x2);
            $n[2] += ($x1 - $x2) * ($y1 + $y2);
        }
        $len = sqrt($n[0] ** 2 + $n[1] ** 2 + $n[2] ** 2);
        return [$n[0] / $len, $n[1] / $len, $n[2] / $len];
    }

    public static function centroid3(array $pts): array
    {
        $c = [0.0, 0.0, 0.0];
        foreach ($pts as $p) {
            for ($i = 0; $i < 3; $i++) {
                $c[$i] += $p[$i] / count($pts);
            }
        }
        return $c;
    }

    /** Areal av en plan mangekant i 3D. */
    public static function polygonArea3(array $pts): float
    {
        $n = self::newell($pts);
        $s = [0.0, 0.0, 0.0];
        $c = count($pts);
        for ($i = 0; $i < $c; $i++) {
            $a = $pts[$i];
            $b = $pts[($i + 1) % $c];
            $s[0] += $a[1] * $b[2] - $a[2] * $b[1];
            $s[1] += $a[2] * $b[0] - $a[0] * $b[2];
            $s[2] += $a[0] * $b[1] - $a[1] * $b[0];
        }
        return abs(self::dot($n, $s)) / 2;
    }

    /** Areal av en regulær n-kant med omskrevet radius r (STL-tilnærming av en sirkel). */
    public static function ngonArea(float $r, int $n): float
    {
        return $n / 2 * $r * $r * sin(2 * M_PI / $n);
    }

    private static function pointKey(array $p): string
    {
        return sprintf('%.5f,%.5f,%.5f', $p[0] + 0.0, $p[1] + 0.0, $p[2] + 0.0);
    }

    private static function edgeKey(array $a, array $b): string
    {
        $ka = self::pointKey($a);
        $kb = self::pointKey($b);
        return $ka < $kb ? "$ka|$kb" : "$kb|$ka";
    }

    /* ------------------------------------------------------------ Tekst */

    public static function fmt(float $v, int $dec = 1): string
    {
        $s = number_format($v, $dec, ',', ' ');
        if (str_contains($s, ',')) {
            $s = rtrim(rtrim($s, '0'), ',');
        }
        return $s === '-0' ? '0' : $s;
    }

    public static function mm(float $v): string
    {
        return self::fmt($v, 1) . ' mm';
    }

    public static function joinAnd(array $items): string
    {
        $items = array_values($items);
        if (count($items) < 2) {
            return implode('', $items);
        }
        return implode(', ', array_slice($items, 0, -1)) . ' og ' . end($items);
    }
}
