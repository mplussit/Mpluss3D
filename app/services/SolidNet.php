<?php
declare(strict_types=1);

/**
 * Utbrettingsnett: felles datamodell for 2D-visningen (SVG) og PDF-en.
 *
 * Nettet består av regioner (flater og limfliker). Hver region er en lukket
 * sløyfe av primitiver i mm, med y nedover (som på arket):
 *
 *   ['L', [x1, y1], [x2, y2]]          linjestykke
 *   ['A', [cx, cy], r, a0, a1]         sirkelbue fra vinkel a0 til a1 (radianer,
 *                                      a1 > a0, punkt = c + r·(cos a, sin a))
 *
 * Klippe- og brettelinjer regnes ut av regionene (lines()): en kant som
 * deles av to regioner er en brettelinje, resten er klippelinjer. Derfor kan
 * de aldri bli uenige med flatene.
 *
 * Utskriften er sett utenfra: den trykte siden blir utsiden av figuren.
 */
final class SolidNet
{
    private const EPS = 1e-6;

    /** @var list<array{kind:string,face:?string,edges:list<array>,label:?array}> */
    public array $regions = [];

    public function __construct(public string $variant = '')
    {
    }

    /* ---------------------------------------------------------- Bygging */

    /** Punkt på sirkel. */
    public static function at(array $c, float $r, float $a): array
    {
        return [$c[0] + $r * cos($a), $c[1] + $r * sin($a)];
    }

    /** @param list<string> $lines tekst i flaten (navn, mål) */
    public function polygonFace(string $face, array $pts, array $lines = []): int
    {
        return $this->add('face', $face, self::loop($pts), $lines ? ['lines' => $lines, 'at' => self::centroid($pts), 'size' => self::labelSize($pts)] : null);
    }

    public function circleFace(string $face, array $c, float $r, array $lines = []): int
    {
        $label = $lines ? ['lines' => $lines, 'at' => $c, 'size' => max(2.2, min(4.2, $r / 6))] : null;
        return $this->add('face', $face, [['A', $c, $r, 0.0, 2 * M_PI]], $label);
    }

    public function sectorFace(string $face, array $c, float $r, float $a0, float $a1, array $lines = []): int
    {
        $mid = ($a0 + $a1) / 2;
        $edges = [['L', $c, self::at($c, $r, $a0)], ['A', $c, $r, $a0, $a1], ['L', self::at($c, $r, $a1), $c]];
        $label = $lines ? ['lines' => $lines, 'at' => self::at($c, $r * 0.62, $mid), 'size' => max(2.2, min(4.2, $r / 12))] : null;
        return $this->add('face', $face, $edges, $label);
    }

    /**
     * Limflik langs kanten a → b, på motsatt side av punktet $inside.
     * Skrå hjørner: $ca og $cb er hvor langt inn langs kanten de skrå sidene
     * slutter (standard = bredden, altså 45°).
     */
    public function tab(array $a, array $b, array $inside, float $w, ?float $ca = null, ?float $cb = null): int
    {
        [$u, $n, $len] = self::frame($a, $b, $inside);
        $max = 0.32 * $len;                         // øvre kant minst ca. 1/3 av kanten
        $ca = min($ca ?? $w, $max);
        $cb = min($cb ?? $w, $max);
        $pts = [
            $a,
            self::add2($a, $u, $ca, $n, $w),
            self::add2($b, $u, -$cb, $n, $w),
            $b,
        ];
        $label = $len >= 16 && $w >= 5 ? ['lines' => ['lim'], 'at' => self::add2(self::mid($a, $b), $n, $w / 2, $u, 0), 'size' => min(2.6, $w * 0.38)] : null;
        return $this->add('tab', null, self::loop($pts), $label);
    }

    /**
     * Limflik langs en kant som går ut fra et punkt der åpningen mellom
     * nettets kanter bare er $gap radianer (spissen av en kjeglesektor eller
     * en pyramidevifte). Fliken spisses til mot punktet så den holder seg i
     * åpningen. Returnerer false hvis åpningen er for smal.
     */
    public function apexTab(array $o, array $p, array $inside, float $w, float $gap): bool
    {
        if ($gap < deg2rad(12)) {
            return false;
        }
        $len = hypot($p[0] - $o[0], $p[1] - $o[1]);
        $psi = min(M_PI / 4, $gap - deg2rad(6));
        $ca = min(0.32 * $len, $w / tan($psi));
        $limit = $gap - deg2rad(4);
        if ($limit < M_PI / 2 && $w / $ca > tan($limit)) {
            $w = $ca * tan($gap - deg2rad(4));
        }
        $this->tab($o, $p, $inside, $w, $ca, $w);
        return true;
    }

