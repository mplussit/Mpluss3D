<?php
declare(strict_types=1);

/**
 * Oppgaver til base 10-PDF-en, tilpasset settet læreren har valgt.
 *
 * Settet beskrives med antall klosser per plassverdi:
 *   [1 => enere, 10 => tiere, 100 => hundrere, 1000 => tusenere]
 *
 * Ingen oppgaver er tilfeldige. Kandidatene står i faste lister, og hver
 * kandidat kontrolleres mot settet:
 *   - Bygg et tall: hvert siffer ≤ antall klosser av den typen.
 *   - Addisjon: begge tallene ligger på bordet samtidig, og vekslingen
 *     (10 enere → 1 tier osv.) må ha en ekstra kloss å bytte til.
 *   - Subtraksjon: tallet bygges, og vekslingen (1 tier → 10 enere osv.)
 *     simuleres steg for steg, så det alltid finnes nok klosser å bytte til.
 * Oppgaver som ikke kan løses med klossene, brukes bare som papiroppgaver
 * og merkes tydelig (paper = true).
 */
final class Base10Tasks
{
    public const PLACES = [1000, 100, 10, 1];

    /** @var array<int,int> */
    private array $c;

    /** @param array<int,int> $countsByValue [1 => …, 10 => …, 100 => …, 1000 => …] */
    public function __construct(array $countsByValue)
    {
        foreach (self::PLACES as $v) {
            $this->c[$v] = max(0, (int) ($countsByValue[$v] ?? 0));
        }
    }

    public function count(int $value): int
    {
        return $this->c[$value];
    }

    public function has(int $value): bool
    {
        return $this->c[$value] > 0;
    }

    /** @return array<int,int> [1000 => a, 100 => b, 10 => c, 1 => d] */
    public static function digits(int $n): array
    {
        return [1000 => intdiv($n, 1000) % 10, 100 => intdiv($n, 100) % 10, 10 => intdiv($n, 10) % 10, 1 => $n % 10];
    }

    /** Kan tallet bygges med færrest mulig klosser (ett siffer per plass)? */
    public function canBuild(int $n): bool
    {
        if ($n < 0 || $n > 9999) {
            return false;
        }
        foreach (self::digits($n) as $v => $d) {
            if ($d > $this->c[$v]) {
                return false;
            }
        }
        return true;
    }

    /** Største tall som kan bygges med ett siffer per plass. */
    public function largestCanonical(): int
    {
        $n = 0;
        foreach (self::PLACES as $v) {
            $n += min(9, $this->c[$v]) * $v;
        }
        return $n;
    }

    /** Kan en bestemt sammensetning (antall per plassverdi) legges med klossene? */
    public function canLay(array $rep): bool
    {
        foreach ($rep as $v => $n) {
            if ($n > ($this->c[$v] ?? 0)) {
                return false;
            }
        }
        return true;
    }

    public static function value(array $rep): int
    {
        $s = 0;
        foreach ($rep as $v => $n) {
            $s += $v * $n;
        }
        return $s;
    }

    /**
     * a + b med klosser: begge tallene legges ut, så veksles 10 av en type mot
     * 1 av neste. Krever at hver type har nok klosser til tallene pluss menten.
     */
    public function canAdd(int $a, int $b): bool
    {
        if ($a + $b > 9999) {
            return false;
        }
        $da = self::digits($a);
        $db = self::digits($b);
        $carry = 0;
        foreach ([1, 10, 100, 1000] as $v) {
            $onTable = $da[$v] + $db[$v] + $carry;
            if ($onTable > $this->c[$v]) {
                return false;
            }
            $carry = $onTable >= 10 ? 1 : 0;
        }
        return true;
    }

    /** Trenger a + b veksling? */
    public static function addNeedsExchange(int $a, int $b): bool
    {
        $da = self::digits($a);
        $db = self::digits($b);
        foreach (self::PLACES as $v) {
            if ($da[$v] + $db[$v] >= 10) {
                return true;
            }
        }
        return false;
    }

