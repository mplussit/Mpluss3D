<?php
declare(strict_types=1);

/**
 * PDF for base 10-materiellet: seks A4-sider.
 *
 *   1  Oversikt til læreren: objektene, mål, antall, plassverdisystemet
 *   2  Bygg tall (med klossene, og fra tallord)
 *   3  Hvilket tall? (tegninger av klosser)
 *   4  Veksling
 *   5  Addisjon og subtraksjon
 *   6  Plassverditabell (liggende A4)
 *
 * Oppgavene lages av Base10Tasks og passer alltid til klossene i settet.
 * Oppgaver som ikke kan løses med klossene, er merket PAPIROPPGAVE.
 * Samme utseende som brøksirkel-PDF-en (WorksheetPdf).
 */
final class Base10PdfGenerator extends WorksheetPdf
{
    private const PAGES = 6;
    private const OBLIQUE = 0.5;     // dybdeforkorting i skråprojeksjonen

    private array $p;
    private Base10Tasks $tasks;
    /** @var list<int> plassverdiene i settet, størst først */
    private array $places;

    public function __construct(private Base10Model $model)
    {
    }

    public function render(array $params): string
    {
        $this->p = $params;
        $this->tasks = new Base10Tasks($this->model->countsByValue($params));
        $this->places = array_values(array_filter(Base10Tasks::PLACES, fn($v) => $this->tasks->has($v)));
        $this->newDocument('Base 10 – ' . Base10Model::fmt($params['unit']) . ' mm', !empty($params['_fasit']));

        $this->pageOverview();
        $this->pageBuild();
        $this->pageRead();
        $this->pageExchange();
        $this->pageArithmetic();
        $this->pageTable();
        return $this->pdf->Output('S');
    }

    protected function kicker(): string
    {
        return 'M+ 3D  ·  BASE 10';
    }

    protected function pageCount(): int
    {
        return self::PAGES;
    }

    protected function footerText(): string
    {
        $p = $this->p;
        $set = [];
        foreach ($p['types'] as $t) {
            $set[] = $this->model->countName($t, $p['counts'][$t]);
        }
        return sprintf(
            'M+ 3D · %s v%d · enhet %s mm · avrunding %s mm · rutenett %s%s · sett: %s',
            $this->model->definition()['id'], $this->model->definition()['version'],
            Base10Model::fmt($p['unit']), Base10Model::fmt($p['edge_radius']),
            $p['grid'] ? Base10Model::fmt($p['groove_depth']) . ' × ' . Base10Model::fmt($p['groove_width']) . ' mm' : 'av',
            in_array('tusener', $p['types'], true) ? ' · tusener ' . ($p['hollow'] ? 'hul ' . Base10Model::fmt($p['wall']) . ' mm' : 'massiv') : '',
            implode(', ', $set)
        );
    }

    /* ================================================================ Side 1 */