    /** Limtenner langs en rett kant (sylinderens rektangel). */
    public function teethOnLine(array $a, array $b, array $inside, float $w, float $pitch = 12.0, float $gap = 1.5): void
    {
        [$u, $n, $len] = self::frame($a, $b, $inside);
        $count = max(2, (int) round($len / $pitch));
        $step = $len / $count;
        for ($i = 0; $i < $count; $i++) {
            $t0 = $i * $step + $gap / 2;
            $t1 = ($i + 1) * $step - $gap / 2;
            $c = min($w * 0.5, ($t1 - $t0) * 0.3);
            $p0 = self::add2($a, $u, $t0, $n, 0);
            $p1 = self::add2($a, $u, $t1, $n, 0);
            $this->add('tab', null, self::loop([$p0, self::add2($p0, $u, $c, $n, $w), self::add2($p1, $u, -$c, $n, $w), $p1]), null);
        }
    }

    /** Limtenner utenfor en sirkelbue (kjeglens sektor), fra a0 til a1 med marg i endene. */
    public function teethOnArc(array $c, float $r, float $a0, float $a1, float $w, float $pitch = 12.0, float $gap = 1.5, float $margin = 2.0): void
    {
        $s0 = $a0 + $margin / $r;
        $s1 = $a1 - $margin / $r;
        $len = ($s1 - $s0) * $r;
        $count = max(2, (int) round($len / $pitch));
        $step = ($s1 - $s0) / $count;
        $g = $gap / 2 / $r;
        for ($i = 0; $i < $count; $i++) {
            $b0 = $s0 + $i * $step + $g;
            $b1 = $s0 + ($i + 1) * $step - $g;
            $chord = 2 * $r * sin(($b1 - $b0) / 2);
            $ch = min($w * 0.5, $chord * 0.3) / ($r + $w);
            $edges = [
                ['A', $c, $r, $b0, $b1],
                ['L', self::at($c, $r, $b1), self::at($c, $r + $w, $b1 - $ch)],
                ['L', self::at($c, $r + $w, $b1 - $ch), self::at($c, $r + $w, $b0 + $ch)],
                ['L', self::at($c, $r + $w, $b0 + $ch), self::at($c, $r, $b0)],
            ];
            $this->add('tab', null, $edges, null);
        }
    }

    private function add(string $kind, ?string $face, array $edges, ?array $label): int
    {
        $this->regions[] = ['kind' => $kind, 'face' => $face, 'edges' => $edges, 'label' => $label];
        return count($this->regions) - 1;
    }

    /* ------------------------------------------------------ Transformasjon */

    /** Rotert $deg grader om origo (mot klokka på arket er negativ vinkel, siden y peker ned). */
    public function rotated(float $deg): self
    {
        $t = deg2rad($deg);
        $cos = cos($t);
        $sin = sin($t);
        $f = fn(array $p) => [$p[0] * $cos - $p[1] * $sin, $p[0] * $sin + $p[1] * $cos];
        $out = $this->map($f, $t);
        $out->variant = $this->variant . ($deg ? ' ' . self::fmtDeg($deg) : '');
        return $out;
    }

    public function translated(float $dx, float $dy): self
    {
        return $this->map(fn(array $p) => [$p[0] + $dx, $p[1] + $dy], 0.0);
    }

    /** Flyttet slik at omsluttende rektangel starter i (0, 0). */
    public function normalized(): self
    {
        [$x0, $y0] = $this->bbox();
        return $this->translated(-$x0, -$y0);
    }

    private function map(callable $f, float $dAngle): self
    {
        $out = new self($this->variant);
        foreach ($this->regions as $r) {
            $edges = array_map(fn($e) => $e[0] === 'L'
                ? ['L', $f($e[1]), $f($e[2])]
                : ['A', $f($e[1]), $e[2], $e[3] + $dAngle, $e[4] + $dAngle], $r['edges']);
            $label = $r['label'];
            if ($label) {
                $label['at'] = $f($label['at']);
            }
            $out->regions[] = ['kind' => $r['kind'], 'face' => $r['face'], 'edges' => $edges, 'label' => $label];
        }
        return $out;
    }