    /**
     * a − b med klosser: a bygges, og b tas bort plass for plass fra enerne.
     * Mangler det klosser, veksles én kloss fra nærmeste høyere plass med
     * klosser til 10 av typen under (eventuelt i flere steg). Krever at
     * klossene det veksles til, finnes i settet.
     */
    public function canSubtract(int $a, int $b): bool
    {
        if ($b > $a || !$this->canBuild($a)) {
            return false;
        }
        $table = self::digits($a);
        $db = self::digits($b);
        foreach ([1, 10, 100, 1000] as $v) {
            if ($table[$v] < $db[$v]) {
                // finn nærmeste høyere plass med klosser
                $h = $v * 10;
                while ($h <= 1000 && $table[$h] === 0) {
                    $h *= 10;
                }
                if ($h > 1000) {
                    return false;
                }
                // veksle nedover til plass v
                while ($h > $v) {
                    $table[$h]--;
                    $table[$h / 10] += 10;
                    if ($table[$h / 10] > $this->c[$h / 10]) {
                        return false;
                    }
                    $h /= 10;
                }
            }
            $table[$v] -= $db[$v];
        }
        return true;
    }

    public static function subNeedsExchange(int $a, int $b): bool
    {
        $da = self::digits($a);
        $db = self::digits($b);
        foreach (self::PLACES as $v) {
            if ($da[$v] < $db[$v]) {
                return true;
            }
        }
        return false;
    }

    /* ============================================================ Side 2 */

    /**
     * Tall eleven skal bygge. Først et fast utvalg (med null i en plass), så
     * jevnt fordelte tall fra alle som kan bygges. Sortert stigende.
     * @return list<int>
     */
    public function buildNumbers(int $max = 8): array
    {
        $curated = [24, 136, 405, 1234, 7, 58, 310, 2056, 243, 19, 517, 3402, 90, 1009, 662, 15, 4];
        $out = [];
        foreach ($curated as $n) {
            if (count($out) < $max && $this->canBuild($n)) {
                $out[] = $n;
            }
        }
        $out = $this->fill($out, $max);
        sort($out);
        return $out;
    }

    /** @param list<int> $exclude */
    public function wordNumbers(int $max, array $exclude): array
    {
        $curated = [52, 307, 1460, 18, 230, 4005, 75, 812, 1100, 9, 3];
        $out = [];
        foreach ($curated as $n) {
            if (count($out) < $max && $this->canBuild($n) && !in_array($n, $exclude, true)) {
                $out[] = $n;
            }
        }
        return $this->fill($out, $max, $exclude);
    }

    /** Fyller på med jevnt fordelte tall som kan bygges. */
    private function fill(array $out, int $max, array $exclude = []): array
    {
        if (count($out) >= $max) {
            return $out;
        }
        $all = [];
        for ($n = 1; $n <= $this->largestCanonical(); $n++) {
            if ($this->canBuild($n) && !in_array($n, $out, true) && !in_array($n, $exclude, true)) {
                $all[] = $n;
            }
        }
        $need = $max - count($out);
        foreach (self::spread($all, $need) as $n) {
            $out[] = $n;
        }
        return $out;
    }

    /** k jevnt fordelte elementer fra lista (inkludert det siste). */
    public static function spread(array $items, int $k): array
    {
        $n = count($items);
        if ($n <= $k) {
            return $items;
        }
        $out = [];
        for ($i = 1; $i <= $k; $i++) {
            $out[] = $items[(int) round($i * ($n - 1) / $k)];
        }
        return array_values(array_unique($out));
    }

    /* ============================================================ Side 3 */