    private function pageOverview(): void
    {
        $pdf = $this->pdf;
        $p = $this->p;
        $parts = $this->model->parts($p);
        $y = $this->header(1, 'Base 10 – plassverdimateriell', tab: 'Oversikt', lead: sprintf(
            'Klosser til 3D-printing som viser titallsystemet. Alle klossene er bygd av samme enhet (%s mm), '
            . 'så ti enere er like lange som en tier, ti tiere like store som en hundrer, og ti hundrere like store som en tusener.',
            Base10Model::fmt($p['unit'])
        ));
        [[$xL, $W], [$xR, $wR]] = $this->twoColumns();
        $top = $y;

        // --- Venstre spalte: alle valgte klosser i riktig innbyrdes størrelse --
        $layout = function (float $s) use ($parts): array {
            $items = [];
            $totalW = 0.0;
            foreach (array_reverse($parts) as $part) {
                [$w, $h] = $this->blockSize($part['info']['type'], $s);
                $items[] = [$part, $w, $h];
                $totalW += max($w, 26);
            }
            return [$items, $totalW];
        };
        $s = 3.0;
        [$items, $totalW] = $layout($s);
        while ($s > 1.5 && ($totalW + 6 * (count($items) - 1) > $W || max(array_column($items, 2)) > 48)) {
            $s -= 0.25;
            [$items, $totalW] = $layout($s);
        }
        $gap = count($items) > 1 ? max(0.0, min(14.0, ($W - $totalW) / (count($items) - 1))) : 0;
        $maxH = max(array_column($items, 2));
        $x = $xL + ($W - $totalW - $gap * (count($items) - 1)) / 2;
        $base = $y + 2 + $maxH;
        foreach ($items as [$part, $w, $h]) {
            $i = $part['info'];
            $cell = max($w, 26);
            $this->drawBlock($x + ($cell - $w) / 2, $base, $i['type'], $s);
            $cx = $x + $cell / 2;
            $pdf->SetFont('Helvetica', 'B', 9.5);
            $pdf->SetTextColor(...self::INK);
            $pdf->TextCentered($cx, $base + 6, ucfirst($i['name']) . '  ·  ' . $i['value']);
            $pdf->SetFont('Helvetica', '', 7.5);
            $pdf->SetTextColor(...self::INK2);
            $pdf->TextCentered($cx, $base + 10, implode(' × ', array_map([Base10Model::class, 'fmt'], $i['size_mm'])) . ' mm');
            $x += $cell + $gap;
        }
        $y = $base + 17;

        // --- Tabell: hva skal skrives ut (fargen som en rute foran navnet) ----
        $y = $this->subheadingAt($xL, $y, 'Dette skal skrives ut') + 1.5;
        $cols = [['Objekt', 31], ['Verdi', 11], ['Mål (mm)', 24], ['STL-fil', 31], ['Antall', 15], ['PLA', 12.5]];
        $rowH = 6.5;
        $pdf->SetFillColor(...self::SOFT);
        $pdf->RectExact($xL, $y, $W, $rowH, 'F');
        $pdf->SetFont('Helvetica', 'B', 8.5);
        $pdf->SetTextColor(...self::INK);
        $x = $xL;
        foreach ($cols as [$label, $w]) {
            $pdf->TextUtf8($x + 1.5, $y + 4.5, $label);
            $x += $w;
        }
        $y += $rowH;
        $pieces = 0;
        $grams = 0.0;
        $pdf->SetLineWidth(0.2);
        foreach ($parts as $part) {
            $i = $part['info'];
            $pieces += $part['count'];
            $g = $part['count'] * $i['material']['print_g'];
            $grams += $g;
            $x = $xL;
            $pdf->SetFillHex($i['color'], 0.35);
            $pdf->SetDrawHex($i['color']);
            $pdf->RectExact($x + 1.5, $y + 1.4, 5, 3.6, 'FD');
            $pdf->SetFont('Helvetica', 'B', 8.5);
            $pdf->SetTextColor(...self::INK);
            $pdf->TextUtf8($x + 8, $y + 4.4, ucfirst($i['name']) . ($i['hollow'] ? ' (hul)' : ''));
            $x += $cols[0][1];
            $pdf->SetFont('Helvetica', '', 8.5);
            $pdf->TextUtf8($x + 1.5, $y + 4.4, (string) $i['value']);
            $x += $cols[1][1];
            $pdf->TextUtf8($x + 1.5, $y + 4.4, implode(' × ', array_map([Base10Model::class, 'fmt'], $i['size_mm'])));
            $x += $cols[2][1];
            $pdf->SetFont('Courier', '', 7.5);
            $pdf->TextUtf8($x + 1.5, $y + 4.4, $part['filename']);
            $x += $cols[3][1];
            $pdf->SetFont('Helvetica', 'B', 8.5);
            $pdf->TextUtf8($x + 1.5, $y + 4.4, $part['count'] . ' stk');
            $x += $cols[4][1];
            $pdf->SetFont('Helvetica', '', 8.5);
            $pdf->TextUtf8($x + 1.5, $y + 4.4, Base10Model::grams($g));
            $y += $rowH;
            $pdf->SetDrawColor(...self::RULE);
            $pdf->LineExact($xL, $y, $xL + $W, $y);
        }
        $pdf->SetFont('Helvetica', 'B', 9);
        $pdf->SetTextColor(...self::INK);
        $pdf->TextUtf8($xL + 1.5, $y + 4.6, 'Totalt');
        $xCount = $xL + array_sum(array_column(array_slice($cols, 0, 4), 1));
        $pdf->TextUtf8($xCount + 1.5, $y + 4.6, "$pieces stk");
        $pdf->TextUtf8($xCount + $cols[4][1] + 1.5, $y + 4.6, Base10Model::grams($grams));
        $y += 7.5;
        $pdf->SetFont('Helvetica', '', 8);
        $pdf->SetTextColor(...self::INK2);
        $pdf->SetXY($xL - 1, $y);
        $pdf->MultiCell($W + 2, 3.8, LabelPdf::enc(sprintf(
            'Filament er et overslag for PLA med 2 vegger og 15 %% fyll. Med settet kan elevene bygge alle tall opp til %d.',
            $this->tasks->largestCanonical()
        )));
        $y = $pdf->GetY() + 6;

        // --- Teksten: høyre spalte (og under tabellen hvis det trengs) ------
        $print = 'Klossene ligger slik de skal skrives ut og trenger ikke støtte. Kantene mot byggeplaten har en liten fas i stedet for avrunding, '
            . 'så det ikke blir overheng. Anbefalt: PLA, 0,2 mm laghøyde, 2–3 vegger, 15 % fyll.';
        if (in_array('tusener', $p['types'], true)) {
            $print .= $p['hollow']
                ? ' Den hule tusenerkuben skrives ut med den åpne siden opp (som en kopp), så den ikke trenger støtte eller lange broer. Snu den etter utskrift.'
                : ' Den massive tusenerkuben bør skrives ut med lite fyll (10–15 %).';
        }
        $chain = function (float $x, float $y, float $w) use ($pdf): void {
            $pdf->SetFont('Helvetica', 'B', 8.5);
            $pdf->SetTextColor(...self::INK);
            $cw = $w / 3;
            foreach (['10 enere = 1 tier', '10 tiere = 1 hundrer', '10 hundrere = 1 tusener'] as $k => $txt) {
                $pdf->SetFillColor(...self::SOFT);
                $pdf->RectExact($x + $k * $cw + ($k ? 1.5 : 0), $y, $cw - 1.5, 7, 'F');
                $pdf->TextCentered($x + $k * $cw + $cw / 2 + ($k ? 0.75 : -0.75), $y + 4.7, $txt);
            }
        };
        $this->flowText([
            ['h', 'Plassverdisystemet'],
            ['p', 'Verdien til et siffer avhenger av plassen det står på. I tallet 243 betyr 2 to hundrere, '
                . '4 fire tiere og 3 tre enere: 243 = 200 + 40 + 3. Når vi har ti av en type, kan vi bytte dem mot én av '
                . 'neste type. Det er dette klossene gjør synlig:', 1.5],
            ['box', 7.0, $chain],
            ['h', 'Slik kan materiellet brukes'],
            ['li', 'Tell og grupper: Legg enere i grupper på ti og bytt hver gruppe mot en tier.'],
            ['li', 'Bygg tall: Eleven bygger et tall med færrest mulig klosser og skriver hvor mange av hver type (side 2 og 3).'],
            ['li', 'Veksle: Samme tall kan legges på flere måter, for eksempel 120 som 1 hundrer og 2 tiere eller som 12 tiere (side 4).'],
            ['li', 'Regn: Legg begge tallene, slå sammen eller ta bort, og veksle når det trengs (side 5).'],
            ['li', 'Plassverditabell: Legg klossene i riktig kolonne og skriv sifrene under (side 6).'],
            ['gap', 4.2],
            ['h', 'Utskrift'],
            ['p', $print],
        ], [[$xR, $top, $wR], [$xL, $y, $W]]);
        $this->footer();
    }

