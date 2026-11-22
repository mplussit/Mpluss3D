<?php
declare(strict_types=1);

/**
 * Tegner sidene fra MarbleSheets i en PDF (A4, mm).
 *
 * Bare tegneelementene fra MarbleSheets brukes, de samme som
 * forhåndsvisningen i nettleseren tegner (public/js/sheet-svg.js).
 */
final class MarblePdf
{
    private const PT = 25.4 / 72;

    /** @param list<array{items:list<array>}> $pages */
    public function render(array $pages, string $title): string
    {
        $pdf = new LabelPdf('P', 'A4');
        $pdf->SetTitle(LabelPdf::enc($title));
        $pdf->SetCreator('M+ 3D');
        foreach ($pages as $page) {
            $pdf->AddPage('P');
            foreach ($page['items'] as $it) {
                $this->draw($pdf, $it);
            }
        }
        return $pdf->Output('S');
    }

    private function draw(LabelPdf $pdf, array $it): void
    {
        $fill = $it['f'] ?? null;
        $stroke = $it['s'] ?? null;
        if ($fill) {
            $pdf->SetFillHex($fill);
        }
        if ($stroke) {
            $pdf->SetDrawHex($stroke);
            $pdf->SetLineWidth($it['lw'] ?? 0.2);
        }
        $style = $fill && $stroke ? 'FD' : ($fill ? 'F' : 'D');
        $dashed = isset($it['d']);
        if ($dashed) {
            $pdf->SetDash($it['d'][0], $it['d'][1]);
        }
        switch ($it['k']) {
            case 'rect':
                $pdf->RectExact($it['x'], $it['y'], $it['w'], $it['h'], $style);
                break;
            case 'line':
                $pdf->LineExact($it['x1'], $it['y1'], $it['x2'], $it['y2']);
                break;
            case 'circle':
                $pdf->Circle($it['x'], $it['y'], $it['r'], $style);
                break;
            case 'poly':
                if (!empty($it['open'])) {
                    $pts = $it['p'];
                    for ($i = 1; $i < count($pts); $i++) {
                        $pdf->LineExact($pts[$i - 1][0], $pts[$i - 1][1], $pts[$i][0], $pts[$i][1]);
                    }
                } else {
                    $pdf->Polygon($it['p'], $style);
                }
                break;
            case 'text':
                $pdf->SetFont('Helvetica', $it['b'] ? 'B' : '', $it['sz'] / self::PT);
                [$r, $g, $b] = LabelPdf::hexRgb($it['c']);
                $pdf->SetTextColor($r, $g, $b);
                $s = LabelPdf::enc($it['t']);
                $w = $pdf->GetStringWidth($s);
                $x = match ($it['a']) { 'C' => $it['x'] - $w / 2, 'R' => $it['x'] - $w, default => $it['x'] };
                $pdf->Text($x, $it['y'], $s);
                break;
        }
        if ($dashed) {
            $pdf->SetDash();
        }
    }
}
