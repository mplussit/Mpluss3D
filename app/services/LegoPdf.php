<?php
declare(strict_types=1);

/**
 * PDF for «Regn med Lego»: oversikt over rammene og papiretiketter i
 * faktisk størrelse.
 *
 *   Oversikt    regnestykket, og for hver ramme formen, mengden, målene,
 *               merkefeltet, STL-fila og hvilke etiketter som hører til
 *   Etiketter   én etikett per eksemplar, nøyaktig så stor som etikettfeltet
 *               minus 1 mm marg på hver side, med klippemerker, og eventuelt
 *               regnetegn som papirkort. Kontrollinje på 100 mm nederst.
 *
 * Samme utseende som de andre PDF-ene (WorksheetPdf). Mål og innhold kommer
 * fra LegoModel, så etikettene alltid passer til feltene i STL-filene.
 */
final class LegoPdf extends WorksheetPdf
{
    private const GAP = 7.0;         // mm mellom etikettene
    private const CAPTION = 4.5;     // plass til teksten under en etikett

    private array $p;
    private int $pages = 1;

    public function __construct(private LegoModel $model)
    {
    }

    public function render(array $params): string
    {
        $this->p = $params;
        $this->newDocument('Regn med Lego – etiketter og oversikt');
        $items = $this->items();
        $overview = $this->overviewChunks();
        $labelPages = $this->pack($items);
        $this->pages = count($overview) + count($labelPages);

        $page = 1;
        foreach ($overview as $i => $chunk) {
            $this->overviewPage($page++, $chunk, $i === 0);
        }
        foreach ($labelPages as $placed) {
            $this->labelPage($page++, $placed);
        }
        return $this->pdf->Output('S');
    }

    protected function kicker(): string
    {
        return 'M+ 3D  ·  REGN MED LEGO';
    }

    protected function pageCount(): int
    {
        return $this->pages;
    }

    protected function footerText(): string
    {
        $n = count($this->p['frames']);
        return sprintf('M+ 3D · %s v%d · %d %s · LEGO® er et varemerke for LEGO-gruppen, som ikke står bak M+ 3D',
            $this->model->definition()['id'], $this->model->definition()['version'], $n, $n === 1 ? 'ramme' : 'rammer');
    }

    /* ------------------------------------------------------------ Oversikt */

    /** Rammene fordelt på oversiktssider (første side har også regnestykket). */
    private function overviewChunks(): array
    {
        $frames = array_keys($this->p['frames']);
        $first = array_slice($frames, 0, 6);
        $rest = array_chunk(array_slice($frames, 6), 8);
        return [$first, ...$rest];
    }

    private function overviewPage(int $no, array $frames, bool $first): void
    {
        $lead = $first
            ? 'Rammene i prosjektet, med mål og etiketter. Etikettene på de neste sidene er i faktisk størrelse: skriv ut i 100 %.'
            : 'Flere rammer i prosjektet.';
        $y = $this->header($no, $first ? 'Regn med Lego' : 'Rammene (forts.)', $lead);
        $pdf = $this->pdf;
        if ($first) {
            $y = $this->equation($y);
        }
        foreach ($frames as $i) {
            $y = $this->frameRow($y, $i);
        }
        if ($first) {
            $pdf->SetFont('Helvetica', '', 8.5);
            $pdf->SetTextColor(...self::INK2);
            $pdf->SetXY(self::M - 1, max($y + 2, $this->bottom() - 14));
            $pdf->MultiCell($this->contentW() + 2, 4, LabelPdf::enc(
                'Passformen mot klossene er uprøvd til den er testet med ekte klosser. Skriv ut én ramme først, og juster '
                . 'klaringen om klossene sitter for stramt eller for løst. En form kan kreve smale klosser eller 1 × 1-klosser.'
            ));
        }
        $this->footer();
    }

    /** Regnestykket med rammer og regnetegn, f.eks. [8] + [4] = [12]. */
    private function equation(float $y): float
    {
        $pdf = $this->pdf;
        $frames = $this->p['frames'];
        $pdf->SetFont('Helvetica', 'B', 11);
        $pdf->SetTextColor(...self::INK);
        $pdf->TextUtf8(self::M, $y + 3, 'Regnestykket');
        $x = self::M;
        $y += 7;
        foreach ($frames as $i => $f) {
            $n = (string) $this->model->shape($f)->count();
            $pdf->SetFont('Helvetica', 'B', 16);
            $w = max(14.0, $pdf->GetStringWidth($n) + 8);
            if ($x + $w > self::M + $this->contentW()) {
                $x = self::M;
                $y += 16;
            }
            $pdf->SetDrawColor(...self::INK);
            $pdf->SetFillColor(255, 243, 230);
            $pdf->SetLineWidth(0.4);
            $pdf->RectExact($x, $y, $w, 12, 'FD');
            $pdf->TextCentered($x + $w / 2, $y + 8.2, $n);
            $pdf->SetFont('Helvetica', '', 6.5);
            $pdf->SetTextColor(...self::INK2);
            $pdf->TextCentered($x + $w / 2, $y + 15, 'Ramme ' . ($i + 1));
            $pdf->SetTextColor(...self::INK);
            $x += $w + 3;
            if ($f['operator'] !== '' && $i < count($frames) - 1) {
                $pdf->SetFont('Helvetica', 'B', 16);
                $op = self::op($f['operator']);
                $pdf->TextCentered($x + 4, $y + 8.2, $op);
                $x += 11;
            } else {
                $x += 4;
            }
        }
        return $y + 22;
    }

