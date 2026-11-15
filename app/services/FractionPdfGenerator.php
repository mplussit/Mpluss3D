<?php
declare(strict_types=1);

/**
 * PDF for brøksirklene: fire A4-sider med undervisningsmateriale.
 *
 *   1  Oversikt: brøksettene, antall brikker per STL-fil, råd til læreren
 *   2  Sammenlign brøker (oppgaver laget fra de valgte nevnerne)
 *   3  Likeverdige brøker, eller flere sammenligninger hvis settet ikke har
 *      brøker som kan være like
 *   4  Arbeidsflate med sirkler i full størrelse og 100 mm-linjal
 *
 * Bygger på LabelPdf (FPDF), som terning-PDF-en. Alle mål i mm.
 */
final class FractionPdfGenerator extends WorksheetPdf
{
    private const PAGE_W = 210.0;
    private const PAGE_H = 297.0;
    private const BOTTOM = 276.0;    // innhold må slutte over bunnteksten
    private const PAGES = 4;

    private array $p;

    public function __construct(private FractionModel $model)
    {
    }

    public function render(array $params): string
    {
        $this->p = $params;
        $this->newDocument('Brøksirkler – ' . FractionModel::fmt($params['diameter']) . ' mm');

        $tasks = new FractionTasks($this->model, $params['denominators']);
        $this->pageOverview();
        $this->pageCompare($tasks);
        $this->pageEquivalent($tasks);
        $this->pageWorksheet();
        return $this->pdf->Output('S');
    }

    /* ================================================================ Side 1 */

