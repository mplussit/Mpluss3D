<?php
declare(strict_types=1);

/**
 * Rett trekantet prisme. Grunnflaten er en likebeint trekant med
 * grunnlinje g og høyde h (spissen over midtpunktet på grunnlinjen).
 * Prismet er l langt.
 *
 * Ligger på det største rektangelet (g × l) på byggeplaten: x = lengde,
 * trekanten i yz-planet. Da peker ingen flater nedover.
 */
final class SolidTriPrism extends Solid
{
    public function shape(): string
    {
        return 'triprism';
    }

    public function name(): string
    {
        return 'Trekantet prisme';
    }

    private function g(): float
    {
        return (float) $this->d['base'];
    }

    private function h(): float
    {
        return (float) $this->d['height'];
    }

    private function l(): float
    {
        return (float) $this->d['length'];
    }

    /** De skrå sidene i trekanten: a = √((g/2)² + h²). Beregnes bare her. */
    public function side(): float
    {
        return sqrt(($this->g() / 2) ** 2 + $this->h() ** 2);
    }

    public function size(): array
    {
        return [$this->l(), $this->g(), $this->h()];
    }

    protected function buildFaces(): array
    {
        $x = $this->l() / 2;
        $y = $this->g() / 2;
        $h = $this->h();
        $a = $this->side();
        $tri = ['shape' => 'trekant', 'role' => 'grunnflate', 'area' => $this->baseArea(),
                'dims' => 'g = ' . self::mm($this->g()) . ', h = ' . self::mm($h)];
        $rect = fn(float $w) => ['shape' => abs($w - $this->l()) < 1e-9 ? 'kvadrat' : 'rektangel', 'role' => 'sideflate',
            'area' => $w * $this->l(), 'dims' => self::fmt($w) . ' × ' . self::fmt($this->l()) . ' mm'];
        return [
            ['id' => 'end1', 'name' => 'Grunnflate 1', 'polygon' => [[-$x, -$y, 0], [-$x, $y, 0], [-$x, 0, $h]]] + $tri,
            ['id' => 'end2', 'name' => 'Grunnflate 2', 'polygon' => [[$x, -$y, 0], [$x, $y, 0], [$x, 0, $h]]] + $tri,
            ['id' => 'bottom', 'name' => 'Sideflate (bunn)', 'polygon' => [[-$x, -$y, 0], [$x, -$y, 0], [$x, $y, 0], [-$x, $y, 0]]] + $rect($this->g()),
            ['id' => 'left', 'name' => 'Sideflate (skrå)', 'polygon' => [[-$x, -$y, 0], [$x, -$y, 0], [$x, 0, $h], [-$x, 0, $h]]] + $rect($a),
            ['id' => 'right', 'name' => 'Sideflate (skrå)', 'polygon' => [[-$x, $y, 0], [$x, $y, 0], [$x, 0, $h], [-$x, 0, $h]]] + $rect($a),
        ];
    }

    public function volume(): float
    {
        return $this->baseArea() * $this->l();
    }

    public function baseArea(): float
    {
        return $this->g() * $this->h() / 2;
    }

    public function measures(): array
    {
        return [
            'base'   => ['symbol' => 'g', 'label' => 'Grunnlinje i trekanten', 'value' => $this->g(), 'unit' => 'mm'],
            'height' => ['symbol' => 'h', 'label' => 'Høyde i trekanten', 'value' => $this->h(), 'unit' => 'mm'],
            'length' => ['symbol' => 'l', 'label' => 'Lengde', 'value' => $this->l(), 'unit' => 'mm'],
            'side'   => ['symbol' => 'a', 'label' => 'Skrå side i trekanten', 'value' => $this->side(), 'unit' => 'mm', 'formula' => 'a = √((g/2)² + h²)'],
            'base_perimeter' => ['symbol' => 'O', 'label' => 'Omkrets av trekanten', 'value' => $this->g() + 2 * $this->side(), 'unit' => 'mm', 'formula' => 'g + 2a'],
        ];
    }

    public function symmetryPlanes(): float|int
    {
        // Likebeint: ett plan gjennom spissen og ett midt på lengden. Likesidet: 3 + 1.
        return abs($this->side() - $this->g()) < 1e-9 ? 4 : 2;
    }

    public function scad(): array
    {
        return ['shape' => 3, 'sx' => $this->l(), 'sy' => $this->g(), 'sz' => $this->h()];
    }

    public function cavity(float $t): array
    {
        $a2 = $this->g() / 2;
        $h = $this->h();
        $s = $this->side();
        $floor = $a2 - $t * ($s + $a2) / $h;
        $apex = $h - $t * $s / $a2;
        $q = min($floor, $apex - $t);
        $len = $this->l() - 2 * $t;
        $ok = $q >= 2 && $apex - $t >= 3 && $len >= 4;
        return [
            'ok'     => $ok,
            'reason' => $ok ? null : 'Figuren er for liten til et hulrom med denne veggtykkelsen.',
            'roof'   => $q < $floor - 1e-9,
            'volume' => $ok ? $q * ($apex - $t) * $len : 0.0,
            'vars'   => ['c_half' => $q, 'c_top' => $apex, 'c_len' => $len],
        ];
    }

    public function annotations(): array
    {
        $x = $this->l() / 2;
        $y = $this->g() / 2;
        return [
            [[$x, -$y, 0], [$x, $y, 0], 'g', 'below'],
            [[$x, 0, 0], [$x, 0, $this->h()], 'h', 'inner'],
            [[-$x, -$y, 0], [$x, -$y, 0], 'l', 'below'],
        ];
    }

    /* ------------------------------------------------------------ Nett */

    public function nets(float $tab): array
    {
        return self::withRotations([$this->strip($tab)], [90]);
    }

    /**
     * Tre rektangler på rad (skrå, bunn, skrå) med en trekant på hver ende
     * av bunnrektangelet. Fem limfliker: én for å lukke prismet og to på
     * hver trekant.
     */
    private function strip(float $tab): SolidNet
    {
        $a = $this->side();
        $g = $this->g();
        $h = $this->h();
        $l = $this->l();
        $net = new SolidNet('rekke');
        $left = self::rect(0, 0, $a, $l);
        $bottom = self::rect($a, 0, $g, $l);
        $right = self::rect($a + $g, 0, $a, $l);
        $net->polygonFace('left', $left, $this->label('left'));
        $net->polygonFace('bottom', $bottom, $this->label('bottom'));
        $net->polygonFace('right', $right, $this->label('right'));
        $net->tab($right[1], $right[2], SolidNet::centroid($right), $tab);
        $t1 = [[$a, 0], [$a + $g, 0], [$a + $g / 2, -$h]];
        $t2 = [[$a, $l], [$a + $g, $l], [$a + $g / 2, $l + $h]];
        foreach ([['end1', $t1], ['end2', $t2]] as [$id, $tri]) {
            $net->polygonFace($id, $tri, $this->label($id));
            $c = SolidNet::centroid($tri);
            $net->tab($tri[1], $tri[2], $c, $tab);
            $net->tab($tri[2], $tri[0], $c, $tab);
        }
        return $net;
    }
}