    /* ================================================================ Side 2 */

    private function pageBuild(): void
    {
        $y = $this->header(2, 'Bygg tall', 'Bygg tallet med klossene. Bruk færrest mulig klosser. Skriv hvor mange du brukte av hver type.');
        $numbers = $this->tasks->buildNumbers(8);
        $build = [];
        foreach ($numbers as $n) {
            $build[] = ['tokens' => [['text', 'Bygg tallet '], ['strong', (string) $n], ['text', '.'], ['br'], ['pv', '', $this->places]],
                'answers' => [Base10Tasks::digits($n)]];
        }
        $words = [];
        foreach ($this->tasks->wordNumbers(4, $numbers) as $n) {
            $words[] = ['tokens' => [['text', 'Bygg: '], ['strong', Base10Tasks::words($n)], ['text', '.   Tallet er '], ['line']],
                'answer' => (string) $n];
        }
        $this->drawSections([
            ['title' => 'Bygg tallet', 'intro' => null, 'tasks' => $build],
            ['title' => 'Tallet er skrevet med ord', 'intro' => 'Bygg tallet med klossene, og skriv det med sifre.', 'tasks' => $words],
        ], $y);
        $this->footer();
    }

    /* ================================================================ Side 3 */

    private function pageRead(): void
    {
        $pdf = $this->pdf;
        $y = $this->header(3, 'Hvilket tall?', 'Se på klossene. Skriv tallet de viser. Bygg gjerne det samme med dine egne klosser.');
        $W = $this->contentW();
        $answerW = 44.0;
        $drawW = $W - 9 - $answerW - 4;
        $no = 1;
        foreach ($this->tasks->readTasks(7) as $task) {
            $rep = $task['rep'];
            $s = 1.8;
            $natural = $this->groupWidth($rep, $s);
            if ($natural > $drawW) {
                $s *= $drawW / $natural;
            }
            $h = $this->groupHeight($rep, $s);
            $rowH = max(16.0, $h + 6) + ($task['challenge'] ? 5 : 0);
            if ($y + $rowH > $this->bottom()) {
                break;
            }
            $top = $y;
            if ($task['challenge']) {
                $this->drawTokens([['tag', 'UTFORDRING']], self::M + 9, $y + 2.4, 11);
                $pdf->SetFont('Helvetica', '', 8.5);
                $pdf->SetTextColor(...self::INK2);
                $pdf->TextUtf8(self::M + 36, $y + 3.3, 'Her er det flere enn ni av én type. Veksle først, eller tell.');
                $top += 5;
            }
            $base = $top + 3 + $h;
            $pdf->SetFont('Helvetica', 'B', 11);
            $pdf->SetTextColor(...self::ACCENT);
            $pdf->TextUtf8(self::M, $base - $h / 2 + 1.4, $no . '.');
            $this->drawGroup(self::M + 9, $base, $rep, $s);
            // svarlinje
            $pdf->SetFont('Helvetica', '', 11);
            $pdf->SetTextColor(...self::INK);
            $ax = self::M + $W - $answerW;
            $pdf->TextUtf8($ax, $base - $h / 2 + 1.4, 'Tallet:');
            $pdf->SetDrawColor(...self::INK2);
            $pdf->SetLineWidth(0.25);
            $pdf->LineExact($ax + 14, $base - $h / 2 + 2.2, $ax + $answerW, $base - $h / 2 + 2.2);
            $this->answerOnLine($ax + 15, $base - $h / 2 + 1.4, $answerW - 15, (string) $task['answer'], 11);
            $y += $rowH;
            $pdf->SetDrawColor(...self::RULE);
            $pdf->SetLineWidth(0.2);
            $pdf->LineExact(self::M, $y - 1.5, self::M + $W, $y - 1.5);
            $no++;
        }
        $this->footer();
    }