    private function pageOverview(): void
    {
        $pdf = $this->pdf;
        $parts = $this->model->parts($this->p);
        $W = self::PAGE_W - 2 * self::M;
        $y = $this->header(1, 'Brøksirkler', sprintf(
            'Et sett med brøkbrikker til 3D-printing. Alle brikkene har samme diameter (%s mm), '
            . 'så brøker fra ulike sett kan sammenlignes direkte.',
            FractionModel::fmt($this->p['diameter'])
        ));

        // --- Illustrasjon av settene ---------------------------------------
        $n = count($parts);
        $perRow = min($n, 6);
        $rows = (int) ceil($n / $perRow);
        $gap = 6.0;
        $compact = $n > 6;                  // to rader: mindre sirkler og lavere tabellrader
        $d = min($compact ? 18.0 : 26.0, ($W - ($perRow - 1) * $gap) / $perRow);
        $cellW = ($W - ($perRow - 1) * $gap) / $perRow;
        $y += 2;
        foreach ($parts as $i => $part) {
            $den = $part['info']['denominator'];
            $col = $i % $perRow;
            $row = intdiv($i, $perRow);
            $rowCount = min($perRow, $n - $row * $perRow);
            $x0 = self::M + ($W - ($rowCount * $cellW + ($rowCount - 1) * $gap)) / 2;
            $cx = $x0 + $col * ($cellW + $gap) + $cellW / 2;
            $cy = $y + $row * ($d + 16) + $d / 2;
            $this->drawCircle($cx, $cy, $d / 2, $den, $den, $part['info']['color']);
            $this->drawFraction($cx - $this->fracWidth(1, $den, 10) / 2, $cy + $d / 2 + 5.5, 1, $den, 10, false, $den === 1);
            $pdf->SetFont('Helvetica', '', 8);
            $pdf->SetTextColor(...self::INK2);
            $pdf->TextCentered($cx, $cy + $d / 2 + 13, $this->model->name($den, $den === 1 ? 0 : 1));
        }
        $y += $rows * ($d + 16) + 4;

        // --- Tabell: hva skal skrives ut ------------------------------------
        $pdf->SetFont('Helvetica', 'B', 12);
        $pdf->SetTextColor(...self::INK);
        $pdf->TextUtf8(self::M, $y, 'Dette skal skrives ut');
        $y += 4;
        $cols = [['Farge', 22], ['Brøk', 18], ['Brikke', 34], ['STL-fil', 58], ['Antall', 42]];
        $rowH = $compact ? 6.0 : 7.0;
        $pdf->SetFillColor(243, 239, 231);
        $pdf->RectExact(self::M, $y, $W, $rowH, 'F');
        $pdf->SetFont('Helvetica', 'B', 9);
        $x = self::M;
        foreach ($cols as [$label, $w]) {
            $pdf->TextUtf8($x + 2, $y + 4.8, $label);
            $x += $w;
        }
        $y += $rowH;
        $total = 0;
        $pdf->SetDrawColor(217, 210, 196);
        $pdf->SetLineWidth(0.2);
        foreach ($parts as $part) {
            $info = $part['info'];
            $total += $part['count'];
            $x = self::M;
            $pdf->SetFillHex($info['color'], 0.35);
            $pdf->SetDrawHex($info['color']);
            $pdf->RectExact($x + 2, $y + 1.6, 6, 3.8, 'FD');
            $pdf->SetFont('Helvetica', '', 8);
            $pdf->SetTextColor(...self::INK2);
            $pdf->TextUtf8($x + 9.5, $y + 4.6, $info['color_name']);
            $x += $cols[0][1];
            $pdf->SetFont('Helvetica', 'B', 10);
            $pdf->SetTextColor(...self::INK);
            $pdf->TextUtf8($x + 2, $y + 4.8, $info['fraction']);
            $x += $cols[1][1];
            $pdf->SetFont('Helvetica', '', 9.5);
            $pdf->TextUtf8($x + 2, $y + 4.8, $info['name']);
            $x += $cols[2][1];
            $pdf->SetFont('Courier', '', 9);
            $pdf->TextUtf8($x + 2, $y + 4.8, $part['filename']);
            $x += $cols[3][1];
            $pdf->SetFont('Helvetica', 'B', 10);
            $pdf->TextUtf8($x + 2, $y + 4.8, $part['count'] . ' ' . ($part['count'] === 1 ? 'eksemplar' : 'eksemplarer'));
            $y += $rowH;
            $pdf->SetDrawColor(217, 210, 196);
            $pdf->LineExact(self::M, $y, self::M + $W, $y);
        }
        $pdf->SetFont('Helvetica', 'B', 10);
        $pdf->TextUtf8(self::M + 2, $y + 5, 'Totalt');
        $pdf->TextUtf8(self::M + $W - $cols[4][1] + 2, $y + 5, "$total brikker");
        $y += 13;

        // --- Til læreren ----------------------------------------------------
        $pdf->SetFont('Helvetica', 'B', 12);
        $pdf->SetTextColor(...self::INK);
        $pdf->TextUtf8(self::M, $y, 'Til læreren');
        $y += 2.5;
        $paras = [
            'Hver STL-fil er én brikke. Skriv ut fila så mange ganger som står under «Antall». '
            . 'Bruk gjerne én filamentfarge per brøksett, for eksempel fargene i tabellen. Da blir settene lette å skille fra hverandre.',
            sprintf('Brikkene ligger flatt og trenger ikke støtte. Klaringen på %s mm gir en liten spalte mellom brikkene, '
                . 'så de kan legges sammen uten å presse mot hverandre. Sirkelen beholder likevel diameteren %s mm.',
                FractionModel::fmt($this->p['clearance']), FractionModel::fmt($this->p['diameter'])),
            'Side 2 og 3 har oppgaver som bare bruker brikkene i dette settet. Side 4 er en arbeidsflate med sirkler '
            . 'i full størrelse som brikkene kan legges på.',
        ];
        $paras[] = $this->p['labels']
            ? sprintf('Brikkene har nedsenket brøkmerking (%s mm dyp).', FractionModel::fmt($this->p['label_depth']))
            : 'Brikkene er uten brøkmerking. Elevene kan skrive brøken på brikkene med tusj om de vil.';
        foreach ($this->model->warnings($this->p) as $w) {
            $paras[] = $w;
        }
        $pdf->SetFont('Helvetica', '', $compact ? 9.0 : 9.5);
        $pdf->SetTextColor(...self::INK);
        $lineH = $compact ? 4.3 : 4.6;
        foreach ($paras as $para) {
            $pdf->SetXY(self::M - 1, $y);
            $pdf->MultiCell($W + 2, $lineH, LabelPdf::enc($para));
            $y = $pdf->GetY() + 1.4;
        }

        // --- Innstillinger (står også i bunnteksten, så den kan droppes ved plassmangel)
        $y += 2;
        if ($y + 15 > self::BOTTOM) {
            $this->footer();
            return;
        }
        $pdf->SetFillColor(243, 239, 231);
        $pdf->RectExact(self::M, $y, $W, 15, 'F');
        $pdf->SetFont('Helvetica', 'B', 9);
        $pdf->TextUtf8(self::M + 4, $y + 6, 'Innstillinger');
        $pdf->SetFont('Helvetica', '', 9);
        $pdf->TextUtf8(self::M + 4, $y + 11, sprintf(
            'Diameter %s mm  ·  tykkelse %s mm  ·  klaring %s mm  ·  kantavrunding %s mm  ·  merking: %s',
            FractionModel::fmt($this->p['diameter']), FractionModel::fmt($this->p['thickness']),
            FractionModel::fmt($this->p['clearance']), FractionModel::fmt($this->p['edge_radius']),
            $this->p['labels'] ? 'nedsenket' : 'ingen'
        ));
        $this->footer();
    }

