<?php
declare(strict_types=1);

/**
 * FPDF med noen ekstra tegnefunksjoner (sirkel, polygon, stiplet linje)
 * og UTF-8 → Windows-1252 for norske tegn i standardfontene.
 *
 * Alle koordinater er i millimeter fra øvre venstre hjørne.
 */
final class LabelPdf extends FPDF
{
    public function __construct(string $orientation = 'P', string $format = 'A4')
    {
        parent::__construct($orientation, 'mm', $format);
        $this->SetAutoPageBreak(false);
        $this->SetMargins(0, 0, 0);
        $this->SetCompression(true);
    }

    /** @var array<int,string> Kort navn per side (fanene i forhåndsvisningen). */
    private array $pageTitles = [];
    /** @var list<string> Sidenavnene i den sist lagde PDF-en. */
    private static array $lastPageTitles = [];

    /** Kort navn på gjeldende side, vises som fane i forhåndsvisningen. */
    public function SetPageTitle(string $title): void
    {
        $this->pageTitles[$this->PageNo()] = $title;
    }

    /** Sidenavnene i den sist lagde PDF-en (tom streng når siden ikke har navn). @return list<string> */
    public static function lastPageTitles(): array
    {
        return self::$lastPageTitles;
    }

    public function Output($dest = '', $name = '', $isUTF8 = false)
    {
        $titles = [];
        for ($i = 1; $i <= $this->PageNo(); $i++) {
            $titles[] = $this->pageTitles[$i] ?? '';
        }
        self::$lastPageTitles = $titles;
        return parent::Output($dest, $name, $isUTF8);
    }

    /** Konverterer UTF-8 til tegnsettet som FPDFs standardfonter bruker. */
    public static function enc(string $s): string
    {
        $out = @iconv('UTF-8', 'windows-1252//TRANSLIT', $s);
        return $out === false ? $s : $out;
    }

    public function TextUtf8(float $x, float $y, string $s): void
    {
        $this->Text($x, $y, self::enc($s));
    }

    /** Skriver tekst sentrert om x. */
    public function TextCentered(float $cx, float $y, string $s): void
    {
        $enc = self::enc($s);
        $this->Text($cx - $this->GetStringWidth($enc) / 2, $y, $enc);
    }

    public function Circle(float $x, float $y, float $r, string $style = 'F'): void
    {
        $op = match ($style) { 'F' => 'f', 'FD', 'DF' => 'B', default => 'S' };
        $k = $this->k;
        $h = $this->h;
        $c = 4 / 3 * (M_SQRT2 - 1) * $r;   // Bézier-tilnærming av kvartsirkel
        $p = fn(float $px, float $py) => sprintf('%.3F %.3F', $px * $k, ($h - $py) * $k);
        $this->_out($p($x + $r, $y) . ' m');
        $this->_out($p($x + $r, $y - $c) . ' ' . $p($x + $c, $y - $r) . ' ' . $p($x, $y - $r) . ' c');
        $this->_out($p($x - $c, $y - $r) . ' ' . $p($x - $r, $y - $c) . ' ' . $p($x - $r, $y) . ' c');
        $this->_out($p($x - $r, $y + $c) . ' ' . $p($x - $c, $y + $r) . ' ' . $p($x, $y + $r) . ' c');
        $this->_out($p($x + $c, $y + $r) . ' ' . $p($x + $r, $y + $c) . ' ' . $p($x + $r, $y) . ' c');
        $this->_out($op);
    }

    /** @param list<array{float,float}> $points */
    public function Polygon(array $points, string $style = 'D'): void
    {
        $op = match ($style) { 'F' => 'f', 'FD', 'DF' => 'b', default => 's' };
        $k = $this->k;
        $h = $this->h;
        $s = '';
        foreach ($points as $i => [$x, $y]) {
            $s .= sprintf('%.3F %.3F %s ', $x * $k, ($h - $y) * $k, $i === 0 ? 'm' : 'l');
        }
        $this->_out($s . $op);
    }

    /** Presis rektangel (FPDF bruker bare 2 desimaler i punkter). */
    public function RectExact(float $x, float $y, float $w, float $h, string $style = 'D'): void
    {
        $op = match ($style) { 'F' => 'f', 'FD', 'DF' => 'B', default => 'S' };
        $this->_out(sprintf('%.3F %.3F %.3F %.3F re %s', $x * $this->k, ($this->h - $y) * $this->k, $w * $this->k, -$h * $this->k, $op));
    }

    public function LineExact(float $x1, float $y1, float $x2, float $y2): void
    {
        $this->_out(sprintf('%.3F %.3F m %.3F %.3F l S', $x1 * $this->k, ($this->h - $y1) * $this->k, $x2 * $this->k, ($this->h - $y2) * $this->k));
    }

