<?php
declare(strict_types=1);

/**
 * PDF for tallstavene: sju A4-sider.
 *
 *   1  Lærerveiledning: stavene, antall, farger, bruk og aktiviteter
 *   2  Bygg tall
 *   3  Addisjon
 *   4  Subtraksjon
 *   5  Multiplikasjon og divisjon (også partall/oddetall og forhold)
 *   6  Brøk – verdien avhenger av hvilken stav som er én hel
 *   7  Arbeidsark i full størrelse (liggende A4)
 *
 * Oppgavene lages av RodTasks og bruker bare staver som finnes i settet, i
 * det antallet settet har. Kan en oppgavetype ikke lages, blir den en
 * tegneoppgave merket TEGNEOPPGAVE. Samme utseende som de andre PDF-ene
 * (WorksheetPdf).
 */
final class RodPdfGenerator extends WorksheetPdf
{
    private const PAGES = 7;
    private const ROD_S = 2.6;      // mm per enhet når en stav vises i en oppgave
    private const ROD_H = 4.6;

    private array $p;
    private RodTasks $tasks;
    /** @var array<int,array{hex:string,name:string}> */
    private array $colors;

    public function __construct(private RodModel $model)
    {
    }

    public function render(array $params): string
    {
        $this->p = $params;
        $this->tasks = new RodTasks($params['counts']);
        $this->colors = $this->model->colors($params);
        $this->newDocument('Tallstaver – ' . RodModel::fmt($params['unit']) . ' mm');

        $this->pageGuide();
        $this->pageBuild();
        $this->pageAddition();
        $this->pageSubtraction();
        $this->pageMultiplication();
        $this->pageFractions();
        $this->pageWorksheet();
        return $this->pdf->Output('S');
    }

    protected function kicker(): string
    {
        return 'M+ 3D  ·  TALLSTAVER';
    }

    protected function pageCount(): int
    {
        return self::PAGES;
    }

    protected function footerText(): string
    {
        $p = $this->p;
        $counts = array_map(fn($n) => $p['counts'][$n], $p['lengths']);
        $set = count(array_unique($counts)) === 1
            ? $counts[0] . ' av hver lengde (' . $this->lengthList() . ')'
            : implode(', ', array_map(fn($n) => "$n: {$p['counts'][$n]}", $p['lengths']));
        return sprintf(
            'M+ 3D · %s v%d · enhet %s mm · avrunding %s mm · inndeling %s · tall %s · sett: %s',
            $this->model->definition()['id'], $this->model->definition()['version'],
            RodModel::fmt($p['unit']), RodModel::fmt($p['edge_radius']),
            $p['grid'] ? RodModel::fmt($p['groove_depth']) . ' × ' . RodModel::fmt($p['groove_width']) . ' mm' : 'av',
            $p['labels'] ? RodModel::fmt($p['label_depth']) . ' mm' : 'av',
            $set
        );
    }

    /** «1–10» eller «1, 2, 5 og 10» */
    private function lengthList(): string
    {
        $l = $this->p['lengths'];
        if (count($l) > 2 && $l === range($l[0], end($l))) {
            return $l[0] . '–' . end($l);
        }
        return RodModel::joinAnd($l);
    }

    /* ================================================================ Side 1 */