    /* ================================================================ Side 2 */

    private function pageCompare(FractionTasks $tasks): void
    {
        $y = $this->header(2, 'Sammenlign brøker', 'Bruk brøkbrikkene. Skriv svaret på linja eller i ruta.');
        $y = $this->drawSections($tasks->compareSections(), $y);
        $this->footer();
    }

    /* ================================================================ Side 3 */

    private function pageEquivalent(FractionTasks $tasks): void
    {
        $eq = $tasks->equivalences();
        if (!$eq) {
            $y = $this->header(3, 'Flere sammenligninger',
                'Settet har ingen brøker som kan være like store, så denne siden har flere sammenligninger i stedet.');
            $this->drawSections([['title' => 'Sammenlign og finn det som mangler', 'intro' => 'Legg brikkene ved siden av hverandre.',
                'tasks' => $tasks->extraComparisons()]], $y);
            $this->footer();
            return;
        }

        $pdf = $this->pdf;
        $y = $this->header(3, 'Likeverdige brøker',
            'To brøker er likeverdige når de dekker like mye av sirkelen. Legg brikkene oppå hverandre og finn tallet som mangler. '
            . 'Fargelegg like mye av sirkelen til høyre.');
        $W = self::PAGE_W - 2 * self::M;
        $cellW = $W / 2;
        $cellH = 52.0;
        $r = 13.0;
        foreach ($eq as $i => $e) {
            $col = $i % 2;
            $row = intdiv($i, 2);
            $x0 = self::M + $col * $cellW;
            $y0 = $y + $row * $cellH;
            if ($y0 + $cellH > self::BOTTOM) {
                break;
            }
            $pdf->SetDrawColor(217, 210, 196);
            $pdf->SetLineWidth(0.2);
            $pdf->RectExact($x0 + 2, $y0, $cellW - 4, $cellH - 4, 'D');
            $cy = $y0 + 4 + $r + 1;
            $cxL = $x0 + $cellW / 2 - 22;
            $cxR = $x0 + $cellW / 2 + 22;
            $this->drawCircle($cxL, $cy, $r, $e['a'], $e['k'], $this->model->color($e['a'])['hex']);
            $this->drawCircle($cxR, $cy, $r, $e['b'], 0, $this->model->color($e['b'])['hex']);
            $pdf->SetFont('Helvetica', 'B', 16);
            $pdf->SetTextColor(...self::INK);
            $pdf->TextCentered($x0 + $cellW / 2, $cy + 2, '=');
            $tw = $this->tokensWidth($e['tokens'], 13);
            $this->drawTokens($e['tokens'], $x0 + $cellW / 2 - $tw / 2, $cy + $r + 10, 13);
        }
        $this->footer();
    }

    /* ================================================================ Side 4 */

