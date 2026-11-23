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
        $this->newDocument('Brøksirkler – ' . FractionModel::fmt($params['diameter']) . ' mm', !empty($params['_fasit']));

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
        $W = $this->contentW();
        $y = $this->header(1, 'Brøksirkler', tab: 'Oversikt', lead: sprintf(
            'Et sett med brøkbrikker til 3D-printing. Alle brikkene har samme diameter (%s mm), '
            . 'så brøker fra ulike sett kan sammenlignes direkte.',
            FractionModel::fmt($this->p['diameter'])
        ));

        // --- Illustrasjon av settene, på én rad over hele bredden -------------
        $n = count($parts);
        $gap = 6.0;
        $d = min(26.0, ($W - ($n - 1) * $gap) / $n * 0.8);
        $cellW = min(44.0, ($W - ($n - 1) * $gap) / $n);
        $x0 = self::M + ($W - ($n * $cellW + ($n - 1) * $gap)) / 2;
        $y += 1;
        foreach ($parts as $i => $part) {
            $den = $part['info']['denominator'];
            $cx = $x0 + $i * ($cellW + $gap) + $cellW / 2;
            $cy = $y + $d / 2;
            $this->drawCircle($cx, $cy, $d / 2, $den, $den, $part['info']['color']);
            $this->drawFraction($cx - $this->fracWidth(1, $den, 10) / 2, $cy + $d / 2 + 5.5, 1, $den, 10, false, $den === 1);
            $pdf->SetFont('Helvetica', '', 8);
            $pdf->SetTextColor(...self::INK2);
            $pdf->TextCentered($cx, $cy + $d / 2 + 13, $this->model->name($den, $den === 1 ? 0 : 1));
        }
        $y += $d + 21;

        [[$xL, $wL], [$xR, $wR]] = $this->twoColumns();
        $top = $y;

        // --- Venstre spalte: hva skal skrives ut -----------------------------
        $pdf->SetFont('Helvetica', 'B', 12);
        $pdf->SetTextColor(...self::INK);
        $pdf->TextUtf8($xL, $y, 'Dette skal skrives ut');
        $y += 4;
        $cols = [['Farge', 20], ['Brøk', 12], ['Brikke', 25], ['STL-fil', 37], ['Antall', 30.5]];
        $rowH = $n > 8 ? 6.0 : 7.0;
        $pdf->SetFillColor(243, 239, 231);
        $pdf->RectExact($xL, $y, $wL, $rowH, 'F');
        $pdf->SetFont('Helvetica', 'B', 8.5);
        $x = $xL;
        foreach ($cols as [$label, $w]) {
            $pdf->TextUtf8($x + 1.5, $y + $rowH / 2 + 1.5, $label);
            $x += $w;
        }
        $y += $rowH;
        $total = 0;
        $pdf->SetDrawColor(217, 210, 196);
        $pdf->SetLineWidth(0.2);
        foreach ($parts as $part) {
            $info = $part['info'];
            $total += $part['count'];
            $base = $y + $rowH / 2 + 1.4;
            $x = $xL;
            $pdf->SetFillHex($info['color'], 0.35);
            $pdf->SetDrawHex($info['color']);
            $pdf->RectExact($x + 1.5, $base - 3.2, 5, 3.6, 'FD');
            $pdf->SetFont('Helvetica', '', 7.5);
            $pdf->SetTextColor(...self::INK2);
            $pdf->TextUtf8($x + 8, $base, $info['color_name']);
            $x += $cols[0][1];
            $pdf->SetFont('Helvetica', 'B', 9.5);
            $pdf->SetTextColor(...self::INK);
            $pdf->TextUtf8($x + 1.5, $base, $info['fraction']);
            $x += $cols[1][1];
            $pdf->SetFont('Helvetica', '', 8.5);
            $pdf->TextUtf8($x + 1.5, $base, $info['name']);
            $x += $cols[2][1];
            $pdf->SetFont('Courier', '', 7.5);
            $pdf->TextUtf8($x + 1.5, $base, $part['filename']);
            $x += $cols[3][1];
            $pdf->SetFont('Helvetica', 'B', 8.5);
            $pdf->TextUtf8($x + 1.5, $base, $part['count'] . ' ' . ($part['count'] === 1 ? 'eksemplar' : 'eksemplarer'));
            $y += $rowH;
            $pdf->SetDrawColor(217, 210, 196);
            $pdf->LineExact($xL, $y, $xL + $wL, $y);
        }
        $pdf->SetFont('Helvetica', 'B', 9.5);
        $pdf->TextUtf8($xL + 1.5, $y + 5, 'Totalt');
        $pdf->TextUtf8($xL + $wL - $cols[4][1] + 1.5, $y + 5, "$total brikker");
        $y += 13;

        // --- Til læreren og innstillingene: høyre spalte ----------------------
        $blocks = [['h', 'Til læreren']];
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
        foreach ($paras as $para) {
            $blocks[] = ['p', $para, 1.4];
        }
        $blocks[] = ['gap', 2.6];
        // Innstillingene står også i bunnteksten, så boksen kan utelates ved plassmangel.
        $blocks[] = ['box', 19.0, function (float $x, float $y, float $w) use ($pdf): void {
            $pdf->SetFillColor(243, 239, 231);
            $pdf->RectExact($x, $y, $w, 19, 'F');
            $pdf->SetFont('Helvetica', 'B', 9);
            $pdf->SetTextColor(...self::INK);
            $pdf->TextUtf8($x + 4, $y + 6, 'Innstillinger');
            $pdf->SetFont('Helvetica', '', 8.5);
            $pdf->TextUtf8($x + 4, $y + 11, sprintf(
                'Diameter %s mm  ·  tykkelse %s mm  ·  klaring %s mm',
                FractionModel::fmt($this->p['diameter']), FractionModel::fmt($this->p['thickness']),
                FractionModel::fmt($this->p['clearance'])
            ));
            $pdf->TextUtf8($x + 4, $y + 15.5, sprintf(
                'Kantavrunding %s mm  ·  merking: %s',
                FractionModel::fmt($this->p['edge_radius']), $this->p['labels'] ? 'nedsenket' : 'ingen'
            ));
        }];
        $this->flowText($blocks, [[$xR, $top, $wR], [$xL, $y, $wL]]);
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
        $W = $this->contentW();
        // Liggende: tre ruter i bredden og litt lavere ruter, så det blir plass til flere.
        $cols = $this->landscape() ? 3 : 2;
        $cellW = $W / $cols;
        $cellH = $this->landscape() ? 47.0 : 52.0;
        $r = $this->landscape() ? 12.0 : 13.0;
        foreach ($eq as $i => $e) {
            $col = $i % $cols;
            $row = intdiv($i, $cols);
            $x0 = self::M + $col * $cellW;
            $y0 = $y + $row * $cellH;
            if ($y0 + $cellH > $this->bottom()) {
                break;
            }
            $pdf->SetDrawColor(217, 210, 196);
            $pdf->SetLineWidth(0.2);
            $pdf->RectExact($x0 + 2, $y0, $cellW - 4, $cellH - 4, 'D');
            $cy = $y0 + 4 + $r + 1;
            $cxL = $x0 + $cellW / 2 - 22;
            $cxR = $x0 + $cellW / 2 + 22;
            $this->drawCircle($cxL, $cy, $r, $e['a'], $e['k'], $this->model->color($e['a'])['hex']);
            // Fasit: like mye av sirkelen til høyre er fargelagt.
            $this->drawCircle($cxR, $cy, $r, $e['b'], $this->fasit ? $e['answer'] : 0, $this->model->color($e['b'])['hex']);
            $pdf->SetFont('Helvetica', 'B', 16);
            $pdf->SetTextColor(...self::INK);
            $pdf->TextCentered($x0 + $cellW / 2, $cy + 2, '=');
            [$tokens] = $this->fillAnswers($e);
            $tw = $this->tokensWidth($tokens, 13);
            $this->drawTokens($tokens, $x0 + $cellW / 2 - $tw / 2, $cy + $r + ($this->landscape() ? 9 : 10), 13);
        }
        $this->footer();
    }

    /* ================================================================ Side 4 */

    private function pageWorksheet(): void
    {
        $pdf = $this->pdf;
        $lead = 'Legg brikkene på sirklene. Skriv brøkuttrykket du har laget på linjene, for eksempel 1/4 + 1/4 = 1/2.';
        $help = 'Linjalen skal være nøyaktig 100 mm. Hvis ikke: skriv ut på nytt med «Faktisk størrelse» / 100 %, ikke «Tilpass til side».';
        $W = $this->contentW();
        $D = (float) $this->p['diameter'];
        $rulerY = $this->bottom() - 4;

        // Sirkler i full størrelse ved siden av hverandre, med skrivelinjer under.
        $cellH = $D + 22;
        if ($cellH <= $rulerY - 12 - 45) {
            $y = $this->header(4, 'Arbeidsflate', $lead);
            $top = $y + 2;
            $avail = $rulerY - 12 - $top;
            $n = max(1, (int) floor(($W + 14) / ($D + 14)));
            $rows = max(1, (int) floor(($avail + 8) / ($cellH + 8)));
            $gapX = $n > 1 ? min(30.0, ($W - $n * $D) / ($n - 1)) : 0;
            $x0 = self::M + ($W - $n * $D - ($n - 1) * $gapX) / 2;
            $gapY = $rows > 1 ? ($avail - $rows * $cellH) / ($rows - 1) : 0;
            for ($r = 0; $r < $rows; $r++) {
                for ($c = 0; $c < $n; $c++) {
                    $cx = $x0 + $c * ($D + $gapX) + $D / 2;
                    $cy = $top + $r * ($cellH + $gapY) + $D / 2;
                    $this->worksheetCircle($cx, $cy, $D);
                    $pdf->SetDrawColor(180, 174, 162);
                    $pdf->SetLineWidth(0.25);
                    foreach ([10, 20] as $dy) {
                        $pdf->LineExact($cx - $D / 2, $cy + $D / 2 + $dy, $cx + $D / 2, $cy + $D / 2 + $dy);
                    }
                }
            }
            $pdf->Ruler100(self::M, $rulerY, 'Kontroller utskriften', $help);
            $this->footer();
            return;
        }

        // Store sirkler: én sirkel helt til venstre (fra like under tittelen),
        // og teksten, skrivelinjene og linjalen i et felt til høyre.
        $this->header(4, 'Arbeidsflate', '');
        $top = 33.0;
        $panelMin = 100.0;
        $scale = min(1.0, ($this->pageH() - 9 - $top) / $D, ($W - $panelMin - 10) / $D);
        $Ds = $D * $scale;
        $this->worksheetCircle(self::M + $Ds / 2, $top + $Ds / 2, $Ds);
        $px = self::M + $Ds + 10;
        $pw = $W - $Ds - 10;
        $y = $this->paragraph($top + 3, $lead, 9.5, 4.6, $px, $pw) + 3;
        if ($scale < 1.0) {
            $y = $this->paragraph($y, sprintf('Merk: Sirkelen er skalert til %d %% fordi diameteren (%s mm) er for stor for A4.',
                (int) floor($scale * 100), FractionModel::fmt($D)), 9, 4.4, $px, $pw) + 3;
        }
        $pdf->SetDrawColor(180, 174, 162);
        $pdf->SetLineWidth(0.25);
        $rulerY = $this->pageH() - 14;
        for ($ly = $y + 12; $ly <= $rulerY - 24; $ly += 12) {
            $pdf->LineExact($px, $ly, $px + $pw, $ly);
        }
        $pdf->Ruler100($px, $rulerY, 'Kontroller utskriften', $help, true);
        $this->footer();
    }

    /** Sirkelen på arbeidsflaten, med et lite kryss i sentrum. */
    private function worksheetCircle(float $cx, float $cy, float $d): void
    {
        $pdf = $this->pdf;
        $pdf->SetDrawColor(...self::INK);
        $pdf->SetLineWidth(0.35);
        $pdf->Circle($cx, $cy, $d / 2, 'D');
        $pdf->SetLineWidth(0.2);
        $pdf->LineExact($cx - 2, $cy, $cx + 2, $cy);
        $pdf->LineExact($cx, $cy - 2, $cx, $cy + 2);
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

    /* Brøk-tokens i oppgavene: ['frac', teller, nevner] og ['fracbox', nevner, svar] (tom teller; svaret er «teller/nevner»). */

    protected function answerSlot(string $type): ?int
    {
        return $type === 'fracbox' ? 2 : parent::answerSlot($type);
    }

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
            $w = $this->drawFraction($x, $yMid, 0, $t[1], $size * 0.85, true);
            if (isset($t[2])) {
                $cap = $this->capH($size * 0.85);
                $this->answerInBox($x + 0.3, $yMid - $cap - 2.4, $w - 0.6, $cap + 1.6, explode('/', (string) $t[2])[0], $size * 0.85);
            }
            return true;
        }
        return false;
    }
}
