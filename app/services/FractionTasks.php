<?php
declare(strict_types=1);

/**
 * Lager oppgaver til PDF-en ut fra nevnerne læreren har valgt.
 *
 * Regel: en oppgave skal bare kreve brikker som finnes i settet. Av nevner n
 * finnes det n brikker på 1/n. Hver oppgave har derfor «needs» = [n => antall
 * brikker av 1/n som trengs], og testene sjekker at antall ≤ n og at n er valgt.
 *
 * En oppgave er en liste med «tokens» som FractionPdfGenerator tegner:
 *   ['text', 'Hvilken er størst, ']   vanlig tekst
 *   ['frac', 1, 4]                    brøk (stablet)
 *   ['fracbox', 4]                    brøk med tom teller: ?/4
 *   ['box']                           tom rute for et tall
 *   ['line']                          skrivelinje
 *
 * Alle valg er deterministiske, så samme sett gir samme PDF.
 */
final class FractionTasks
{
    /** @var list<int> nevnere ≥ 2 */
    private array $parts;
    private bool $hasWhole;

    /** @param list<int> $denominators sortert, unike */
    public function __construct(private FractionModel $model, private array $denominators)
    {
        $this->parts = array_values(array_filter($denominators, fn($n) => $n >= 2));
        $this->hasWhole = in_array(1, $denominators, true);
    }

    /* ------------------------------------------------------------- Side 2 */