    /* ================================================================ Side 4 */

    private function pageExchange(): void
    {
        $tasks = $this->tasks->exchangeTasks($this->model);
        $paper = in_array(true, array_column($tasks, 'paper'), true);
        $lead = 'Ti like klosser kan byttes mot én kloss av neste type, og én kloss kan byttes mot ti av typen under. Tallet blir det samme.';
        if ($paper) {
            $lead .= ' Oppgaver merket PAPIROPPGAVE kan ikke gjøres med klossene i dette settet. Tegn eller tenk i stedet.';
        }
        $y = $this->header(4, 'Veksling', $lead);
        $list = [];
        foreach ($tasks as $t) {
            $tokens = $t['tokens'];
            $task = ['tokens' => $tokens, 'answer' => $t['answer']];
            if (!empty($t['two_ways'])) {
                $places = $this->places ?: [10, 1];
                $task['tokens'][] = ['br'];
                $task['tokens'][] = ['pv', 'Måte 1:', $places];
                $task['tokens'][] = ['br'];
                $task['tokens'][] = ['pv', 'Måte 2:', $places];
                $task['answers'] = $this->twoWays($t['number'], $places);
            }
            $list[] = $task;
        }
        $this->drawSections([['title' => 'Veksle og finn svaret', 'intro' => null, 'tasks' => $list]], $y);
        $this->footer();
    }

    /* ================================================================ Side 5 */