    /**
     * Setter sammen flere deler (hvert nett normalisert) med $gap mm mellomrom.
     * $how: 'row' (ved siden av hverandre) eller 'column' (under hverandre).
     * @param list<SolidNet> $parts
     */
    public static function arrange(array $parts, string $how, float $gap, string $variant): self
    {
        $out = new self($variant);
        $x = 0.0;
        $y = 0.0;
        foreach ($parts as $p) {
            $p = $p->normalized();
            [, , $w, $h] = $p->bbox();
            $moved = $p->translated($x, $y);
            array_push($out->regions, ...$moved->regions);
            if ($how === 'row') {
                $x += $w + $gap;
            } else {
                $y += $h + $gap;
            }
        }
        return $out;
    }

    /* --------------------------------------------------------- Målinger */

    /**
     * Omsluttende rektangel [x0, y0, x1, y1] (eksakt, også for buer).
     * @return array{float,float,float,float}
     */
    public function bbox(): array
    {
        $x0 = $y0 = INF;
        $x1 = $y1 = -INF;
        foreach ($this->regions as $r) {
            foreach ($r['edges'] as $e) {
                $pts = $e[0] === 'L' ? [$e[1], $e[2]] : self::arcExtremes($e);
                foreach ($pts as [$x, $y]) {
                    $x0 = min($x0, $x);
                    $y0 = min($y0, $y);
                    $x1 = max($x1, $x);
                    $y1 = max($y1, $y);
                }
            }
        }
        return [$x0, $y0, $x1, $y1];
    }

    public function width(): float
    {
        $b = $this->bbox();
        return $b[2] - $b[0];
    }

    public function height(): float
    {
        $b = $this->bbox();
        return $b[3] - $b[1];
    }

    /** Endepunkter og ytterpunkter (0°, 90°, 180°, 270°) på en bue. */
    private static function arcExtremes(array $e): array
    {
        [, $c, $r, $a0, $a1] = $e;
        $pts = [self::at($c, $r, $a0), self::at($c, $r, $a1)];
        for ($k = (int) ceil($a0 / (M_PI / 2)); $k * M_PI / 2 <= $a1 + 1e-12; $k++) {
            $pts[] = self::at($c, $r, $k * M_PI / 2);
        }
        return $pts;
    }

    /**
     * Regionen som mangekant (buer deles i biter på høyst $step mm).
     * @return list<array{float,float}>
     */
    public function polygon(int $i, float $step = 0.5): array
    {
        $pts = [];
        foreach ($this->regions[$i]['edges'] as $e) {
            if ($e[0] === 'L') {
                $pts[] = $e[1];
            } else {
                [, $c, $r, $a0, $a1] = $e;
                $n = max(2, (int) ceil(($a1 - $a0) * $r / $step));
                for ($k = 0; $k < $n; $k++) {
                    $pts[] = self::at($c, $r, $a0 + ($a1 - $a0) * $k / $n);
                }
            }
        }
        return $pts;
    }

    /** Areal av en region (buer eksakt via Greens teorem). */
    public function area(int $i): float
    {
        $s = 0.0;
        foreach ($this->regions[$i]['edges'] as $e) {
            if ($e[0] === 'L') {
                $s += $e[1][0] * $e[2][1] - $e[2][0] * $e[1][1];
            } else {
                [, [$cx, $cy], $r, $a0, $a1] = $e;
                // ∫ x dy − y dx langs buen
                $s += $r * $r * ($a1 - $a0) + $r * ($cx * (sin($a1) - sin($a0)) - $cy * (cos($a1) - cos($a0)));
            }
        }
        return abs($s) / 2;
    }

    /* ------------------------------------------------- Klipp og brett */