    private function pageGuide(): void
    {
        $pdf = $this->pdf;
        $p = $this->p;
        $W = $this->contentW();
        $u = RodModel::fmt($p['unit']);
        $y = $this->header(1, 'Tallstaver – lærerveiledning', sprintf(
            'Staver i opptil ti lengder til 3D-printing. Stav 1 er en kube på %1$s × %1$s × %1$s mm, og stav n er n ganger så lang. '
            . 'Alle stavene har samme tverrsnitt og plane ender, så to staver lagt ende mot ende er nøyaktig like lange som summen.', $u
        ));

        // --- Tabell: stavene, tegnet som en trapp -----------------------------
        $y = $this->subheading($y, 'Dette skal skrives ut') + 1.5;
        $cols = [['Stav', 46], ['Farge', 30], ['Mål (mm)', 26], ['STL-fil', 34], ['Antall', 20], ['Ca. PLA', 18]];
        $rowH = 6.0;
        $pdf->SetFillColor(...self::SOFT);
        $pdf->RectExact(self::M, $y, $W, 6.5, 'F');
        $pdf->SetFont('Helvetica', 'B', 9);
        $pdf->SetTextColor(...self::INK);
        $x = self::M;
        foreach ($cols as [$label, $w]) {
            $pdf->TextUtf8($x + 2, $y + 4.5, $label);
            $x += $w;
        }
        $y += 6.5;
        $pieces = 0;
        $grams = 0.0;
        $s = 3.6;
        foreach ($this->model->parts($p) as $part) {
            $i = $part['info'];
            $n = $i['length'];
            $pieces += $part['count'];
            $g = $part['count'] * $i['material']['print_g'];
            $grams += $g;
            $x = self::M;
            $this->drawRod($x + 2, $y + 0.9, $n, $s, $rowH - 1.8, true);
            $x += $cols[0][1];
            $pdf->SetFont('Helvetica', '', 9);
            $pdf->SetTextColor(...self::INK);
            $pdf->TextUtf8($x + 2, $y + 4.2, $i['color_name']);
            $x += $cols[1][1];
            $pdf->TextUtf8($x + 2, $y + 4.2, implode(' × ', array_map([RodModel::class, 'fmt'], $i['size_mm'])));
            $x += $cols[2][1];
            $pdf->SetFont('Courier', '', 8.5);
            $pdf->TextUtf8($x + 2, $y + 4.2, $part['filename']);
            $x += $cols[3][1];
            $pdf->SetFont('Helvetica', 'B', 9);
            $pdf->TextUtf8($x + 2, $y + 4.2, $part['count'] . ' stk');
            $x += $cols[4][1];
            $pdf->SetFont('Helvetica', '', 9);
            $pdf->TextUtf8($x + 2, $y + 4.2, RodModel::grams($g));
            $y += $rowH;
            $pdf->SetDrawColor(...self::RULE);
            $pdf->SetLineWidth(0.2);
            $pdf->LineExact(self::M, $y, self::M + $W, $y);
        }
        $pdf->SetFont('Helvetica', 'B', 9.5);
        $pdf->SetTextColor(...self::INK);
        $pdf->TextUtf8(self::M + 2, $y + 4.6, 'Totalt');
        $xCount = self::M + array_sum(array_column(array_slice($cols, 0, 4), 1));
        $pdf->TextUtf8($xCount + 2, $y + 4.6, "$pieces stk");
        $pdf->TextUtf8($xCount + $cols[4][1] + 2, $y + 4.6, RodModel::grams($grams));
        $y += 8.5;
        $pdf->SetFont('Helvetica', '', 8);
        $pdf->SetTextColor(...self::INK2);
        $pdf->TextUtf8(self::M, $y + 1, sprintf(
            'Filament er et overslag for PLA med 2 vegger og 15 %% fyll. Alle stavene etter hverandre blir %d enheter (%s cm).',
            $this->tasks->totalLength(), RodModel::fmt(round($this->tasks->totalLength() * $p['unit'] / 10, 1))
        ));
        $y += 7;

        // --- Farger -----------------------------------------------------------
        $y = $this->subheading($y, 'Farger');
        $text = match ($p['color_mode']) {
            'single' => 'Alle stavene har samme farge. Da må elevene sammenligne lengdene direkte, og det er lengden alene som viser verdien. ',
            'custom' => 'Stavene har farger valgt av læreren (se tabellen). Staver med samme lengde har samme farge, så fargen hjelper elevene å kjenne igjen lengdene. ',
            default  => 'Stavene har de klassiske Cuisenaire-fargene: 1 hvit, 2 rød, 3 lysegrønn, 4 lilla, 5 gul, 6 mørkegrønn, 7 svart, 8 brun, 9 blå og 10 oransje. '
                . 'Fargene gjør det lett å finne en lengde, og «familier» hører sammen: rød 2, lilla 4 og brun 8 er doblinger. ',
        };
        $text .= 'STL-filer har ingen farge: skriv ut hver lengde med sitt filament, eller mal stavene etterpå.';
        $y = $this->paragraph($y, $text) + 5;

        // --- Uten tall ------------------------------------------------------------
        $y = $this->subheading($y, 'Stavene kan brukes uten tall');
        $y = $this->paragraph($y, 'Elevene trenger ikke å kjenne tallverdiene på forhånd. La dem først utforske: Hvilke to staver er like lange som den gule? '
            . 'Hvilken stav er dobbelt så lang som den røde? Når de setter navn på lengdene selv, må de resonnere om sammenhengene. '
            . 'Tallene kan komme senere – og verdien til en stav avhenger av hvilken stav som er én hel (side 6).') + 5;

        // --- Pedagogisk bruk og aktiviteter -----------------------------------
        $y = $this->subheading($y, 'Forslag til aktiviteter');
        $tips = [
            'Tall og tallkombinasjoner: Lag «tog» som er like lange som en stav. Finn alle måtene å bygge 10 på (side 2).',
            'Addisjon og subtraksjon: Legg to staver etter hverandre og finn staven som er like lang. Legg to staver ved siden av hverandre og finn forskjellen (side 3 og 4).',
            'Multiplikasjon og divisjon: Legg like staver etter hverandre (3 × 4). Hvor mange røde staver er like lange som den brune? (side 5)',
            'Partall og oddetall: Kan staven bygges med to like staver? (side 5)',
            'Brøk og forhold: Velg en stav som én hel og finn halvdelen, femtedelen … Bytt hel og se at verdiene endres (side 6).',
            'Mønstre: Bygg en trapp fra 1 til 10. Hva skjer når du legger en hvit stav på hvert trinn?',
        ];
        foreach ($tips as $tip) {
            if ($y + 5 > $this->bottom()) {
                break;
            }
            $pdf->SetFillColor(243, 109, 33);
            $pdf->RectExact(self::M + 0.5, $y + 1.6, 1.6, 1.6, 'F');
            $y = $this->paragraph($y, $tip, 9.5, 4.6, self::M + 4, $W - 4) + 0.8;
        }
        $y += 5;

        // --- Utskrift -------------------------------------------------------------
        $print = 'Stavene ligger slik de skal skrives ut og trenger ikke støtte. Kantene mot byggeplaten har en liten fas i stedet for avrunding. '
            . 'Endeflatene er plane, så stavene kan legges ende mot ende. Anbefalt: PLA, 0,2 mm laghøyde, 2–3 vegger, 15 % fyll.';
        if ($y + 2.5 + $this->paragraphHeight($print, 9.5, 4.6, $W) <= $this->bottom()) {
            $y = $this->subheading($y, 'Utskrift');
            $this->paragraph($y, $print);
        }
        $this->footer();
    }

