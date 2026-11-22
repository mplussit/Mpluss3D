<?php
declare(strict_types=1);

/**
 * PDF med utbrettingsfiguren til en romfigur.
 *
 * Nettet og plasseringen kommer fra SolidModel::net() (SolidNet + NetLayout),
 * akkurat som 2D-visningen i nettleseren. Målestokken er 1:1 når ikke noe
 * annet står tydelig på arket.
 *
 *   Ett ark      nettet i 1:1 (stående eller liggende A4)
 *   Flere ark    oversikt (forminsket) + ett ark per del i 1:1, med overlapp,
 *                skjøtelinjer og passmerker
 *   Skalert      ett ark, tydelig merket med målestokken
 *
 * Alle ark har forklaring (klipp, brett, lim) og en kontrollinje på 50 mm.
 */
final class SolidNetPdf
{
    private const INK = [22, 22, 22];
    private const INK2 = [93, 90, 85];
    private const ACCENT = [163, 63, 14];
    private const BLUE = [36, 80, 200];
    private const TAB = [228, 224, 216];

    private LabelPdf $pdf;
    private Solid $solid;
    private array $p;
    private array $plan;
    private SolidNet $net;
    private array $lines;
    private int $pages;
    private int $page = 0;

    public function __construct(private SolidModel $model)
    {
    }

    public function render(array $p): string
    {
        $this->p = $p;
        $this->solid = $this->model->solid($p);
        ['net' => $this->net, 'plan' => $this->plan] = $this->model->net($p);
        $this->lines = $this->net->lines();
        $this->pdf = new LabelPdf('P', 'A4');
        $this->pdf->SetTitle(LabelPdf::enc($this->solid->name() . ' – utbrettingsfigur'));
        $this->pdf->SetCreator('M+ 3D');

        $plan = $this->plan;
        if ($plan['mode'] === 'tiles') {
            $this->pages = count($plan['pages']) + 1;
            $this->overviewPage();
            foreach ($plan['pages'] as $tile) {
                $this->tilePage($tile);
            }
        } else {
            $this->pages = 1;
            $this->singlePage();
        }
        return $this->pdf->Output('S');
    }

    /* ------------------------------------------------------------- Sider */

    private function singlePage(): void
    {
        $plan = $this->plan;
        $scaled = $plan['mode'] === 'scaled';
        $this->header($plan['orientation'], $scaled
            ? ['warn', 'SKALERT – målestokk ' . $plan['scale_label'] . '. Ikke 1:1: papirfiguren blir mindre enn 3D-modellen.']
            : ['ok', 'Målestokk 1:1. Skriv ut i 100 % («Faktisk størrelse»), ikke «Tilpass til siden».']);
        $pg = $plan['pages'][0];
        $s = $plan['scale'];
        $this->drawNet([$s, $pg['origin'][0], $pg['origin'][1]], !$scaled);
        $this->footer($plan['orientation']);
    }