    public function SetDash(float $on = 0, float $off = 0): void
    {
        $this->_out($on > 0 ? sprintf('[%.3F %.3F] 0 d', $on * $this->k, $off * $this->k) : '[] 0 d');
    }

    /**
     * Sirkelsektor med sentrum (cx, cy). Vinkler i grader, 0° = høyre, 90° = opp
     * på arket, økende mot klokka.
     */
    public function Sector(float $cx, float $cy, float $r, float $a0, float $a1, string $style = 'D'): void
    {
        $steps = max(2, (int) ceil(abs($a1 - $a0) / 3));
        $pts = [[$cx, $cy]];
        for ($i = 0; $i <= $steps; $i++) {
            $a = deg2rad($a0 + ($a1 - $a0) * $i / $steps);
            $pts[] = [$cx + $r * cos($a), $cy - $r * sin($a)];
        }
        $this->Polygon($pts, $style);
    }

    /** Fyllfarge fra #rrggbb, eventuelt lysnet mot hvitt (0 = uendret, 1 = hvit). */
    public function SetFillHex(string $hex, float $tint = 0): void
    {
        [$r, $g, $b] = self::hexRgb($hex, $tint);
        $this->SetFillColor($r, $g, $b);
    }

    public function SetDrawHex(string $hex, float $tint = 0): void
    {
        [$r, $g, $b] = self::hexRgb($hex, $tint);
        $this->SetDrawColor($r, $g, $b);
    }

    /** @return array{int,int,int} */
    public static function hexRgb(string $hex, float $tint = 0): array
    {
        $c = array_map('hexdec', str_split(ltrim($hex, '#'), 2));
        return array_map(fn($v) => (int) round($v + (255 - $v) * $tint), $c);
    }

    /** Linjal på nøyaktig 100 mm med mm-inndeling. (x, y) er venstre ende av grunnlinja. */
    public function Ruler100(float $x, float $y, string $caption, string $help): void
    {
        $this->SetDrawColor(0, 0, 0);
        $this->SetLineWidth(0.2);
        $this->LineExact($x, $y, $x + 100, $y);
        for ($mm = 0; $mm <= 100; $mm++) {
            $len = $mm % 10 === 0 ? 4.0 : ($mm % 5 === 0 ? 2.5 : 1.5);
            $this->SetLineWidth($mm % 10 === 0 ? 0.25 : 0.12);
            $this->LineExact($x + $mm, $y, $x + $mm, $y - $len);
        }
        $this->SetFont('Helvetica', '', 6);
        $this->SetTextColor(20, 20, 20);
        foreach ([0, 50, 100] as $mm) {
            $this->TextCentered($x + $mm, $y + 3, (string) $mm);
        }
        $this->SetFont('Helvetica', 'B', 9);
        $this->TextUtf8($x + 106, $y - 1.5, $caption);
        $this->SetFont('Helvetica', '', 7);
        $this->SetXY($x + 106, $y);
        $this->MultiCell(68, 3.2, self::enc($help));
    }

    /* ------------------------------------------- Stier med buer (utbrettingsnett) */

    /**
     * Tegner en sti av primitiver i mm (se SolidNet): ['L', [x1,y1], [x2,y2]] og
     * ['A', [cx,cy], r, a0, a1]. Med $closed lukkes stien (fylling).
     * Transformasjonen $t = [skala, dx, dy] gjør nettkoordinater om til arkkoordinater.
     */
    public function PathPrims(array $prims, string $style = 'D', array $t = [1.0, 0.0, 0.0], bool $closed = true): void
    {
        [$sc, $dx, $dy] = $t;
        $k = $this->k;
        $h = $this->h;
        $P = fn(float $x, float $y) => sprintf('%.3F %.3F', ($x * $sc + $dx) * $k, ($h - ($y * $sc + $dy)) * $k);
        $out = '';
        $cur = null;
        foreach ($prims as $e) {
            if ($e[0] === 'L') {
                [$a, $b] = [$e[1], $e[2]];
                if ($cur === null || abs($cur[0] - $a[0]) > 1e-6 || abs($cur[1] - $a[1]) > 1e-6) {
                    $out .= $P($a[0], $a[1]) . ' m ';
                }
                $out .= $P($b[0], $b[1]) . ' l ';
                $cur = $b;
            } else {
                [, $c, $r, $a0, $a1] = $e;
                $start = [$c[0] + $r * cos($a0), $c[1] + $r * sin($a0)];
                if ($cur === null || abs($cur[0] - $start[0]) > 1e-6 || abs($cur[1] - $start[1]) > 1e-6) {
                    $out .= $P($start[0], $start[1]) . ' m ';
                }
                $n = max(1, (int) ceil(abs($a1 - $a0) / (M_PI / 2)));
                $step = ($a1 - $a0) / $n;
                $kk = 4 / 3 * tan($step / 4);
                for ($i = 0; $i < $n; $i++) {
                    $s0 = $a0 + $i * $step;
                    $s1 = $s0 + $step;
                    $p0 = [$c[0] + $r * cos($s0), $c[1] + $r * sin($s0)];
                    $p1 = [$c[0] + $r * cos($s1), $c[1] + $r * sin($s1)];
                    $c1 = [$p0[0] - $kk * $r * sin($s0), $p0[1] + $kk * $r * cos($s0)];
                    $c2 = [$p1[0] + $kk * $r * sin($s1), $p1[1] - $kk * $r * cos($s1)];
                    $out .= $P($c1[0], $c1[1]) . ' ' . $P($c2[0], $c2[1]) . ' ' . $P($p1[0], $p1[1]) . ' c ';
                }
                $cur = [$c[0] + $r * cos($a1), $c[1] + $r * sin($a1)];
            }
        }
        $op = match ($style) { 'F' => $closed ? 'h f' : 'f', 'FD', 'DF' => $closed ? 'b' : 'B', default => $closed ? 's' : 'S' };
        $this->_out($out . $op);
    }