    /**
     * «Hvilket tall?» – sammensetninger som tegnes. De vanlige har ett siffer
     * per plass; utfordringene har 10 eller flere av én type.
     * Bare typer som finnes i settet, og aldri flere enn settet har.
     * @return list<array{rep:array<int,int>,answer:int,challenge:bool}>
     */
    public function readTasks(int $max = 7): array
    {
        $curated = [
            [100 => 3, 10 => 5, 1 => 2], [10 => 2, 1 => 6], [100 => 1, 1 => 7], [100 => 2, 10 => 4],
            [1000 => 1, 100 => 2, 1 => 3], [10 => 4, 1 => 9], [1000 => 2, 10 => 3, 1 => 5],
            [100 => 4, 10 => 1, 1 => 8], [1 => 8], [10 => 7], [1000 => 1, 100 => 1, 10 => 1, 1 => 1],
        ];
        $challenges = [[100 => 1, 10 => 12, 1 => 3], [10 => 3, 1 => 14], [1000 => 1, 100 => 11]];
        $out = [];
        foreach ($curated as $rep) {
            if (count($out) < $max - 1 && $this->canLay($rep)) {
                $out[] = ['rep' => self::full($rep), 'answer' => self::value($rep), 'challenge' => false];
            }
        }
        if (count($out) < 4) {
            // Lite sett: bruk tall som kan bygges.
            $have = array_column($out, 'answer');
            foreach (self::spread(array_values(array_filter(range(1, $this->largestCanonical()),
                fn($n) => $this->canBuild($n) && !in_array($n, $have, true))), 5 - count($out)) as $n) {
                $out[] = ['rep' => self::digits($n), 'answer' => $n, 'challenge' => false];
            }
        }
        foreach ($challenges as $rep) {
            if (count($out) < $max && $this->canLay($rep)) {
                $out[] = ['rep' => self::full($rep), 'answer' => self::value($rep), 'challenge' => true];
                break;
            }
        }
        return $out;
    }

    private static function full(array $rep): array
    {
        $out = [];
        foreach (self::PLACES as $v) {
            $out[$v] = $rep[$v] ?? 0;
        }
        return $out;
    }

    /* ============================================================ Side 4 */

