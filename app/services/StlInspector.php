<?php
declare(strict_types=1);

/**
 * Kontrollerer at en STL-fil er et lukket, gyldig volum med riktige mål.
 *
 * At OpenSCAD klarer å eksportere en fil, betyr ikke at den er utskriftsklar.
 * Denne klassen leser STL-filen (binær eller ASCII) og sjekker:
 *
 *  - at filen har trekanter og ikke for mange
 *  - at ingen trekanter er degenererte (null areal)
 *  - at nettet er lukket: hver kant deles av nøyaktig to trekanter
 *  - at orienteringen er konsistent (hver rettet kant har én motsatt kant)
 *  - at volumet er positivt (normalene peker utover)
 *  - at Euler-karakteristikken V − E + F = 2 (ett lukket legeme uten hull),
 *    eller 2 · antall skall (en hul figur med lukket hulrom har to skall)
 *  - at de ytre målene stemmer med forventede mål innenfor toleranse
 */
final class StlInspector
{
    public function __construct(private int $maxTriangles = 200000)
    {
    }

    /**
     * @param array{min:array{float,float,float},max:array{float,float,float}}|null $expected
     * @param int $shells antall lukkede skall (2 for en hul figur med lukket hulrom)
     * @param int|null $euler forventet Euler-karakteristikk når legemet har gjennomgående
     *                   hull (2 − 2 · antall hull, f.eks. 0 for en ramme uten bunn)
     * @return array<string,mixed> rapport; 'ok' => bool og 'problems' => list<string>
     */
    public function inspect(string $file, ?array $expected = null, float $tolerance = 0.02, int $shells = 1, ?int $euler = null): array
    {
        $problems = [];
        $data = @file_get_contents($file);
        if ($data === false || strlen($data) < 84) {
            return ['ok' => false, 'problems' => ['STL-filen mangler eller er tom.']];
        }

        $triangles = $this->isBinary($data) ? $this->readBinary($data) : $this->readAscii($data);
        unset($data);
        $f = count($triangles);
        if ($f === 0) {
            return ['ok' => false, 'problems' => ['STL-filen inneholder ingen trekanter.']];
        }
        if ($f > $this->maxTriangles) {
            return ['ok' => false, 'problems' => ["For mange trekanter ($f)."]];
        }

        $vertexIndex = [];
        $vertices = [];
        $directed = [];
        $degenerate = 0;
        $volume = 0.0;
        $area = 0.0;
        $min = [INF, INF, INF];
        $max = [-INF, -INF, -INF];

        foreach ($triangles as $t) {
            $idx = [];
            foreach ($t as $v) {
                $key = sprintf('%.5f,%.5f,%.5f', $v[0], $v[1], $v[2]);
                if (!isset($vertexIndex[$key])) {
                    $vertexIndex[$key] = count($vertices);
                    $vertices[] = $v;
                    for ($i = 0; $i < 3; $i++) {
                        $min[$i] = min($min[$i], $v[$i]);
                        $max[$i] = max($max[$i], $v[$i]);
                    }
                }
                $idx[] = $vertexIndex[$key];
            }
            [$a, $b, $c] = $t;
            $ab = [$b[0] - $a[0], $b[1] - $a[1], $b[2] - $a[2]];
            $ac = [$c[0] - $a[0], $c[1] - $a[1], $c[2] - $a[2]];
            $cross = [
                $ab[1] * $ac[2] - $ab[2] * $ac[1],
                $ab[2] * $ac[0] - $ab[0] * $ac[2],
                $ab[0] * $ac[1] - $ab[1] * $ac[0],
            ];
            $triArea = 0.5 * sqrt($cross[0] ** 2 + $cross[1] ** 2 + $cross[2] ** 2);
            $area += $triArea;
            $volume += ($a[0] * ($b[1] * $c[2] - $b[2] * $c[1])
                      - $a[1] * ($b[0] * $c[2] - $b[2] * $c[0])
                      + $a[2] * ($b[0] * $c[1] - $b[1] * $c[0])) / 6.0;

            if ($idx[0] === $idx[1] || $idx[1] === $idx[2] || $idx[0] === $idx[2] || $triArea < 1e-9) {
                $degenerate++;
                continue;
            }
            for ($i = 0; $i < 3; $i++) {
                $k = ($idx[$i] << 24) | $idx[($i + 1) % 3];
                $directed[$k] = ($directed[$k] ?? 0) + 1;
            }
        }
        unset($triangles, $vertexIndex);

        $boundary = 0;
        $nonManifold = 0;
        $undirected = 0;
        foreach ($directed as $k => $count) {
            $a = $k >> 24;
            $b = $k & 0xFFFFFF;
            $reverse = $directed[($b << 24) | $a] ?? 0;
            if ($count > 1 || $reverse > 1) {
                $nonManifold++;
            } elseif ($reverse === 0) {
                $boundary++;
            }
            if ($a < $b || $reverse === 0) {
                $undirected++;
            }
        }

        $v = count($vertices);
        $expectedEuler = $euler ?? 2 * $shells;
        $eulerValue = $v - $undirected + ($f - $degenerate);

        if ($degenerate > 0) {
            $problems[] = "$degenerate degenererte trekanter.";
        }
        if ($boundary > 0) {
            $problems[] = "Nettet er ikke lukket ($boundary åpne kanter).";
        }
        if ($nonManifold > 0) {
            $problems[] = "Nettet har $nonManifold ugyldige (ikke-manifold eller feilorienterte) kanter.";
        }
        if ($volume <= 0) {
            $problems[] = 'Volumet er ikke positivt (normalene peker innover).';
        }
        if ($boundary === 0 && $nonManifold === 0 && $eulerValue !== $expectedEuler) {
            $problems[] = "Uventet topologi (Euler-karakteristikk $eulerValue, forventet $expectedEuler).";
        }

        $size = [$max[0] - $min[0], $max[1] - $min[1], $max[2] - $min[2]];
        if ($expected !== null) {
            foreach ([0 => 'X', 1 => 'Y', 2 => 'Z'] as $i => $axis) {
                if (abs($min[$i] - $expected['min'][$i]) > $tolerance || abs($max[$i] - $expected['max'][$i]) > $tolerance) {
                    $problems[] = sprintf(
                        'Feil mål i %s: %.3f til %.3f mm, forventet %.3f til %.3f mm.',
                        $axis, $min[$i], $max[$i], $expected['min'][$i], $expected['max'][$i]
                    );
                }
            }
        }

        return [
            'ok'         => $problems === [],
            'problems'   => $problems,
            'triangles'  => $f,
            'vertices'   => $v,
            'edges'      => $undirected,
            'euler'      => $eulerValue,
            'boundary_edges'     => $boundary,
            'non_manifold_edges' => $nonManifold,
            'degenerate' => $degenerate,
            'volume_mm3' => round($volume, 2),
            'area_mm2'   => round($area, 2),
            'bounds'     => ['min' => array_map(fn($x) => round($x, 4), $min), 'max' => array_map(fn($x) => round($x, 4), $max)],
            'size_mm'    => array_map(fn($x) => round($x, 3), $size),
        ];
    }