    /** Klipper alt som tegnes til rektangelet, til ClipEnd(). */
    public function ClipRect(float $x, float $y, float $w, float $h): void
    {
        $this->_out(sprintf('q %.3F %.3F %.3F %.3F re W n', $x * $this->k, ($this->h - $y) * $this->k, $w * $this->k, -$h * $this->k));
    }

    public function ClipEnd(): void
    {
        $this->_out('Q');
        // Q tilbakestiller fonten i PDF-en; tving FPDF til å sette den på nytt.
        $this->FontFamily = '';
    }

    /* ------------------------------------- Tekst med π, √ og ≈ (Symbol-fonten) */

    private const SYMBOLS = ['π' => 'p', '√' => "\xD6", '≈' => "\xBB", '≤' => "\xA3", '≥' => "\xB3"];

    /** Deler teksten i biter: [font, tekst] der font er null (vanlig) eller 'Symbol'. */
    public static function richSegments(string $s): array
    {
        $parts = preg_split('/(π|√|≈|≤|≥)/u', $s, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        return array_map(fn($p) => isset(self::SYMBOLS[$p]) ? ['Symbol', self::SYMBOLS[$p]] : [null, self::enc($p)], $parts ?: []);
    }

    /** Bredde av rik tekst med gjeldende font og størrelse. */
    public function RichWidth(string $s): float
    {
        $family = $this->FontFamily;
        $style = $this->FontStyle;
        $size = $this->FontSizePt;
        $w = 0.0;
        foreach (self::richSegments($s) as [$font, $txt]) {
            if ($font) {
                $this->SetFont('Symbol', '', $size);
                $w += $this->GetStringWidth($txt);
                $this->SetFont($family, $style, $size);
            } else {
                $w += $this->GetStringWidth($txt);
            }
        }
        return $w;
    }

    /** Skriver rik tekst fra (x, y). $align: L, C eller R. Returnerer bredden. */
    public function TextRich(float $x, float $y, string $s, string $align = 'L'): float
    {
        $family = $this->FontFamily;
        $style = $this->FontStyle;
        $size = $this->FontSizePt;
        $w = $this->RichWidth($s);
        if ($align === 'C') {
            $x -= $w / 2;
        } elseif ($align === 'R') {
            $x -= $w;
        }
        foreach (self::richSegments($s) as [$font, $txt]) {
            if ($font) {
                $this->SetFont('Symbol', '', $size);
                $this->Text($x, $y, $txt);
                $x += $this->GetStringWidth($txt);
                $this->SetFont($family, $style, $size);
            } else {
                $this->Text($x, $y, $txt);
                $x += $this->GetStringWidth($txt);
            }
        }
        return $w;
    }

    /** Tekst (allerede kodet) rotert $deg grader mot klokka, startpunkt (x, y). */
    public function TextRotated(float $x, float $y, string $txt, float $deg): void
    {
        $a = deg2rad($deg);
        $c = cos($a);
        $s = sin($a);
        $op = sprintf('BT %.5F %.5F %.5F %.5F %.3F %.3F Tm (%s) Tj ET', $c, $s, -$s, $c, $x * $this->k, ($this->h - $y) * $this->k, $this->_escape($txt));
        if ($this->ColorFlag) {
            $op = 'q ' . $this->TextColor . ' ' . $op . ' Q';
        }
        $this->_out($op);
    }
}