    /**
     * Vekslingsoppgaver. Hver oppgave har en fysisk og en papirbasert tekst.
     * Fysisk hvis klossene finnes; papir (merket) hvis minst én av typene
     * finnes i settet; ellers utelatt.
     * @return list<array{tokens:list<array>,paper:bool,answer:?string}>
     */
    public function exchangeTasks(Base10Model $model): array
    {
        $n = fn(int $v, int $k) => $model->countName($model->typeForValue($v), $k);
        $one = fn(int $v) => 'én ' . $model->name($model->typeForValue($v));
        $tasks = [];

        // Tell og grupper i tiere (fungerer også med bare enere)
        if ($this->c[1] >= 12) {
            $k = min($this->c[1], 23);
            $tasks[] = self::task(sprintf('Legg %d enere i grupper på ti. Hvor mange hele tiergrupper får du, og hvor mange enere blir til overs?', $k),
                false, intdiv($k, 10) . ' og ' . ($k % 10));
        }

        // Bytt 10 av en type mot 1 av neste
        foreach ([1, 10, 100] as $v) {
            $up = $v * 10;
            if (!$this->has($v) && !$this->has($up)) {
                continue;
            }
            $physical = $this->c[$v] >= 10 && $this->c[$up] >= 1;
            $tasks[] = $physical
                ? self::task(sprintf('Legg %s. Bytt dem mot %s. Hvor mange %s er like mye som %s?',
                    $n($v, 10), $one($up), $model->name($model->typeForValue($v), 1), $one($up)), false, '10')
                : self::task(sprintf('Tegn %s og %s. Hvor mange %s er like mye som %s?',
                    $n($v, 10), $one($up), $model->name($model->typeForValue($v), 1), $one($up)), true, '10');
        }

        // Vis et tall på to måter
        $twoWays = null;
        foreach ([120, 34, 215, 1100, 13] as $num) {
            if (count($this->representations($num)) >= 2) {
                $twoWays = $num;
                break;
            }
        }
        if ($twoWays !== null || $this->has(10)) {
            $tasks[] = self::task(sprintf('Vis tallet %d på to forskjellige måter. Skriv hvor mange av hver kloss du brukte.', $twoWays ?? 120),
                $twoWays === null, null, true);
        }

        // Færrest mulig klosser
        $cases = [[10 => 2, 1 => 14], [100 => 1, 10 => 13, 1 => 2], [10 => 1, 1 => 12]];
        foreach ($cases as $rep) {
            $num = self::value($rep);
            $parts = [];
            foreach ($rep as $v => $k) {
                $parts[] = $n($v, $k);
            }
            $text = 'Du har ' . FractionModel::joinList($parts) . '. Veksle så du får færrest mulig klosser. Hvilket tall er det?';
            if ($this->canLay($rep) && $this->canBuild($num)) {
                $tasks[] = self::task($text, false, (string) $num);
                break;
            }
            if ($rep === end($cases) && $this->has(10)) {
                $tasks[] = self::task(str_replace('Du har', 'Tenk deg at du har', $text), true, (string) $num);
            }
        }

        // Fra stor til små
        if ($this->c[100] >= 1 && $this->c[10] >= 13) {
            $tasks[] = self::task('Legg 1 hundrer og 3 tiere. Veksle hundreren til tiere. Hvor mange tiere har du nå?', false, '13');
        } elseif ($this->c[10] >= 1 && $this->c[1] >= 15) {
            $tasks[] = self::task('Legg 1 tier og 5 enere. Veksle tieren til enere. Hvor mange enere har du nå?', false, '15');
        } elseif ($this->has(10)) {
            $tasks[] = self::task('Hvor mange enere er det i 4 tiere?', true, '40');
        }
        return $tasks;
    }

    /**
     * Ulike måter å legge et tall på med klossene (veksle én høyere kloss om
     * gangen til 10 av typen under). Første element er færrest mulig klosser.
     * @return list<array<int,int>>
     */
    public function representations(int $num): array
    {
        $out = [];
        $rep = self::digits($num);
        $seen = [];
        $queue = [$rep];
        while ($queue && count($out) < 6) {
            $r = array_shift($queue);
            $key = json_encode($r);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            if ($this->canLay($r)) {
                $out[] = $r;
            }
            foreach ([1000, 100, 10] as $v) {
                if ($r[$v] > 0) {
                    $next = $r;
                    $next[$v]--;
                    $next[$v / 10] += 10;
                    $queue[] = $next;
                }
            }
        }
        return $out;
    }

    /* ============================================================ Side 5 */

    /**
     * @return array{add:list<array>,sub:list<array>}
     *   hver oppgave: ['a','b','op','answer','exchange','paper','tokens']
     */
    public function arithmeticTasks(int $perOp = 5): array
    {
        $addEasy = [[3, 4], [23, 14], [31, 25], [142, 213], [52, 36], [321, 405], [1203, 2341], [2, 5]];
        $addHard = [[8, 5], [27, 15], [38, 46], [156, 267], [45, 8], [1456, 1278], [96, 7], [6, 7]];
        $subEasy = [[9, 4], [48, 23], [365, 142], [76, 51], [2875, 1432], [7, 3]];
        $subHard = [[12, 5], [42, 17], [231, 145], [302, 7], [50, 23], [3204, 1516], [63, 28], [15, 8]];

        return [
            'add' => $this->pick($addEasy, $addHard, '+', $perOp),
            'sub' => $this->pick($subEasy, $subHard, '–', $perOp),   // tankestrek: U+2212 finnes ikke i PDF-fontene
        ];
    }

