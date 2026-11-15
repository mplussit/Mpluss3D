<?php
declare(strict_types=1);

/**
 * Strektegning av en romfigur i PDF (parallellprojeksjon sett skrått forfra
 * og ovenfra). Synlige kanter heltrukket, skjulte kanter stiplet. Bruker
 * kantene, flatene og målene fra den felles geometrimodellen.
 */
final class SolidDrawing
{
    private const AZ = -32.0;       // grader rundt z-aksen
    private const EL = 24.0;        // grader over vannrett

    private float $ca;
    private float $sa;
    private float $ce;
    private float $se;

    public function __construct(private Solid $solid)
    {
        $this->ca = cos(deg2rad(self::AZ));
        $this->sa = sin(deg2rad(self::AZ));
        $this->ce = cos(deg2rad(self::EL));
        $this->se = sin(deg2rad(self::EL));
    }

    /** Projeksjon: [x mot høyre, y opp] og dybde mot betrakteren. */
    private function proj(array $p): array
    {
        $x = $p[0] * $this->ca - $p[1] * $this->sa;
        $y = $p[0] * $this->sa + $p[1] * $this->ca;
        return [$x, $p[2] * $this->ce + $y * $this->se];
    }

    /** Er en flate med normal $n vendt mot betrakteren? */
    private function facing(array $n): bool
    {
        $y = $n[0] * $this->sa + $n[1] * $this->ca;
        return -$y * $this->ce + $n[2] * $this->se > 1e-9;
    }

    public function draw(LabelPdf $pdf, float $bx, float $by, float $bw, float $bh, bool $dims): void
    {
        $paths = $this->paths();
        // Tilpass boksen
        $xs = $ys = [];
        foreach ($paths as $pth) {
            foreach ($pth['pts'] as $p) {
                $q = $this->proj($p);
                $xs[] = $q[0];
                $ys[] = $q[1];
            }
        }
        $s = min($bw / (max($xs) - min($xs)), $bh / (max($ys) - min($ys))) * ($dims ? 0.78 : 0.9);
        $cx = (max($xs) + min($xs)) / 2;
        $cy = (max($ys) + min($ys)) / 2;
        $map = function (array $p) use ($s, $cx, $cy, $bx, $by, $bw, $bh) {
            $q = $this->proj($p);
            return [$bx + $bw / 2 + ($q[0] - $cx) * $s, $by + $bh / 2 - ($q[1] - $cy) * $s];
        };

        foreach ([false, true] as $visible) {        // skjulte først, så synlige oppå
            foreach ($paths as $pth) {
                if ($pth['visible'] !== $visible) {
                    continue;
                }
                $pdf->SetDrawColor(...($visible ? [22, 22, 22] : [140, 136, 128]));
                $pdf->SetLineWidth($visible ? 0.45 : 0.25);
                $pdf->SetDash(...($visible ? [0, 0] : [1.4, 1.0]));
                $pts = array_map($map, $pth['pts']);
                for ($i = 0; $i + 1 < count($pts); $i++) {
                    $pdf->LineExact($pts[$i][0], $pts[$i][1], $pts[$i + 1][0], $pts[$i + 1][1]);
                }
            }
        }
        $pdf->SetDash();

        if ($dims) {
            $center = $map([0, 0, $this->solid->size()[2] / 2]);
            foreach ($this->solid->annotations() as [$a, $b, $sym, $style]) {
                $pa = $map($a);
                $pb = $map($b);
                $pdf->SetDrawColor(36, 80, 200);
                $pdf->SetLineWidth(0.5);
                if ($style === 'inner' || $style === 'face') {
                    $pdf->SetDash(1.6, 1.0);
                }
                $pdf->LineExact($pa[0], $pa[1], $pb[0], $pb[1]);
                $pdf->SetDash();
                $mid = [($pa[0] + $pb[0]) / 2, ($pa[1] + $pb[1]) / 2];
                $d = [$mid[0] - $center[0], $mid[1] - $center[1]];
                $len = hypot($d[0], $d[1]);
                $off = $style === 'inner' || $style === 'face' ? [2.2, 0] : ($len > 1e-6 ? [$d[0] / $len * 3.6, $d[1] / $len * 3.6] : [0, 3.6]);
                $pdf->SetFont('Helvetica', 'BI', 10);
                $pdf->SetTextColor(36, 80, 200);
                $pdf->TextCentered($mid[0] + $off[0], $mid[1] + $off[1] + 1.3, $sym);
            }
        }
    }

    /**
     * Linjene som skal tegnes: [pts => 3D-punkter, visible => bool].
     * @return list<array{pts:list<array>,visible:bool}>
     */
    private function paths(): array
    {
        $faces = $this->solid->faces();
        $shape = $this->solid->shape();
        if ($shape === 'cylinder' || $shape === 'cone') {
            return $this->curvedPaths();
        }
        $out = [];
        foreach ($this->solid->edges() as $e) {
            $vis = false;
            foreach ($e['faces'] as $fi) {
                $vis = $vis || $this->facing($faces[$fi]['plane']['n']);
            }
            $out[] = ['pts' => [$e['a'], $e['b']], 'visible' => $vis];
        }
        return $out;
    }

    /** Sylinder og kjegle: sirkler som ellipser, omriss og skjult del av bunnsirkelen. */
    private function curvedPaths(): array
    {
        [$d, , $h] = $this->solid->size();
        $r = $d / 2;
        $cone = $this->solid->shape() === 'cone';
        $n = 144;
        $out = [];
        // Punkt på sirkelen ved vinkel ψ målt i den roterte rammen (ψ = φ + AZ).
        $az = deg2rad(self::AZ);
        $pt = fn(float $psi, float $z) => [$r * cos($psi - $az), $r * sin($psi - $az), $z];
        // Synlig del av bunnsirkelen: flatenormalen der vender mot betrakteren.
        $k = $cone ? $r * $this->se / ($h * $this->ce) : 0.0;
        $visibleAt = fn(float $psi) => sin($psi) < $k;
        $cur = null;
        for ($i = 0; $i <= $n; $i++) {
            $psi = -M_PI / 2 + 2 * M_PI * $i / $n;      // start foran
            $v = $visibleAt($psi);
            if ($cur === null || $cur['visible'] !== $v) {
                if ($cur) {
                    $cur['pts'][] = $pt($psi, 0);
                    $out[] = $cur;
                }
                $cur = ['pts' => [$pt($psi, 0)], 'visible' => $v];
            } else {
                $cur['pts'][] = $pt($psi, 0);
            }
        }
        $out[] = $cur;
        if ($cone) {
            if (abs($k) < 1) {
                foreach ([asin($k), M_PI - asin($k)] as $psi) {
                    $out[] = ['pts' => [[0.0, 0.0, $h], $pt($psi, 0)], 'visible' => true];
                }
            }
        } else {
            $top = [];
            for ($i = 0; $i <= $n; $i++) {
                $top[] = $pt(2 * M_PI * $i / $n, $h);
            }
            $out[] = ['pts' => $top, 'visible' => true];
            foreach ([0.0, M_PI] as $psi) {
                $out[] = ['pts' => [$pt($psi, 0), $pt($psi, $h)], 'visible' => true];
            }
        }
        return $out;
    }
}