    private function overviewPage(): void
    {
        $plan = $this->plan;
        $pdf = $this->pdf;
        $this->header('P', ['warn', sprintf('Oversikt – forminsket (%s). Ikke klipp ut denne siden. Arkene som følger er i 1:1.', $plan['overview']['label'])]);
        $W = 190.0;
        $text = sprintf(
            'Nettet er %s × %s mm og får ikke plass på ett A4-ark i målestokk 1:1. Det er delt på %d ark (%d %s × %d %s, %s). '
            . 'Nabo-arkene overlapper %s mm. Slik setter du dem sammen: Klipp hvert ark langs den blå heltrukne linja (skjøtekanten). '
            . 'Legg kanten over nabo-arket slik at den treffer den blå stiplede linja og passmerkene dekker hverandre. Tap eller lim, og klipp så ut nettet.',
            Solid::fmt($plan['size'][0], 0), Solid::fmt($plan['size'][1], 0), count($plan['pages']),
            $plan['rows'], $plan['rows'] === 1 ? 'rad' : 'rader', $plan['cols'], $plan['cols'] === 1 ? 'kolonne' : 'kolonner',
            $plan['orientation'] === 'L' ? 'liggende' : 'stående', Solid::fmt(NetLayout::OVERLAP, 0)
        );
        $pdf->SetFont('Helvetica', '', 9.5);
        $pdf->SetTextColor(...self::INK);
        $pdf->SetXY(9, 35);
        $pdf->MultiCell($W + 2, 4.6, LabelPdf::enc($text));
        $pdf->SetFont('Helvetica', '', 8.5);
        $pdf->SetTextColor(...self::INK2);
        $pdf->SetX(9);
        $pdf->MultiCell($W + 2, 4.2, LabelPdf::enc(sprintf(
            'Vil du heller ha nettet på ett ark, kan du velge «Skalert ned til ett A4-ark» (målestokk %s). Da blir papirfiguren mindre enn 3D-modellen.',
            $plan['scaled_alt']['label']
        )));

        $ov = $plan['overview'];
        $s = $ov['scale'];
        $t = [$s, $ov['origin'][0], $ov['origin'][1]];
        $this->drawNet($t, false);
        // Rutenettet av ark
        $pdf->SetLineWidth(0.35);
        foreach ($plan['pages'] as $tile) {
            [$wx, $wy, $ww, $wh] = $tile['window'];
            $pdf->SetDrawColor(...self::BLUE);
            $pdf->RectExact($wx * $s + $t[1], $wy * $s + $t[2], $ww * $s, $wh * $s);
            $pdf->SetFont('Helvetica', 'B', 13);
            $pdf->SetTextColor(...self::BLUE);
            $pdf->TextUtf8($wx * $s + $t[1] + 2, $wy * $s + $t[2] + 6, $tile['label']);
        }
        $this->footer('P', false);
    }

    private function tilePage(array $tile): void
    {
        $plan = $this->plan;
        $pdf = $this->pdf;
        $o = $plan['orientation'];
        $this->header($o, ['ok', sprintf('Ark %s (rad %d, kolonne %d) – målestokk 1:1. Skriv ut i 100 %% («Faktisk størrelse»).',
            $tile['label'], $tile['row'] + 1, $tile['col'] + 1)]);
        [$ax, $ay, $aw, $ah] = NetLayout::area($o);
        [$wx, $wy] = $tile['window'];
        $t = [1.0, $ax - $wx, $ay - $wy];

        $pdf->ClipRect($ax, $ay, $aw, $ah);
        $this->drawNet($t, true);
        $pdf->ClipEnd();

        $ov = NetLayout::OVERLAP;
        $byPos = [];
        foreach ($plan['pages'] as $other) {
            $byPos[$other['row'] . ':' . $other['col']] = $other['label'];
        }
        $r = $tile['row'];
        $c = $tile['col'];
        $pdf->SetLineWidth(0.4);
        $pdf->SetFont('Helvetica', 'B', 7.5);
        $pdf->SetTextColor(...self::BLUE);
        $pdf->SetDrawColor(...self::BLUE);
        // Skjøtekant (heltrukken) mot naboen til venstre/over: klipp her.
        if (isset($byPos["$r:" . ($c - 1)])) {
            $pdf->SetDash();
            $pdf->LineExact($ax, $ay, $ax, $ay + $ah);
            $this->vText($ax - 2.2, $ay + $ah / 2, 'Klipp her og legg over ' . $byPos["$r:" . ($c - 1)]);
        }
        if (isset($byPos[($r - 1) . ":$c"])) {
            $pdf->SetDash();
            $pdf->LineExact($ax, $ay, $ax + $aw, $ay);
            $pdf->TextCentered($ax + $aw / 2, $ay - 1.5, 'Klipp her og legg over ' . $byPos[($r - 1) . ":$c"]);
        }
        // Stiplet linje der naboen til høyre/under skal ligge.
        $pdf->SetDash(2, 1.5);
        if (isset($byPos["$r:" . ($c + 1)])) {
            $x = $ax + $aw - $ov;
            $pdf->LineExact($x, $ay, $x, $ay + $ah);
            $this->vText($ax + $aw + 3.2, $ay + $ah / 2, 'Kanten av ' . $byPos["$r:" . ($c + 1)] . ' skal ligge på den stiplede linja');
            $this->registration($x + $ov / 2, $ay, $ah, true);
        }
        if (isset($byPos[($r + 1) . ":$c"])) {
            $y = $ay + $ah - $ov;
            $pdf->LineExact($ax, $y, $ax + $aw, $y);
            $pdf->TextCentered($ax + $aw / 2, $ay + $ah + 3.2, 'Kanten av ' . $byPos[($r + 1) . ":$c"] . ' skal ligge på den stiplede linja');
            $this->registration($y + $ov / 2, $ax, $aw, false);
        }
        $pdf->SetDash();
        // Passmerker i overlappen mot venstre/over (samme nettposisjon som på naboen).
        if (isset($byPos["$r:" . ($c - 1)])) {
            $this->registration($ax + $ov / 2, $ay, $ah, true);
        }
        if (isset($byPos[($r - 1) . ":$c"])) {
            $this->registration($ay + $ov / 2, $ax, $aw, false);
        }
        $this->miniMap($o, $tile);
        $this->footer($o);
    }