    /* =========================================================== Oppgavesider */

    /**
     * Seksjoner fra [tittel, intro, [[type, nivå, antall], …]]. Returnerer
     * [seksjoner, harTegneoppgaver].
     */
    private function sections(array $spec): array
    {
        $out = [];
        $paper = false;
        foreach ($spec as [$title, $intro, $parts]) {
            $tasks = [];
            foreach ($parts as [$type, $level, $n]) {
                foreach ($this->tasks->tasks($type, $level, $n) as $t) {
                    $paper = $paper || $t['paper'];
                    $tasks[] = $t;
                }
            }
            $out[] = ['title' => $title, 'intro' => $intro, 'tasks' => $tasks];
        }
        return [$out, $paper];
    }

    private function taskPage(int $page, string $title, string $lead, array $spec): void
    {
        [$sections, $paper] = $this->sections($spec);
        if ($paper) {
            $lead .= ' Oppgaver merket TEGNEOPPGAVE trenger staver som ikke er i settet. Tegn stavene på arbeidsarket (side 7) i stedet.';
        }
        $y = $this->header($page, $title, $lead);
        $this->drawSections($sections, $y);
        $this->footer();
    }

    private function pageBuild(): void
    {
        $this->taskPage(2, 'Bygg tall', 'Bruk stavene. Et «tog» er staver lagt ende mot ende. Toget er like langt som staven når endene er på linje.', [
            ['Finn staven', null, [['find', 'lett', 3]]],
            ['Hvilken stav er like lang?', null, [['find', 'middels', 2], ['find', 'vanskelig', 1]]],
            ['Bygg med to staver', 'Legg toget rett under staven.', [['build', 'lett', 2]]],
            ['Mange måter', null, [['build', 'middels', 2], ['build', 'vanskelig', 1]]],
        ]);
    }