    private function isBinary(string $data): bool
    {
        $n = unpack('V', substr($data, 80, 4))[1];
        return strlen($data) === 84 + 50 * $n;
    }

    /** @return list<array{array{float,float,float},array{float,float,float},array{float,float,float}}> */
    private function readBinary(string $data): array
    {
        $n = unpack('V', substr($data, 80, 4))[1];
        $out = [];
        for ($i = 0, $o = 84; $i < $n; $i++, $o += 50) {
            $f = unpack('g12', substr($data, $o, 48));   // little-endian float32: normal + 3 hjørner
            $out[] = [[$f[4], $f[5], $f[6]], [$f[7], $f[8], $f[9]], [$f[10], $f[11], $f[12]]];
        }
        return $out;
    }

    private function readAscii(string $data): array
    {
        preg_match_all('/vertex\s+(\S+)\s+(\S+)\s+(\S+)/', $data, $m, PREG_SET_ORDER);
        $out = [];
        for ($i = 0; $i + 2 < count($m); $i += 3) {
            $tri = [];
            for ($j = 0; $j < 3; $j++) {
                $tri[] = [(float) $m[$i + $j][1], (float) $m[$i + $j][2], (float) $m[$i + $j][3]];
            }
            $out[] = $tri;
        }
        return $out;
    }
}