    /** Passmerker (sirkel med kryss) langs en skjøt. Like posisjoner på begge ark. */
    private function registration(float $at, float $from, float $len, bool $vertical): void
    {
        $pdf = $this->pdf;
        $pdf->SetDrawColor(...self::BLUE);
        $pdf->SetLineWidth(0.25);
        for ($d = 20.0; $d < $len - 10; $d += 50) {
            [$x, $y] = $vertical ? [$at, $from + $d] : [$from + $d, $at];
            $pdf->Circle($x, $y, 2.4, 'D');
            $pdf->LineExact($x - 3.6, $y, $x + 3.6, $y);
            $pdf->LineExact($x, $y - 3.6, $x, $y + 3.6);
        }
    }

    /** Lite kart over arkene øverst til høyre, med dette arket markert. */
    private function miniMap(string $o, array $tile): void
    {
        $pdf = $this->pdf;
        [$W] = NetLayout::page($o);
        $cell = 4.5;
        $x0 = $W - NetLayout::MARGIN - $this->plan['cols'] * $cell;
        $y0 = 15.5;
        $pdf->SetLineWidth(0.2);
        $pdf->SetDrawColor(...self::INK2);
        foreach ($this->plan['pages'] as $t) {
            $x = $x0 + $t['col'] * $cell;
            $y = $y0 + $t['row'] * $cell * 0.75;
            if ($t['label'] === $tile['label']) {
                $pdf->SetFillColor(...self::BLUE);
                $pdf->RectExact($x, $y, $cell, $cell * 0.75, 'FD');
            } else {
                $pdf->RectExact($x, $y, $cell, $cell * 0.75);
            }
        }
    }

    /** Loddrett tekst (lest nedenfra og opp) sentrert om (x, y). */
    private function vText(float $x, float $y, string $s): void
    {
        $enc = LabelPdf::enc($s);
        $w = $this->pdf->GetStringWidth($enc);
        $this->pdf->TextRotated($x, $y + $w / 2, $enc, 90);
    }

    /* ----------------------------------------------------- Topp og bunn */

