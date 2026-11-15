<?php
declare(strict_types=1);

/**
 * Sylinder med diameter d og høyde h, stående på den ene sirkelflaten.
 *
 * Skolegeometri: 3 flater (2 sirkler og 1 krum sideflate), 2 sirkelformede
 * kanter og ingen hjørner. STL-fila er en mangekant-tilnærming med
 * $segments sider; nettet bruker den matematisk eksakte sirkelen.
 */
final class SolidCylinder extends Solid
{
    public function shape(): string
    {
        return 'cylinder';
    }

    public function name(): string
    {
        return 'Sylinder';
    }

    private function r(): float
    {
        return (float) $this->d['diameter'] / 2;
    }

    private function h(): float
    {
        return (float) $this->d['height'];
    }

    /** Omkretsen π · d: lengden av rektangelet i nettet. Beregnes bare her. */
    public function circumference(): float
    {
        return 2 * M_PI * $this->r();
    }

    public function size(): array
    {
        $d = 2 * $this->r();
        return [$d, $d, $this->h()];
    }

    protected function buildFaces(): array
    {
        $r = $this->r();
        $h = $this->h();
        $disk = ['shape' => 'sirkel', 'role' => 'grunnflate', 'area' => M_PI * $r * $r, 'dims' => 'd = ' . self::mm(2 * $r)];
        return [
            ['id' => 'bottom', 'name' => 'Grunnflate (bunn)', 'disk' => ['center' => [0.0, 0.0, 0.0], 'r' => $r, 'normal' => [0.0, 0.0, -1.0]]] + $disk,
            ['id' => 'top', 'name' => 'Grunnflate (topp)', 'disk' => ['center' => [0.0, 0.0, $h], 'r' => $r, 'normal' => [0.0, 0.0, 1.0]]] + $disk,
            ['id' => 'side', 'name' => 'Krum sideflate', 'shape' => 'krum', 'role' => 'sideflate',
             'area' => $this->circumference() * $h,
             'dims' => self::fmt($this->circumference()) . ' × ' . self::fmt($h) . ' mm',
             'surface' => ['type' => 'cylinder', 'r' => $r, 'z0' => 0.0, 'z1' => $h]],
        ];
    }

    public function edges(): array
    {
        $r = $this->r();
        $len = $this->circumference();
        return [
            ['kind' => 'circle', 'center' => [0.0, 0.0, 0.0], 'r' => $r, 'length' => $len, 'faces' => [0, 2]],
            ['kind' => 'circle', 'center' => [0.0, 0.0, $this->h()], 'r' => $r, 'length' => $len, 'faces' => [1, 2]],
        ];
    }

    public function vertices(): array
    {
        return [];
    }

    public function counts(): array
    {
        return [
            'note' => 'Den krumme sideflaten regnes som én flate, og de to sirkelformede kantene regnes som kanter. '
                . 'Sylinderen har ingen hjørner.',
        ] + parent::counts();
    }

    public function volume(): float
    {
        return M_PI * $this->r() ** 2 * $this->h();
    }

    public function polygonalVolume(): float
    {
        return self::ngonArea($this->r(), $this->segments) * $this->h();
    }

    public function baseArea(): float
    {
        return M_PI * $this->r() ** 2;
    }

    public function measures(): array
    {
        return [
            'diameter' => ['symbol' => 'd', 'label' => 'Diameter', 'value' => 2 * $this->r(), 'unit' => 'mm'],
            'radius'   => ['symbol' => 'r', 'label' => 'Radius', 'value' => $this->r(), 'unit' => 'mm', 'formula' => 'r = d / 2'],
            'height'   => ['symbol' => 'h', 'label' => 'Høyde', 'value' => $this->h(), 'unit' => 'mm'],
            'circumference' => ['symbol' => 'O', 'label' => 'Omkrets av grunnflaten', 'value' => $this->circumference(), 'unit' => 'mm', 'formula' => 'O = π · d'],
            'lateral_area' => ['symbol' => 'M', 'label' => 'Areal av den krumme sideflaten', 'value' => $this->circumference() * $this->h(), 'unit' => 'mm²', 'formula' => 'M = π · d · h'],
        ];
    }

    public function symmetryPlanes(): float|int
    {
        return INF;
    }

    public function scad(): array
    {
        $d = 2 * $this->r();
        return ['shape' => 4, 'sx' => $d, 'sy' => $d, 'sz' => $this->h()];
    }

    /** Sylinder forskjøvet $t innover, med kjegleformet tak (45°) i stedet for flatt tak. */
    public function cavity(float $t): array
    {
        $ri = $this->r() - $t;
        $top = $this->h() - $t;
        $H = $top - $t;
        $ok = $ri >= 2 && $H >= 3;
        $k = Solid::ngonArea(1.0, $this->segments);       // areal av n-kant med radius 1
        $V = $H >= $ri ? $k * $ri * $ri * ($H - $ri) + $k * $ri ** 3 / 3 : $k * $H ** 3 / 3;
        return [
            'ok'     => $ok,
            'reason' => $ok ? null : 'Figuren er for liten til et hulrom med denne veggtykkelsen.',
            'roof'   => true,
            'volume' => $ok ? $V : 0.0,
            'vars'   => ['c_r' => $ri, 'c_top' => $top],
        ];
    }

    public function annotations(): array
    {
        $r = $this->r();
        $h = $this->h();
        return [
            [[-$r, 0, $h], [$r, 0, $h], 'd', 'inner'],
            [[0, 0, 0], [0, 0, $h], 'h', 'inner'],
        ];
    }

    /* ------------------------------------------------------------ Nett */

    /**
     * Rektangel (π · d × h) med limtenner langs begge de lange kantene og en
     * limflik for å lukke røret. Sirklene klippes ut for seg og limes på
     * tennene.
     */
    public function nets(float $tab): array
    {
        $r = $this->r();
        $h = $this->h();
        $C = $this->circumference();
        $main = new SolidNet('rektangel');
        $rect = self::rect(0, 0, $C, $h);
        $main->polygonFace('side', $rect, $this->label('side'));
        $c = SolidNet::centroid($rect);
        $teeth = min($tab, 0.45 * $r);
        $main->teethOnLine($rect[0], $rect[1], $c, $teeth);
        $main->teethOnLine($rect[3], $rect[2], $c, $teeth);
        $main->tab($rect[1], $rect[2], $c, $tab);

        $circle = function (string $id) use ($r) {
            $n = new SolidNet();
            $n->circleFace($id, [0.0, 0.0], $r, $this->label($id));
            return $n;
        };
        $top = $circle('top');
        $bottom = $circle('bottom');
        $gap = 5.0;
        $nets = [
            SolidNet::arrange([$main, SolidNet::arrange([$top, $bottom], 'row', $gap, '')], 'column', $gap, 'sirkler under'),
            SolidNet::arrange([$main, SolidNet::arrange([$top, $bottom], 'column', $gap, '')], 'row', $gap, 'sirkler ved siden av'),
            SolidNet::arrange([$top, $main, $bottom], 'column', $gap, 'sirkel over og under'),
            SolidNet::arrange([$main, $top, $bottom], 'row', $gap, 'alt på rad'),
        ];
        return self::withRotations($nets, [90]);
    }
}