    private function pageAddition(): void
    {
        $this->taskPage(3, 'Addisjon', 'Legg stavene etter hverandre. Finn staven som er like lang som toget, og skriv svaret.', [
            ['To staver', null, [['add', 'lett', 6]]],
            ['Tre staver', 'Rekkefølgen spiller ingen rolle. Prøv å bytte om stavene.', [['add', 'middels', 4]]],
            ['Mer enn 10', 'Legg toget ved siden av den oransje 10-staven. Hvilken stav fyller resten?', [['add', 'vanskelig', 3]]],
        ]);
    }

    private function pageSubtraction(): void
    {
        $this->taskPage(4, 'Subtraksjon', 'Legg stavene ved siden av hverandre med venstre ende på linje. Forskjellen er staven som fyller gapet.', [
            ['Finn forskjellen', null, [['sub', 'lett', 4]]],
            ['Hvilken stav mangler?', null, [['sub', 'middels', 5]]],
            ['Tre staver', null, [['sub', 'vanskelig', 3]]],
        ]);
    }

    private function pageMultiplication(): void
    {
        $this->taskPage(5, 'Multiplikasjon og divisjon', 'Like staver etter hverandre viser gange: 3 × 4 er tre staver med lengde 4. Deling er å finne hvor mange like staver som får plass.', [
            ['Like staver etter hverandre', null, [['mul', 'lett', 3], ['mul', 'middels', 2], ['mul', 'vanskelig', 1]]],
            ['Deling', null, [['div', 'lett', 2], ['div', 'middels', 1], ['div', 'vanskelig', 1]]],
            ['Partall og oddetall', null, [['parity', 'lett', 2]]],
            ['Forhold', 'Finn staven som passer: den skal være like mange ganger så lang i begge parene.', [['ratio', 'vanskelig', 2]]],
        ]);
    }

    /* ================================================================ Side 6 */

