<?php
declare(strict_types=1);

/**
 * Felles grunnlag for oppgave-PDF-ene (brøksirkler, base 10, …).
 *
 * Gir samme utseende på alle modellene: topptekst med modellnavn og
 * sidetall, stor tittel med oransje strek, ingress, bunntekst med
 * innstillingene, og oppgaveseksjoner med nummererte oppgaver.
 *
 * Oppgaver består av «tokens» som brytes over flere linjer ved behov:
 *   ['text', 'Bygg tallet 24.']   tekst (brytes mellom ord)
 *   ['box']                       tom svarrute
 *   ['line']                      svarlinje
 *   ['tag', 'Papiroppgave']       liten merkelapp
 *   ['br']                        linjeskift
 * Underklasser kan legge til egne tokens (f.eks. stablede brøker) ved å
 * overstyre customTokenWidth(), drawCustomToken() og customLineHeight().
 *
 * Alle mål i mm. Alle sidene er liggende A4 (som i M+ matematikk), også
 * lærerveiledningene og sidene i målestokk 1:1. Oppgavene står i to spalter,
 * og lengre tekst kan fordeles i spalter med flowText().
 */
abstract class WorksheetPdf
{
    protected const M = 18.0;                  // marg
    protected const INK = [22, 22, 22];
    protected const INK2 = [93, 90, 85];
    protected const ACCENT = [163, 63, 14];
    protected const RULE = [217, 210, 196];
    protected const SOFT = [243, 239, 231];

    protected LabelPdf $pdf;

    /** Øverst til venstre på hver side, f.eks. «M+ 3D  ·  BRØKSIRKLER». */
    abstract protected function kicker(): string;

    abstract protected function pageCount(): int;

    /** Bunnteksten (innstillingene), samme på alle sider. */
    abstract protected function footerText(): string;

    protected function newDocument(string $title): void
    {
        $this->pdf = new LabelPdf('L', 'A4');
        $this->pdf->SetTitle(LabelPdf::enc($title));
        $this->pdf->SetCreator('M+ 3D');
    }

    protected function pageW(): float
    {
        return round($this->pdf->GetPageWidth(), 1);    // FPDF: A4 = 210,0015 mm
    }

    protected function pageH(): float
    {
        return round($this->pdf->GetPageHeight(), 1);
    }

    protected function contentW(): float
    {
        return $this->pageW() - 2 * self::M;
    }

    /** Innhold må slutte over bunnteksten (189 mm på liggende A4). */
    protected function bottom(): float
    {
        return $this->pageH() - 21.0;
    }

    /** Ny side med topptekst, tittel og ingress. Returnerer y under ingressen. */
    protected function header(int $page, string $title, string $lead, string $orientation = 'L', ?string $tab = null): float
    {
        $pdf = $this->pdf;
        $pdf->AddPage($orientation);
        $pdf->SetPageTitle($tab ?? $title);
        $W = $this->contentW();
        $pdf->SetFont('Helvetica', 'B', 8);
        $pdf->SetTextColor(...self::ACCENT);
        $pdf->TextUtf8(self::M, 13, $this->kicker());
        $pdf->SetFont('Helvetica', '', 8);
        $pdf->SetTextColor(...self::INK2);
        $s = LabelPdf::enc("Side $page av " . $this->pageCount());
        $pdf->Text(self::M + $W - $pdf->GetStringWidth($s), 13, $s);

        $pdf->SetFont('Helvetica', 'B', 22);
        $pdf->SetTextColor(...self::INK);
        $pdf->TextUtf8(self::M, 25, $title);
        $pdf->SetFillColor(243, 109, 33);
        $pdf->RectExact(self::M, 28.5, 14, 1.2, 'F');

        $pdf->SetFont('Helvetica', '', 10.5);
        $pdf->SetTextColor(...self::INK2);
        $pdf->SetXY(self::M - 1, 32);
        $pdf->MultiCell($W + 2, 5, LabelPdf::enc($lead));
        return $pdf->GetY() + 6;
    }

    protected function footer(): void
    {
        $pdf = $this->pdf;
        $pdf->SetFont('Helvetica', '', 6.5);
        $pdf->SetTextColor(130, 130, 130);
        $pdf->TextUtf8(self::M, $this->pageH() - 7, $this->footerText() . ' · ' . date('d.m.Y'));
    }

    /** Overskrift i brødteksten (12 pt). Returnerer y under overskriften. */
    protected function subheading(float $y, string $text): float
    {
        return $this->subheadingAt(self::M, $y, $text);
    }

    /** Overskrift i en spalte som starter i $x. */
    protected function subheadingAt(float $x, float $y, string $text): float
    {
        $this->pdf->SetFont('Helvetica', 'B', 12);
        $this->pdf->SetTextColor(...self::INK);
        $this->pdf->TextUtf8($x, $y, $text);
        return $y + 2.5;
    }

