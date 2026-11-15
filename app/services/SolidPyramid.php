<?php
declare(strict_types=1);

/**
 * Firkantet pyramide: kvadratisk grunnflate med side g og loddrett høyde h.
 * Spissen ligger rett over sentrum av grunnflaten. Sideflatene er fire
 * likebeinte trekanter med grunnlinje g og høyde s (skråhøyden).
 */
final class SolidPyramid extends Solid
{
    public function shape(): string
    {
        return 'pyramid';
    }

    public function name(): string
    {
        return 'Firkantet pyramide';
    }

    private function g(): float
    {
        return (float) $this->d['base'];
    }

    private function h(): float
    {
        return (float) $this->d['height'];
    }

    /** Skråhøyden: høyden i sideflatene, s = √(h² + (g/2)²). Beregnes bare her. */
    public function slant(): float
    {
        return sqrt($this->h() ** 2 + ($this->g() / 2) ** 2);
    }

    /** Sidekanten fra et hjørne i grunnflaten til spissen. */
    public function lateralEdge(): float
    {
        return sqrt($this->slant() ** 2 + ($this->g() / 2) ** 2);
    }

    public function size(): array
    {
        return [$this->g(), $this->g(), $this->h()];
    }

    protected function buildFaces(): array
    {
        $a = $this->g() / 2;
        $h = $this->h();
        $s = $this->slant();
        $apex = [0.0, 0.0, $h];
        $tri = [
            'shape' => 'trekant', 'role' => 'sideflate', 'area' => $this->g() * $s / 2,
            'dims' => 'g = ' . self::mm($this->g()) . ', s = ' . self::mm($s),
        ];
        return [
            ['id' => 'base', 'name' => 'Grunnflate', 'role' => 'grunnflate', 'shape' => 'kvadrat', 'area' => $this->g() ** 2,
             'dims' => self::fmt($this->g()) . ' × ' . self::fmt($this->g()) . ' mm',
             'polygon' => [[-$a, -$a, 0], [-$a, $a, 0], [$a, $a, 0], [$a, -$a, 0]]],
            ['id' => 'front', 'name' => 'Sideflate 1', 'polygon' => [[-$a, -$a, 0], [$a, -$a, 0], $apex]] + $tri,
            ['id' => 'right', 'name' => 'Sideflate 2', 'polygon' => [[$a, -$a, 0], [$a, $a, 0], $apex]] + $tri,
            ['id' => 'back', 'name' => 'Sideflate 3', 'polygon' => [[$a, $a, 0], [-$a, $a, 0], $apex]] + $tri,
            ['id' => 'left', 'name' => 'Sideflate 4', 'polygon' => [[-$a, $a, 0], [-$a, -$a, 0], $apex]] + $tri,
        ];
    }

    public function volume(): float
    {
        return $this->g() ** 2 * $this->h() / 3;
    }

    public function baseArea(): float
    {
        return $this->g() ** 2;
    }

    public function measures(): array
    {
        return [
            'base'   => ['symbol' => 'g', 'label' => 'Grunnside', 'value' => $this->g(), 'unit' => 'mm'],
            'height' => ['symbol' => 'h', 'label' => 'Høyde (loddrett)', 'value' => $this->h(), 'unit' => 'mm'],
            'slant'  => ['symbol' => 's', 'label' => 'Skråhøyde (høyden i sideflatene)', 'value' => $this->slant(), 'unit' => 'mm', 'formula' => 's = √(h² + (g/2)²)'],
            'edge'   => ['symbol' => 'k', 'label' => 'Sidekant', 'value' => $this->lateralEdge(), 'unit' => 'mm', 'formula' => 'k = √(s² + (g/2)²)'],
            'base_perimeter' => ['symbol' => 'O', 'label' => 'Omkrets av grunnflaten', 'value' => 4 * $this->g(), 'unit' => 'mm', 'formula' => '4 · g'],
        ];
    }

    public function symmetryPlanes(): float|int
    {
        return 4;
    }

    public function scad(): array
    {
        return ['shape' => 2, 'sx' => $this->g(), 'sy' => $this->g(), 'sz' => $this->h()];
    }

    /**
     * Sideflatene forskjøvet $t innover og gulvet $t opp. Er sideflatene
     * slakere enn 45°, brukes en 45°-pyramide med samme topp (den ligger
     * under de forskjøvne flatene), så taket aldri heller mindre enn 45°.
     */
    public function cavity(float $t): array
    {
        $a = $this->g() / 2;
        $h = $this->h();
        $s = $this->slant();
        $floor = $a - $t * ($s + $a) / $h;
        $apex = $h - $t * $s / $a;
        $q = min($floor, $apex - $t);
        $ok = $q >= 2 && $apex - $t >= 3;
        return [
            'ok'     => $ok,
            'reason' => $ok ? null : 'Figuren er for liten til et hulrom med denne veggtykkelsen.',
            'roof'   => $q < $floor - 1e-9,
            'volume' => $ok ? (2 * $q) ** 2 * ($apex - $t) / 3 : 0.0,
            'vars'   => ['c_half' => $q, 'c_top' => $apex],
        ];
    }