    private function pageFractions(): void
    {
        $pdf = $this->pdf;
        $W = $this->contentW();
        $w1 = $this->tasks->mainWhole();
        $lead = 'Velg én stav som én hel. Da får de andre stavene en brøkverdi. Verdien til en stav avhenger av hvilken stav som er den hele: '
            . 'Er den oransje 10-staven én hel, er den gule 5-staven en halv. Er den brune 8-staven én hel, er den lilla 4-staven en halv.';
        $spec = [];
        if ($w1 !== null) {
            $spec[] = ["Når stav $w1 er én hel", null, [['fraction', 'lett', 3], ['fraction', 'middels', 1], ['fraction', 'vanskelig', 2]]];
        } else {
            $spec[] = ['Velg en hel', null, [['fraction', 'lett', 2], ['fraction', 'vanskelig', 1]]];
        }
        [$sections, $paper] = $this->sections($spec);
        $sections = array_map(fn($sec) => ['tasks' => array_map([self::class, 'dropWhole'], $sec['tasks'])] + $sec, $sections);
        if ($paper) {
            $lead .= ' Settet har ingen staver som går opp i hverandre. Tegneoppgavene løses på arbeidsarket (side 7).';
        }
        $y = $this->header(6, 'Brøk', $lead);

        // Brøkvegg: helen øverst, og hver stammebrøk som like staver under.
        if ($w1 !== null) {
            $units = $this->tasks->wholes()[0]['units'];
            $rows = array_slice($units, 0, 4, true);
            $s = min(12.0, ($W - 30) / $w1);
            $h = 6.2;
            $x0 = self::M + 22;
            $this->drawRod($x0, $y, $w1, $s, $h, true);
            $pdf->SetFont('Helvetica', 'B', 9.5);
            $pdf->SetTextColor(...self::INK);
            $pdf->TextUtf8(self::M, $y + 3.8, 'én hel');
            $yy = $y + $h + 1.4;
            foreach ($rows as $q => $len) {
                for ($i = 0; $i < $q; $i++) {
                    $this->drawRod($x0 + $i * $len * $s, $yy, $len, $s, $h, $i === 0);
                }
                $this->drawFrac(self::M + 4, $yy + $h / 2, 1, $q, 7.5);
                $yy += $h + 1.4;
            }
            $pdf->SetFont('Helvetica', '', 8);
            $pdf->SetTextColor(...self::INK2);
            $pdf->TextUtf8(self::M, $yy + 3, "Brøkvegg med stav $w1 som én hel. Bygg den med stavene, og se at like staver dekker helen nøyaktig.");
            $y = $yy + 7;
        }

        $second = $this->tasks->secondWhole();
        if ($second !== null) {
            $w2 = $second['whole'];
            $r = $second['rod'];
            $g1 = RodTasks::gcd($r, $w1);
            $g2 = RodTasks::gcd($r, $w2);
            $sections[] = ['title' => "Bytt hel: nå er stav $w2 én hel", 'intro' => null, 'tasks' => [
                ...array_map([self::class, 'dropWhole'], $this->tasks->fractionTasksFor($w2, 'lett', 2)),
                ['tokens' => [['text', 'Da stav ' . $w1 . ' var én hel, var '], ['rod', $r], ['text', ' lik '], ['frac', $r / $g1, $w1 / $g1],
                    ['text', '. Hva er den nå? '], ['fracbox'], ['text', ' Hvorfor er svaret et annet?']],
                 'answer' => ($r / $g2) . '/' . ($w2 / $g2), 'rods' => [$w1, $w2, $r], 'paper' => false],
            ]];
        }
        [$more, $paper2] = $this->sections([
            ['Likeverdige brøker', null, [['equiv', 'vanskelig', 2]]],
            ['Fra del til hel', null, [['whole', 'middels', 2]]],
        ]);
        $sections = [...$sections, ...$more];
        $this->drawSections($sections, $y);
        $this->footer();
    }

    /** Fjerner «Når [stav] er én hel:» når seksjonsoverskriften allerede sier det. */
    private static function dropWhole(array $t): array
    {
        if (isset($t['whole']) && !$t['paper']) {
            $t['tokens'] = array_slice($t['tokens'], 3);
        }
        return $t;
    }

    /* ================================================================ Side 7 */

