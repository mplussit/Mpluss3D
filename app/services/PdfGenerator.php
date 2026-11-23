<?php
declare(strict_types=1);

/**
 * Lager et liggende A4-ark med seks etiketter (én per side) til venstre,
 * monteringsveiledning til høyre og en målestokk på 100 mm nederst.
 *
 * PDF-generatoren er uavhengig av OpenSCAD. Den bruker de samme validerte
 * parameterne og samme modellbeskrivelse (DiceModel::describe()) som
 * STL-genereringen. Senere kan M+ sende inn en tilsvarende modellbeskrivelse.
 *
 * Orientering: hver side har et lokalt system (u = høyre, v = opp) slik siden
 * ser ut utenfra. Dette er nøyaktig samme definisjon som i models/dice/cube.scad.
 * I den utbrettede terningen (korset) står derfor alle etiketter rett opp.
 */
final class PdfGenerator
{
    /** Prikkmønstre i enheter av «spread» – identisk med pip_pattern() i cube.scad. */
    public const PIP_PATTERNS = [
        1 => [[0, 0]],
        2 => [[-1, 1], [1, -1]],
        3 => [[-1, 1], [0, 0], [1, -1]],
        4 => [[-1, -1], [-1, 1], [1, -1], [1, 1]],
        5 => [[-1, -1], [-1, 1], [0, 0], [1, -1], [1, 1]],
        6 => [[-1, -1], [-1, 0], [-1, 1], [1, -1], [1, 0], [1, 1]],
    ];

    /** Etikettrekkefølge på arket (sortert etter innhold 1–6). */
    private const FACE_ORDER = ['top', 'front', 'right', 'left', 'back', 'bottom'];

    /** Posisjon i det utbrettede korset [kolonne, rad]. */
    private const NET = [
        'top' => [1, 0], 'left' => [0, 1], 'front' => [1, 1], 'right' => [2, 1], 'back' => [3, 1], 'bottom' => [1, 2],
    ];

    private array $tpl;

    public function __construct(private DiceModel $model, ?string $template = null)
    {
        $this->tpl = require $template ?? dirname(__DIR__, 2) . '/templates/dice-labels/a4-landscape.php';
    }

