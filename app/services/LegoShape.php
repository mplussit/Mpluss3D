<?php
declare(strict_types=1);

/**
 * Formen til en Lego-ramme: et rutenett der hver valgt rute er én
 * knottplass (8 × 8 mm).
 *
 * Rutenettet er bare et redigeringsverktøy. Rammen får vegger langs formens
 * grenser, aldri mellom ruter. Formen er gyldig når den
 *   - har minst én rute,
 *   - henger sammen langs sidene (ikke bare i hjørnene), og
 *   - ikke har to ruter som bare møtes i et hjørne (2 × 2-mønster på skrå).
 * Utsparinger (tomme ruter helt omsluttet av formen) er tillatt.
 *
 * Radene leses ovenfra og ned, slik de vises i redigeringsverktøyet.
 */
final class LegoShape
{
    /** @var array<int,array<int,bool>> [rad][kolonne] etter trimming */
    private array $on = [];
    private int $cols = 0;
    private int $rows = 0;

    /** @param list<string> $rows «1» = rute med, «0» = tom, øverste rad først */
    public function __construct(array $rows)
    {
        $cells = [];
        foreach ($rows as $r => $row) {
            foreach (str_split($row) as $c => $ch) {
                if ($ch === '1') {
                    $cells[] = [$c, $r];
                }
            }
        }
        if (!$cells) {
            return;
        }
        // Trim til formens omsluttende rektangel.
        $c0 = min(array_column($cells, 0));
        $r0 = min(array_column($cells, 1));
        $this->cols = max(array_column($cells, 0)) - $c0 + 1;
        $this->rows = max(array_column($cells, 1)) - $r0 + 1;
        foreach ($cells as [$c, $r]) {
            $this->on[$r - $r0][$c - $c0] = true;
        }
    }

    /** En hel rektangulær form, cols × rows. */
    public static function rectangle(int $cols, int $rows): self
    {
        return new self(array_fill(0, $rows, str_repeat('1', $cols)));
    }

    public function has(int $c, int $r): bool
    {
        return $this->on[$r][$c] ?? false;
    }

    public function cols(): int
    {
        return $this->cols;
    }

    public function rows(): int
    {
        return $this->rows;
    }

    /** Antall knottplasser (mengden rammen representerer). */
    public function count(): int
    {
        return array_sum(array_map('count', $this->on));
    }

    /** @return list<array{int,int}> [kolonne, rad] */
    public function cells(): array
    {
        $out = [];
        for ($r = 0; $r < $this->rows; $r++) {
            for ($c = 0; $c < $this->cols; $c++) {
                if ($this->has($c, $r)) {
                    $out[] = [$c, $r];
                }
            }
        }
        return $out;
    }

    public function isRectangle(): bool
    {
        return $this->count() > 0 && $this->count() === $this->cols * $this->rows;
    }

    /**
     * Problemer med formen, som forståelige meldinger. Tom liste = gyldig.
     * @return list<string>
     */
    public function problems(): array
    {
        if ($this->count() === 0) {
            return ['Formen har ingen ruter. Klikk i rutenettet for å velge knottplasser.'];
        }
        $out = [];
        $parts = $this->components(true);
        if (count($parts) > 1) {
            $out[] = sprintf('Formen består av %d deler som ikke henger sammen. Alle rutene må henge sammen langs en side, ikke bare i et hjørne.', count($parts));
        }
        if ($this->diagonalPinches()) {
            $out[] = 'To ruter møtes bare i et hjørne. Da blir veggen der for tynn til å skrives ut. Fyll inn eller fjern en av rutene ved siden av.';
        }
        return $out;
    }

    /**
     * Antall utsparinger: områder med tomme ruter som er helt omsluttet av
     * formen. De blir hull gjennom rammen (også gjennom bunnen).
     */
    public function holes(): int
    {
        $n = 0;
        foreach ($this->components(false) as $comp) {
            $border = false;
            foreach ($comp as [$c, $r]) {
                if ($c === 0 || $r === 0 || $c === $this->cols - 1 || $r === $this->rows - 1) {
                    $border = true;
                    break;
                }
            }
            $n += $border ? 0 : 1;
        }
        return $n;
    }

    /** 2 × 2-ruter der to ruter bare møtes i hjørnet (på skrå). */
    public function diagonalPinches(): int
    {
        $n = 0;
        for ($r = 0; $r + 1 < $this->rows; $r++) {
            for ($c = 0; $c + 1 < $this->cols; $c++) {
                $a = $this->has($c, $r);
                $b = $this->has($c + 1, $r);
                $d = $this->has($c, $r + 1);
                $e = $this->has($c + 1, $r + 1);
                if (($a && $e && !$b && !$d) || ($b && $d && !$a && !$e)) {
                    $n++;
                }
            }
        }
        return $n;
    }

