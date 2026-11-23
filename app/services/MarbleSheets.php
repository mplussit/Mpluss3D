<?php
declare(strict_types=1);

/**
 * Kulespill: innhold og sideoppsett for arkene.
 *
 * Lager innholdet (tall, regnestykker, bokstaver eller symboler i hullene
 * på spillbrett A, B eller C, verdier, oppgaver og fasit) og legger det ut som A4-sider av
 * enkle tegneelementer i millimeter fra øvre venstre hjørne:
 *
 *   ['k' => 'rect',   'x', 'y', 'w', 'h', 'f' => fyll, 's' => strek, 'lw', 'd' => [på, av]]
 *   ['k' => 'line',   'x1', 'y1', 'x2', 'y2', 's', 'lw', 'd']
 *   ['k' => 'circle', 'x', 'y', 'r', 'f', 's', 'lw', 'd']
 *   ['k' => 'poly',   'p' => [[x, y], …], 'f', 's', 'lw', 'open' => true for åpen linje]
 *   ['k' => 'text',   'x', 'y' (grunnlinje), 't', 'sz' (mm), 'b' (fet), 'c' (farge), 'a' (L, C, R)]
 *   ['k' => 'group',  'm' => [a, b, c, d, e, f], 'items' => [...]]
 *       elementer i et eget koordinatsystem: (x, y) → (a·x + c·y + e, b·x + d·y + f),
 *       som SVG-attributtet transform="matrix(a b c d e f)"
 *
 * Alle sidene er liggende A4. Hullarket (160 × 250 mm) er for høyt til å stå
 * rett på en liggende side, så selve arket ligger i en gruppe som er dreid 90°
 * (toppen mot venstre). Inne i gruppen er koordinatene de samme som før
 * (toSheet(), SHEET_X og SHEET_Y), så 3D-visningen kan bruke dem direkte.
 *
 * Både PDF-en (MarblePdf) og hullarket på 3D-modellen i nettleseren
 * (public/js/sheet-svg.js) tegner nøyaktig disse elementene, så de kan
 * ikke bli forskjellige. Tekstbredder måles med FPDFs Helvetica-metrikk.
 *
 * Alt er deterministisk: samme parametere og samme frø (seed) gir samme ark.
 */
final class MarbleSheets
{
    public const PAGE_W = 210.0;
    public const PAGE_H = 297.0;
    /**
     * Arkets øvre venstre hjørne (160 × 250 mm) i hullarkets egne koordinater,
     * som er en stående A4 med arket midt på. På den liggende siden dreies dette
     * 90° (se sheetMatrix()).
     */
    public const SHEET_X = 25.0;
    public const SHEET_Y = 23.5;
    /** Der arket havner på den liggende siden: øvre venstre hjørne av det dreide arket. */
    public const SHEET_LX = 23.5;
    public const SHEET_LY = 27.0;

    private const M = 18.0;                 // marg på oppgavesidene
    private const BOTTOM = 276.0;           // innhold slutter over bunnteksten
    private const INK = '#161616';
    private const INK2 = '#5d5a55';
    private const ACCENT = '#a33f0e';
    private const ORANGE = '#f36d21';
    private const RULE = '#d9d2c4';
    private const SOFT = '#f3efe7';
    private const HOLE_FILL = '#fff3e6';
    private const PT = 25.4 / 72;           // mm per punkt

    /** Gjeldende side: alle sidene er liggende (som i M+ matematikk). */
    private float $pw = self::PAGE_W;
    private float $ph = self::PAGE_H;

    private LabelPdf $metrics;
    private array $pages = [];
    private array $warnings = [];

    public function __construct(private array $def, private array $p)
    {
        $this->metrics = new LabelPdf();
    }

    /* ================================================================ Innhold */

    /**
     * Innholdet for alle settene (ulike verdier på samme spillbrett).
     * @return list<array{holes:list<array>, calc:list<array>, find:list<array>}>
     */
    public function variants(): array
    {
        $out = [];
        for ($v = 1; $v <= $this->p['variants']; $v++) {
            $rng = new MarbleRandom(crc32($this->p['seed'] . ':' . $v));
            $holes = $this->holes($rng);
            $values = array_column($holes, 'value');
            $out[] = [
                'number' => $v,
                'holes'  => $holes,
                'calc'   => $this->p['task_calc'] && $this->usesSymbols() ? $this->calcTasks($rng, $values) : [],
                'find'   => $this->p['task_find'] ? $this->findTasks($rng, $values) : [],
            ];
        }
        return $out;
    }

    /** Bokstaver og symboler står i hullene; verdiene står på et eget ark. */
    public function usesSymbols(): bool
    {
        return in_array($this->p['content'], ['letters', 'symbols'], true);
    }

    /** Er hullarket likt for alle variantene? (Bokstaver og symboler.) */
    public function sharedHoleSheet(): bool
    {
        return $this->usesSymbols();
    }

    private function holes(MarbleRandom $rng): array
    {
        $n = count($this->def['holes']);
        $p = $this->p;
        switch ($p['content']) {
            case 'numbers':
                $vals = $this->draw($rng, $n, $p['num_min'], $p['num_max'], $p['unique']);
                return array_map(fn($v) => ['label' => (string) $v, 'value' => $v], $vals);
            case 'expressions':
                return $this->expressions($rng, $n);
            case 'letters':
                $vals = $this->draw($rng, $n, $p['value_min'], $p['value_max'], $p['unique']);
                return array_map(fn($i) => ['label' => $this->def['letters'][$i], 'value' => $vals[$i]], range(0, $n - 1));
            default:
                $vals = $this->draw($rng, $n, $p['value_min'], $p['value_max'], $p['unique']);
                return array_map(fn($i) => ['symbol' => $i, 'label' => $this->def['symbols'][$i]['name'], 'value' => $vals[$i]], range(0, $n - 1));
        }
    }