    private function pageArithmetic(): void
    {
        $t = $this->tasks->arithmeticTasks(5);
        $paper = in_array(true, array_column(array_merge($t['add'], $t['sub']), 'paper'), true);
        $lead = 'Legg det første tallet med klossene. Legg til eller ta bort det andre tallet, og veksle når du må. Skriv svaret.';
        if ($paper) {
            $lead .= ' Oppgaver merket PAPIROPPGAVE krever flere klosser enn settet har – regn dem på papiret.';
        }
        $y = $this->header(5, 'Addisjon og subtraksjon', $lead);
        $y = $this->drawSections([
            ['title' => 'Addisjon', 'intro' => 'Har du ti eller flere av én type? Bytt ti av dem mot én av neste type.', 'tasks' => $t['add']],
            ['title' => 'Subtraksjon', 'intro' => 'Har du for få av én type? Bytt én kloss av neste type mot ti.', 'tasks' => $t['sub']],
        ], $y);

        // Oppstillingsruter hvis det er plass.
        $places = $this->places ?: [10, 1];
        $cell = 11.0;
        $gridH = 7 + 3 * $cell;
        $y += 4;
        if ($y + 8 + $gridH <= $this->bottom()) {
            $y = $this->subheading($y + 3, 'Still opp og regn');
            $W = $this->contentW();
            $gw = count($places) * $cell + $cell;
            $n = min(4, (int) floor(($W + 8) / ($gw + 8)));
            for ($k = 0; $k < $n; $k++) {
                $this->drawColumnGrid(self::M + $k * ($gw + 8), $y + 2, $places, $cell);
            }
        }
        $this->footer();
    }

    /* ================================================================ Side 6 */

    private function pageTable(): void
    {
        $pdf = $this->pdf;
        $y = $this->header(6, 'Plassverditabell', 'Legg klossene i riktig kolonne, eller tegn dem. Skriv sifrene i rutene under og tallet til høyre.', 'L');
        $W = $this->contentW();
        $numW = 46.0;
        $colW = ($W - $numW) / 4;
        $headH = 14.0;
        $rows = 4;
        $rowH = 13.0;
        $bigH = $this->bottom() - $y - $headH - $rows * $rowH - 8;

        foreach (Base10Tasks::PLACES as $k => $v) {
            $type = $this->model->typeForValue($v);
            $t = $this->model->type($type);
            $x = self::M + $k * $colW;
            $pdf->SetFillHex($t['color']['hex'], 0.75);
            $pdf->RectExact($x, $y, $colW, $headH, 'F');
            // liten tegning av klossen
            $s = $v === 1000 ? 0.75 : ($v === 100 ? 0.9 : 0.9);
            [$bw, $bh] = $this->blockSize($type, $s, $v === 10 || $v === 100);
            $this->drawBlock($x + 4, $y + ($headH + $bh) / 2, $type, $s, $v === 10 || $v === 100);
            $pdf->SetFont('Helvetica', 'B', 13);
            $pdf->SetTextColor(...self::INK);
            $pdf->TextUtf8($x + 6 + $bw + 3, $y + 6.5, ucfirst($t['names'][1]));
            $pdf->SetFont('Helvetica', '', 8.5);
            $pdf->SetTextColor(...self::INK2);
            $pdf->TextUtf8($x + 6 + $bw + 3, $y + 11, $this->tasks->has($v) ? "verdi $v" : "verdi $v · ikke i settet");
        }
        $pdf->SetFillColor(...self::SOFT);
        $pdf->RectExact(self::M + 4 * $colW, $y, $numW, $headH, 'F');
        $pdf->SetFont('Helvetica', 'B', 13);
        $pdf->SetTextColor(...self::INK);
        $pdf->TextUtf8(self::M + 4 * $colW + 4, $y + 8.5, 'Tallet');

        $top = $y + $headH;
        $pdf->SetFont('Helvetica', '', 8.5);
        $pdf->SetTextColor(...self::INK2);
        $pdf->TextUtf8(self::M + 3, $top + 5, 'Legg klossene her');
        // rader for sifre
        $pdf->SetFont('Helvetica', '', 8.5);
        $rowsTop = $top + $bigH;
        for ($r = 0; $r < $rows; $r++) {
            $ry = $rowsTop + $r * $rowH;
            foreach (range(0, 3) as $k) {
                $cx = self::M + $k * $colW + $colW / 2;
                $pdf->SetDrawColor(...self::INK2);
                $pdf->SetLineWidth(0.25);
                $pdf->RectExact($cx - 4.5, $ry + 2, 9, 9, 'D');
            }
            $pdf->LineExact(self::M + 4 * $colW + 6, $ry + 10, self::M + $W - 6, $ry + 10);
        }
        // rammer
        $pdf->SetDrawColor(...self::INK);
        $pdf->SetLineWidth(0.35);
        $pdf->RectExact(self::M, $y, $W, $headH + $bigH + $rows * $rowH, 'D');
        $pdf->SetLineWidth(0.25);
        for ($k = 1; $k <= 4; $k++) {
            $pdf->LineExact(self::M + $k * $colW, $y, self::M + $k * $colW, $top + $bigH + $rows * $rowH);
        }
        $pdf->LineExact(self::M, $top, self::M + $W, $top);
        $pdf->LineExact(self::M, $rowsTop, self::M + $W, $rowsTop);
        $pdf->SetDrawColor(...self::RULE);
        $pdf->SetLineWidth(0.2);
        for ($r = 1; $r < $rows; $r++) {
            $pdf->LineExact(self::M, $rowsTop + $r * $rowH, self::M + $W, $rowsTop + $r * $rowH);
        }

        $note = sprintf('Rutene er %s mm brede. Enere og tiere får plass; hundrere og tusenere er større enn rutene, så legg dem over kolonnen eller tegn dem.',
            Base10Model::fmt(round($colW)));
        if (10 * $this->p['unit'] <= $colW - 4) {
            $note = sprintf('Rutene er %s mm brede, så alle klossene får plass. Hundrere og tusenere kan legges oppå hverandre.', Base10Model::fmt(round($colW)));
        }
        $pdf->SetFont('Helvetica', '', 8);
        $pdf->SetTextColor(...self::INK2);
        $pdf->TextUtf8(self::M, $rowsTop + $rows * $rowH + 5, $note);
        $this->footer();
    }