    /** @param array{string,string} $band ['ok'|'warn', tekst] */
    private function header(string $o, array $band): void
    {
        $pdf = $this->pdf;
        $pdf->AddPage($o);
        $this->page++;
        $pdf->SetPageTitle($this->pages === 1 ? 'Nett' : ($this->page === 1 && $this->plan['mode'] === 'tiles' ? 'Nett – oversikt' : 'Nett-ark ' . ($this->page - 1)));
        [$W] = NetLayout::page($o);
        $m = NetLayout::MARGIN;
        $pdf->SetFont('Helvetica', 'B', 8);
        $pdf->SetTextColor(...self::ACCENT);
        $pdf->TextUtf8($m, 11, 'M+ 3D  ·  ROMFIGURER  ·  UTBRETTINGSFIGUR');
        $pdf->SetFont('Helvetica', '', 8);
        $pdf->SetTextColor(...self::INK2);
        $sp = LabelPdf::enc("Ark {$this->page} av {$this->pages}");
        $pdf->Text($W - $m - $pdf->GetStringWidth($sp) - ($this->plan['mode'] === 'tiles' && $this->page > 1 ? $this->plan['cols'] * 4.5 + 3 : 0), 11, $sp);

        $pdf->SetFont('Helvetica', 'B', 15);
        $pdf->SetTextColor(...self::INK);
        $pdf->TextUtf8($m, 19.5, $this->solid->name() . '  ' . $this->dimsText());

        [$kind, $text] = $band;
        if ($kind === 'warn') {
            $pdf->SetFillColor(252, 228, 222);
            $pdf->SetDrawColor(163, 35, 26);
        } else {
            $pdf->SetFillColor(255, 243, 205);
            $pdf->SetDrawColor(230, 180, 60);
        }
        $pdf->SetLineWidth(0.3);
        $pdf->RectExact($m, 23, $W - 2 * $m, 7, 'FD');
        $pdf->SetFont('Helvetica', 'B', 9);
        $pdf->SetTextColor(...($kind === 'warn' ? [163, 35, 26] : self::INK));
        $pdf->TextUtf8($m + 2.5, 27.8, $text);
    }

    private function dimsText(): string
    {
        $parts = [];
        foreach ($this->solid->measures() as $k => $mm) {
            if (in_array($k, ['side', 'length', 'width', 'height', 'base', 'diameter'], true)) {
                $parts[] = $mm['symbol'] . ' = ' . Solid::fmt($mm['value']) . ' mm';
            }
        }
        return '(' . implode(', ', $parts) . ')';
    }

    private function footer(string $o, bool $legend = true): void
    {
        $pdf = $this->pdf;
        [$W, $H] = NetLayout::page($o);
        $m = NetLayout::MARGIN;
        $y = $H - 19;
        if ($legend) {
            $pdf->SetLineWidth(0.4);
            $pdf->SetDrawColor(...self::INK);
            $pdf->LineExact($m, $y, $m + 10, $y);
            $this->legendText($m + 12, $y, 'Klipp');
            $pdf->SetLineWidth(0.3);
            $pdf->SetDrawColor(...self::INK2);
            $pdf->SetDash(2, 1.2);
            $pdf->LineExact($m + 26, $y, $m + 36, $y);
            $pdf->SetDash();
            $this->legendText($m + 38, $y, 'Brett bakover (trykket side blir utsiden)');
            $pdf->SetFillColor(...self::TAB);
            $pdf->SetDrawColor(...self::INK);
            $pdf->SetLineWidth(0.3);
            $pdf->RectExact($m + 99, $y - 2.2, 8, 4.4, 'FD');
            $this->legendText($m + 109, $y, 'Limflik (limes på innsiden)');
        }
        // Kontrollinje 50 mm
        $x = $W - $m - 50;
        $pdf->SetDrawColor(0, 0, 0);
        $pdf->SetLineWidth(0.25);
        $pdf->LineExact($x, $y + 5, $x + 50, $y + 5);
        for ($mm = 0; $mm <= 50; $mm += 5) {
            $len = $mm % 10 === 0 ? 2.6 : 1.5;
            $pdf->LineExact($x + $mm, $y + 5, $x + $mm, $y + 5 - $len);
        }
        $pdf->SetFont('Helvetica', 'B', 7.5);
        $pdf->SetTextColor(...self::INK);
        $cap = LabelPdf::enc('Kontrollinje 50 mm:');
        $pdf->Text($x - 3 - $pdf->GetStringWidth($cap), $y + 5.8, $cap);
        $pdf->SetFont('Helvetica', '', 6);
        foreach ([0, 50] as $mm) {
            $pdf->TextCentered($x + $mm, $y + 8.2, (string) $mm);
        }
        $pdf->SetFont('Helvetica', '', 6.5);
        $pdf->SetTextColor(130, 130, 130);
        $pdf->TextUtf8($m, $H - 5, sprintf(
            'M+ 3D · %s v%d · %s · nett: %s · limflik %s mm · STL: %s · %s',
            $this->model->definition()['id'], $this->model->definition()['version'],
            $this->solid->name(), $this->net->variant, Solid::fmt($this->p['tab_width']),
            $this->model->parts($this->p)[0]['filename'], date('d.m.Y')
        ));
    }