    /** Avsnitt med linjebryting. Returnerer y under avsnittet. */
    protected function paragraph(float $y, string $text, float $size = 9.5, float $lineH = 4.6, ?float $x = null, ?float $w = null): float
    {
        $pdf = $this->pdf;
        $pdf->SetFont('Helvetica', '', $size);
        $pdf->SetTextColor(...self::INK);
        // MultiCell har 1 mm indre marg; flytt boksen 1 mm ut så teksten flukter med margen.
        $pdf->SetXY(($x ?? self::M) - 1, $y);
        $pdf->MultiCell(($w ?? $this->contentW()) + 2, $lineH, LabelPdf::enc($text));
        return $pdf->GetY();
    }

    /** Høyden et avsnitt vil få (uten å tegne det). */
    protected function paragraphHeight(string $text, float $size, float $lineH, float $w): float
    {
        $this->pdf->SetFont('Helvetica', '', $size);
        $lines = 0;
        foreach (explode("\n", $text) as $para) {
            $words = preg_split('/\s+/u', trim($para));
            $line = '';
            $n = 1;
            foreach ($words as $word) {
                $try = $line === '' ? $word : "$line $word";
                if ($line !== '' && $this->pdf->GetStringWidth(LabelPdf::enc($try)) > $w - 2) {
                    $n++;
                    $line = $word;
                } else {
                    $line = $try;
                }
            }
            $lines += $n;
        }
        return $lines * $lineH;
    }

    /* ================================================== Tekst i spalter */

    /**
     * De to spaltene på en liggende side: [[x, bredde], [x, bredde]].
     * $leftW gir venstre spalte en egen bredde (resten går til høyre spalte).
     * @return array{array{float,float},array{float,float}}
     */
    protected function twoColumns(?float $leftW = null): array
    {
        $W = $this->contentW();
        $l = $leftW ?? ($W - self::COLUMN_GAP) / 2;
        return [[self::M, $l], [self::M + $l + self::COLUMN_GAP, $W - $l - self::COLUMN_GAP]];
    }

    /**
     * Fordeler tekstblokker i spalter, ovenfra og ned, og fortsetter i neste
     * spalte når en blokk ikke får plass. En overskrift flyttes alltid sammen
     * med blokken under. Blokker som ikke får plass i siste spalte, utelates.
     *
     *   ['h', tekst]                     overskrift (12 pt)
     *   ['p', tekst, mellomrom etter]    avsnitt (mellomrom 5 mm hvis utelatt)
     *   ['li', tekst]                    kulepunkt
     *   ['gap', mm]                      mellomrom (ikke øverst i en spalte)
     *   ['box', høyde, fn(x, y, w)]      egen tegning med fast høyde (+ 5 mm etter)
     *
     * @param list<array{float,float,float}> $cols [x, y, bredde] for hver spalte
     * @return array{int,float} [spalten og y der teksten sluttet]
     */
    protected function flowText(array $blocks, array $cols, float $size = 9.5, float $lineH = 4.6): array
    {
        $c = 0;
        [$x, $y, $w] = $cols[0];
        $top = true;
        $bottom = $this->bottom();
        $height = function (array $b) use (&$w, $size, $lineH): float {
            return match ($b[0]) {
                'p'   => $this->paragraphHeight($b[1], $size, $lineH, $w),
                'li'  => $this->paragraphHeight($b[1], $size, $lineH, $w - 4),
                'box' => $b[1],
                default => 0.0,
            };
        };
        for ($i = 0; $i < count($blocks); $i++) {
            $b = $blocks[$i];
            if ($b[0] === 'gap') {
                if (!$top) {
                    $y += $b[1];
                }
                continue;
            }
            // Overskriften må ha plass til blokken under.
            $need = $b[0] === 'h'
                ? 2.5 + (isset($blocks[$i + 1]) ? $height($blocks[$i + 1]) : 0)
                : $height($b);
            if ($y + $need > $bottom + 0.01) {
                if (!isset($cols[$c + 1])) {
                    break;
                }
                [$x, $y, $w] = $cols[++$c];
                $top = true;
                $i--;
                continue;
            }
            $pdf = $this->pdf;
            switch ($b[0]) {
                case 'h':
                    $pdf->SetFont('Helvetica', 'B', 12);
                    $pdf->SetTextColor(...self::INK);
                    $pdf->TextUtf8($x, $y, $b[1]);
                    $y += 2.5;
                    break;
                case 'p':
                    $y = $this->paragraph($y, $b[1], $size, $lineH, $x, $w) + ($b[2] ?? 5.0);
                    break;
                case 'li':
                    $pdf->SetFillColor(243, 109, 33);
                    $pdf->RectExact($x + 0.5, $y + 1.6, 1.6, 1.6, 'F');
                    $y = $this->paragraph($y, $b[1], $size, $lineH, $x + 4, $w - 4) + 0.8;
                    break;
                case 'box':
                    ($b[2])($x, $y, $w);
                    $y += $b[1] + 5;
                    break;
            }
            $top = false;
        }
        return [$c, $y];
    }