    /* ======================================================= Tegning av klosser */

    /** Mål på arket (bredde, høyde) for en kloss tegnet i skråprojeksjon. */
    private function blockSize(string $type, float $s, bool $flatFront = false): array
    {
        [$w, $d, $h] = $this->drawUnits($type, $flatFront);
        $D = $d * $s * self::OBLIQUE * M_SQRT1_2;
        return [$w * $s + $D, $h * $s + $D];
    }

    /**
     * Enheter [bredde, dybde, høyde] på tegningen. Tieren står oppreist, og
     * hundreren står med den store flaten mot leseren (som i lærebøker).
     */
    private function drawUnits(string $type, bool $flatFront = false): array
    {
        return match ($type) {
            'ener'    => [1, 1, 1],
            'tier'    => [1, 1, 10],
            'hundrer' => [10, 1, 10],
            default   => [10, 10, 10],
        };
    }

    /**
     * Tegner en kloss i skråprojeksjon med (x, yBase) = nedre venstre hjørne av
     * forsiden. Rutenettet viser enhetene.
     */
    private function drawBlock(float $x, float $yBase, string $type, float $s, bool $flatFront = false): void
    {
        $pdf = $this->pdf;
        $hex = $this->model->type($type)['color']['hex'];
        [$w, $d, $h] = $this->drawUnits($type, $flatFront);
        $W = $w * $s;
        $H = $h * $s;
        $D = $d * $s * self::OBLIQUE;
        $dx = $D * M_SQRT1_2;
        $dy = $D * M_SQRT1_2;
        $top = $yBase - $H;

        $pdf->SetLineWidth(max(0.12, min(0.25, $s * 0.08)));
        $pdf->SetDrawHex($hex, 0);
        $pdf->SetFillHex($hex, 0.35);
        $pdf->RectExact($x, $top, $W, $H, 'FD');
        $pdf->SetFillHex($hex, 0.6);
        $pdf->Polygon([[$x, $top], [$x + $W, $top], [$x + $W + $dx, $top - $dy], [$x + $dx, $top - $dy]], 'FD');
        $pdf->SetFillHex($hex, 0.05);
        $pdf->Polygon([[$x + $W, $yBase], [$x + $W + $dx, $yBase - $dy], [$x + $W + $dx, $top - $dy], [$x + $W, $top]], 'FD');

        if ($w * $h * $d === 1) {
            return;
        }
        $pdf->SetLineWidth(max(0.06, min(0.15, $s * 0.04)));
        $pdf->SetDrawHex($hex, 0);
        for ($i = 1; $i < $w; $i++) {           // forside og topp: loddrette
            $pdf->LineExact($x + $i * $s, $yBase, $x + $i * $s, $top);
            $pdf->LineExact($x + $i * $s, $top, $x + $i * $s + $dx, $top - $dy);
        }
        for ($j = 1; $j < $h; $j++) {           // forside og høyre side: vannrette
            $pdf->LineExact($x, $yBase - $j * $s, $x + $W, $yBase - $j * $s);
            $pdf->LineExact($x + $W, $yBase - $j * $s, $x + $W + $dx, $yBase - $j * $s - $dy);
        }
        for ($k = 1; $k < $d; $k++) {           // dybde: topp og høyre side
            $f = $k / $d;
            $pdf->LineExact($x + $f * $dx, $top - $f * $dy, $x + $W + $f * $dx, $top - $f * $dy);
            $pdf->LineExact($x + $W + $f * $dx, $yBase - $f * $dy, $x + $W + $f * $dx, $top - $f * $dy);
        }
    }