    /**
     * Klippelinjer og brettelinjer. En kant (eller del av en kant) som deles
     * av to regioner er en brettelinje; resten er klippelinjer.
     * @return array{cuts:list<array>,folds:list<array>}
     */
    public function lines(): array
    {
        $prims = [];
        foreach ($this->regions as $ri => $r) {
            foreach ($r['edges'] as $e) {
                $prims[] = [$ri, $e];
            }
        }
        $cuts = [];
        $folds = [];
        foreach ($prims as $i => [$ri, $e]) {
            $any = [];
            $higher = [];
            foreach ($prims as $j => [$rj, $f]) {
                if ($rj === $ri) {
                    continue;
                }
                foreach (self::overlap($e, $f) as $iv) {
                    $any[] = $iv;
                    if ($rj > $ri) {
                        $higher[] = $iv;
                    }
                }
            }
            foreach (self::complement(self::merge($any)) as $iv) {
                $cuts[] = self::subPrim($e, $iv);
            }
            foreach (self::merge($higher) as $iv) {
                $folds[] = self::subPrim($e, $iv);
            }
        }
        return ['cuts' => $cuts, 'folds' => $folds];
    }

    /** Overlapp mellom to primitiver som intervaller i e sin parameter [0, 1]. */
    private static function overlap(array $e, array $f): array
    {
        if ($e[0] !== $f[0]) {
            return [];
        }
        if ($e[0] === 'L') {
            [, $a, $b] = $e;
            $d = [$b[0] - $a[0], $b[1] - $a[1]];
            $len2 = $d[0] ** 2 + $d[1] ** 2;
            $len = sqrt($len2);
            foreach ([$f[1], $f[2]] as $q) {
                $cross = ($q[0] - $a[0]) * $d[1] - ($q[1] - $a[1]) * $d[0];
                if (abs($cross) / $len > 1e-5) {
                    return [];
                }
            }
            $t = array_map(fn($q) => (($q[0] - $a[0]) * $d[0] + ($q[1] - $a[1]) * $d[1]) / $len2, [$f[1], $f[2]]);
            $lo = max(0.0, min($t));
            $hi = min(1.0, max($t));
            return ($hi - $lo) * $len > 1e-4 ? [[$lo, $hi]] : [];
        }
        [, $c, $r, $a0, $a1] = $e;
        [, $c2, $r2, $b0, $b1] = $f;
        if (abs($c[0] - $c2[0]) > 1e-5 || abs($c[1] - $c2[1]) > 1e-5 || abs($r - $r2) > 1e-5) {
            return [];
        }
        $out = [];
        $span = $a1 - $a0;
        for ($k = -2; $k <= 2; $k++) {
            $lo = max($a0, $b0 + 2 * M_PI * $k);
            $hi = min($a1, $b1 + 2 * M_PI * $k);
            if (($hi - $lo) * $r > 1e-4) {
                $out[] = [($lo - $a0) / $span, ($hi - $a0) / $span];
            }
        }
        return $out;
    }

    private static function merge(array $ivs): array
    {
        usort($ivs, fn($x, $y) => $x[0] <=> $y[0]);
        $out = [];
        foreach ($ivs as $iv) {
            if ($out && $iv[0] <= $out[count($out) - 1][1] + 1e-9) {
                $out[count($out) - 1][1] = max($out[count($out) - 1][1], $iv[1]);
            } else {
                $out[] = $iv;
            }
        }
        return $out;
    }

    private static function complement(array $merged): array
    {
        $out = [];
        $t = 0.0;
        foreach ($merged as [$lo, $hi]) {
            if ($lo > $t + 1e-9) {
                $out[] = [$t, $lo];
            }
            $t = max($t, $hi);
        }
        if ($t < 1 - 1e-9) {
            $out[] = [$t, 1.0];
        }
        return $out;
    }

    private static function subPrim(array $e, array $iv): array
    {
        [$t0, $t1] = $iv;
        if ($e[0] === 'L') {
            $p = fn($t) => [$e[1][0] + ($e[2][0] - $e[1][0]) * $t, $e[1][1] + ($e[2][1] - $e[1][1]) * $t];
            return ['L', $p($t0), $p($t1)];
        }
        $span = $e[4] - $e[3];
        return ['A', $e[1], $e[2], $e[3] + $span * $t0, $e[3] + $span * $t1];
    }

    /* ------------------------------------------------------------- JSON */