    /**
     * Arbeidsark med baner i full størrelse: hver bane er like bred som en
     * stav og har en enhetsinndeling. Får ikke ti enheter plass på bredden,
     * skaleres arket ned og merkes tydelig som skalert.
     */
    private function pageWorksheet(): void
    {
        $pdf = $this->pdf;
        $u = (float) $this->p['unit'];
        $y = $this->header(7, 'Arbeidsark', 'Legg stavene i banene, med venstre ende ved 0. Tegn kombinasjonene og skriv regnestykket på linja under.', 'L');
        $W = $this->contentW();
        $labelW = 8.0;
        $avail = $W - $labelW - 2;
        $scale = 10 * $u <= $avail ? 1.0 : $avail / (10 * $u);
        $s = $u * $scale;
        $units = min(20, (int) floor($avail / $s + 1e-9));
        $rulerH = 16.0;
        $trackH = $s;
        $rowH = $trackH + 13;
        $rows = max(1, (int) floor(($this->bottom() - $rulerH - $y) / $rowH));

        if ($scale < 1) {
            $pdf->SetFillColor(255, 243, 205);
            $pdf->RectExact(self::M, $y - 3, $W, 7, 'F');
            $pdf->SetFont('Helvetica', 'B', 9);
            $pdf->SetTextColor(...self::ACCENT);
            $pdf->TextUtf8(self::M + 2, $y + 1.6, sprintf('SKALERT: Arket er %s %% av full størrelse. Stavene passer ikke i banene – tegn dem i stedet.', RodModel::fmt(round($scale * 100))));
            $y += 6;
        }

        $x0 = self::M + $labelW;
        for ($r = 0; $r < $rows; $r++) {
            $top = $y + $r * $rowH + 4;
            // tall over inndelingen
            $pdf->SetFont('Helvetica', '', 6.5);
            $pdf->SetTextColor(...self::INK2);
            for ($k = 0; $k <= $units; $k++) {
                if ($k % ($s < 7 ? 2 : 1) === 0) {
                    $pdf->TextCentered($x0 + $k * $s, $top - 1, (string) $k);
                }
            }
            $pdf->SetFont('Helvetica', 'B', 10);
            $pdf->SetTextColor(...self::ACCENT);
            $pdf->TextUtf8(self::M, $top + $trackH / 2 + 1.5, (string) ($r + 1));
            $pdf->SetFillColor(250, 248, 243);
            $pdf->SetDrawColor(...self::INK2);
            $pdf->SetLineWidth(0.3);
            $pdf->RectExact($x0, $top, $units * $s, $trackH, 'FD');
            $pdf->SetDrawColor(...self::RULE);
            $pdf->SetLineWidth(0.15);
            for ($k = 1; $k < $units; $k++) {
                $pdf->SetDrawColor(...($k % 10 === 0 ? self::INK2 : ($k % 5 === 0 ? [190, 182, 168] : self::RULE)));
                $pdf->LineExact($x0 + $k * $s, $top, $x0 + $k * $s, $top + $trackH);
            }
            // skrivelinje
            $ly = $top + $trackH + 8;
            $pdf->SetFont('Helvetica', '', 8.5);
            $pdf->SetTextColor(...self::INK2);
            $pdf->TextUtf8($x0, $ly, 'Regnestykke:');
            $pdf->SetDrawColor(...self::INK2);
            $pdf->SetLineWidth(0.2);
            $pdf->LineExact($x0 + 20, $ly + 0.6, $x0 + $units * $s, $ly + 0.6);
        }

        // Kontroll av utskriftsstørrelsen
        $ry = $this->bottom() - 4;
        if ($scale === 1.0) {
            $pdf->Ruler100(self::M, $ry, 'Kontroller utskriften', sprintf(
                'Linjalen skal være nøyaktig 100 mm, og banene %s mm brede. Skriv ut i 100 %% (ikke «tilpass til side»).', RodModel::fmt($u)));
        } else {
            $pdf->SetFont('Helvetica', 'B', 9);
            $pdf->SetTextColor(...self::ACCENT);
            $pdf->TextUtf8(self::M, $ry, sprintf('Skalert til %s %%: én enhet er %s mm på arket, men %s mm på stavene.',
                RodModel::fmt(round($scale * 100)), RodModel::fmt(round($s, 1)), RodModel::fmt($u)));
        }
        $this->footer();
    }

    /* ========================================================= Tegning av staver */

    /** Lys eller mørk tekst på en farge (relativ luminans). */
    private static function isDark(string $hex): bool
    {
        [$r, $g, $b] = LabelPdf::hexRgb($hex);
        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b < 140;
    }

    /** Tegner stav n som et rektangel n · s mm langt, med (x, yTop) øverst til venstre. */
    private function drawRod(float $x, float $yTop, int $n, float $s, float $h, bool $number = true): void
    {
        $pdf = $this->pdf;
        $hex = $this->colors[$n]['hex'];
        $pdf->SetFillHex($hex);
        $pdf->SetDrawHex(self::isDark($hex) ? '#161616' : '#5d5a55');
        $pdf->SetLineWidth(0.2);
        $pdf->RectExact($x, $yTop, $n * $s, $h, 'FD');
        if ($this->p['grid'] && $n > 1 && $s >= 2) {
            // inndelingen: lysere strek på mørke staver, grå på lyse
            self::isDark($hex) ? $pdf->SetDrawHex($hex, 0.45) : $pdf->SetDrawColor(115, 110, 100);
            $pdf->SetLineWidth(0.1);
            for ($k = 1; $k < $n; $k++) {
                $pdf->LineExact($x + $k * $s, $yTop + 0.3, $x + $k * $s, $yTop + $h - 0.3);
            }
        }
        if ($number) {
            $size = min(8.0, $h * 1.7);
            $pdf->SetFont('Helvetica', 'B', $size);
            $pdf->SetTextColor(...(self::isDark($hex) ? [255, 255, 255] : self::INK));
            $cx = $this->p['grid'] && $n > 1 ? $x + (intdiv($n - 1, 2) + 0.5) * $s : $x + $n * $s / 2;
            $pdf->TextCentered($cx, $yTop + $h / 2 + $this->capH($size) / 2, (string) $n);
        }
    }