    /* ================================================== Oppgaveseksjoner */

    /** Liggende side? */
    protected function landscape(): bool
    {
        return $this->pageW() > $this->pageH();
    }

    /** Spalter for oppgavelister: to på liggende sider (alle sidene), én på stående. */
    protected function columns(): int
    {
        return $this->landscape() ? 2 : 1;
    }

    protected const COLUMN_GAP = 12.0;

    /** Bredden på én spalte. */
    protected function columnW(?int $columns = null): float
    {
        $n = $columns ?? $this->columns();
        return ($this->contentW() - ($n - 1) * self::COLUMN_GAP) / $n;
    }

    /**
     * Tegner seksjoner med nummererte oppgaver. Returnerer y etter siste oppgave
     * (den laveste y-en i spaltene som er brukt).
     *
     * På liggende sider flyter oppgavene i to spalter: når en oppgave ikke får
     * plass nederst i venstre spalte, fortsetter den øverst i høyre spalte.
     * Oppgaver som ikke får plass, utelates (antallet bør begrenses av den som lager dem).
     *
     * @param list<array{title:string,intro:?string,tasks:list<array{tokens:list<array>}>}> $sections
     */
    protected function drawSections(array $sections, float $y, int $no = 1, ?int $columns = null): float
    {
        $pdf = $this->pdf;
        $cols = $columns ?? $this->columns();
        $W = $this->columnW($cols);
        $bottom = $this->bottom();
        $top = $y;
        $col = 0;
        $x = self::M;
        $maxY = $y;
        $nextColumn = function () use (&$col, &$x, &$y, &$maxY, $cols, $W, $top): bool {
            if ($col + 1 >= $cols) {
                return false;
            }
            $maxY = max($maxY, $y);
            $col++;
            $x = self::M + $col * ($W + self::COLUMN_GAP);
            $y = $top;
            return true;
        };
        foreach ($sections as $sec) {
            if (!$sec['tasks']) {
                continue;
            }
            // Overskriften tegnes bare hvis minst den første oppgaven får plass under.
            $first = $this->wrapTokens($sec['tasks'][0]['tokens'], $W - 9, 11);
            $headH = 3 + 1.5 + (!empty($sec['intro']) ? 5 : 0) + 3;
            if ($y + $headH + $this->linesHeight($first) > $bottom
                && (!$nextColumn() || $y + $headH + $this->linesHeight($first) > $bottom)) {
                break;
            }
            $y += 3;
            $pdf->SetFont('Helvetica', 'B', 12.5);
            $pdf->SetTextColor(...self::INK);
            $pdf->TextUtf8($x, $y, $sec['title']);
            $y += 1.5;
            if (!empty($sec['intro'])) {
                $pdf->SetFont('Helvetica', '', 9.5);
                $pdf->SetTextColor(...self::INK2);
                $pdf->TextRich($x, $y + 4, $sec['intro']);   // også π, √ og ≈
                $y += 5;
            }
            $y += 3;
            foreach ($sec['tasks'] as $task) {
                $lines = $this->wrapTokens($task['tokens'], $W - 9, 11);
                if ($y + $this->linesHeight($lines) > $bottom) {
                    if (!$nextColumn()) {
                        break 2;
                    }
                }
                $pdf->SetFont('Helvetica', 'B', 11);
                $pdf->SetTextColor(...self::ACCENT);
                $pdf->TextUtf8($x, $y + $this->lineHeight($lines[0]) / 2 + 1.4, $no . '.');
                foreach ($lines as $line) {
                    $lh = $this->lineHeight($line);
                    $this->drawTokens($line, $x + 9, $y + $lh / 2, 11);
                    $y += $lh;
                }
                $no++;
            }
            $y += 2;
        }
        return max($maxY, $y);
    }

    /** Høyden en seksjonsliste vil få, uten å tegne den. */
    protected function sectionsHeight(array $sections): float
    {
        $W = $this->columnW();
        $h = 0.0;
        foreach ($sections as $sec) {
            if (!$sec['tasks']) {
                continue;
            }
            $h += 3 + 1.5 + (!empty($sec['intro']) ? 5 : 0) + 3 + 2;
            foreach ($sec['tasks'] as $task) {
                $h += $this->linesHeight($this->wrapTokens($task['tokens'], $W - 9, 11));
            }
        }
        return $h;
    }