    public function toArray(): array
    {
        $r4 = fn(array $p) => [round($p[0], 3), round($p[1], 3)];
        $prim = fn(array $e) => $e[0] === 'L'
            ? ['L', $r4($e[1]), $r4($e[2])]
            : ['A', $r4($e[1]), round($e[2], 3), round($e[3], 6), round($e[4], 6)];
        $lines = $this->lines();
        $b = $this->bbox();
        return [
            'variant' => $this->variant,
            'bbox'    => array_map(fn($v) => round($v, 3), $b),
            'regions' => array_map(fn($r) => [
                'kind'  => $r['kind'],
                'face'  => $r['face'],
                'edges' => array_map($prim, $r['edges']),
                'label' => $r['label'] ? ['lines' => $r['label']['lines'], 'at' => $r4($r['label']['at']), 'size' => round($r['label']['size'], 2)] : null,
            ], $this->regions),
            'cuts'    => array_map($prim, $lines['cuts']),
            'folds'   => array_map($prim, $lines['folds']),
        ];
    }

    /* -------------------------------------------------- Overlappkontroll */

    /**
     * Areal (mm²) der to regioner overlapper. Brukes av testene for å
     * kontrollere at limfliker og flater ikke kolliderer.
     */
    public function overlapArea(int $i, int $j, float $step = 0.5): float
    {
        $a = $this->polygon($i, $step);
        $b = $this->polygon($j, $step);
        if (!self::boxesTouch($a, $b)) {
            return 0.0;
        }
        $ta = self::triangulate($a);
        $tb = self::triangulate($b);
        $sum = 0.0;
        foreach ($ta as $t1) {
            foreach ($tb as $t2) {
                if (!self::boxesTouch($t1, $t2)) {
                    continue;
                }
                $clip = self::clipConvex($t1, $t2);
                if (count($clip) >= 3) {
                    $sum += abs(self::signedArea($clip));
                }
            }
        }
        return $sum;
    }

    private static function boxesTouch(array $a, array $b): bool
    {
        $ax = array_column($a, 0);
        $ay = array_column($a, 1);
        $bx = array_column($b, 0);
        $by = array_column($b, 1);
        return min($ax) < max($bx) && min($bx) < max($ax) && min($ay) < max($by) && min($by) < max($ay);
    }

    public static function signedArea(array $pts): float
    {
        $s = 0.0;
        $n = count($pts);
        for ($i = 0; $i < $n; $i++) {
            $p = $pts[$i];
            $q = $pts[($i + 1) % $n];
            $s += $p[0] * $q[1] - $q[0] * $p[1];
        }
        return $s / 2;
    }