    private function pageWorksheet(): void
    {
        $pdf = $this->pdf;
        $y = $this->header(4, 'Arbeidsflate',
            'Legg brikkene på sirklene. Skriv brøkuttrykket du har laget på linjene, for eksempel 1/4 + 1/4 = 1/2.');
        $W = self::PAGE_W - 2 * self::M;
        $D = (float) $this->p['diameter'];
        $top = $y + 2;
        $bottom = 262.0;                       // over linjalen
        $availH = $bottom - $top;

        $linesW = 64.0;
        $side = $D + 10 + $linesW <= $W;       // linjer ved siden av sirkelen
        $cellH = $side ? $D + 8 : $D + 30;
        $scale = min(1.0, $W / $D, ($availH - ($side ? 0 : 22)) / $D);
        if ($scale < 1.0) {
            $side = false;
            $cellH = $D * $scale + 30;
        }
        $Ds = $D * $scale;
        $rows = max(1, (int) floor(($availH + 8) / $cellH));

        if ($scale < 1.0) {
            $pdf->SetFillColor(255, 243, 205);
            $pdf->SetDrawColor(230, 180, 60);
            $pdf->RectExact(self::M, $top, $W, 9, 'FD');
            $pdf->SetFont('Helvetica', 'B', 9);
            $pdf->SetTextColor(...self::INK);
            $pdf->TextUtf8(self::M + 3, $top + 5.8, sprintf(
                'Merk: Sirklene på denne siden er skalert til %d %% fordi diameteren (%s mm) er for stor for A4.',
                (int) floor($scale * 100), FractionModel::fmt($D)
            ));
            $top += 13;
        }

        for ($i = 0; $i < $rows; $i++) {
            $y0 = $top + $i * $cellH;
            $cx = $side ? self::M + $Ds / 2 : self::M + $W / 2;
            $cy = $y0 + $Ds / 2;
            $pdf->SetDrawColor(...self::INK);
            $pdf->SetLineWidth(0.35);
            $pdf->Circle($cx, $cy, $Ds / 2, 'D');
            $pdf->SetLineWidth(0.2);
            $pdf->LineExact($cx - 2, $cy, $cx + 2, $cy);
            $pdf->LineExact($cx, $cy - 2, $cx, $cy + 2);

            $pdf->SetDrawColor(180, 174, 162);
            $pdf->SetLineWidth(0.25);
            if ($side) {
                $lx = self::M + $Ds + 10;
                $n = max(2, min(5, (int) floor($Ds / 12)));
                $step = $Ds / ($n + 1);
                for ($j = 1; $j <= $n; $j++) {
                    $pdf->LineExact($lx, $y0 + $j * $step + 4, self::M + $W, $y0 + $j * $step + 4);
                }
            } else {
                foreach ([10, 20] as $dy) {
                    $pdf->LineExact(self::M + 10, $cy + $Ds / 2 + $dy, self::M + $W - 10, $cy + $Ds / 2 + $dy);
                }
            }
        }

        $pdf->Ruler100(self::M, 272, 'Kontroller utskriften',
            'Linjalen skal være nøyaktig 100 mm. Hvis ikke: skriv ut på nytt med «Faktisk størrelse» / 100 %, ikke «Tilpass til side».');
        $this->footer();
    }

    /* ======================================================= Felles tegning */

    protected function kicker(): string
    {
        return 'M+ 3D  ·  BRØKSIRKLER';
    }

    protected function pageCount(): int
    {
        return self::PAGES;
    }

    protected function footerText(): string
    {
        return sprintf(
            'M+ 3D · %s v%d · diameter %s mm · tykkelse %s mm · klaring %s mm · brøker: %s',
            $this->model->definition()['id'], $this->model->definition()['version'],
            FractionModel::fmt($this->p['diameter']), FractionModel::fmt($this->p['thickness']),
            FractionModel::fmt($this->p['clearance']),
            implode(', ', array_map(fn($n) => $n === 1 ? '1' : "1/$n", $this->p['denominators']))
        );
    }