    protected function lineHeight(array $line): float
    {
        $types = array_column($line, 0);
        $custom = $this->customLineHeight($types);
        if ($custom !== null) {
            return $custom;
        }
        return in_array('box', $types, true) ? 9.5 : 7.0;
    }

    protected function linesHeight(array $lines): float
    {
        return array_sum(array_map(fn($l) => $this->lineHeight($l), $lines));
    }

    protected function capH(float $size): float
    {
        return $size * 0.3528 * 0.72;
    }

    /* ---------------------------------------------- Kroker for egne tokens */

    /** @param list<string> $types tokentypene på linja */
    protected function customLineHeight(array $types): ?float
    {
        return null;
    }

    protected function customTokenWidth(array $t, float $size): ?float
    {
        return null;
    }

    /** Returnerer true hvis tokenet ble tegnet. */
    protected function drawCustomToken(array $t, float $x, float $yMid, float $size): bool
    {
        return false;
    }

    /* ----------------------------------------------------------- Tokens */

    protected function tokenWidth(array $t, float $size): float
    {
        $custom = $this->customTokenWidth($t, $size);
        if ($custom !== null) {
            return $custom;
        }
        $pdf = $this->pdf;
        switch ($t[0]) {
            case 'text':
                $pdf->SetFont('Helvetica', '', $size);
                return $pdf->GetStringWidth(LabelPdf::enc($t[1]));
            case 'tag':
                $pdf->SetFont('Helvetica', 'B', 7);
                return $pdf->GetStringWidth(LabelPdf::enc($t[1])) + 6;
            case 'box':
                return 9.0;
            case 'line':
                return 30.0;
            default:
                return 0.0;
        }
    }

    protected function tokensWidth(array $tokens, float $size): float
    {
        $w = 0.0;
        foreach ($tokens as $t) {
            $w += $this->tokenWidth($t, $size);
        }
        return $w;
    }

    /** Bryter tokens i linjer som får plass i $maxW. Tekst brytes mellom ord. */
    protected function wrapTokens(array $tokens, float $maxW, float $size): array
    {
        $atoms = [];
        foreach ($tokens as $t) {
            if ($t[0] === 'text') {
                foreach (preg_split('/(?<= )/u', $t[1]) as $word) {
                    if ($word !== '') {
                        $atoms[] = ['text', $word];
                    }
                }
            } else {
                $atoms[] = $t;
            }
        }
        $lines = [[]];
        $w = 0.0;
        foreach ($atoms as $a) {
            if ($a[0] === 'br') {            // tvunget linjeskift
                $lines[] = [];
                $w = 0.0;
                continue;
            }
            $aw = $this->tokenWidth($a, $size);
            if ($w + $aw > $maxW && $lines[count($lines) - 1]) {
                $lines[] = [];
                $w = 0.0;
                if ($a[0] === 'text') {
                    $a[1] = ltrim($a[1]);
                    $aw = $this->tokenWidth($a, $size);
                }
            }
            $lines[count($lines) - 1][] = $a;
            $w += $aw;
        }
        return $lines;
    }

    protected function drawTokens(array $tokens, float $x, float $yMid, float $size): void
    {
        $pdf = $this->pdf;
        foreach ($tokens as $t) {
            if (!$this->drawCustomToken($t, $x, $yMid, $size)) {
                switch ($t[0]) {
                    case 'text':
                        $pdf->SetFont('Helvetica', '', $size);
                        $pdf->SetTextColor(...self::INK);
                        $pdf->TextUtf8($x, $yMid + $this->capH($size) / 2, $t[1]);
                        break;
                    case 'tag':
                        $w = $this->tokenWidth($t, $size) - 2;
                        $pdf->SetFillColor(255, 243, 205);
                        $pdf->SetDrawColor(230, 180, 60);
                        $pdf->SetLineWidth(0.2);
                        $pdf->RectExact($x, $yMid - 2.4, $w, 4.8, 'FD');
                        $pdf->SetFont('Helvetica', 'B', 7);
                        $pdf->SetTextColor(...self::INK);
                        $pdf->TextUtf8($x + 2, $yMid + 0.9, $t[1]);
                        break;
                    case 'box':
                        $pdf->SetDrawColor(...self::INK);
                        $pdf->SetLineWidth(0.25);
                        $pdf->RectExact($x + 0.5, $yMid - 3.6, 8, 7.2, 'D');
                        break;
                    case 'line':
                        $pdf->SetDrawColor(...self::INK2);
                        $pdf->SetLineWidth(0.25);
                        $pdf->LineExact($x + 1, $yMid + $this->capH($size) / 2 + 0.8, $x + 29, $yMid + $this->capH($size) / 2 + 0.8);
                        break;
                }
            }
            $x += $this->tokenWidth($t, $size);
        }
    }
}