    /**
     * @param array<string,mixed> $params validerte parametere fra DiceModel::validate()
     * @return string PDF-innhold
     */
    public function render(array $params): string
    {
        $desc = $this->model->describe($params);
        $t = $this->tpl['text'];
        $m = $this->tpl['margin'];
        $pageW = $this->tpl['page']['width'];
        $pageH = $this->tpl['page']['height'];
        $contentW = $pageW - 2 * $m;
        $L = (float) $params['label_size'];
        $dims = $this->dims($desc);

        $pdf = new LabelPdf($this->tpl['page']['orientation'], $this->tpl['page']['format']);
        $pdf->SetTitle(LabelPdf::enc(str_replace('{dims}', $dims, $t['title'])));
        $pdf->SetCreator('M+ 3D');
        $pdf->AddPage();
        $pdf->SetPageTitle('Dekor');

        // --- Topptekst --------------------------------------------------------
        $pdf->SetTextColor(20, 20, 20);
        $pdf->SetFont('Helvetica', 'B', 16);
        $pdf->TextUtf8($m, $m + 5, str_replace('{dims}', $dims, $t['title']));

        $pdf->SetFillColor(255, 243, 205);
        $pdf->SetDrawColor(230, 180, 60);
        $pdf->SetLineWidth(0.3);
        $pdf->RectExact($m, $m + 9, $contentW, 8, 'FD');
        $pdf->SetFont('Helvetica', 'B', 10);
        $pdf->TextUtf8($m + 3, $m + 14.3, $t['print_warning']);

        $y = $m + 22;
        foreach ($this->model->warnings($params) as $warning) {
            $pdf->SetTextColor(180, 30, 30);
            $pdf->SetFont('Helvetica', '', 8);
            $pdf->TextUtf8($m, $y, $warning);
            $y += 4;
        }

        // --- Etiketter (venstre) ----------------------------------------------
        $pdf->SetTextColor(20, 20, 20);
        $pdf->SetFont('Helvetica', 'B', 11);
        $pdf->TextUtf8($m, $y + 3, $t['labels_heading']);
        $top = $y;
        $y += 6;

        $cols = 3;
        $gapX = 12.0;
        $gridW = $cols * $L + ($cols - 1) * $gapX;
        $x0 = $m;
        $rowPitch = 5 + $L + 11;

        foreach (self::FACE_ORDER as $i => $face) {
            $col = $i % $cols;
            $row = intdiv($i, $cols);
            $lx = $x0 + $col * ($L + $gapX);
            $ly = $y + 5 + $row * $rowPitch;
            $this->drawLabel($pdf, $lx, $ly, $L, $desc['faces'][$face], $params['decor'], true);
            $this->drawUpMarker($pdf, $lx + $L / 2, $ly);

            $pdf->SetTextColor(20, 20, 20);
            $pdf->SetFont('Helvetica', 'B', 9);
            $pdf->TextCentered($lx + $L / 2, $ly + $L + 5, $desc['faces'][$face] . '  ' . $this->tpl['face_names'][$face]);
            $pdf->SetFont('Helvetica', '', 7);
            $pdf->SetTextColor(90, 90, 90);
            $towards = $this->tpl['face_names'][$this->tpl['up_towards'][$face]];
            $pdf->TextCentered($lx + $L / 2, $ly + $L + 8.5, str_replace('{towards}', $towards, $t['caption_up']));
        }

        // --- Monteringsveiledning (høyre spalte) ------------------------------
        $xR = $m + max($gridW, 110.0) + 14;
        $wR = $m + $contentW - $xR;
        $pdf->SetDrawColor(200, 200, 200);
        $pdf->SetLineWidth(0.2);
        $pdf->LineExact($xR - 7, $top, $xR - 7, $top + 6 + 5 + 2 * $rowPitch - 8);
        $y = $top;
        $pdf->SetTextColor(20, 20, 20);
        $pdf->SetFont('Helvetica', 'B', 11);
        $pdf->TextUtf8($xR, $y + 3, $t['assembly_heading']);
        $y += 9;

        $s = min(12.0, ($wR - 36) / 4);              // rutestørrelse i korset
        $this->drawNet($pdf, $xR, $y, $s, $desc['faces'], $params['decor']);
        $pdf->SetFont('Helvetica', '', 7);
        $pdf->SetTextColor(90, 90, 90);
        $pdf->TextCentered($xR + 2 * $s, $y + 3 * $s + 4, $t['net_caption']);

        $isoX = $xR + 4 * $s + 18;
        $this->drawIsoCube($pdf, $isoX, $y + 17, 13, $desc['faces']);
        $pdf->SetFont('Helvetica', '', 7);
        $pdf->SetTextColor(90, 90, 90);
        $pdf->TextCentered($isoX, $y + 3 * $s + 4, $t['iso_caption']);

        $steps = $params['pip_depth'] > 0 ? $t['steps_pips'] : $t['steps_blank_die'];
        if ($params['decor'] === 'blank') {
            $steps[] = $t['step_blank_labels'];
        }
        $pdf->SetTextColor(20, 20, 20);
        $pdf->SetFont('Helvetica', '', 8.5);
        $pdf->SetXY($xR, $y + 3 * $s + 10);
        foreach ($steps as $n => $step) {
            $pdf->SetX($xR);
            $pdf->Cell(5, 4, ($n + 1) . '.');
            $pdf->MultiCell($wR - 5, 4, LabelPdf::enc($step));
            $pdf->Ln(1);
        }

        // --- Målestokk ---------------------------------------------------------
        $this->drawRuler($pdf, $m, $pageH - 18, $t);

        // --- Bunntekst ----------------------------------------------------------
        $pdf->SetFont('Helvetica', '', 6.5);
        $pdf->SetTextColor(130, 130, 130);
        $pdf->TextUtf8($m, $pageH - 7, strtr($t['footer'], [
            '{model}'   => $desc['model'],
            '{version}' => (string) $desc['version'],
            '{dims}'    => $dims,
            '{radius}'  => DiceModel::fmt($desc['corner_radius']),
            '{label}'   => DiceModel::fmt($L) . ' × ' . DiceModel::fmt($L),
            '{date}'    => date('d.m.Y'),
        ]));

        return $pdf->Output('S');
    }