    /** n tall fra [min, max], ulike hvis $unique (validert: området har plass). */
    private function draw(MarbleRandom $rng, int $n, int $min, int $max, bool $unique): array
    {
        if (!$unique) {
            return array_map(fn() => $rng->int($min, $max), range(1, $n));
        }
        $pool = range($min, $max);
        $rng->shuffle($pool);
        return array_slice($pool, 0, $n);
    }

    /** Ni ulike regnestykker med hele, ikke-negative svar. Helst ulike svar. */
    private function expressions(MarbleRandom $rng, int $n): array
    {
        $out = [];
        $seen = [];
        $answers = [];
        for ($tries = 0; count($out) < $n && $tries < 2000; $tries++) {
            [$a, $op, $b, $ans] = $this->expression($rng);
            $key = "$a$op$b";
            if (isset($seen[$key]) || (isset($answers[$ans]) && $tries < 600)) {
                continue;
            }
            $seen[$key] = true;
            $answers[$ans] = true;
            $out[] = ['label' => $a . ' ' . self::opSign($op) . ' ' . $b, 'value' => $ans, 'op' => $op];
        }
        return $out;
    }

    /** @return array{int,string,int,int} */
    private function expression(MarbleRandom $rng): array
    {
        $M = $this->p['expr_max'];
        $op = $this->p['ops'][$rng->int(0, count($this->p['ops']) - 1)];
        switch ($op) {
            case '+':
                $a = $rng->int(1, max(1, $M - 1));
                $b = $rng->int(1, max(1, $M - $a));
                return [$a, '+', $b, $a + $b];
            case '-':
                $a = $rng->int(2, $M);
                $b = $rng->int(1, $a - 1);
                return [$a, '-', $b, $a - $b];
            case '*':
                $a = $rng->int(1, min(10, $M));
                $b = $rng->int(1, max(1, min(10, intdiv($M, $a))));
                return [$a, '*', $b, $a * $b];
            default:
                $b = $rng->int(2, 10);
                $q = $rng->int(1, min(10, $M));
                return [$b * $q, '/', $b, $q];
        }
    }

