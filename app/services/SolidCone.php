<?php
declare(strict_types=1);

/**
 * Rett kjegle med grunnflatediameter d og loddrett høyde h. Spissen ligger
 * rett over sentrum av grunnflaten.
 *
 * Skolegeometri: 2 flater (sirkelen og 1 krum sideflate), 1 sirkelformet kant
 * og 1 toppunkt.
 *
 * Nettet: en sirkelsektor med radius s (sidelinjen) og vinkel 2π · r / s,
 * så buelengden blir lik omkretsen av grunnflaten.
 */
final class SolidCone extends Solid
{
    public function shape(): string
    {
        return 'cone';
    }

    public function name(): string
    {
        return 'Kjegle';
    }

    private function r(): float
    {
        return (float) $this->d['diameter'] / 2;
    }

    private function h(): float
    {
        return (float) $this->d['height'];
    }

    /** Sidelinjen (sidekanten) s = √(h² + r²): radius i sektoren. Beregnes bare her. */
    public function slant(): float
    {
        return sqrt($this->h() ** 2 + $this->r() ** 2);
    }

    /** Sektorvinkelen i radianer: 2π · r / s. */
    public function sectorAngle(): float
    {
        return 2 * M_PI * $this->r() / $this->slant();
    }

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
        $s = $this->slant();
        return [
            ['id' => 'base', 'name' => 'Grunnflate', 'shape' => 'sirkel', 'role' => 'grunnflate', 'area' => M_PI * $r * $r,
             'dims' => 'd = ' . self::mm(2 * $r),
             'disk' => ['center' => [0.0, 0.0, 0.0], 'r' => $r, 'normal' => [0.0, 0.0, -1.0]]],
            ['id' => 'side', 'name' => 'Krum sideflate', 'shape' => 'krum', 'role' => 'sideflate', 'area' => M_PI * $r * $s,
             'dims' => 's = ' . self::mm($s) . ', ' . self::fmt(rad2deg($this->sectorAngle())) . '°',
             'surface' => ['type' => 'cone', 'r' => $r, 'h' => $this->h()]],
        ];
    }

    public function edges(): array
    {
        return [['kind' => 'circle', 'center' => [0.0, 0.0, 0.0], 'r' => $this->r(), 'length' => $this->circumference(), 'faces' => [0, 1]]];
    }

    public function vertices(): array
    {
        return [['p' => [0.0, 0.0, $this->h()], 'name' => 'toppunkt']];
    }

    public function counts(): array
    {
        return [
            'vertex_word' => 'toppunkt',
            'note' => 'Den krumme sideflaten regnes som én flate, og sirkelen rundt grunnflaten regnes som en kant. '
                . 'Spissen kalles toppunktet.',
        ] + parent::counts();
    }

    public function volume(): float
    {
        return M_PI * $this->r() ** 2 * $this->h() / 3;
    }

    public function polygonalVolume(): float
    {
        return self::ngonArea($this->r(), $this->segments) * $this->h() / 3;
    }

    public function baseArea(): float
    {
        return M_PI * $this->r() ** 2;
    }

    public function measures(): array
    {
        $r = $this->r();
        return [
            'diameter' => ['symbol' => 'd', 'label' => 'Diameter', 'value' => 2 * $r, 'unit' => 'mm'],
            'radius'   => ['symbol' => 'r', 'label' => 'Radius', 'value' => $r, 'unit' => 'mm', 'formula' => 'r = d / 2'],
            'height'   => ['symbol' => 'h', 'label' => 'Høyde (loddrett)', 'value' => $this->h(), 'unit' => 'mm'],
            'slant'    => ['symbol' => 's', 'label' => 'Sidelinje (radius i sektoren)', 'value' => $this->slant(), 'unit' => 'mm', 'formula' => 's = √(h² + r²)'],
            'circumference' => ['symbol' => 'O', 'label' => 'Omkrets av grunnflaten', 'value' => $this->circumference(), 'unit' => 'mm', 'formula' => 'O = π · d'],
            'sector_angle' => ['symbol' => 'v', 'label' => 'Vinkel i sektoren', 'value' => rad2deg($this->sectorAngle()), 'unit' => '°', 'formula' => 'v = 360° · r / s'],
            'arc_length' => ['symbol' => 'b', 'label' => 'Buelengde i sektoren', 'value' => $this->sectorAngle() * $this->slant(), 'unit' => 'mm', 'formula' => 'b = v · s = π · d'],
            'lateral_area' => ['symbol' => 'M', 'label' => 'Areal av den krumme sideflaten', 'value' => M_PI * $r * $this->slant(), 'unit' => 'mm²', 'formula' => 'M = π · r · s'],
        ];
    }

    public function symmetryPlanes(): float|int
    {
        return INF;
    }

    public function scad(): array
    {
        $d = 2 * $this->r();
        return ['shape' => 5, 'sx' => $d, 'sy' => $d, 'sz' => $this->h()];
    }

    public function cavity(float $t): array
    {
        $r = $this->r();
        $h = $this->h();
        $s = $this->slant();
        $floor = $r - $t * ($s + $r) / $h;
        $apex = $h - $t * $s / $r;
        $q = min($floor, $apex - $t);
        $ok = $q >= 2 && $apex - $t >= 3;
        return [
            'ok'     => $ok,
            'reason' => $ok ? null : 'Figuren er for liten til et hulrom med denne veggtykkelsen.',
            'roof'   => $q < $floor - 1e-9,
            'volume' => $ok ? self::ngonArea($q, $this->segments) * ($apex - $t) / 3 : 0.0,
            'vars'   => ['c_half' => $q, 'c_top' => $apex],
        ];
    }

    public function annotations(): array
    {
        $r = $this->r();
        $h = $this->h();
        return [
            [[-$r, 0, 0], [$r, 0, 0], 'd', 'inner'],
            [[0, 0, 0], [0, 0, $h], 'h', 'inner'],
            [[0, 0, $h], [$r * cos(-0.35), $r * sin(-0.35), 0], 's', 'face'],
        ];
    }

    /* ------------------------------------------------------------ Nett */

    /**
     * Sektoren med limtenner langs buen (limes til sirkelen) og en limflik
     * langs den ene rette kanten (lukker kjeglen). Sirkelen klippes ut for
     * seg. Prøver flere rotasjoner av sektoren for å få plass på arket.
     */
    public function nets(float $tab): array
    {
        $r = $this->r();
        $s = $this->slant();
        $theta = $this->sectorAngle();
        $o = [0.0, 0.0];
        $a0 = M_PI / 2 - $theta / 2;            // symmetrisk om loddlinja, spissen øverst
        $a1 = M_PI / 2 + $theta / 2;
        $sector = new SolidNet('sektor');
        $sector->sectorFace('side', $o, $s, $a0, $a1, $this->label('side'));
        $sector->teethOnArc($o, $s, $a0, $a1, min($tab, 0.45 * $r));

        // Limfliken ligger i åpningen mellom de rette kantene (2π − v). Nær
        // spissen er åpningen smal, så fliken spisses til der.
        $inside = SolidNet::at($o, $s * 0.6, ($a0 + $a1) / 2);
        $sector->apexTab($o, SolidNet::at($o, $s, $a1), $inside, $tab, 2 * M_PI - $theta);

        $circle = new SolidNet();
        $circle->circleFace('base', [0.0, 0.0], $r, $this->label('base'));

        $nets = [];
        $angles = [0, 180, 90, 270];
        for ($d = 15; $d < 360; $d += 15) {
            if (!in_array($d, $angles, true)) {
                $angles[] = $d;
            }
        }
        foreach ($angles as $deg) {
            $rot = $sector->rotated($deg);
            $rotTxt = $deg ? " (rotert {$deg}°)" : '';
            $nets[] = SolidNet::arrange([$rot, $circle], 'column', 5.0, 'sektor over sirkel' . $rotTxt);
            $nets[] = SolidNet::arrange([$rot, $circle], 'row', 5.0, 'sektor og sirkel' . $rotTxt);
        }
        return array_map(fn(SolidNet $n) => $n->normalized(), $nets);
    }
}