    /** Tegner én etikett med øvre venstre hjørne i (x, y). */
    private function drawLabel(LabelPdf $pdf, float $x, float $y, float $L, string $content, string $decor, bool $cutMarks): void
    {
        // Klippelinje
        $pdf->SetDrawColor(0, 0, 0);
        $pdf->SetLineWidth(0.25);
        $pdf->RectExact($x, $y, $L, $L);

        if ($cutMarks) {
            // Hjørnemerker utenfor etiketten, i tilfelle klippelinjen blir borte i dekoren.
            $pdf->SetLineWidth(0.15);
            $o = 1.0;
            $len = 3.0;
            foreach ([[$x, $y, -1, -1], [$x + $L, $y, 1, -1], [$x, $y + $L, -1, 1], [$x + $L, $y + $L, 1, 1]] as [$cx, $cy, $dx, $dy]) {
                $pdf->LineExact($cx + $dx * $o, $cy, $cx + $dx * ($o + $len), $cy);
                $pdf->LineExact($cx, $cy + $dy * $o, $cx, $cy + $dy * ($o + $len));
            }
        }
        $this->drawContent($pdf, $x + $L / 2, $y + $L / 2, $L, $content, $decor);
    }

    /** Tegner innholdet (prikker, tall eller ingenting) sentrert i (cx, cy). */
    private function drawContent(LabelPdf $pdf, float $cx, float $cy, float $L, string $content, string $decor): void
    {
        $pdf->SetFillColor(25, 25, 25);
        $pdf->SetTextColor(25, 25, 25);
        if ($decor === 'pips' && isset(self::PIP_PATTERNS[(int) $content])) {
            $a = 0.25 * $L;
            $r = 0.08 * $L;
            foreach (self::PIP_PATTERNS[(int) $content] as [$u, $v]) {
                // v peker opp på siden, men ned på arket (y øker nedover).
                $pdf->Circle($cx + $u * $a, $cy - $v * $a, $r, 'F');
            }
        } elseif ($decor === 'numbers' || $decor === 'pips') {
            $capHeight = 0.5 * $L;                                  // mm
            $pt = $capHeight / 0.718 * 72 / 25.4;                   // Helvetica: versalhøyde ≈ 0,718 em
            $pdf->SetFont('Helvetica', 'B', $pt);
            $pdf->TextCentered($cx, $cy + $capHeight / 2, $content);
        }
    }

    /** Liten «OPP»-trekant over etikettens øvre kant. */
    private function drawUpMarker(LabelPdf $pdf, float $cx, float $top): void
    {
        $pdf->SetFillColor(0, 110, 190);
        $pdf->Polygon([[$cx - 1.6, $top - 1.6], [$cx + 1.6, $top - 1.6], [$cx, $top - 4.0]], 'F');
        $pdf->SetFont('Helvetica', 'B', 6);
        $pdf->SetTextColor(0, 110, 190);
        $pdf->TextUtf8($cx + 2.4, $top - 1.8, 'OPP');
    }