    private function pick(array $easy, array $hard, string $op, int $max): array
    {
        $ok = fn($p) => $op === '+' ? $this->canAdd($p[0], $p[1]) : $this->canSubtract($p[0], $p[1]);
        $physical = [];
        foreach ([[$easy, 2], [$hard, $max - 2]] as [$list, $want]) {
            $taken = 0;
            foreach ($list as $p) {
                if ($taken < $want && $ok($p)) {
                    $physical[] = $p;
                    $taken++;
                }
            }
        }
        // Fyll opp med lette hvis det ikke finnes nok med veksling.
        foreach ($easy as $p) {
            if (count($physical) < min(3, $max) && $ok($p) && !in_array($p, $physical, true)) {
                $physical[] = $p;
            }
        }
        $tasks = array_map(fn($p) => $this->arith($p[0], $p[1], $op, false), $physical);

        // For få oppgaver med klossene: legg til papiroppgaver (merket), en lett og en med veksling.
        if (count($tasks) < 3) {
            foreach ([$easy, $hard] as $list) {
                foreach ($list as $p) {
                    if (!in_array($p, $physical, true) && $p[0] >= 10) {
                        $tasks[] = $this->arith($p[0], $p[1], $op, true);
                        break;
                    }
                }
            }
        }
        return array_slice($tasks, 0, $max);
    }

    private function arith(int $a, int $b, string $op, bool $paper): array
    {
        $answer = $op === '+' ? $a + $b : $a - $b;
        $exchange = $op === '+' ? self::addNeedsExchange($a, $b) : self::subNeedsExchange($a, $b);
        $tokens = [];
        if ($paper) {
            $tokens[] = ['tag', 'PAPIROPPGAVE'];
        }
        $tokens[] = ['text', "$a $op $b = "];
        $tokens[] = ['line'];
        return [
            'a' => $a, 'b' => $b, 'op' => $op, 'answer' => $answer,
            'exchange' => $exchange, 'paper' => $paper, 'tokens' => $tokens,
        ];
    }

    private static function task(string $text, bool $paper, ?string $answer, bool $twoWays = false): array
    {
        $tokens = $paper ? [['tag', 'PAPIROPPGAVE'], ['text', $text]] : [['text', $text]];
        if (!$twoWays) {
            $tokens[] = ['br'];
            $tokens[] = ['text', 'Svar: '];
            $tokens[] = ['line'];
        }
        return ['tokens' => $tokens, 'paper' => $paper, 'answer' => $answer, 'two_ways' => $twoWays];
    }

    /* ======================================================== Tall i ord */

    /** Norske tallord, 0–9999: 1234 → «ett tusen to hundre og trettifire». */
    public static function words(int $n): string
    {
        if ($n === 0) {
            return 'null';
        }
        $ones = ['', 'en', 'to', 'tre', 'fire', 'fem', 'seks', 'sju', 'åtte', 'ni', 'ti',
                 'elleve', 'tolv', 'tretten', 'fjorten', 'femten', 'seksten', 'sytten', 'atten', 'nitten'];
        $tens = [2 => 'tjue', 3 => 'tretti', 4 => 'førti', 5 => 'femti', 6 => 'seksti', 7 => 'sytti', 8 => 'åtti', 9 => 'nitti'];
        $below100 = function (int $x) use ($ones, $tens): string {
            if ($x < 20) {
                return $ones[$x];
            }
            return $tens[intdiv($x, 10)] . ($x % 10 ? $ones[$x % 10] : '');
        };
        $parts = [];
        $th = intdiv($n, 1000);
        $h = intdiv($n, 100) % 10;
        $rest = $n % 100;
        if ($th) {
            $parts[] = ($th === 1 ? 'ett' : $ones[$th]) . ' tusen';
        }
        if ($h) {
            $parts[] = ($h === 1 ? 'ett' : $ones[$h]) . ' hundre';
        }
        if ($rest) {
            $parts[] = ($parts ? 'og ' : '') . $below100($rest);
        }
        return implode(' ', $parts);
    }
}