    /**
     * Seksjoner for «Sammenlign brøker».
     * @return list<array{title:string,intro:?string,tasks:list<array>}>
     */
    public function compareSections(): array
    {
        $sections = [];
        $p = $this->parts;
        $pairs = $this->pairs();

        // A. Hvilken er størst?
        $tasks = [];
        foreach (array_slice($pairs, 0, 3) as $i => [$a, $b]) {
            [$x, $y] = $i % 2 ? [$b, $a] : [$a, $b];
            $tasks[] = $this->task(
                [['text', 'Hvilken brøk er størst, '], ['frac', 1, $x], ['text', ' eller '], ['frac', 1, $y], ['text', '?  '], ['line']],
                [$a => 1, $b => 1],
                '1/' . min($a, $b)
            );
        }
        if (count($p) === 1 && $p[0] >= 3) {
            $n = $p[0];
            $tasks[] = $this->task(
                [['text', 'Hvilken brøk er størst, '], ['frac', 2, $n], ['text', ' eller '], ['frac', 1, $n], ['text', '?  '], ['line']],
                [$n => 2], "2/$n"
            );
        }
        if (count($p) >= 3) {
            $tokens = [['text', 'Sorter brikkene fra minst til størst: ']];
            foreach ($this->spread($p) as $j => $n) {
                if ($j > 0) {
                    $tokens[] = ['text', ', '];
                }
                $tokens[] = ['frac', 1, $n];
            }
            $tasks[] = $this->task($tokens, array_fill_keys($p, 1), implode(' < ', array_map(fn($n) => "1/$n", array_reverse($p))));
        }
        if ($tasks) {
            $sections[] = ['title' => 'Hvilken er størst?', 'intro' => 'Legg brikkene oppå hverandre og sammenlign.', 'tasks' => $tasks];
        }

        // B. Hvor mange trenger du?
        $tasks = [];
        foreach ($this->dividingPairs() as [$a, $b]) {
            if (count($tasks) >= 2) {
                break;
            }
            $tasks[] = $this->task(
                [['text', 'Hvor mange '], ['frac', 1, $b], ['text', ' trenger du for å dekke '], ['frac', 1, $a], ['text', '?  '], ['box']],
                [$a => 1, $b => intdiv($b, $a)],
                (string) intdiv($b, $a)
            );
        }
        foreach ($p as $n) {
            if (count($tasks) >= 3) {
                break;
            }
            $tasks[] = $this->task(
                [['text', 'Hvor mange ' . $this->model->name($n, 1) . ' trenger du for å lage en hel sirkel?  '], ['box']],
                [$n => $n] + ($this->hasWhole ? [1 => 1] : []),
                (string) $n
            );
        }
        if ($tasks) {
            $sections[] = ['title' => 'Hvor mange trenger du?', 'intro' => null, 'tasks' => $tasks];
        }

        // C. Hel sirkel med to slags brikker
        $tasks = [];
        foreach ($pairs as [$a, $b]) {
            if (count($tasks) >= 2) {
                break;
            }
            $sol = self::wholeFrom($a, $b);
            if ($sol === null) {
                continue;
            }
            [$x, $y] = $sol;
            $tasks[] = $this->task(
                [['text', 'Kan du lage én hel sirkel med både ' . $this->model->name($a, 1) . ' og ' . $this->model->name($b, 1) . '? '
                    . 'Hvor mange av hver brukte du?  '], ['box'], ['text', ' ' . $this->model->name($a, 1) . ' og '], ['box'],
                    ['text', ' ' . $this->model->name($b, 1)]],
                [$a => $x, $b => $y],
                "$x og $y"
            );
        }
        if ($tasks) {
            $sections[] = ['title' => 'Lag en hel sirkel', 'intro' => 'Bruk bare brikker fra to brøksett.', 'tasks' => $tasks];
        }

        // D. Legg sammen og trekk fra
        $tasks = [];
        foreach ($this->dividingPairs() as [$a, $b]) {
            if ($a < 2 || count($tasks) >= 3) {
                continue;
            }
            $q = intdiv($b, $a);
            $tasks[] = $this->task(
                [['frac', 1, $a], ['text', ' + '], ['frac', 1, $b], ['text', ' = '], ['fracbox', $b]],
                [$a => 1, $b => $q + 1], ($q + 1) . "/$b"
            );
            if ($q >= 2 && count($tasks) < 3) {
                $tasks[] = $this->task(
                    [['frac', 1, $a], ['text', ' – '], ['frac', 1, $b], ['text', ' = '], ['fracbox', $b]],
                    [$a => 1, $b => $q], ($q - 1) . "/$b"
                );
            }
        }
        foreach ($p as $n) {
            if (count($tasks) >= 4) {
                break;
            }
            if ($n >= 3) {
                $tasks[] = $this->task(
                    [['frac', 2, $n], ['text', ' + '], ['frac', 1, $n], ['text', ' = '], ['fracbox', $n]],
                    [$n => 3], "3/$n"
                );
            }
            if (count($tasks) < 4) {
                $tasks[] = $this->task(
                    [['text', '1 – '], ['frac', 1, $n], ['text', ' = '], ['fracbox', $n]],
                    [$n => $n], ($n - 1) . "/$n"
                );
            }
        }
        if ($tasks) {
            $sections[] = ['title' => 'Legg sammen og trekk fra', 'intro' => 'Legg brikkene på sirkelen og finn svaret.', 'tasks' => $tasks];
        }

        if (!$sections) {
            // Bare hele sirkler valgt.
            $sections[] = ['title' => 'Utforsk', 'intro' => 'Settet har bare hele sirkler. Velg flere nevnere for å få oppgaver med brøker.', 'tasks' => [
                $this->task([['text', 'Legg den hele brikka på en sirkel på arbeidsarket. Dekker den hele sirkelen?  '], ['line']], [1 => 1], 'ja'),
                $this->task([['text', 'Tegn en strek som deler sirkelen på arbeidsarket i to like store deler.']], [], ''),
                $this->task([['text', 'Hvor mange like store deler kan du dele sirkelen i? Tegn og forklar.  '], ['line']], [], ''),
            ]];
        }
        return $sections;
    }

    /* ------------------------------------------------------------- Side 3 */

    /**
     * Likeverdige brøker: k/a = ?/b der a går opp i b.
     * @return list<array{a:int,b:int,k:int,answer:int,tokens:list<array>,needs:array<int,int>}>
     */
    public function equivalences(): array
    {
        $out = [];
        $pairs = array_values(array_filter($this->dividingPairs(), fn($ab) => $ab[0] >= 2));
        // Jevn fordeling: første k for alle par, deretter neste k.
        for ($round = 1; $round <= 3 && count($out) < 8; $round++) {
            foreach ($pairs as [$a, $b]) {
                if (count($out) >= 8) {
                    break;
                }
                $k = $round;
                if ($k >= $a) {
                    continue;
                }
                $ans = $k * intdiv($b, $a);
                $out[] = [
                    'a' => $a, 'b' => $b, 'k' => $k, 'answer' => $ans,
                    'tokens' => [['frac', $k, $a], ['text', ' = '], ['fracbox', $b]],
                    'needs'  => [$a => $k, $b => $ans],
                ];
            }
        }
        return $out;
    }