    /** Én rad i oversikten: form, mengde, mål, merkefelt, STL og etiketter. */
    private function frameRow(float $y, int $i): float
    {
        $pdf = $this->pdf;
        $f = $this->p['frames'][$i];
        $g = $this->model->geometry($f);
        $h = 30.0;
        $pdf->SetDrawColor(...self::RULE);
        $pdf->SetLineWidth(0.2);
        $pdf->LineExact(self::M, $y, self::M + $this->contentW(), $y);

        // Formen i liten skala
        $s = min(26 / max($g['cols'], $g['rows']), 4.0);
        $x0 = self::M + 2 + (26 - $g['cols'] * $s) / 2;
        $y0 = $y + 2 + (26 - $g['rows'] * $s) / 2;
        $pdf->SetFillColor(243, 109, 33);
        $pdf->SetDrawColor(255, 255, 255);
        $pdf->SetLineWidth(0.15);
        foreach ($this->model->shape($f)->cells() as [$c, $r]) {
            $pdf->RectExact($x0 + $c * $s, $y0 + $r * $s, $s, $s, 'FD');
        }

        $x = self::M + 34;
        $pdf->SetFont('Helvetica', 'B', 11);
        $pdf->SetTextColor(...self::INK);
        $pdf->TextUtf8($x, $y + 6, sprintf('Ramme %d  ·  plass til %d knotter', $i + 1, $g['count']));
        $pdf->SetFont('Helvetica', '', 8.5);
        $pdf->SetTextColor(...self::INK2);
        $lines = [
            sprintf('Innvendig %s × %s mm%s · utvendig %s × %s mm · høyde %s mm (%s)',
                self::fmt($g['inner'][0]), self::fmt($g['inner'][1]), $g['rectangle'] ? '' : ' (største mål)',
                self::fmt($g['outer'][0]), self::fmt($g['outer'][1]), self::fmt($g['height']),
                $f['bottom'] ? 'bunn ' . self::fmt($g['bottom']) . ' mm' : 'uten bunn'),
            sprintf('Vegg %s mm · klaring %s mm per side%s', self::fmt($g['wall']), self::fmt($g['clearance']),
                $g['holes'] ? ' · ' . $g['holes'] . ($g['holes'] === 1 ? ' utsparing' : ' utsparinger') : ''),
            $this->fieldText($f, $g),
            'STL: ' . $this->model->filename($f, $i),
        ];
        foreach ($lines as $k => $line) {
            $pdf->TextUtf8($x, $y + 11 + $k * 4.3, $line);
        }
        return $y + $h;
    }

    private function fieldText(array $f, array $g): string
    {
        $side = $this->model->definition()['choices']['field_side']['options'][$f['field_side']] ?? '';
        $content = $this->model->content($f);
        return match ($f['field']) {
            'none'  => 'Ingen merkefelt',
            'text'  => sprintf('Merkefelt %s: %s tekst «%s» (%s × %s mm)', mb_strtolower($side),
                $f['text_style'] === 'raised' ? 'opphøyd' : 'innfelt', $content, self::fmt($g['field']['length']), self::fmt($g['field']['depth'])),
            default => sprintf('Etikettfelt %s %s × %s mm · %d %s %s', mb_strtolower($side),
                self::fmt($g['field']['length']), self::fmt($g['field']['depth']), $f['copies'], $f['copies'] === 1 ? 'etikett' : 'etiketter',
                $content === '' ? '(tomme, til håndskrift)' : "«{$content}»"),
        };
    }

    /* ------------------------------------------------------------ Etiketter */

    /**
     * Alt som skal klippes ut: etiketter (én per eksemplar) og regnetegn-kort.
     * @return list<array{w:float,h:float,text:string,caption:string,kind:string}>
     */
    private function items(): array
    {
        $out = [];
        foreach ($this->p['frames'] as $i => $f) {
            if ($f['field'] !== 'label') {
                continue;
            }
            $g = $this->model->geometry($f);
            for ($k = 1; $k <= $f['copies']; $k++) {
                $out[] = [
                    'w' => $g['label']['width'], 'h' => $g['label']['height'],
                    'text' => $this->model->content($f), 'kind' => 'label',
                    'caption' => sprintf('Ramme %d%s', $i + 1, $f['copies'] > 1 ? " · $k/{$f['copies']}" : ''),
                ];
            }
        }
        if ($this->p['operator_cards']) {
            $card = (float) $this->model->definition()['labels']['card'];
            $last = count($this->p['frames']) - 1;
            foreach ($this->p['frames'] as $i => $f) {
                if ($f['operator'] !== '' && $i < $last) {
                    $out[] = ['w' => $card, 'h' => $card, 'text' => $f['operator'], 'kind' => 'card',
                              'caption' => sprintf('Mellom ramme %d og %d', $i + 1, $i + 2)];
                }
            }
        }
        return $out;
    }

