<?php
declare(strict_types=1);

/**
 * Oppgave-PDF for en romfigur: fem oppgavesider og en fasitside for læreren.
 *
 *   1  Undersøk figuren       (med tegning av figuren)
 *   2  Mål figuren            (tegning med mål og «Opplysninger på arket»)
 *   3  Overflateareal
 *   4  Volum
 *   5  Fra papir til romfigur
 *   6  Fasit
 *
 * Oppgavene lages av SolidTasks fra den felles geometrimodellen. Samme
 * utseende som de andre oppgave-PDF-ene (WorksheetPdf). Tekst med π, √ og ≈
 * skrives med Symbol-fonten (standardfontene mangler disse tegnene).
 */
final class SolidTasksPdf extends WorksheetPdf
{
    private const PAGES = 6;
    private const LEVELS = [1 => 'Nivå 1 – Utforsk', 2 => 'Nivå 2 – Mål og sammenlign', 3 => 'Nivå 3 – Beregn'];

    private array $p;
    private Solid $solid;

    public function __construct(private SolidModel $model)
    {
    }

    public function render(array $p): string
    {
        $this->p = $p;
        $this->solid = $this->model->solid($p);
        $this->newDocument($this->solid->name() . ' – oppgaver');
        $pages = (new SolidTasks($this->solid, $p['level']))->pages();
        foreach ($pages as $i => $page) {
            $this->taskPage($i + 1, $page);
        }
        $this->answerPage($pages);
        return $this->pdf->Output('S');
    }

    protected function kicker(): string
    {
        return 'M+ 3D  ·  ROMFIGURER  ·  ' . mb_strtoupper(self::LEVELS[$this->p['level']]);
    }

    protected function pageCount(): int
    {
        return self::PAGES;
    }

    protected function footerText(): string
    {
        $dims = [];
        foreach ($this->solid->measures() as $k => $m) {
            if (in_array($k, ['side', 'length', 'width', 'height', 'base', 'diameter'], true)) {
                $dims[] = $m['symbol'] . ' = ' . Solid::fmt($m['value']) . ' mm';
            }
        }
        return sprintf('M+ 3D · %s v%d · %s (%s) · %s',
            $this->model->definition()['id'], $this->model->definition()['version'],
            $this->solid->name(), implode(', ', $dims), self::LEVELS[$this->p['level']]);
    }

    /* -------------------------------------------------------------- Sider */

    private function taskPage(int $no, array $page): void
    {
        $y = $this->header($no, $page['title'], $page['lead']);
        if ($no === 1 || $no === 2) {
            $y = $this->figureBand($y, $no === 2);
        }
        $sections = array_map(fn($s) => [
            'title' => $s['title'],
            'intro' => $s['intro'],
            'tasks' => array_map(fn($t) => ['tokens' => $this->richTokens($t['tokens'])], $s['tasks']),
        ], $page['sections']);
        $this->drawSections($sections, $y);
        $this->footer();
    }

    private function answerPage(array $pages): void
    {
        $y = $this->header(self::PAGES, 'Fasit', 'For læreren. Svarene er regnet ut fra målene til figuren og avrundet til én desimal. '
            . 'Målte verdier kan avvike litt.');
        $pdf = $this->pdf;
        $W = $this->contentW();
        foreach ($pages as $i => $page) {
            $rows = [];
            $n = 1;
            foreach ($page['sections'] as $sec) {
                foreach ($sec['tasks'] as $t) {
                    if (!empty($sec['steps'])) {
                        $n++;
                        continue;
                    }
                    $rows[] = [$n++, (string) $t['answer']];
                }
            }
            if (!$rows) {
                continue;
            }
            if ($y + 10 > $this->bottom()) {
                break;
            }
            $pdf->SetFont('Helvetica', 'B', 10.5);
            $pdf->SetTextColor(...self::INK);
            $pdf->TextUtf8(self::M, $y + 4, 'Side ' . ($i + 1) . ': ' . $page['title']);
            $y += 7;
            foreach ($rows as [$k, $answer]) {
                $lines = $this->wrapTokens($this->richTokens([['text', $answer]]), $W - 10, 9.5);
                if ($y + 4.6 * count($lines) > $this->bottom()) {
                    break 2;
                }
                $pdf->SetFont('Helvetica', 'B', 9.5);
                $pdf->SetTextColor(...self::ACCENT);
                $pdf->TextUtf8(self::M, $y + 3.2, $k . '.');
                foreach ($lines as $line) {
                    $this->drawTokens($line, self::M + 8, $y + 2.2, 9.5);
                    $y += 4.6;
                }
            }
            $y += 3;
        }
        $this->footer();
    }