    /** Utbrettet terning (kors) med alle sider rett opp. */
    private function drawNet(LabelPdf $pdf, float $x, float $y, float $s, array $faces, string $decor): void
    {
        foreach (self::NET as $face => [$c, $r]) {
            $fx = $x + $c * $s;
            $fy = $y + $r * $s;
            $pdf->SetFillColor(245, 247, 250);
            $pdf->SetDrawColor(60, 60, 60);
            $pdf->SetLineWidth(0.2);
            $pdf->RectExact($fx, $fy, $s, $s, 'FD');
            // Innhold i øvre del, navn nederst
            if ($decor !== 'blank') {
                $this->drawContent($pdf, $fx + $s / 2, $fy + $s * 0.42, $s * 0.68, $faces[$face], $decor);
            }
            $pdf->SetFont('Helvetica', 'B', $decor === 'blank' ? 6 : 4.5);
            $pdf->SetTextColor(0, 110, 190);
            $pdf->TextCentered($fx + $s / 2, $decor === 'blank' ? $fy + $s / 2 + 1 : $fy + $s - 1.2, $this->tpl['face_names'][$face]);
        }
    }

    /** Enkel isometrisk terning sett fra front-høyre-topp, med TOPP, FRONT og HØYRE. */
    private function drawIsoCube(LabelPdf $pdf, float $cx, float $cy, float $e, array $faces): void
    {
        $c30 = cos(M_PI / 6);
        $p = fn(float $x, float $y, float $z) => [$cx + ($x + $y) * $c30 * $e, $cy - ($z + ($y - $x) * 0.5) * $e];
        $h = 0.5;
        $polys = [
            'top'   => [[[-$h, -$h, $h], [$h, -$h, $h], [$h, $h, $h], [-$h, $h, $h]], [252, 252, 252], [0, 0, $h]],
            'front' => [[[-$h, -$h, -$h], [$h, -$h, -$h], [$h, -$h, $h], [-$h, -$h, $h]], [228, 233, 240], [0, -$h, 0]],
            'right' => [[[$h, -$h, -$h], [$h, $h, -$h], [$h, $h, $h], [$h, -$h, $h]], [205, 213, 225], [$h, 0, 0]],
        ];
        $pdf->SetDrawColor(60, 60, 60);
        $pdf->SetLineWidth(0.25);
        foreach ($polys as $face => [$pts, $rgb, $center]) {
            $pdf->SetFillColor(...$rgb);
            $pdf->Polygon(array_map(fn($v) => $p(...$v), $pts), 'FD');
            [$tx, $ty] = $p(...$center);
            $pdf->SetTextColor(20, 20, 20);
            $pdf->SetFont('Helvetica', 'B', 9);
            $pdf->TextCentered($tx, $ty + 0.5, $faces[$face]);
            $pdf->SetFont('Helvetica', '', 4.5);
            $pdf->TextCentered($tx, $ty + 3, $this->tpl['face_names'][$face]);
        }
    }

    /** Linjal på nøyaktig 100 mm med mm-inndeling. */
    private function drawRuler(LabelPdf $pdf, float $x, float $y, array $t): void
    {
        $pdf->SetDrawColor(0, 0, 0);
        $pdf->SetLineWidth(0.2);
        $pdf->LineExact($x, $y, $x + 100, $y);
        for ($mm = 0; $mm <= 100; $mm++) {
            $len = $mm % 10 === 0 ? 4.0 : ($mm % 5 === 0 ? 2.5 : 1.5);
            $pdf->SetLineWidth($mm % 10 === 0 ? 0.25 : 0.12);
            $pdf->LineExact($x + $mm, $y, $x + $mm, $y - $len);
        }
        $pdf->SetFont('Helvetica', '', 6);
        $pdf->SetTextColor(20, 20, 20);
        foreach ([0, 50, 100] as $mm) {
            $pdf->TextCentered($x + $mm, $y + 3, (string) $mm);
        }
        $pdf->SetFont('Helvetica', 'B', 9);
        $pdf->TextUtf8($x + 106, $y - 1.5, $t['ruler_caption']);
        $pdf->SetFont('Helvetica', '', 7);
        $pdf->SetXY($x + 106, $y);
        $pdf->MultiCell(74, 3.2, LabelPdf::enc($t['ruler_help']));
    }

    private function dims(array $desc): string
    {
        $d = $desc['dimensions'];
        return DiceModel::fmt($d['width']) . ' × ' . DiceModel::fmt($d['depth']) . ' × ' . DiceModel::fmt($d['height']) . ' mm';
    }
}