    private function legendText(float $x, float $y, string $s): void
    {
        $this->pdf->SetFont('Helvetica', '', 7.5);
        $this->pdf->SetTextColor(...self::INK);
        $this->pdf->TextUtf8($x, $y + 1, $s);
    }

    /* -------------------------------------------------------------- Nettet */

    /**
     * Tegner nettet med transformasjonen $t = [skala, dx, dy] fra nettets
     * koordinater (mm) til arket. Flatene får en lys utgave av fargen i
     * 3D-visningen, med navn og mål.
     */
    private function drawNet(array $t, bool $dims): void
    {
        $pdf = $this->pdf;
        $colors = [];
        foreach ($this->solid->faces() as $f) {
            $colors[$f['id']] = $f['color'];
        }
        $pdf->SetLineWidth(0.1);
        foreach ($this->net->regions as $r) {
            if ($r['kind'] === 'face') {
                $pdf->SetFillHex($colors[$r['face']] ?? '#f36d21', 0.84);
            } else {
                $pdf->SetFillColor(...self::TAB);
            }
            $pdf->PathPrims($r['edges'], 'F', $t);
        }
        // Brettelinjer
        $pdf->SetDrawColor(...self::INK2);
        $pdf->SetLineWidth(0.25);
        $pdf->SetDash(2 * max(0.5, $t[0]), 1.2 * max(0.5, $t[0]));
        foreach ($this->lines['folds'] as $e) {
            $pdf->PathPrims([$e], 'D', $t, false);
        }
        $pdf->SetDash();
        // Klippelinjer
        $pdf->SetDrawColor(...self::INK);
        $pdf->SetLineWidth(0.35);
        foreach ($this->lines['cuts'] as $e) {
            $pdf->PathPrims([$e], 'D', $t, false);
        }
        // Tekst
        foreach ($this->net->regions as $r) {
            $label = $r['label'];
            if (!$label) {
                continue;
            }
            $lines = $label['lines'];
            if (!$dims && $r['kind'] === 'face') {
                $lines = [$lines[0]];
            }
            $pt = max(4.5, $label['size'] * 2.2 * min(1.0, max($t[0], 0.6)));
            $x = $label['at'][0] * $t[0] + $t[1];
            $y = $label['at'][1] * $t[0] + $t[2];
            $lh = $pt * 0.3528 * 1.25;
            $y -= ($lh * (count($lines) - 1)) / 2;
            foreach ($lines as $i => $line) {
                $pdf->SetFont('Helvetica', $i === 0 && $r['kind'] === 'face' ? 'B' : '', $i === 0 ? $pt : $pt * 0.85);
                $pdf->SetTextColor(...($r['kind'] === 'tab' ? self::INK2 : self::INK));
                $pdf->TextCentered($x, $y + $pt * 0.3528 * 0.36 + $i * $lh, $line);
            }
        }
    }
}