    /**
     * Tegning av figuren til venstre. Side 2: med mål på tegningen og en boks
     * med opplysningene eleven skal sammenligne med.
     */
    private function figureBand(float $y, bool $withDims): float
    {
        $pdf = $this->pdf;
        $h = 52.0;
        $pdf->SetFillColor(...self::SOFT);
        $pdf->RectExact(self::M, $y, $this->contentW(), $h, 'F');
        (new SolidDrawing($this->solid))->draw($pdf, self::M + 4, $y + 4, 78, $h - 8, $withDims);
        $x = self::M + 92;
        $pdf->SetTextColor(...self::INK);
        if ($withDims) {
            $pdf->SetFont('Helvetica', 'B', 10.5);
            $pdf->TextUtf8($x, $y + 8, 'Opplysninger på arket');
            $yy = $y + 15;
            foreach ($this->solid->measures() as $k => $m) {
                if (!in_array($k, ['side', 'length', 'width', 'height', 'base', 'diameter'], true)) {
                    continue;
                }
                $pdf->SetFont('Helvetica', '', 10);
                $pdf->TextUtf8($x, $yy, $m['label']);
                $pdf->SetFont('Helvetica', 'B', 10);
                $pdf->TextUtf8($x + 52, $yy, $m['symbol'] . ' = ' . Solid::fmt($m['value']) . ' mm');
                $yy += 6;
            }
        } else {
            $pdf->SetFont('Helvetica', 'B', 10.5);
            $pdf->TextUtf8($x, $y + 8, 'Slik ser figuren ut');
            $pdf->SetFont('Helvetica', '', 9.5);
            $pdf->SetTextColor(...self::INK2);
            $pdf->SetXY($x - 1, $y + 11);
            $pdf->MultiCell($this->contentW() - 92, 4.6, LabelPdf::enc(
                'Heltrukne linjer er kanter du ser. Stiplede linjer er kanter som er skjult bak figuren.'
                . (in_array($this->solid->shape(), ['cylinder', 'cone'], true) ? ' Sirkelkantene ser ut som ellipser når vi ser dem på skrå.' : '')
            ));
        }
        return $y + $h + 4;
    }

    /* ------------------------------------------------- π, √ og ≈ i oppgaver */

    /** Tekstord med π, √ eller ≈ blir egne «rich»-tokens (skrives med Symbol-fonten). */
    private function richTokens(array $tokens): array
    {
        $out = [];
        foreach ($tokens as $t) {
            if ($t[0] !== 'text' || !preg_match('/[π√≈≤≥]/u', $t[1])) {
                $out[] = $t;
                continue;
            }
            foreach (preg_split('/(?<= )/u', $t[1]) as $word) {
                if ($word === '') {
                    continue;
                }
                $out[] = preg_match('/[π√≈≤≥]/u', $word) ? ['rich', $word] : ['text', $word];
            }
        }
        return $out;
    }

    protected function customTokenWidth(array $t, float $size): ?float
    {
        if ($t[0] !== 'rich') {
            return null;
        }
        $this->pdf->SetFont('Helvetica', '', $size);
        return $this->pdf->RichWidth($t[1]);
    }

    protected function drawCustomToken(array $t, float $x, float $yMid, float $size): bool
    {
        if ($t[0] !== 'rich') {
            return false;
        }
        $this->pdf->SetFont('Helvetica', '', $size);
        $this->pdf->SetTextColor(...self::INK);
        $this->pdf->TextRich($x, $yMid + $this->capH($size) / 2, $t[1]);
        return true;
    }
}