    private const GROUP_GAP = 4.0;

    /** Plassering av klossene i en sammensetning: [type, x, antall per rad …]. */
    private function groupLayout(array $rep, float $s): array
    {
        $items = [];
        $x = 0.0;
        foreach (Base10Tasks::PLACES as $v) {
            $n = $rep[$v] ?? 0;
            if ($n <= 0) {
                continue;
            }
            $type = $this->model->typeForValue($v);
            [$bw] = $this->blockSize($type, $s);
            if ($v === 1) {
                // enere i rader på fem
                $cols = min(5, $n);
                $step = $bw + 0.6 * $s;
                $items[] = ['type' => $type, 'x' => $x, 'n' => $n, 'cols' => $cols, 'step' => $step];
                $x += $cols * $step;
            } else {
                $step = $bw + ($v === 10 ? 0.6 : 1.0) * $s;
                $items[] = ['type' => $type, 'x' => $x, 'n' => $n, 'cols' => $n, 'step' => $step];
                $x += $n * $step;
            }
            $x += self::GROUP_GAP;
        }
        return ['items' => $items, 'width' => max(0.0, $x - self::GROUP_GAP)];
    }

    private function groupWidth(array $rep, float $s): float
    {
        return $this->groupLayout($rep, $s)['width'];
    }

    private function groupHeight(array $rep, float $s): float
    {
        $h = 0.0;
        foreach (Base10Tasks::PLACES as $v) {
            $n = $rep[$v] ?? 0;
            if ($n > 0) {
                [, $bh] = $this->blockSize($this->model->typeForValue($v), $s);
                $h = max($h, $v === 1 ? $bh + (int) (ceil($n / 5) - 1) * ($bh + 0.6 * $s) : $bh);
            }
        }
        return $h;
    }

    private function drawGroup(float $x0, float $base, array $rep, float $s): void
    {
        foreach ($this->groupLayout($rep, $s)['items'] as $it) {
            [, $bh] = $this->blockSize($it['type'], $s);
            for ($i = 0; $i < $it['n']; $i++) {
                $col = $i % $it['cols'];
                $row = intdiv($i, $it['cols']);
                $this->drawBlock($x0 + $it['x'] + $col * $it['step'], $base - $row * ($bh + 0.6 * $s), $it['type'], $s);
            }
        }
    }

    /** Rutenett for oppstilling: én kolonne per plassverdi + en for tegn. */
    private function drawColumnGrid(float $x, float $y, array $places, float $cell): void
    {
        $pdf = $this->pdf;
        $pdf->SetFont('Helvetica', 'B', 8);
        $pdf->SetTextColor(...self::INK2);
        $short = [1000 => 'T', 100 => 'H', 10 => 'T', 1 => 'E'];
        foreach ($places as $k => $v) {
            $label = $v === 1000 ? 'Tu' : $short[$v];
            $pdf->TextCentered($x + ($k + 1) * $cell + $cell / 2, $y + 4, $label);
        }
        $pdf->SetDrawColor(...self::RULE);
        $pdf->SetLineWidth(0.2);
        $top = $y + 6;
        $cols = count($places) + 1;
        for ($r = 0; $r <= 3; $r++) {
            $pdf->LineExact($x, $top + $r * $cell, $x + $cols * $cell, $top + $r * $cell);
        }
        for ($c = 0; $c <= $cols; $c++) {
            $pdf->LineExact($x + $c * $cell, $top, $x + $c * $cell, $top + 3 * $cell);
        }
        $pdf->SetDrawColor(...self::INK);
        $pdf->SetLineWidth(0.35);
        $pdf->LineExact($x, $top + 2 * $cell, $x + $cols * $cell, $top + 2 * $cell);
    }