    /** Øreklipping av en enkel mangekant. */
    private static function triangulate(array $pts): array
    {
        // Fjern punkter som ligger på linje (gir degenererte ører).
        $pts = array_values($pts);
        if (self::signedArea($pts) < 0) {
            $pts = array_reverse($pts);
        }
        $out = [];
        $guard = 0;
        while (count($pts) > 3 && $guard++ < 100000) {
            $n = count($pts);
            $found = false;
            for ($i = 0; $i < $n; $i++) {
                $p = $pts[($i + $n - 1) % $n];
                $q = $pts[$i];
                $r = $pts[($i + 1) % $n];
                $cross = ($q[0] - $p[0]) * ($r[1] - $p[1]) - ($q[1] - $p[1]) * ($r[0] - $p[0]);
                if ($cross <= 1e-12) {
                    if (abs($cross) <= 1e-12) {       // på linje: fjern punktet
                        array_splice($pts, $i, 1);
                        $found = true;
                        break;
                    }
                    continue;
                }
                $inside = false;
                foreach ($pts as $k => $s) {
                    if ($k === $i || $k === ($i + $n - 1) % $n || $k === ($i + 1) % $n) {
                        continue;
                    }
                    if (self::inTriangle($s, $p, $q, $r)) {
                        $inside = true;
                        break;
                    }
                }
                if (!$inside) {
                    $out[] = [$p, $q, $r];
                    array_splice($pts, $i, 1);
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                break;
            }
        }
        if (count($pts) === 3) {
            $out[] = $pts;
        }
        return $out;
    }

    private static function inTriangle(array $s, array $a, array $b, array $c): bool
    {
        $d1 = ($s[0] - $b[0]) * ($a[1] - $b[1]) - ($a[0] - $b[0]) * ($s[1] - $b[1]);
        $d2 = ($s[0] - $c[0]) * ($b[1] - $c[1]) - ($b[0] - $c[0]) * ($s[1] - $c[1]);
        $d3 = ($s[0] - $a[0]) * ($c[1] - $a[1]) - ($c[0] - $a[0]) * ($s[1] - $a[1]);
        $neg = $d1 < -1e-12 || $d2 < -1e-12 || $d3 < -1e-12;
        $pos = $d1 > 1e-12 || $d2 > 1e-12 || $d3 > 1e-12;
        return !($neg && $pos);
    }

    /** Sutherland–Hodgman: $subject klippet mot den konvekse $clip (begge mot klokka). */
    private static function clipConvex(array $subject, array $clip): array
    {
        if (self::signedArea($clip) < 0) {
            $clip = array_reverse($clip);
        }
        $out = $subject;
        $n = count($clip);
        for ($i = 0; $i < $n && $out; $i++) {
            $a = $clip[$i];
            $b = $clip[($i + 1) % $n];
            $in = $out;
            $out = [];
            $side = fn($p) => ($b[0] - $a[0]) * ($p[1] - $a[1]) - ($b[1] - $a[1]) * ($p[0] - $a[0]);
            $m = count($in);
            for ($k = 0; $k < $m; $k++) {
                $p = $in[$k];
                $q = $in[($k + 1) % $m];
                $sp = $side($p);
                $sq = $side($q);
                if ($sp >= 0) {
                    $out[] = $p;
                }
                if (($sp >= 0) !== ($sq >= 0)) {
                    $t = $sp / ($sp - $sq);
                    $out[] = [$p[0] + ($q[0] - $p[0]) * $t, $p[1] + ($q[1] - $p[1]) * $t];
                }
            }
        }
        return $out;
    }

    /* --------------------------------------------------------- Hjelpere */

    private static function loop(array $pts): array
    {
        $edges = [];
        $n = count($pts);
        for ($i = 0; $i < $n; $i++) {
            $edges[] = ['L', $pts[$i], $pts[($i + 1) % $n]];
        }
        return $edges;
    }

    /** Enhetsvektor langs kanten, enhetsnormal bort fra $inside, og lengde. */
    private static function frame(array $a, array $b, array $inside): array
    {
        $len = hypot($b[0] - $a[0], $b[1] - $a[1]);
        $u = [($b[0] - $a[0]) / $len, ($b[1] - $a[1]) / $len];
        $n = [-$u[1], $u[0]];
        $m = self::mid($a, $b);
        if (($inside[0] - $m[0]) * $n[0] + ($inside[1] - $m[1]) * $n[1] > 0) {
            $n = [-$n[0], -$n[1]];
        }
        return [$u, $n, $len];
    }

    private static function add2(array $p, array $u, float $s, array $n, float $t): array
    {
        return [$p[0] + $u[0] * $s + $n[0] * $t, $p[1] + $u[1] * $s + $n[1] * $t];
    }

    private static function mid(array $a, array $b): array
    {
        return [($a[0] + $b[0]) / 2, ($a[1] + $b[1]) / 2];
    }

    public static function centroid(array $pts): array
    {
        $a = self::signedArea($pts);
        if (abs($a) < 1e-12) {
            return [array_sum(array_column($pts, 0)) / count($pts), array_sum(array_column($pts, 1)) / count($pts)];
        }
        $cx = $cy = 0.0;
        $n = count($pts);
        for ($i = 0; $i < $n; $i++) {
            $p = $pts[$i];
            $q = $pts[($i + 1) % $n];
            $f = $p[0] * $q[1] - $q[0] * $p[1];
            $cx += ($p[0] + $q[0]) * $f;
            $cy += ($p[1] + $q[1]) * $f;
        }
        return [$cx / (6 * $a), $cy / (6 * $a)];
    }

    /** Skriftstørrelse (mm) til teksten i en flate: større flater, større tekst. */
    private static function labelSize(array $pts): float
    {
        $xs = array_column($pts, 0);
        $ys = array_column($pts, 1);
        $m = min(max($xs) - min($xs), max($ys) - min($ys));
        if (count($pts) === 3) {
            $m *= 0.55;     // trekanter er smalere enn omsluttende rektangel
        }
        return max(2.0, min(4.2, $m / 9));
    }

    private static function fmtDeg(float $deg): string
    {
        return '(rotert ' . rtrim(rtrim(number_format($deg, 1, ',', ''), '0'), ',') . '°)';
    }
}