    /** Erstatning for side 3 når settet ikke har likeverdige brøker. */
    public function extraComparisons(): array
    {
        $tasks = [];
        $p = $this->parts;
        foreach ($this->pairs() as $i => [$a, $b]) {
            if (count($tasks) >= 4) {
                break;
            }
            [$x, $y] = $i % 2 ? [$b, $a] : [$a, $b];
            $tasks[] = $this->task(
                [['text', 'Hvilken brøk er størst, '], ['frac', $x - 1, $x], ['text', ' eller '], ['frac', $y - 1, $y], ['text', '?  '], ['line']],
                [$a => $a - 1, $b => $b - 1], ($b - 1) . "/$b"
            );
        }
        foreach ($p as $n) {
            if (count($tasks) >= 8) {
                break;
            }
            $k = max(1, intdiv($n, 2));
            if ($n >= 3) {
                $tasks[] = $this->task(
                    [['text', 'Hvilken brøk er størst, '], ['frac', $k, $n], ['text', ' eller '], ['frac', $k + 1, $n], ['text', '?  '], ['line']],
                    [$n => $k + 1], ($k + 1) . "/$n"
                );
            }
            $tasks[] = $this->task(
                [['text', 'Hvor mye mangler på en hel? '], ['frac', $n - 1 > 0 ? $n - 1 : 1, $n], ['text', ' + '], ['fracbox', $n], ['text', ' = 1']],
                [$n => $n], "1/$n"
            );
        }
        if (count($p) >= 2) {
            $a = $p[0];
            $b = $p[count($p) - 1];
            $tasks[] = $this->task(
                [['text', 'Hvor mange ' . $this->model->name($b, 1) . ' er like store som én ' . $this->model->name($a, 0)
                    . '? Prøv. Går det opp?  '], ['line']],
                [$a => 1, $b => $b], ''
            );
        }
        return $tasks;
    }

    /* ---------------------------------------------------- Hjelpefunksjoner */

    /** Par (a < b) av nevnere ≥ 2, nabopar først. @return list<array{int,int}> */
    public function pairs(): array
    {
        $p = $this->parts;
        $first = [];
        $rest = [];
        for ($i = 0; $i < count($p); $i++) {
            for ($j = $i + 1; $j < count($p); $j++) {
                if ($j === $i + 1) {
                    $first[] = [$p[$i], $p[$j]];
                } else {
                    $rest[] = [$p[$i], $p[$j]];
                }
            }
        }
        return array_merge($first, $rest);
    }

    /** Par der a går opp i b (a < b), minste kvotient først. @return list<array{int,int}> */
    public function dividingPairs(): array
    {
        $out = [];
        foreach ($this->parts as $a) {
            foreach ($this->parts as $b) {
                if ($b > $a && $b % $a === 0) {
                    $out[] = [$a, $b];
                }
            }
        }
        usort($out, fn($x, $y) => [intdiv($x[1], $x[0]), $x[0]] <=> [intdiv($y[1], $y[0]), $y[0]]);
        return $out;
    }

    /**
     * Finnes x/a + y/b = 1 med 1 ≤ x < a og 1 ≤ y < b? Returnerer [x, y] eller null.
     * @return array{int,int}|null
     */
    public static function wholeFrom(int $a, int $b): ?array
    {
        for ($x = 1; $x < $a; $x++) {
            $num = ($a - $x) * $b;      // y = (1 - x/a) · b
            if ($num % $a === 0) {
                $y = intdiv($num, $a);
                if ($y >= 1 && $y < $b) {
                    return [$x, $y];
                }
            }
        }
        return null;
    }

    /** Stokker rekkefølgen deterministisk (ikke sortert). */
    private function spread(array $p): array
    {
        // Annenhver: [2, 3, 4, 6, 8] → [3, 6, 2, 4, 8]
        $odd = [];
        $even = [];
        foreach (array_values($p) as $i => $v) {
            $i % 2 ? $odd[] = $v : $even[] = $v;
        }
        return array_merge($odd, $even);
    }

    private function task(array $tokens, array $needs, string $answer): array
    {
        return ['tokens' => $tokens, 'needs' => $needs, 'answer' => $answer];
    }
}