    private function fracWidth(int $p, int $q, float $size): float
    {
        $this->pdf->SetFont('Helvetica', 'B', $size);
        return max($this->pdf->GetStringWidth((string) $p), $this->pdf->GetStringWidth((string) $q)) + 1.6;
    }

    /** Stablet brøk sentrert om (x, yMid). x er venstre kant. */
    private function drawFrac(float $x, float $yMid, int $p, int $q, float $size): void
    {
        $pdf = $this->pdf;
        $w = $this->fracWidth($p, $q, $size);
        $pdf->SetFont('Helvetica', 'B', $size);
        $pdf->SetTextColor(...self::INK);
        $pdf->TextCentered($x + $w / 2, $yMid - 0.9, (string) $p);
        $pdf->TextCentered($x + $w / 2, $yMid + 0.9 + $this->capH($size), (string) $q);
        $pdf->SetDrawColor(...self::INK);
        $pdf->SetLineWidth(0.25);
        $pdf->LineExact($x + 0.3, $yMid, $x + $w - 0.3, $yMid);
    }

    /* ======================================================= Egne tokens */

    // ['rod', n]          liten stav i riktig farge og lengde
    // ['strong', tekst]   uthevet tekst
    // ['frac', p, q]      stablet brøk
    // ['fracbox']         tom brøk (to ruter)

    protected function customLineHeight(array $types): ?float
    {
        if (array_intersect($types, ['frac', 'fracbox'])) {
            return 11.0;
        }
        return in_array('rod', $types, true) ? 9.5 : null;
    }

    protected function customTokenWidth(array $t, float $size): ?float
    {
        return match ($t[0]) {
            'rod'     => $t[1] * self::ROD_S + 1.0,
            'strong'  => (function () use ($t, $size) {
                $this->pdf->SetFont('Helvetica', 'B', $size);
                return $this->pdf->GetStringWidth(LabelPdf::enc($t[1]));
            })(),
            'frac'    => $this->fracWidth($t[1], $t[2], $size * 0.85) + 1.0,
            'fracbox' => 9.0,
            default   => null,
        };
    }

    protected function drawCustomToken(array $t, float $x, float $yMid, float $size): bool
    {
        $pdf = $this->pdf;
        switch ($t[0]) {
            case 'rod':
                $this->drawRod($x + 0.5, $yMid - self::ROD_H / 2, $t[1], self::ROD_S, self::ROD_H, true);
                return true;
            case 'strong':
                $pdf->SetFont('Helvetica', 'B', $size);
                $pdf->SetTextColor(...self::INK);
                $pdf->TextUtf8($x, $yMid + $this->capH($size) / 2, $t[1]);
                return true;
            case 'frac':
                $this->drawFrac($x + 0.5, $yMid, $t[1], $t[2], $size * 0.85);
                return true;
            case 'fracbox':
                $pdf->SetDrawColor(...self::INK);
                $pdf->SetLineWidth(0.25);
                $pdf->RectExact($x + 1.5, $yMid - 5.2, 6, 4.4, 'D');
                $pdf->RectExact($x + 1.5, $yMid + 0.8, 6, 4.4, 'D');
                $pdf->LineExact($x + 0.8, $yMid, $x + 8.2, $yMid);
                return true;
        }
        return false;
    }
}