    public function annotations(): array
    {
        $a = $this->g() / 2;
        $h = $this->h();
        return [
            [[-$a, -$a, 0], [$a, -$a, 0], 'g', 'below'],
            [[0, 0, 0], [0, 0, $h], 'h', 'inner'],
            [[0, 0, $h], [0, -$a, 0], 's', 'face'],
        ];
    }

    /* ------------------------------------------------------------ Nett */

    public function nets(float $tab): array
    {
        $star = $this->star($tab);
        $fan = $this->fan($tab);
        $out = [$star->normalized()];
        if ($fan) {
            $out[] = $fan->normalized();
        }
        $rot = [[$star, 45]];
        if ($fan) {
            foreach ([90, 180, 270, 45, 135, 225, 315] as $deg) {
                $rot[] = [$fan, $deg];
            }
        }
        foreach ($rot as [$n, $deg]) {
            $out[] = $n->rotated($deg)->normalized();
        }
        return $out;
    }

    /** Stjerne: grunnflaten i midten med en trekant på hver kant. */
    private function star(float $tab): SolidNet
    {
        $a = $this->g() / 2;
        $s = $this->slant();
        $net = new SolidNet('stjerne');
        $sq = [[-$a, -$a], [$a, -$a], [$a, $a], [-$a, $a]];
        $net->polygonFace('base', $sq, $this->label('base'));
        // Nettet er sett utenfra: kanten med y = −a i nettet er forsiden (y = −a i 3D).
        $ids = ['front', 'right', 'back', 'left'];
        $out = [[0, -1], [1, 0], [0, 1], [-1, 0]];
        for ($k = 0; $k < 4; $k++) {
            $p = $sq[$k];
            $q = $sq[($k + 1) % 4];
            $apex = [($p[0] + $q[0]) / 2 + $out[$k][0] * $s, ($p[1] + $q[1]) / 2 + $out[$k][1] * $s];
            $tri = [$p, $q, $apex];
            $net->polygonFace($ids[$k], $tri, $this->label($ids[$k]));
            $net->tab($q, $apex, SolidNet::centroid($tri), $tab);
        }
        return $net;
    }

    /**
     * Vifte: fire trekanter rundt spissen, grunnflaten på en av dem. Ikke
     * mulig når pyramiden er så flat at viftens åpning blir for smal til
     * limfliken.
     */
    private function fan(float $tab): ?SolidNet
    {
        $g = $this->g();
        $s = $this->slant();
        $e = $this->lateralEdge();
        $beta = 2 * atan(($g / 2) / $s);         // vinkelen i spissen av hver trekant
        $o = [0.0, 0.0];
        $phi = fn(int $k) => M_PI / 2 - 2 * $beta + $k * $beta;
        $net = new SolidNet('vifte');
        $ids = ['front', 'right', 'back', 'left'];
        $tris = [];
        for ($k = 0; $k < 4; $k++) {
            $tri = [$o, SolidNet::at($o, $e, $phi($k)), SolidNet::at($o, $e, $phi($k + 1))];
            $tris[] = $tri;
            $net->polygonFace($ids[$k], $tri, $this->label($ids[$k]));
        }
        // Grunnflaten på grunnlinjen til trekant 1, ut fra spissen.
        [$A, $B] = [$tris[1][1], $tris[1][2]];
        $m = [($A[0] + $B[0]) / 2, ($A[1] + $B[1]) / 2];
        $len = hypot($m[0], $m[1]);
        $n = [$m[0] / $len * $g, $m[1] / $len * $g];
        $sq = [$A, $B, [$B[0] + $n[0], $B[1] + $n[1]], [$A[0] + $n[0], $A[1] + $n[1]]];
        $net->polygonFace('base', $sq, $this->label('base'));
        $c = SolidNet::centroid($sq);
        $net->tab($sq[1], $sq[2], $c, $tab);
        $net->tab($sq[2], $sq[3], $c, $tab);
        $net->tab($sq[3], $sq[0], $c, $tab);
        $ok = $net->apexTab($o, $tris[0][1], SolidNet::centroid($tris[0]), $tab, 2 * M_PI - 4 * $beta);
        return $ok ? $net : null;
    }
}