    /** Stykker som A + B – C med bokstavene/symbolene i hullene. */
    private function calcTasks(MarbleRandom $rng, array $values): array
    {
        $out = [];
        $seen = [];
        $n = count($values);
        $terms = min($this->p['calc_terms'], $n);
        for ($tries = 0; count($out) < $this->p['calc_count'] && $tries < 3000; $tries++) {
            $idx = range(0, $n - 1);
            $rng->shuffle($idx);
            $idx = array_slice($idx, 0, $terms);
            $ops = [];
            $total = $values[$idx[0]];
            $ok = true;
            for ($i = 1; $i < $terms; $i++) {
                $op = $this->p['calc_ops'][$rng->int(0, count($this->p['calc_ops']) - 1)];
                $ops[] = $op;
                $total += $op === '+' ? $values[$idx[$i]] : -$values[$idx[$i]];
                if ($this->p['no_negative'] && $total < 0) {
                    $ok = false;
                    break;
                }
            }
            $key = implode(',', $idx) . '|' . implode('', $ops);
            if (!$ok || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = ['terms' => $idx, 'ops' => $ops, 'answer' => $total];
        }
        if (count($out) < $this->p['calc_count']) {
            $this->warnings['calc'] = 'Det var ikke mulig å lage så mange ulike stykker uten negative svar. Arket har færre stykker.';
        }
        return $out;
    }

    /** «Hvilke k hull gir til sammen t?» med alle løsningene til fasiten. */
    private function findTasks(MarbleRandom $rng, array $values): array
    {
        $k = $this->p['find_size'];
        $n = count($values);
        $combos = self::combinations($n, $k);
        $out = [];
        $used = [];
        for ($tries = 0; count($out) < $this->p['find_count'] && $tries < 500; $tries++) {
            $c = $combos[$rng->int(0, count($combos) - 1)];
            $t = array_sum(array_map(fn($i) => $values[$i], $c));
            if (isset($used[$t])) {
                continue;
            }
            $used[$t] = true;
            $sol = array_values(array_filter($combos, fn($cc) => array_sum(array_map(fn($i) => $values[$i], $cc)) === $t));
            $out[] = ['target' => $t, 'solutions' => $sol];
        }
        if (count($out) < $this->p['find_count']) {
            $this->warnings['find'] = 'Verdiene gir for få ulike summer til alle «Finn hullene»-oppgavene. Arket har færre oppgaver.';
        }
        return $out;
    }

    /** @return list<list<int>> */
    public static function combinations(int $n, int $k, int $start = 0): array
    {
        if ($k === 0) {
            return [[]];
        }
        $out = [];
        for ($i = $start; $i <= $n - $k; $i++) {
            foreach (self::combinations($n, $k - 1, $i + 1) as $rest) {
                $out[] = array_merge([$i], $rest);
            }
        }
        return $out;
    }

    /** Regnetegnet slik det skrives på arket. «–» (tankestrek) har samme bredde som et siffer. */
    public static function opSign(string $op): string
    {
        return ['+' => '+', '-' => '–', '*' => '×', '/' => ':'][$op];
    }

    /* ================================================================== Sider */

    /**
     * Alle sidene i rekkefølge.
     * @return array{pages:list<array>, variants:list<array>, warnings:list<string>}
     */
    public function build(): array
    {
        $this->pages = [];
        $variants = $this->variants();
        $shared = $this->sharedHoleSheet();
        if ($shared) {
            $this->holeSheet($variants[0], null);
        }
        foreach ($variants as $v) {
            $num = count($variants) > 1 ? $v['number'] : null;
            if (!$shared) {
                $this->holeSheet($v, $num);
            }
            if ($this->usesSymbols()) {
                $this->valueSheet($v, $num);
            }
            if ($this->p['task_round'] || $v['calc'] || $v['find']) {
                $this->taskSheet($v, $num);
            }
        }
        if ($this->p['answer_key']) {
            $this->answerKey($variants);
        }
        $total = count($this->pages);
        foreach ($this->pages as $i => &$page) {
            $page['items'][] = $this->text($page['w'] - self::M, $page['h'] - 6.5, 'Side ' . ($i + 1) . " av $total", 2.3, false, '#828282', 'R');
        }
        unset($page);
        return ['pages' => $this->pages, 'variants' => $variants, 'warnings' => array_values($this->warnings)];
    }

    private function addPage(string $kind, string $label, ?int $variant, array $items): void
    {
        $items[] = $this->text(self::M, $this->ph - 6.5, $this->footerText(), 2.3, false, '#828282');
        $this->pages[] = [
            'kind' => $kind, 'label' => $label, 'variant' => $variant,
            'o' => $this->pw > $this->ph ? 'L' : 'P', 'w' => $this->pw, 'h' => $this->ph,
            'items' => $items,
        ];
    }

    /** Liggende A4 (alle sidene); $landscape = false gir stående mål. */
    private function orient(bool $landscape): void
    {
        [$this->pw, $this->ph] = $landscape ? [self::PAGE_H, self::PAGE_W] : [self::PAGE_W, self::PAGE_H];
    }

    /** Innhold slutter over bunnteksten. */
    private function bottom(): float
    {
        return $this->ph - 21.0;
    }

    private function footerText(): string
    {
        $c = $this->def['contents'][$this->p['content']];
        return "M+ 3D · Kulespill {$this->p['layout']} · $c · frø {$this->p['seed']}";
    }

    /* -------------------------------------------------------------- Hullark */

    /**
     * Transformasjonen fra hullarkets egne koordinater til den liggende siden:
     * arket dreies 90° med toppen mot venstre, og øvre venstre hjørne av det
     * dreide arket havner i (SHEET_LX, SHEET_LY).
     * @return array{float,float,float,float,float,float}
     */
    public function sheetMatrix(): array
    {
        $W = $this->def['board']['width'];
        return [0.0, -1.0, 1.0, 0.0, self::SHEET_LX - self::SHEET_Y, self::SHEET_LY + $W + self::SHEET_X];
    }

    /** Arket som legges under spillet: 160 × 250 mm med innholdet over hullene, dreid 90° på en liggende side. */
    private function holeSheet(array $v, ?int $num): void
    {
        $this->orient(true);
        $page = [];
        $items = [];
        $x0 = self::SHEET_X;
        $y0 = self::SHEET_Y;
        $W = $this->def['board']['width'];
        $L = $this->def['board']['length'];
        $board = $this->p['layout'];
        $title = "Kulespill $board · Hullark" . ($num ? " · Sett $num" : ($this->p['variants'] > 1 ? ' · alle sett' : ''));
        // Topptekst på den liggende siden (ikke dreid).
        $lx = self::SHEET_LX;
        array_push($page, ...$this->badge($lx, 5.0, 9.0));
        $page[] = $this->text($lx + 12, 13.5, $title, 3.9, true, self::INK);
        $page[] = $this->text($lx + $L, 13.5, 'Skriv ut i 100 % (faktisk størrelse) · klipp langs hjørnemerkene: 160 × 250 mm', 2.6, false, self::INK2, 'R');
        $page[] = $this->text($lx + $L, 18.0, 'Toppen av spillet er mot venstre', 2.6, false, self::INK2, 'R');

        // Skjærelinje (svak) og hjørnemerker.
        $items[] = ['k' => 'rect', 'x' => $x0, 'y' => $y0, 'w' => $W, 'h' => $L, 's' => self::RULE, 'lw' => 0.2, 'd' => [1.5, 1.5]];
        foreach ([[$x0, $y0, -1, -1], [$x0 + $W, $y0, 1, -1], [$x0, $y0 + $L, -1, 1], [$x0 + $W, $y0 + $L, 1, 1]] as [$cx, $cy, $sx, $sy]) {
            $items[] = ['k' => 'line', 'x1' => $cx + $sx * 2, 'y1' => $cy, 'x2' => $cx + $sx * 9, 'y2' => $cy, 's' => self::INK, 'lw' => 0.25];
            $items[] = ['k' => 'line', 'x1' => $cx, 'y1' => $cy + $sy * 2, 'x2' => $cx, 'y2' => $cy + $sy * 9, 's' => self::INK, 'lw' => 0.25];
        }

        // Omrisset av spillets runde topp (orientering; ligger under spillet).
        [$ax, $ay] = $this->toSheet(0, 0);
        $r = $this->def['board']['arc_r'];
        $arc = [];
        for ($i = 0; $i <= 48; $i++) {
            $a = M_PI + M_PI * $i / 48;
            $arc[] = [$ax + $r * cos($a), $ay + $r * sin($a)];
        }
        $items[] = ['k' => 'poly', 'p' => $arc, 's' => self::RULE, 'lw' => 0.2, 'd' => [0.6, 1.2], 'open' => true];
        $items[] = $this->text($ax, $y0 + 9, 'TOPP', 2.6, true, self::RULE, 'C');

        $R = $this->def['hole_diameter'] / 2;
        // Samme tekststørrelse i alle hullene: den største som får plass i alle.
        $size = min(array_map(fn($h) => isset($h['symbol']) ? 99.0 : $this->fitSize($h['label']), $v['holes']));
        if ($size < 3.2) {
            $this->warnings['small'] = 'Noen regnestykker er så lange at teksten i hullene blir liten.';
        }
        foreach ($this->def['holes'] as $i => [$hx, $hy]) {
            [$cx, $cy] = $this->toSheet($hx, $hy);
            $items[] = ['k' => 'circle', 'x' => $cx, 'y' => $cy, 'r' => $R + 1.5, 'f' => self::HOLE_FILL];
            $items[] = ['k' => 'circle', 'x' => $cx, 'y' => $cy, 'r' => $R, 's' => '#e6c9a8', 'lw' => 0.2, 'd' => [0.8, 0.8]];
            array_push($items, ...$this->holeContent($v['holes'][$i], $cx, $cy, $size));
        }

        // Bokstaven til brettet nederst til høyre på arket, under utskytingsbanen,
        // så arket kan kjennes igjen når det er klippet ut.
        $items[] = $this->text($x0 + $W - 10, $y0 + $L - 5, $board, 9.0, true, self::RULE, 'C');

        $note = $num ? "Sett $num" : ($this->sharedHoleSheet() && $this->p['variants'] > 1 ? 'Samme hullark for alle settene' : '');
        if ($note !== '') {
            $items[] = $this->text($x0 + 4, $y0 + $L - 4, $note, 2.4, false, '#a8a196');
        }

        // Selve arket, dreid 90° på siden.
        $page[] = ['k' => 'group', 'm' => $this->sheetMatrix(), 'items' => $items];

        // Kontrollinje på 100 mm (ikke dreid).
        $rx = $lx;
        $ry = 202.0;
        $page[] = ['k' => 'line', 'x1' => $rx, 'y1' => $ry, 'x2' => $rx + 100, 'y2' => $ry, 's' => self::INK, 'lw' => 0.2];
        for ($mm = 0; $mm <= 100; $mm += 5) {
            $len = $mm % 10 === 0 ? 3.0 : 1.8;
            $page[] = ['k' => 'line', 'x1' => $rx + $mm, 'y1' => $ry, 'x2' => $rx + $mm, 'y2' => $ry - $len, 's' => self::INK, 'lw' => $mm % 10 === 0 ? 0.25 : 0.15];
        }
        $page[] = $this->text($rx + 104, $ry, 'Kontroll: skal være nøyaktig 100 mm', 2.5, false, self::INK2);

        $label = 'Hullark' . ($num ? " $num" : '');
        $this->pages[] = ['kind' => 'holes', 'label' => $label, 'variant' => $num, 'o' => 'L', 'w' => $this->pw, 'h' => $this->ph, 'items' => $page];
    }

    /** Spillkoordinater (mm) → punkt på A4. */
    public function toSheet(float $x, float $y): array
    {
        return [
            self::SHEET_X + $x + $this->def['board']['width'] / 2,
            self::SHEET_Y + $this->def['board']['top_y'] - $y,
        ];
    }

    /** Største skriftstørrelse (mm) der teksten (bredde × versalhøyde 0,718 em) ligger innenfor content_diameter. */
    public function fitSize(string $s): float
    {
        $Rc = $this->def['content_diameter'] / 2;
        $em = $this->tw($s, 1.0, true);
        return min(12.0, $Rc / sqrt(($em / 2) ** 2 + 0.359 ** 2));
    }

    /** Innholdet i ett hull, innenfor content_diameter. */
    private function holeContent(array $hole, float $cx, float $cy, float $size): array
    {
        if (isset($hole['symbol'])) {
            return $this->symbol($hole['symbol'], $cx, $cy, $this->def['content_diameter'] / 2 * 0.86);
        }
        return [$this->text($cx, $cy + 0.359 * $size, $hole['label'], $size, true, self::INK, 'C')];
    }

    /* ------------------------------------------------------------- Verdiark */

    private function valueSheet(array $v, ?int $num): void
    {
        $this->orient(true);
        $what = $this->p['content'] === 'letters' ? 'bokstav' : 'symbol';
        [$items, $y] = $this->header('Verdiark', $num,
            "Hvert $what i kulespillet har en verdi. Bruk verdiene når du regner ut poengene og løser oppgavene.");
        $cw = 54.0;
        $ch = 34.0;
        $gap = ($this->pw - 2 * self::M - 3 * $cw) / 2;
        $y += 2;
        foreach ($v['holes'] as $i => $h) {
            $x = self::M + ($i % 3) * ($cw + $gap);
            $yy = $y + intdiv($i, 3) * ($ch + 6);
            $items[] = ['k' => 'rect', 'x' => $x, 'y' => $yy, 'w' => $cw, 'h' => $ch, 'f' => '#fffdf9', 's' => self::INK, 'lw' => 0.35];
            $cx = $x + 15;
            $cy = $yy + $ch / 2;
            if (isset($h['symbol'])) {
                array_push($items, ...$this->symbol($h['symbol'], $cx, $cy, 9.5));
            } else {
                $items[] = $this->text($cx, $cy + 0.359 * 17, $h['label'], 17, true, self::INK, 'C');
            }
            $vs = min(12.0, 12.0 * ($cw - 32) / $this->tw('= ' . $h['value'], 12.0, true));
            $items[] = $this->text($x + 29, $cy + 0.359 * $vs, '= ' . $h['value'], $vs, true, self::INK);
        }
        $y += intdiv(count($v['holes']) + 2, 3) * ($ch + 6) + 4;
        $items[] = $this->text(self::M, $y, 'Tips: Heng opp verdiarket eller legg det ved siden av spillet. Klipp gjerne ut kortene.', 3.3, false, self::INK2);
        $this->addPage('values', 'Verdiark' . ($num ? " $num" : ''), $num, $items);
    }

    /* ------------------------------------------------------------- Oppgaver */

    private function taskSheet(array $v, ?int $num): void
    {
        $this->orient(true);
        [$items, $y] = $this->header('Oppgaver', $num, $this->taskLead());
        $items[] = $this->text($this->pw - self::M - 62, 25, 'Navn:', 3.5, false, self::INK2);
        $items[] = ['k' => 'line', 'x1' => $this->pw - self::M - 51, 'y1' => 25.6, 'x2' => $this->pw - self::M, 'y2' => 25.6, 's' => self::INK2, 'lw' => 0.25];
        $section = 0;
        $label = 'Oppgaver' . ($num ? " $num" : '');

        // To spalter på den liggende siden: fyll venstre spalte, så høyre, så ny side.
        $gap = 12.0;
        $cw = ($this->pw - 2 * self::M - $gap) / 2;
        $x0 = self::M;
        $top = $y;
        $ensure = function (float $need) use (&$items, &$y, &$x0, &$top, $num, $label, $cw, $gap) {
            if ($y + $need <= $this->bottom()) {
                return;
            }
            if ($x0 === self::M) {
                $x0 = self::M + $cw + $gap;
                $y = $top;
                return;
            }
            $this->addPage('tasks', $label, $num, $items);
            [$items, $y] = $this->header('Oppgaver (fortsatt)', $num, '');
            $x0 = self::M;
            $top = $y;
        };

        if ($this->p['task_round']) {
            $section++;
            $n = $this->p['balls'];
            $ensure(14 + ($n + 2) * 8.5);
            $items[] = $this->text($x0, $y, "$section · Kulerunde", 4.2, true, self::INK);
            $y += 5.5;
            foreach ($this->wrap($this->roundText(), 3.5, $cw) as $line) {
                $items[] = $this->text($x0, $y, $line, 3.5, false, self::INK);
                $y += 4.8;
            }
            $y += 2;
            $tw = ($cw - 8) / 2;
            foreach ([0, 1] as $t) {
                array_push($items, ...$this->roundTable($x0 + $t * ($tw + 8), $y, $tw, 'Runde ' . ($t + 1)));
            }
            $y += 6 + ($n + 2) * 8.5 + 8;
        }

        if ($v['calc']) {
            $section++;
            $ensure(30);
            $what = $this->p['content'] === 'letters' ? 'hver bokstav' : 'hvert symbol';
            $items[] = $this->text($x0, $y, "$section · Regn med verdiene", 4.2, true, self::INK);
            $y += 5.5;
            $items[] = $this->text($x0, $y, "Skriv verdien under $what, og regn ut svaret.", 3.5, false, self::INK);
            $y += 7;
            $w = $this->calcWidth($this->p['calc_terms']);
            $cols = 2 * $w + 10 <= $cw ? 2 : 1;
            foreach (array_chunk($v['calc'], $cols) as $ri => $row) {
                $ensure(19);
                foreach ($row as $ci => $c) {
                    $i = $ri * $cols + $ci;
                    $x = $x0 + $ci * (($cw) / $cols);
                    array_push($items, ...$this->calcItem($x, $y, chr(97 + $i) . ')', $c, $v['holes']));
                }
                $y += 19;
            }
            $y += 4;
        }

        if ($v['find']) {
            $section++;
            $ensure(26);
            $items[] = $this->text($x0, $y, "$section · Finn hullene", 4.2, true, self::INK);
            $y += 5.5;
            foreach ($this->wrap($this->findText(), 3.5, $cw) as $line) {
                $items[] = $this->text($x0, $y, $line, 3.5, false, self::INK);
                $y += 4.8;
            }
            $y += 4;
            $fw = 7 + 20 * $this->p['find_size'] + 12;     // a)  [ ] + [ ] = 17
            $cols = 2 * $fw + 6 <= $cw ? 2 : 1;
            foreach (array_chunk($v['find'], $cols) as $ri => $row) {
                $ensure(14);
                foreach ($row as $ci => $f) {
                    $i = $ri * $cols + $ci;
                    $x = $x0 + $ci * (($cw) / $cols);
                    array_push($items, ...$this->findItem($x, $y, chr(97 + $i) . ')', $f['target']));
                }
                $y += 14;
            }
        }
        $this->addPage('tasks', $label, $num, $items);
    }

    private function taskLead(): string
    {
        return match ($this->p['content']) {
            'numbers'     => 'Legg hullarket i bunnen og sett kulespillet oppå. Tallet i hullet er poengene kula gir.',
            'expressions' => 'Legg hullarket i bunnen og sett kulespillet oppå. Regn ut stykket i hullet kula havner i. Svaret er poengene.',
            'letters'     => 'Legg hullarket i bunnen og sett kulespillet oppå. Finn verdien til bokstaven på verdiarket.',
            default       => 'Legg hullarket i bunnen og sett kulespillet oppå. Finn verdien til symbolet på verdiarket.',
        };
    }

    private function roundText(): string
    {
        $n = $this->p['balls'];
        return match ($this->p['content']) {
            'numbers'     => "Skyt $n kuler. Skriv tallet i hullet hver kule havner i. Regn ut summen.",
            'expressions' => "Skyt $n kuler. Skriv regnestykket i hullet hver kule havner i, og regn ut svaret. Legg sammen svarene.",
            'letters'     => "Skyt $n kuler. Skriv bokstaven i hullet hver kule havner i, og verdien fra verdiarket. Legg sammen verdiene.",
            default       => "Skyt $n kuler. Tegn symbolet i hullet hver kule havner i, og skriv verdien fra verdiarket. Legg sammen verdiene.",
        };
    }

    private function findText(): string
    {
        $k = $this->p['find_size'] === 2 ? 'to' : 'tre';
        return match ($this->p['content']) {
            'numbers'     => "Hvilke $k tall i kulespillet gir til sammen summen? Skriv tallene i rutene.",
            'expressions' => "Hvilke $k regnestykker har svar som til sammen gir summen? Skriv svarene i rutene.",
            'letters'     => "Hvilke $k bokstaver har verdier som til sammen gir summen? Skriv bokstavene i rutene.",
            default       => "Hvilke $k symboler har verdier som til sammen gir summen? Tegn symbolene i rutene.",
        };
    }

    private function roundTable(float $x, float $y, float $w, string $title): array
    {
        $items = [$this->text($x, $y + 3.5, $title, 3.5, true, self::INK)];
        $y += 6;
        $rh = 8.5;
        $cols = match ($this->p['content']) {
            'numbers'     => [['Kule', 0.22], ['Tall', 0.78]],
            'expressions' => [['Kule', 0.18], ['Regnestykke', 0.48], ['Svar', 0.34]],
            'letters'     => [['Kule', 0.18], ['Bokstav', 0.4], ['Verdi', 0.42]],
            default       => [['Kule', 0.18], ['Symbol', 0.4], ['Verdi', 0.42]],
        };
        $n = $this->p['balls'];
        $items[] = ['k' => 'rect', 'x' => $x, 'y' => $y, 'w' => $w, 'h' => $rh, 'f' => self::SOFT];
        $cx = $x;
        foreach ($cols as [$name, $frac]) {
            $items[] = $this->text($cx + 2, $y + 5.8, $name, 3.1, true, self::INK);
            $cx += $w * $frac;
        }
        for ($r = 1; $r <= $n; $r++) {
            $items[] = $this->text($x + $w * $cols[0][1] / 2, $y + $r * $rh + 5.8, (string) $r, 3.3, false, self::INK2, 'C');
        }
        $sumY = $y + ($n + 1) * $rh;
        $items[] = $this->text($x + 2, $sumY + 5.8, 'Sum', 3.3, true, self::INK);
        // Rammer og linjer.
        $items[] = ['k' => 'rect', 'x' => $x, 'y' => $y, 'w' => $w, 'h' => ($n + 2) * $rh, 's' => self::INK, 'lw' => 0.3];
        for ($r = 1; $r <= $n + 1; $r++) {
            $items[] = ['k' => 'line', 'x1' => $x, 'y1' => $y + $r * $rh, 'x2' => $x + $w, 'y2' => $y + $r * $rh,
                's' => $r === $n + 1 ? self::INK : self::RULE, 'lw' => $r === $n + 1 ? 0.45 : 0.2];
        }
        $cx = $x;
        foreach (array_slice($cols, 0, -1) as [, $frac]) {
            $cx += $w * $frac;
            $items[] = ['k' => 'line', 'x1' => $cx, 'y1' => $y, 'x2' => $cx, 'y2' => $sumY, 's' => self::RULE, 'lw' => 0.2];
        }
        return $items;
    }

    private const TERM_W = 13.0;
    private const OP_W = 6.0;

    private function calcWidth(int $terms): float
    {
        return 7 + $terms * self::TERM_W + ($terms - 1) * self::OP_W + self::OP_W + 18;
    }

    /** a)  A + B – C = [  ] med en rute under hvert ledd. */
    private function calcItem(float $x, float $y, string $tag, array $c, array $holes): array
    {
        $items = [$this->text($x, $y + 1.5, $tag, 3.5, true, self::INK2)];
        $cx = $x + 7;
        $mid = $y;                          // midtlinja til bokstavene/symbolene
        foreach ($c['terms'] as $i => $h) {
            $tc = $cx + self::TERM_W / 2;
            if (isset($holes[$h]['symbol'])) {
                array_push($items, ...$this->symbol($holes[$h]['symbol'], $tc, $mid, 3.6));
            } else {
                $items[] = $this->text($tc, $mid + 0.359 * 6, $holes[$h]['label'], 6, true, self::INK, 'C');
            }
            $items[] = ['k' => 'rect', 'x' => $tc - 5.5, 'y' => $mid + 5, 'w' => 11, 'h' => 8, 's' => self::INK2, 'lw' => 0.25];
            $cx += self::TERM_W;
            $sign = $i < count($c['ops']) ? self::opSign($c['ops'][$i]) : '=';
            $items[] = $this->text($cx + self::OP_W / 2, $mid + 0.359 * 5, $sign, 5, true, self::INK, 'C');
            $cx += self::OP_W;
        }
        $items[] = ['k' => 'rect', 'x' => $cx + 1, 'y' => $mid - 4.5, 'w' => 16, 'h' => 9, 's' => self::INK, 'lw' => 0.35];
        return $items;
    }

    /** a)  [  ] + [  ] = 13 */
    private function findItem(float $x, float $y, string $tag, int $target): array
    {
        $items = [$this->text($x, $y + 1.5, $tag, 3.5, true, self::INK2)];
        $cx = $x + 7;
        for ($i = 0; $i < $this->p['find_size']; $i++) {
            $items[] = ['k' => 'rect', 'x' => $cx, 'y' => $y - 4.5, 'w' => 13, 'h' => 9, 's' => self::INK, 'lw' => 0.35];
            $cx += 13;
            $sign = $i < $this->p['find_size'] - 1 ? '+' : '=';
            $items[] = $this->text($cx + 3.5, $y + 0.359 * 5, $sign, 5, true, self::INK, 'C');
            $cx += 7;
        }
        $items[] = $this->text($cx + 1, $y + 0.359 * 5.5, (string) $target, 5.5, true, self::INK);
        return $items;
    }

    /* ---------------------------------------------------------------- Fasit */

    private function answerKey(array $variants): void
    {
        $this->orient(true);
        [$items, $y] = $this->header('Fasit', null, 'For læreren. Verdiene i hullene, svarene på stykkene og alle løsningene på «Finn hullene».');
        // To spalter på den liggende siden, som oppgavearket.
        $gap = 12.0;
        $cw = ($this->pw - 2 * self::M - $gap) / 2;
        $x0 = self::M;
        $top = $y;
        $flush = function (float $need) use (&$items, &$y, &$x0, &$top, $cw, $gap) {
            if ($y + $need <= $this->bottom()) {
                return;
            }
            if ($x0 === self::M) {
                $x0 = self::M + $cw + $gap;
                $y = $top;
                return;
            }
            $this->addPage('key', 'Fasit', null, $items);
            [$items, $y] = $this->header('Fasit (fortsatt)', null, '');
            $x0 = self::M;
            $top = $y;
        };
        $W = $cw;
        foreach ($variants as $v) {
            $flush(30);
            if (count($variants) > 1) {
                $items[] = $this->text($x0, $y, 'Sett ' . $v['number'], 4.2, true, self::ACCENT);
                $y += 6.5;
            }
            // Hullene i tre kolonner, slik de står på arket.
            $items[] = $this->text($x0, $y, 'Hullene', 3.5, true, self::INK);
            $y += 5.5;
            foreach (array_chunk($v['holes'], 3) as $row) {
                $flush(6);
                foreach ($row as $ci => $h) {
                    array_push($items, ...$this->keyHole($x0 + $ci * ($W / 3), $y, $h));
                }
                $y += 6;
            }
            $y += 2;
            if ($v['calc']) {
                $flush(12);
                $items[] = $this->text($x0, $y, 'Regn med verdiene', 3.5, true, self::INK);
                $y += 5.5;
                $texts = array_map(fn($c, $i) => chr(97 + $i) . ')  ' . $this->calcAnswerText($c, $v['holes']), $v['calc'], array_keys($v['calc']));
                $widest = max(array_map(fn($t) => $this->tw($t, 3.3), $texts));
                $cols = 2 * $widest + 4 <= $W ? 2 : 1;
                foreach (array_chunk($texts, $cols) as $row) {
                    $flush(6);
                    foreach ($row as $ci => $s) {
                        $items[] = $this->text($x0 + $ci * ($W / 2), $y, $s, 3.3, false, self::INK);
                    }
                    $y += 5.5;
                }
                $y += 2;
            }
            if ($v['find']) {
                $flush(12);
                $items[] = $this->text($x0, $y, 'Finn hullene (alle løsninger)', 3.5, true, self::INK);
                $y += 5.5;
                foreach ($v['find'] as $i => $f) {
                    $sols = array_map(fn($sol) => implode(' + ', array_map(fn($h) => $this->holeName($v['holes'][$h]), $sol)), $f['solutions']);
                    $lines = $this->wrap(chr(97 + $i) . ')  ' . $f['target'] . ':  ' . implode('   eller   ', $sols), 3.3, $W);
                    foreach ($lines as $li => $line) {
                        $flush(5.5);
                        $items[] = $this->text($x0 + ($li ? 6 : 0), $y, $line, 3.3, false, self::INK);
                        $y += 5;
                    }
                }
            }
            $y += 6;
        }
        $this->addPage('key', 'Fasit', null, $items);
    }

    private function keyHole(float $x, float $y, array $h): array
    {
        if (isset($h['symbol'])) {
            return [...$this->symbol($h['symbol'], $x + 2.4, $y - 1.2, 2.4),
                $this->text($x + 6.5, $y, $h['label'] . ' = ' . $h['value'], 3.3, false, self::INK)];
        }
        $s = match ($this->p['content']) {
            'numbers'     => $h['label'],
            'expressions' => $h['label'] . ' = ' . $h['value'],
            default       => $h['label'] . ' = ' . $h['value'],
        };
        return [$this->text($x, $y, $s, 3.3, false, self::INK)];
    }

    private function holeName(array $h): string
    {
        return $this->p['content'] === 'expressions' ? (string) $h['value'] : $h['label'];
    }

    private function calcAnswerText(array $c, array $holes): string
    {
        $names = '';
        $vals = '';
        foreach ($c['terms'] as $i => $h) {
            $sep = $i ? ' ' . self::opSign($c['ops'][$i - 1]) . ' ' : '';
            $names .= $sep . $holes[$h]['label'];
            $vals .= $sep . $holes[$h]['value'];
        }
        return "$names = $vals = {$c['answer']}";
    }

    /* ------------------------------------------------------------- Felles */

    /** Topptekst, tittel og ingress som de andre PDF-ene. @return array{0:list<array>,1:float} */
    private function header(string $title, ?int $num, string $lead): array
    {
        $tx = self::M + 14;
        $items = [
            ...$this->badge(self::M, 17.5, 10.5),
            $this->text($tx, 13, 'M+ 3D  ·  KULESPILL ' . $this->p['layout'], 2.82, true, self::ACCENT),
            $this->text($tx, 25, $title, 7.76, true, self::INK),
            ['k' => 'rect', 'x' => $tx, 'y' => 28.5, 'w' => 14, 'h' => 1.2, 'f' => self::ORANGE],
        ];
        if ($num) {
            $items[] = $this->text($this->pw - self::M, 13, "Sett $num", 2.82, true, self::INK2, 'R');
        }
        $y = 36.5;
        if ($lead !== '') {
            foreach ($this->wrap($lead, 3.7, $this->pw - 2 * self::M) as $line) {
                $items[] = $this->text(self::M, $y, $line, 3.7, false, self::INK2);
                $y += 5;
            }
            $y += 5;
        }
        return [$items, $y];
    }

    /** Merket med bokstaven til spillbrettet (A, B eller C): oransje rute med hvit bokstav. */
    private function badge(float $x, float $y, float $size): array
    {
        $fs = $size * 0.72;
        return [
            ['k' => 'rect', 'x' => $x, 'y' => $y, 'w' => $size, 'h' => $size, 'f' => self::ORANGE],
            $this->text($x + $size / 2, $y + $size / 2 + 0.359 * $fs, $this->p['layout'], $fs, true, '#ffffff', 'C'),
        ];
    }

    private function text(float $x, float $y, string $t, float $size, bool $bold, string $color, string $align = 'L'): array
    {
        return ['k' => 'text', 'x' => $x, 'y' => $y, 't' => $t, 'sz' => $size, 'b' => $bold, 'c' => $color, 'a' => $align];
    }

    /** Tekstbredde i mm (Helvetica, størrelse i mm). */
    public function tw(string $s, float $size, bool $bold = false): float
    {
        $this->metrics->SetFont('Helvetica', $bold ? 'B' : '', $size / self::PT);
        return $this->metrics->GetStringWidth(LabelPdf::enc($s));
    }

    /** @return list<string> */
    private function wrap(string $text, float $size, float $width): array
    {
        $lines = [];
        $line = '';
        foreach (preg_split('/\s+/u', trim($text)) as $word) {
            $try = $line === '' ? $word : "$line $word";
            if ($line !== '' && $this->tw($try, $size) > $width) {
                $lines[] = $line;
                $line = $word;
            } else {
                $line = $try;
            }
        }
        if ($line !== '') {
            $lines[] = $line;
        }
        return $lines;
    }

    /* --------------------------------------------------------------- Symboler */

    /** Symbol nummer $i med sentrum (cx, cy) innenfor radius r. */
    public function symbol(int $i, float $cx, float $cy, float $r): array
    {
        $sym = $this->def['symbols'][$i];
        $fill = $sym['color'];
        $poly = fn(array $pts) => [['k' => 'poly', 'p' => array_map(fn($q) => [$cx + $q[0], $cy + $q[1]], $pts), 'f' => $fill]];
        $ring = function (int $n, float $rad, float $a0) {
            $pts = [];
            for ($k = 0; $k < $n; $k++) {
                $a = $a0 + 2 * M_PI * $k / $n;
                $pts[] = [$rad * cos($a), $rad * sin($a)];
            }
            return $pts;
        };
        switch ($sym['key']) {
            case 'star':
                $pts = [];
                for ($k = 0; $k < 10; $k++) {
                    $a = -M_PI / 2 + M_PI * $k / 5;
                    $rad = $k % 2 ? $r * 0.42 : $r;
                    $pts[] = [$rad * cos($a), $rad * sin($a) + $r * 0.08];
                }
                return $poly($pts);
            case 'circle':
                return [['k' => 'circle', 'x' => $cx, 'y' => $cy, 'r' => $r * 0.82, 'f' => $fill]];
            case 'triangle':
                return $poly(array_map(fn($q) => [$q[0], $q[1] + $r * 0.18], $ring(3, $r, -M_PI / 2)));
            case 'square':
                $h = $r * 0.7;
                return $poly([[-$h, -$h], [$h, -$h], [$h, $h], [-$h, $h]]);
            case 'diamond':
                return $poly([[0, -$r], [$r * 0.68, 0], [0, $r], [-$r * 0.68, 0]]);
            case 'hexagon':
                return $poly($ring(6, $r * 0.88, 0));
            case 'cross':
                $a = $r * 0.3;
                $b = $r * 0.88;
                return $poly([[-$a, -$b], [$a, -$b], [$a, -$a], [$b, -$a], [$b, $a], [$a, $a], [$a, $b], [-$a, $b], [-$a, $a], [-$b, $a], [-$b, -$a], [-$a, -$a]]);
            case 'heart':
                $pts = [];
                $s = $r / 16.5;
                for ($k = 0; $k < 64; $k++) {
                    $t = 2 * M_PI * $k / 64;
                    $x = 16 * sin($t) ** 3;
                    $y = 13 * cos($t) - 5 * cos(2 * $t) - 2 * cos(3 * $t) - cos(4 * $t);
                    $pts[] = [$x * $s, -($y + 2.5) * $s];
                }
                return $poly($pts);
            default:    // måne: ytre sirkel minus en forskjøvet sirkel
                $R1 = $r * 0.9;
                $R2 = $r * 0.74;
                $d = $r * 0.46;
                $ix = ($d * $d + $R1 * $R1 - $R2 * $R2) / (2 * $d);
                $iy = sqrt(max(0, $R1 * $R1 - $ix * $ix));
                $t1 = atan2($iy, $ix);
                $p1 = atan2($iy, $ix - $d);
                $pts = [];
                for ($k = 0; $k <= 40; $k++) {
                    $a = $t1 + (2 * M_PI - 2 * $t1) * $k / 40;
                    $pts[] = [$R1 * cos($a) + $r * 0.12, $R1 * sin($a)];
                }
                for ($k = 0; $k <= 40; $k++) {
                    $a = (2 * M_PI - $p1) - (2 * M_PI - 2 * $p1) * $k / 40;
                    $pts[] = [$d + $R2 * cos($a) + $r * 0.12, $R2 * sin($a)];
                }
                return $poly($pts);
        }
    }
}

/**
 * Liten, deterministisk tallgenerator (xorshift32), så samme frø gir samme ark
 * uansett PHP-versjon.
 */
final class MarbleRandom
{
    private int $s;

    public function __construct(int $seed)
    {
        $this->s = ($seed & 0xFFFFFFFF) ?: 0x9E3779B9;
        for ($i = 0; $i < 4; $i++) {
            $this->next();
        }
    }

    public function next(): int
    {
        $x = $this->s;
        $x ^= ($x << 13) & 0xFFFFFFFF;
        $x ^= $x >> 17;
        $x ^= ($x << 5) & 0xFFFFFFFF;
        return $this->s = $x & 0xFFFFFFFF;
    }

    /** Heltall i [min, max]. */
    public function int(int $min, int $max): int
    {
        if ($max <= $min) {
            return $min;
        }
        return $min + $this->next() % ($max - $min + 1);
    }

    public function shuffle(array &$a): void
    {
        for ($i = count($a) - 1; $i > 0; $i--) {
            $j = $this->int(0, $i);
            [$a[$i], $a[$j]] = [$a[$j], $a[$i]];
        }
    }
}