    /**
     * Sammenhengende områder (langs sidene) av valgte ($filled) eller tomme ruter.
     * @return list<list<array{int,int}>>
     */
    private function components(bool $filled): array
    {
        $seen = [];
        $out = [];
        for ($r = 0; $r < $this->rows; $r++) {
            for ($c = 0; $c < $this->cols; $c++) {
                if ($this->has($c, $r) !== $filled || isset($seen["$c,$r"])) {
                    continue;
                }
                $comp = [];
                $stack = [[$c, $r]];
                $seen["$c,$r"] = true;
                while ($stack) {
                    [$x, $y] = array_pop($stack);
                    $comp[] = [$x, $y];
                    foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
                        $nx = $x + $dx;
                        $ny = $y + $dy;
                        if ($nx < 0 || $ny < 0 || $nx >= $this->cols || $ny >= $this->rows) {
                            continue;
                        }
                        if ($this->has($nx, $ny) === $filled && !isset($seen["$nx,$ny"])) {
                            $seen["$nx,$ny"] = true;
                            $stack[] = [$nx, $ny];
                        }
                    }
                }
                $out[] = $comp;
            }
        }
        return $out;
    }

    /**
     * Lengste sammenhengende rekke av ruter langs en side av formen, der
     * merkefeltet festes. Returnerer [fra, til] (indekser langs siden, til
     * er inkludert).
     *   front: nederste rad, back: øverste rad, left: første kolonne, right: siste kolonne
     * @return array{int,int}
     */
    public function longestRun(string $side): array
    {
        $line = match ($side) {
            'front' => array_map(fn($c) => $this->has($c, $this->rows - 1), range(0, $this->cols - 1)),
            'back'  => array_map(fn($c) => $this->has($c, 0), range(0, $this->cols - 1)),
            'left'  => array_map(fn($r) => $this->has(0, $r), range(0, $this->rows - 1)),
            'right' => array_map(fn($r) => $this->has($this->cols - 1, $r), range(0, $this->rows - 1)),
        };
        $best = [0, 0];
        $bestLen = 0;
        $start = null;
        foreach ([...$line, false] as $i => $v) {
            if ($v && $start === null) {
                $start = $i;
            } elseif (!$v && $start !== null) {
                if ($i - $start > $bestLen) {
                    $bestLen = $i - $start;
                    $best = [$start, $i - 1];
                }
                $start = null;
            }
        }
        return $best;
    }

    /** Radene som tekst (trimmet), til JSON og filnavn. @return list<string> */
    public function rowsText(): array
    {
        $out = [];
        for ($r = 0; $r < $this->rows; $r++) {
            $s = '';
            for ($c = 0; $c < $this->cols; $c++) {
                $s .= $this->has($c, $r) ? '1' : '0';
            }
            $out[] = $s;
        }
        return $out;
    }

    /**
     * Arealet av unionen av akseparallelle rektangler [x0, y0, x1, y1]
     * (eksakt, ved koordinatkomprimering).
     * @param list<array{float,float,float,float}> $rects
     */
    public static function unionArea(array $rects): float
    {
        $xs = [];
        $ys = [];
        foreach ($rects as [$x0, $y0, $x1, $y1]) {
            $xs[] = $x0;
            $xs[] = $x1;
            $ys[] = $y0;
            $ys[] = $y1;
        }
        $xs = array_values(array_unique(array_map(fn($v) => round($v, 9), $xs)));
        $ys = array_values(array_unique(array_map(fn($v) => round($v, 9), $ys)));
        sort($xs);
        sort($ys);
        $area = 0.0;
        for ($i = 0; $i + 1 < count($xs); $i++) {
            $mx = ($xs[$i] + $xs[$i + 1]) / 2;
            for ($j = 0; $j + 1 < count($ys); $j++) {
                $my = ($ys[$j] + $ys[$j + 1]) / 2;
                foreach ($rects as [$x0, $y0, $x1, $y1]) {
                    if ($mx > $x0 && $mx < $x1 && $my > $y0 && $my < $y1) {
                        $area += ($xs[$i + 1] - $xs[$i]) * ($ys[$j + 1] - $ys[$j]);
                        break;
                    }
                }
            }
        }
        return $area;
    }
}