    /**
     * Fasit for «Vis tallet på to måter»: færrest mulig klosser, og så med én
     * kloss vekslet til ti av typen under (bare plassene som vises på arket).
     * @param list<int> $places
     * @return list<array<int,int>>
     */
    private function twoWays(int $num, array $places): array
    {
        $first = Base10Tasks::digits($num);
        $smallest = min($places);
        foreach (array_reverse(array_values(array_filter($places, fn($v) => $v > $smallest))) as $v) {
            // Veksle fra den minste plassen som har klosser og kan veksles nedover.
            if ($first[$v] > 0 && in_array($v / 10, $places, true)) {
                $second = $first;
                $second[$v]--;
                $second[$v / 10] += 10;
                return [$first, $second];
            }
        }
        return [$first];
    }

    /* ======================================================= Egne tokens */

    // ['strong', tekst]                         uthevet tekst
    // ['pv', etikett, [1000,100,…], svar]       svarruter per plassverdi med navn (svar: [plass => antall])

    protected function answerSlot(string $type): ?int
    {
        return $type === 'pv' ? 3 : parent::answerSlot($type);
    }

    protected function customLineHeight(array $types): ?float
    {
        return in_array('pv', $types, true) ? 10.5 : null;
    }

    protected function customTokenWidth(array $t, float $size): ?float
    {
        $pdf = $this->pdf;
        if ($t[0] === 'strong') {
            $pdf->SetFont('Helvetica', 'B', $size);
            return $pdf->GetStringWidth(LabelPdf::enc($t[1]));
        }
        if ($t[0] === 'pv') {
            $w = 0.0;
            if ($t[1] !== '') {
                $pdf->SetFont('Helvetica', 'B', 9);
                $w += $pdf->GetStringWidth(LabelPdf::enc($t[1])) + 3;
            }
            $pdf->SetFont('Helvetica', '', 9);
            foreach ($t[2] as $v) {
                $w += $pdf->GetStringWidth(LabelPdf::enc($this->model->name($this->model->typeForValue($v), 1))) + 1.5 + 9 + 5;
            }
            return $w;
        }
        return null;
    }

    protected function drawCustomToken(array $t, float $x, float $yMid, float $size): bool
    {
        $pdf = $this->pdf;
        if ($t[0] === 'strong') {
            $pdf->SetFont('Helvetica', 'B', $size);
            $pdf->SetTextColor(...self::INK);
            $pdf->TextUtf8($x, $yMid + $this->capH($size) / 2, $t[1]);
            return true;
        }
        if ($t[0] !== 'pv') {
            return false;
        }
        if ($t[1] !== '') {
            $pdf->SetFont('Helvetica', 'B', 9);
            $pdf->SetTextColor(...self::INK);
            $pdf->TextUtf8($x, $yMid + 1.1, $t[1]);
            $x += $pdf->GetStringWidth(LabelPdf::enc($t[1])) + 3;
        }
        foreach ($t[2] as $v) {
            $type = $this->model->typeForValue($v);
            $name = $this->model->name($type, 1);
            $pdf->SetFont('Helvetica', '', 9);
            $pdf->SetTextColor(...self::INK2);
            $pdf->TextUtf8($x, $yMid + 1.1, $name);
            $x += $pdf->GetStringWidth(LabelPdf::enc($name)) + 1.5;
            $pdf->SetDrawHex($this->model->type($type)['color']['hex']);
            $pdf->SetFillHex($this->model->type($type)['color']['hex'], 0.85);
            $pdf->SetLineWidth(0.3);
            $pdf->RectExact($x, $yMid - 4, 9, 8, 'FD');
            if (isset($t[3][$v])) {
                $this->answerInBox($x, $yMid - 4, 9, 8, (string) $t[3][$v]);
            }
            $x += 9 + 5;
        }
        return true;
    }
}