    /**
     * Fordeler etiketter og kort i rader på A4-sider.
     * @return list<list<array>> sider med plasserte elementer (x, y lagt til)
     */
    private function pack(array $items): array
    {
        $left = self::M;
        $right = 210 - self::M;
        $top = 52.0;
        $bottom = 297 - 21 - 18;      // over linjalen og bunnteksten
        $pages = [];
        $page = [];
        $x = $left;
        $y = $top;
        $rowH = 0.0;
        foreach ($items as $it) {
            $w = $it['w'];
            $h = $it['h'] + self::CAPTION;
            if ($x + $w > $right && $page) {
                $x = $left;
                $y += $rowH + self::GAP;
                $rowH = 0.0;
            }
            if ($y + $h > $bottom && $page) {
                $pages[] = $page;
                $page = [];
                $x = $left;
                $y = $top;
                $rowH = 0.0;
            }
            $page[] = $it + ['x' => $x, 'y' => $y];
            $x += $w + self::GAP;
            $rowH = max($rowH, $h);
        }
        if ($page) {
            $pages[] = $page;
        }
        return $pages;
    }

    private function labelPage(int $no, array $placed): void
    {
        $this->header($no, 'Etiketter', 'Klipp langs linjene og lim eller tap etiketten på det flate etikettfeltet på rammen.');
        $pdf = $this->pdf;
        $pdf->SetFillColor(255, 243, 205);
        $pdf->SetDrawColor(230, 180, 60);
        $pdf->SetLineWidth(0.3);
        $pdf->RectExact(self::M, 41, $this->contentW(), 7, 'FD');
        $pdf->SetFont('Helvetica', 'B', 9);
        $pdf->SetTextColor(...self::INK);
        $pdf->TextUtf8(self::M + 2.5, 45.7, 'Skriv ut i 100 % («Faktisk størrelse»), ikke «Tilpass til siden». Kontroller linjalen nederst.');

        foreach ($placed as $it) {
            $this->drawItem($it);
        }
        $pdf->Ruler100(self::M, $this->bottom() - 4, 'Kontrollinje 100 mm',
            'Mål linjalen etter utskrift. Er den ikke 100 mm, passer ikke etikettene på rammene.');
        $this->footer();
    }

    private function drawItem(array $it): void
    {
        $pdf = $this->pdf;
        ['x' => $x, 'y' => $y, 'w' => $w, 'h' => $h] = $it;
        if ($it['kind'] === 'card') {
            $pdf->SetFillColor(255, 243, 230);
            $pdf->SetDrawColor(...self::INK);
            $pdf->SetLineWidth(0.3);
            $pdf->RectExact($x, $y, $w, $h, 'FD');
        } else {
            $pdf->SetDrawColor(...self::INK);
            $pdf->SetLineWidth(0.25);
            $pdf->RectExact($x, $y, $w, $h);
        }
        // Klippemerker utenfor hjørnene
        $pdf->SetLineWidth(0.15);
        foreach ([[$x, $y, -1, -1], [$x + $w, $y, 1, -1], [$x, $y + $h, -1, 1], [$x + $w, $y + $h, 1, 1]] as [$cx, $cy, $dx, $dy]) {
            $pdf->LineExact($cx + $dx * 0.8, $cy, $cx + $dx * 2.8, $cy);
            $pdf->LineExact($cx, $cy + $dy * 0.8, $cx, $cy + $dy * 2.8);
        }
        $text = self::op($it['text']);
        if ($text !== '') {
            // Største skrift som får plass: versalhøyde ≤ 65 % av høyden, bredde ≤ bredden − 3 mm.
            $pt = min($h * 0.65 / (0.3528 * 0.72), 60);
            $pdf->SetFont('Helvetica', 'B', $pt);
            $tw = $pdf->GetStringWidth(LabelPdf::enc($text));
            if ($tw > $w - 3) {
                $pt *= ($w - 3) / $tw;
                $pdf->SetFont('Helvetica', 'B', $pt);
            }
            $pdf->SetTextColor(...self::INK);
            $pdf->TextCentered($x + $w / 2, $y + $h / 2 + $pt * 0.3528 * 0.72 / 2, $text);
        }
        $pdf->SetFont('Helvetica', '', 6.5);
        $pdf->SetTextColor(...self::INK2);
        $pdf->TextUtf8($x, $y + $h + 3.6, $it['caption'] . ($it['kind'] === 'label' ? sprintf(' · %s × %s mm', self::fmt($w), self::fmt($h)) : ''));
    }

    /* ------------------------------------------------------------ Hjelpere */

    /** Minustegnet finnes ikke i standardfontene; tankestrek ser likt ut. */
    private static function op(string $s): string
    {
        return str_replace('−', '–', $s);
    }

    private static function fmt(float|int $v): string
    {
        return ParameterValidator::fmt(round((float) $v, 2));
    }
}
