<?php
declare(strict_types=1);

/**
 * Rektangulært prisme (rett firkantet prisme): lengde × bredde × høyde.
 * Kuben (SolidCube) er det samme med like sider.
 *
 * Står på bunnen: x = lengde, y = bredde, z = høyde.
 */
class SolidCuboid extends Solid
{
    public function shape(): string
    {
        return 'cuboid';
    }

    public function name(): string
    {
        return 'Rektangulært prisme';
    }

    protected function l(): float
    {
        return (float) $this->d['length'];
    }

    protected function b(): float
    {
        return (float) $this->d['width'];
    }

    protected function h(): float
    {
        return (float) $this->d['height'];
    }

    public function size(): array
    {
        return [$this->l(), $this->b(), $this->h()];
    }

    protected function buildFaces(): array
    {
        [$L, $W, $H] = $this->size();
        $x = $L / 2;
        $y = $W / 2;
        $rect = fn(float $a, float $b) => [
            'shape' => abs($a - $b) < 1e-9 ? 'kvadrat' : 'rektangel',
            'area'  => $a * $b,
            'dims'  => self::fmt($a) . ' × ' . self::fmt($b) . ' mm',
        ];
        return [
            ['id' => 'bottom', 'name' => 'Bunn', 'role' => 'grunnflate', 'polygon' => [[-$x, -$y, 0], [-$x, $y, 0], [$x, $y, 0], [$x, -$y, 0]]] + $rect($L, $W),
            ['id' => 'top', 'name' => 'Topp', 'role' => 'grunnflate', 'polygon' => [[-$x, -$y, $H], [$x, -$y, $H], [$x, $y, $H], [-$x, $y, $H]]] + $rect($L, $W),
            ['id' => 'front', 'name' => 'Forside', 'role' => 'sideflate', 'polygon' => [[-$x, -$y, 0], [$x, -$y, 0], [$x, -$y, $H], [-$x, -$y, $H]]] + $rect($L, $H),
            ['id' => 'back', 'name' => 'Bakside', 'role' => 'sideflate', 'polygon' => [[$x, $y, 0], [-$x, $y, 0], [-$x, $y, $H], [$x, $y, $H]]] + $rect($L, $H),
            ['id' => 'left', 'name' => 'Venstre side', 'role' => 'sideflate', 'polygon' => [[-$x, $y, 0], [-$x, -$y, 0], [-$x, -$y, $H], [-$x, $y, $H]]] + $rect($W, $H),
            ['id' => 'right', 'name' => 'Høyre side', 'role' => 'sideflate', 'polygon' => [[$x, -$y, 0], [$x, $y, 0], [$x, $y, $H], [$x, -$y, $H]]] + $rect($W, $H),
        ];
    }

    public function volume(): float
    {
        return $this->l() * $this->b() * $this->h();
    }

    public function baseArea(): float
    {
        return $this->l() * $this->b();
    }

    public function measures(): array
    {
        [$L, $W, $H] = $this->size();
        return [
            'length' => ['symbol' => 'l', 'label' => 'Lengde', 'value' => $L, 'unit' => 'mm'],
            'width'  => ['symbol' => 'b', 'label' => 'Bredde', 'value' => $W, 'unit' => 'mm'],
            'height' => ['symbol' => 'h', 'label' => 'Høyde', 'value' => $H, 'unit' => 'mm'],
            'base_perimeter' => ['symbol' => 'O', 'label' => 'Omkrets av grunnflaten', 'value' => 2 * ($L + $W), 'unit' => 'mm', 'formula' => '2 · (l + b)'],
            'diagonal' => ['symbol' => 'd', 'label' => 'Romdiagonal', 'value' => sqrt($L * $L + $W * $W + $H * $H), 'unit' => 'mm', 'formula' => '√(l² + b² + h²)'],
        ];
    }

    public function symmetryPlanes(): float|int
    {
        [$L, $W, $H] = $this->size();
        $eq = (int) (abs($L - $W) < 1e-9) + (int) (abs($L - $H) < 1e-9) + (int) (abs($W - $H) < 1e-9);
        return match (true) {
            $eq === 3 => 9,      // kube
            $eq === 1 => 5,      // to like mål: kvadratisk prisme
            default   => 3,
        };
    }

    public function scad(): array
    {
        [$L, $W, $H] = $this->size();
        return ['shape' => 1, 'sx' => $L, 'sy' => $W, 'sz' => $H];
    }

    /**
     * Hulrom: boksen forskjøvet $t innover, med valmtak (45°) i stedet for
     * flatt tak. Mønet ligger $t under toppen.
     */
    public function cavity(float $t): array
    {
        [$L, $W, $H] = $this->size();
        $li = $L - 2 * $t;
        $wi = $W - 2 * $t;
        $top = $H - $t;
        $m = min($li, $wi);
        $ok = $li >= 4 && $wi >= 4 && $top - $t >= 3;
        $D = min($m / 2, $top - $t);
        $V = $li * $wi * max(0.0, ($top - $t) - $m / 2) + abs($li - $wi) * $D * $D + 4 / 3 * $D ** 3;
        return [
            'ok'     => $ok,
            'reason' => $ok ? null : 'Figuren er for liten til et hulrom med denne veggtykkelsen.',
            'roof'   => true,
            'volume' => $ok ? $V : 0.0,
            'vars'   => ['c_x' => $li, 'c_y' => $wi, 'c_top' => $top, 'ridge_x' => max(0.0, $li - $m), 'ridge_y' => max(0.0, $wi - $m)],
        ];
    }