    /**
     * Sirkel delt i n like sektorer der de «filled» første er fargelagt.
     * Første sektor står øverst (som brikka med halveringslinja langs +Y).
     */
    private function drawCircle(float $cx, float $cy, float $r, int $n, int $filled, string $hex): void
    {
        $pdf = $this->pdf;
        $pdf->SetLineWidth(0.3);
        $pdf->SetDrawHex($hex, 0);
        if ($n === 1) {
            $pdf->SetFillHex($hex, $filled ? 0.45 : 1.0);
            $pdf->Circle($cx, $cy, $r, 'FD');
            return;
        }
        $step = 360 / $n;
        for ($i = 0; $i < $n; $i++) {
            $a0 = 90 - $step / 2 + $i * $step;
            $pdf->SetFillHex($hex, $i < $filled ? 0.45 : 1.0);
            $pdf->Sector($cx, $cy, $r, $a0, $a0 + $step, 'FD');
        }
    }

    /* ------------------------------------------- Tekst med stablede brøker */

    private function fracWidth(int $num, int $den, float $size): float
    {
        $this->pdf->SetFont('Helvetica', 'B', $size);
        return max($this->pdf->GetStringWidth((string) $num), $this->pdf->GetStringWidth((string) $den)) + 1.6;
    }

    /**
     * Stablet brøk. (x, yMid) = venstre kant og høyden til brøkstreken.
     * $boxNum gir tom rute i telleren. $whole viser bare «1» (hel).
     */
    private function drawFraction(float $x, float $yMid, int $num, int $den, float $size, bool $boxNum = false, bool $whole = false): float
    {
        $pdf = $this->pdf;
        $pdf->SetFont('Helvetica', 'B', $size);
        $pdf->SetTextColor(...self::INK);
        $cap = $this->capH($size);
        if ($whole) {
            $w = $pdf->GetStringWidth('1');
            $pdf->Text($x, $yMid + $cap / 2, '1');
            return $w;
        }
        $w = $boxNum ? max(6.0, $pdf->GetStringWidth((string) $den) + 2.4) : $this->fracWidth($num, $den, $size);
        $pdf->SetDrawColor(...self::INK);
        $pdf->SetLineWidth(0.3);
        $pdf->LineExact($x, $yMid, $x + $w, $yMid);
        if ($boxNum) {
            $pdf->SetLineWidth(0.25);
            $pdf->RectExact($x + 0.3, $yMid - $cap - 2.4, $w - 0.6, $cap + 1.6, 'D');
        } else {
            $s = (string) $num;
            $pdf->Text($x + ($w - $pdf->GetStringWidth($s)) / 2, $yMid - 0.9, $s);
        }
        $s = (string) $den;
        $pdf->Text($x + ($w - $pdf->GetStringWidth($s)) / 2, $yMid + 0.9 + $cap, $s);
        return $w;
    }

    /* Brøk-tokens i oppgavene: ['frac', teller, nevner] og ['fracbox', nevner] (tom teller). */

    protected function customLineHeight(array $types): ?float
    {
        // Stablede brøker trenger mer plass enn ruter og vanlig tekst.
        return array_intersect($types, ['frac', 'fracbox']) ? 11.0 : null;
    }

    protected function customTokenWidth(array $t, float $size): ?float
    {
        return match ($t[0]) {
            'frac'    => $this->fracWidth($t[1], $t[2], $size * 0.85),
            'fracbox' => (function () use ($t, $size) {
                $this->pdf->SetFont('Helvetica', 'B', $size * 0.85);
                return max(6.0, $this->pdf->GetStringWidth((string) $t[1]) + 2.4);
            })(),
            default   => null,
        };
    }

    protected function drawCustomToken(array $t, float $x, float $yMid, float $size): bool
    {
        if ($t[0] === 'frac') {
            $this->drawFraction($x, $yMid, $t[1], $t[2], $size * 0.85);
            return true;
        }
        if ($t[0] === 'fracbox') {
            $this->drawFraction($x, $yMid, 0, $t[1], $size * 0.85, true);
            return true;
        }
        return false;
    }
}