    public function annotations(): array
    {
        [$L, $W, $H] = $this->size();
        $x = $L / 2;
        $y = $W / 2;
        return [
            [[-$x, -$y, 0], [$x, -$y, 0], 'l', 'below'],
            [[$x, -$y, 0], [$x, $y, 0], 'b', 'below'],
            [[$x, -$y, 0], [$x, -$y, $H], 'h', 'right'],
        ];
    }

    /* ------------------------------------------------------------ Nett */

    public function nets(float $tab): array
    {
        [$L, $W, $H] = $this->size();
        $nets = [
            // Kors med bunnen i midten (klassisk), deretter andre flater i midten.
            $this->cross('kors', $L, $W, $H, ['C' => 'bottom', 'N1' => 'front', 'O' => 'top', 'N2' => 'back', 'S1' => 'left', 'S2' => 'right'], $tab),
            $this->cross('kors med forsiden i midten', $L, $H, $W, ['C' => 'front', 'N1' => 'top', 'O' => 'back', 'N2' => 'bottom', 'S1' => 'left', 'S2' => 'right'], $tab),
            $this->cross('kors med siden i midten', $W, $H, $L, ['C' => 'right', 'N1' => 'top', 'O' => 'left', 'N2' => 'bottom', 'S1' => 'front', 'S2' => 'back'], $tab),
            // Fire sideflater på rad, med bunn og topp på den første.
            $this->strip('rekke', [$L, $W, $L, $W], $H, ['front', 'right', 'back', 'left'], ['top', 'bottom'], $tab),
            $this->strip('rekke rundt lengden', [$H, $W, $H, $W], $L, ['front', 'top', 'back', 'bottom'], ['left', 'right'], $tab),
            $this->strip('rekke rundt bredden', [$L, $H, $L, $H], $W, ['bottom', 'right', 'top', 'left'], ['front', 'back'], $tab),
        ];
        return self::withRotations($nets, [90]);
    }

    /**
     * Korsnett: midtflaten C (a × b), N1 over og N2 under (a × c), O over N1
     * (a × b), S1 og S2 på sidene (c × b). Sju limfliker: én for hver kant som
     * skal limes, og aldri to mot hverandre.
     */
    protected function cross(string $variant, float $a, float $b, float $c, array $id, float $tab): SolidNet
    {
        $net = new SolidNet($variant);
        $faces = [
            'C'  => self::rect(0, 0, $a, $b),
            'N1' => self::rect(0, -$c, $a, $c),
            'O'  => self::rect(0, -$c - $b, $a, $b),
            'N2' => self::rect(0, $b, $a, $c),
            'S1' => self::rect(-$c, 0, $c, $b),
            'S2' => self::rect($a, 0, $c, $b),
        ];
        foreach ($faces as $k => $pts) {
            $net->polygonFace($id[$k], $pts, $this->label($id[$k]));
        }
        $in = fn(array $pts) => SolidNet::centroid($pts);
        foreach (['N1', 'N2', 'O'] as $k) {
            $p = $faces[$k];
            $net->tab($p[0], $p[3], $in($p), $tab);       // venstre kant
            $net->tab($p[1], $p[2], $in($p), $tab);       // høyre kant
        }
        $o = $faces['O'];
        $net->tab($o[0], $o[1], $in($o), $tab);           // ytterste kant
        return $net;
    }

    /**
     * Rekkenett: fire flater på rad (bredder $w, felles høyde $h), lokk på
     * begge sider av den første flaten. Sju limfliker.
     */
    protected function strip(string $variant, array $w, float $h, array $ids, array $caps, float $tab): SolidNet
    {
        $net = new SolidNet($variant);
        $x = 0.0;
        $last = null;
        foreach ($w as $i => $wi) {
            $pts = self::rect($x, 0, $wi, $h);
            $net->polygonFace($ids[$i], $pts, $this->label($ids[$i]));
            $x += $wi;
            $last = $pts;
        }
        $net->tab($last[1], $last[2], SolidNet::centroid($last), $tab);
        $d = $w[1];
        foreach ([[$caps[0], -$d], [$caps[1], $h]] as [$id, $y]) {
            $pts = self::rect(0, $y, $w[0], $d);
            $net->polygonFace($id, $pts, $this->label($id));
            $c = SolidNet::centroid($pts);
            $net->tab($pts[0], $pts[3], $c, $tab);
            $net->tab($pts[1], $pts[2], $c, $tab);
            $far = $y < 0 ? [$pts[0], $pts[1]] : [$pts[3], $pts[2]];
            $net->tab($far[0], $far[1], $c, $tab);
        }
        return $net;
    }
}
