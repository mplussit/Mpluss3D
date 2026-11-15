<?php
declare(strict_types=1);

/**
 * Oppgavegenerator for tallstavene.
 *
 * Alle oppgaver lages systematisk fra stavene som faktisk finnes i settet:
 * hvilke lengder som er valgt, og hvor mange det er av hver. Generatoren
 * går gjennom alle kandidater (alle par, tripler, oppdelinger …), beholder
 * dem som kan løses med settet, og velger et jevnt utvalg. Det brukes ingen
 * tilfeldighet, så samme sett gir alltid samme PDF.
 *
 * En oppgave er
 *   type     find | build | add | sub | mul | div | parity | ratio | fraction | equiv | whole
 *   level    lett | middels | vanskelig
 *   tokens   innholdet (se WorksheetPdf, pluss ['rod', n] og ['frac', p, q])
 *   answer   fasit som tekst
 *   rods     stavene som må ligge på bordet samtidig (brukes til kontroll)
 *   paper    true = settet har ikke stavene; tegneoppgave (merket)
 *
 * Kan en oppgavetype ikke lages med settet, lager tasks() i stedet
 * tegneoppgaver fra et fullt sett, merket TEGNEOPPGAVE. De løses ved å
 * tegne stavene på arbeidsarket.
 */
final class RodTasks
{
    public const LEVELS = ['lett', 'middels', 'vanskelig'];
    public const TYPES = ['find', 'build', 'add', 'sub', 'mul', 'div', 'parity', 'ratio', 'fraction', 'equiv', 'whole'];
    public const PAPER_TAG = 'TEGNEOPPGAVE';

    /** @var array<int,int> lengde → antall (bare lengder med antall > 0) */
    private array $counts = [];

    /** @param array<int,int> $counts lengde → antall */
    public function __construct(array $counts)
    {
        foreach ($counts as $len => $c) {
            $len = (int) $len;
            if ($len >= 1 && $len <= 10 && (int) $c > 0) {
                $this->counts[$len] = (int) $c;
            }
        }
        ksort($this->counts);
    }

    /** Et fullt sett (alle lengder, ti av hver). Brukes til tegneoppgaver. */
    public static function full(): self
    {
        return new self(array_fill(1, 10, 10));
    }

    /** @return list<int> */
    public function lengths(): array
    {
        return array_keys($this->counts);
    }

    public function has(int $len): bool
    {
        return isset($this->counts[$len]);
    }

    public function count(int $len): int
    {
        return $this->counts[$len] ?? 0;
    }

    /** Kan alle stavene ligge på bordet samtidig? */
    public function canLay(array $rods): bool
    {
        foreach (array_count_values(array_map('intval', $rods)) as $len => $n) {
            if ($this->count($len) < $n) {
                return false;
            }
        }
        return true;
    }

    /* ------------------------------------------------------- Oppdelinger */

    /**
     * Alle måter å bygge $target på med stavene i settet (antall tas hensyn til).
     * Hver måte er sortert synkende, f.eks. [5, 3, 2]. Rekkefølge: færrest staver først.
     *
     * @return list<list<int>>
     */
    public function partitions(int $target, int $minParts = 2, ?int $maxParts = null, int $limit = 5000): array
    {
        $out = [];
        $lens = array_reverse($this->lengths());
        $maxParts ??= $target;
        $walk = function (int $rest, int $maxLen, array $acc, array $used) use (&$walk, &$out, $lens, $minParts, $maxParts, $limit) {
            if (count($out) >= $limit) {
                return;
            }
            if ($rest === 0) {
                if (count($acc) >= $minParts) {
                    $out[] = $acc;
                }
                return;
            }
            if (count($acc) >= $maxParts) {
                return;
            }
            foreach ($lens as $len) {
                if ($len > $maxLen || $len > $rest || ($used[$len] ?? 0) >= $this->counts[$len]) {
                    continue;
                }
                $u = $used;
                $u[$len] = ($u[$len] ?? 0) + 1;
                $walk($rest - $len, $len, [...$acc, $len], $u);
            }
        };
        $walk($target, $target, [], []);
        usort($out, fn($a, $b) => count($a) <=> count($b) ?: self::cmpDesc($a, $b));
        return $out;
    }

    /**
     * Rekkefølge som sprer valgene jevnt over en liste med $len elementer:
     * midten, kvartene, åttendedelene … (van der Corput-følgen, fra 1/2). Samme som spread() i rod-combos.js.
     *
     * @return list<int>
     */
    public static function spread(int $len): array
    {
        $order = [];
        $used = [];
        for ($k = 1; count($order) < $len; $k++) {
            $v = 0.0;
            $f = 0.5;
            for ($i = $k; $i > 0; $i >>= 1, $f /= 2) {
                if ($i & 1) {
                    $v += $f;
                }
            }
            $idx = (int) floor($v * $len);
            if (!isset($used[$idx])) {
                $used[$idx] = true;
                $order[] = $idx;
            }
        }
        return $order;
    }

    /**
     * Et variert utvalg: én fra hver størrelse (2 staver, 3 staver …) om gangen,
     * spredt over hver gruppe, til det er valgt $n. Samme regel brukes i
     * forhåndsvisningen (rod-combos.js).
     *
     * @param list<list<int>> $partitions
     * @return list<list<int>>
     */
    public static function showcase(array $partitions, int $n): array
    {
        $groups = [];
        foreach ($partitions as $p) {
            $groups[count($p)][] = $p;
        }
        ksort($groups);
        foreach ($groups as $k => $g) {
            $groups[$k] = array_map(fn($i) => $g[$i], self::spread(count($g)));
        }
        $out = [];
        for ($round = 0; count($out) < $n; $round++) {
            $added = false;
            foreach ($groups as $g) {
                if (isset($g[$round]) && count($out) < $n) {
                    $out[] = $g[$round];
                    $added = true;
                }
            }
            if (!$added) {
                break;
            }
        }
        usort($out, fn($a, $b) => count($a) <=> count($b) ?: self::cmpDesc($a, $b));
        return $out;
    }

    private static function cmpDesc(array $a, array $b): int
    {
        foreach ($a as $i => $v) {
            if (!isset($b[$i])) {
                return 1;
            }
            if ($v !== $b[$i]) {
                return $b[$i] <=> $v;
            }
        }
        return count($a) <=> count($b);
    }

    /* ---------------------------------------------------------- Inngang */

    /**
     * Lager inntil $n gyldige oppgaver av en type og vanskelighetsgrad.
     * Med $paper = true lages tegneoppgaver (fra et fullt sett) når settet
     * ikke har stavene som trengs.
     *
     * @return list<array<string,mixed>>
     */
    public function tasks(string $type, string $level, int $n, bool $paper = true): array
    {
        if (!in_array($type, self::TYPES, true) || !in_array($level, self::LEVELS, true)) {
            throw new InvalidArgumentException("Ukjent oppgavetype: $type/$level");
        }
        $tasks = $this->generate($type, $level, $n);
        if (!$tasks && $paper) {
            $tasks = array_map(function (array $t) {
                $t['paper'] = true;
                $t['tokens'] = [['tag', self::PAPER_TAG], ['text', ' '], ...$t['tokens']];
                return $t;
            }, self::full()->generate($type, $level, min($n, 2)));
        }
        return $tasks;
    }

    /** Oppgaver som kan løses med settet (uten tegneoppgaver). */
    public function generate(string $type, string $level, int $n): array
    {
        $cands = match ($type) {
            'find'     => $this->findCandidates($level),
            'build'    => $this->buildCandidates($level),
            'add'      => $this->addCandidates($level),
            'sub'      => $this->subCandidates($level),
            'mul'      => $this->mulCandidates($level),
            'div'      => $this->divCandidates($level),
            'parity'   => $this->parityCandidates(),
            'ratio'    => $this->ratioCandidates(),
            'fraction' => $this->fractionCandidates($level),
            'equiv'    => $this->equivCandidates(),
            'whole'    => $this->wholeCandidates(),
        };
        $out = [];
        foreach (self::pick($cands, $n) as $c) {
            $c += ['type' => $type, 'level' => $level, 'paper' => false];
            if (!$this->canLay($c['rods'])) {
                throw new LogicException("Oppgave krever staver settet ikke har: " . json_encode($c['rods']));
            }
            $out[] = $c;
        }
        return $out;
    }

    /** Jevnt fordelt utvalg av kandidater (deterministisk). */
    public static function pick(array $cands, int $n): array
    {
        $cands = array_values($cands);
        $c = count($cands);
        if ($c <= $n) {
            return $cands;
        }
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = $cands[(int) floor(($i + 0.5) * $c / $n)];
        }
        return $out;
    }

    private static function task(array $tokens, string $answer, array $rods, array $extra = []): array
    {
        return ['tokens' => $tokens, 'answer' => $answer, 'rods' => array_values($rods)] + $extra;
    }

    private static function sum(array $p): string
    {
        return implode(' + ', $p);
    }

    /* -------------------------------------------------------- Bygg tall */

    private function findCandidates(string $level): array
    {
        $out = [];
        foreach ($this->lengths() as $n) {
            if ($level === 'lett') {
                // Finn staven med lengde n (kontroll med enerstaver hvis settet har nok).
                $tokens = [['text', 'Finn staven som er '], ['strong', (string) $n], ['text', $n === 1 ? ' enhet lang.' : ' enheter lang.']];
                $rods = [$n];
                if ($n > 1 && $this->count(1) >= $n) {
                    $tokens[] = ['text', ' Kontroller med ' . $n . ' '];
                    $tokens[] = ['rod', 1];
                    $tokens[] = ['text', '.'];
                    $rods = [...$rods, ...array_fill(0, $n, 1)];
                }
                $out[] = self::task($tokens, "stav $n", $rods);
            } elseif ($level === 'middels') {
                // Hvilken stav er like lang som a + b?
                foreach ($this->partitions($n, 2, 2) as [$a, $b]) {
                    $out[] = self::task([['text', 'Hvilken stav er like lang som '], ['rod', $b], ['text', ' + '], ['rod', $a], ['text', '?  '], ['line']],
                        "stav $n", [$a, $b, $n]);
                }
            } else {
                // Hvilken stav er like lang som k like staver?
                foreach ($this->lengths() as $a) {
                    $k = intdiv($n, $a);
                    if ($a < $n && $n % $a === 0 && $k <= $this->count($a) && $k >= 2) {
                        $out[] = self::task([['text', 'Hvilken stav er like lang som ' . $k . ' '], ['rod', $a], ['text', ' etter hverandre?  '], ['line']],
                            "stav $n", [...array_fill(0, $k, $a), $n]);
                    }
                }
            }
        }
        return $out;
    }

    private function buildCandidates(string $level): array
    {
        $out = [];
        if ($level === 'vanskelig') {
            // Lengder over 10: bygg på to måter. Referansen er to staver som til sammen er T.
            for ($t = 11; $t <= 20; $t++) {
                $ways = $this->partitions($t, 2, 4);
                if (!$ways) {
                    continue;
                }
                // Referansetoget og et nytt tog skal kunne ligge ved siden av hverandre.
                $ref = $ways[0];
                $others = array_values(array_filter(array_slice($ways, 1), fn($w) => $this->canLay([...$ref, ...$w])));
                if (count($others) < 2) {
                    continue;
                }
                $out[] = self::task([['text', 'Lengden ' . $t . ' er like lang som ' . self::sum($ref) . '. Bygg lengden ' . $t . ' på to andre måter.'],
                    ['br'], ['text', 'Måte 1: '], ['line'], ['text', '   Måte 2: '], ['line']],
                    self::sum($others[0]) . '; ' . self::sum($others[1]), [...$ref, ...$others[0]]);
            }
            return $out;
        }
        foreach ($this->lengths() as $t) {
            if ($level === 'lett') {
                $two = $this->partitions($t, 2, 2);
                if ($two) {
                    $out[] = self::task([['text', 'Bygg '], ['rod', $t], ['text', ' med to staver. Hvilke? '], ['line']],
                        implode(' eller ', array_map([self::class, 'sum'], $two)), [$t, ...$two[0]]);
                }
            } else {
                $ways = $this->partitions($t);
                if (count($ways) >= 3) {
                    $show = self::showcase($ways, 3);
                    $out[] = self::task([['text', 'Bygg '], ['rod', $t], ['text', ' på tre forskjellige måter. Skriv regnestykkene.'],
                        ['br'], ['text', 'Måte 1: '], ['line'], ['text', '  Måte 2: '], ['line'], ['text', '  Måte 3: '], ['line']],
                        implode('; ', array_map([self::class, 'sum'], $show)), [$t, ...$show[0]]);
                }
            }
        }
        return $out;
    }

    /* -------------------------------------------------------- Addisjon */

    private function addCandidates(string $level): array
    {
        $out = [];
        $L = $this->lengths();
        if ($level === 'middels') {
            foreach ($L as $a) {
                foreach ($L as $b) {
                    foreach ($L as $c) {
                        $s = $a + $b + $c;
                        if ($a >= $b && $b >= $c && $s <= 10 && $this->canLay([$a, $b, $c, $s])) {
                            // Skriv dem i en blandet rekkefølge, som i spesifikasjonen: 3 + 3 + 2
                            $out[] = self::task([['rod', $b], ['text', ' + '], ['rod', $a], ['text', ' + '], ['rod', $c], ['text', ' = '], ['box']],
                                (string) $s, [$a, $b, $c, $s]);
                        }
                    }
                }
            }
            return $out;
        }
        foreach ($L as $a) {
            foreach ($L as $b) {
                $s = $a + $b;
                if ($level === 'lett' && $s <= 10 && $this->canLay([$a, $b, $s])) {
                    $out[] = self::task([['rod', $a], ['text', ' + '], ['rod', $b], ['text', ' = '], ['box']], (string) $s, [$a, $b, $s]);
                } elseif ($level === 'vanskelig' && $s > 10 && $a <= $b && $b < 10 && $this->canLay([$a, $b, 10, $s - 10])) {
                    $out[] = self::task([['rod', $a], ['text', ' + '], ['rod', $b], ['text', ' = '], ['rod', 10], ['text', ' + '], ['box'], ['text', ' = '], ['box']],
                        ($s - 10) . ', ' . $s, [$a, $b, 10, $s - 10]);
                }
            }
        }
        return $out;
    }

    /* ------------------------------------------------------ Subtraksjon */

    private function subCandidates(string $level): array
    {
        $out = [];
        $L = $this->lengths();
        foreach ($L as $b) {
            foreach ($L as $a) {
                $d = $b - $a;
                if ($d < 1) {
                    continue;
                }
                if ($level === 'lett' && $b <= 7 && $this->canLay([$a, $b, $d])) {
                    $out[] = self::task([['text', 'Legg '], ['rod', $a], ['text', ' ved siden av '], ['rod', $b], ['text', '. Hvor stor er forskjellen? '], ['box']],
                        (string) $d, [$a, $b, $d]);
                } elseif ($level === 'middels' && $this->canLay([$a, $b, $d])) {
                    $out[] = ($a + $b) % 2 === 0
                        ? self::task([['text', 'Hvilken stav mangler?  '], ['rod', $a], ['text', ' + '], ['box'], ['text', ' = '], ['rod', $b]], (string) $d, [$a, $d, $b])
                        : self::task([['text', 'Hvor mye lengre er '], ['rod', $b], ['text', ' enn '], ['rod', $a], ['text', '? '], ['box']], (string) $d, [$a, $b, $d]);
                }
            }
        }
        if ($level === 'vanskelig') {
            foreach ($L as $c) {
                foreach ($L as $a) {
                    foreach ($L as $b) {
                        $d = $c - $a - $b;
                        if ($a >= $b && $d >= 1 && $this->canLay([$a, $b, $d, $c])) {
                            $out[] = self::task([['rod', $a], ['text', ' + '], ['rod', $b], ['text', ' + '], ['box'], ['text', ' = '], ['rod', $c],
                                ['text', '   og   ' . $c . ' – ' . $a . ' – ' . $b . ' = '], ['box']], (string) $d, [$a, $b, $d, $c]);
                        }
                    }
                }
            }
        }
        return $out;
    }

    /* ------------------------------------------- Multiplikasjon og divisjon */

    private function mulCandidates(string $level): array
    {
        $out = [];
        if ($level === 'vanskelig') {
            // Finn to måter å bygge P med like staver.
            for ($p = 4; $p <= 20; $p++) {
                $ways = [];
                foreach ($this->lengths() as $d) {
                    $k = intdiv($p, $d);
                    if ($d < $p && $p % $d === 0 && $k >= 2 && $k <= $this->count($d)) {
                        $ways[] = [$k, $d];
                    }
                }
                if (count($ways) >= 2) {
                    $w = array_slice(array_reverse($ways), 0, 2);   // de største stavene først
                    $out[] = self::task([['text', 'Bygg lengden '], ['strong', (string) $p], ['text', ' med like staver på to måter.'],
                        ['br'], ['text', 'Gangestykker: '], ['line'], ['text', '  og  '], ['line']],
                        implode(' og ', array_map(fn($x) => "{$x[0]} × {$x[1]}", $ways)), array_fill(0, $w[0][0], $w[0][1]));
                }
            }
            return $out;
        }
        foreach ($this->lengths() as $a) {
            for ($k = 2; $k <= min(5, $this->count($a)); $k++) {
                $p = $k * $a;
                if ($level === 'lett' && $p <= 10 && $k <= 4) {
                    $rods = array_fill(0, $k, $a);
                    $check = $this->canLay([...$rods, $p]);
                    $out[] = self::task([['text', 'Legg ' . $k . ' '], ['rod', $a], ['text', ' etter hverandre. Hvor langt blir det? '], ['box'],
                        ...($check ? [['text', '  Kontroller med én stav.']] : [])],
                        (string) $p . ($check ? ", stav $p" : ''), $check ? [...$rods, $p] : $rods);
                } elseif ($level === 'middels' && $p > 6 && $p <= 20) {
                    $out[] = self::task([['text', 'Vis '], ['strong', "$k × $a"], ['text', ' med staver. Hvor langt blir det? '], ['box']],
                        (string) $p, array_fill(0, $k, $a));
                }
            }
        }
        return $out;
    }

    private function divCandidates(string $level): array
    {
        $out = [];
        foreach ($this->lengths() as $b) {
            foreach ($this->lengths() as $a) {
                if ($a >= $b) {
                    continue;
                }
                $q = intdiv($b, $a);
                $r = $b % $a;
                if ($level !== 'vanskelig' && $r === 0 && $q >= 2 && $this->canLay([...array_fill(0, $q, $a), $b])) {
                    if (($level === 'lett') === ($q <= 3)) {
                        $out[] = self::task([['text', 'Hvor mange '], ['rod', $a], ['text', ' er like lange som '], ['rod', $b], ['text', '? '], ['box']],
                            (string) $q, [...array_fill(0, $q, $a), $b]);
                    }
                } elseif ($level === 'vanskelig' && $r > 0 && $q >= 2 && $this->canLay([...array_fill(0, $q, $a), $b, $r])) {
                    $out[] = self::task([['text', 'Hvor mange '], ['rod', $a], ['text', ' får plass langs '], ['rod', $b], ['text', '? '], ['box'],
                        ['text', '  Rest: '], ['box']], "$q, rest $r", [...array_fill(0, $q, $a), $b, $r]);
                }
            }
        }
        return $out;
    }

    /** Partall: kan staven bygges med to like staver? */
    private function parityCandidates(): array
    {
        $out = [];
        foreach ($this->lengths() as $b) {
            if ($b < 2) {
                continue;
            }
            $even = $b % 2 === 0;
            if ($even && !$this->canLay([$b, $b / 2, $b / 2])) {
                continue;
            }
            // Oddetall: eleven skal prøve med to staver som er nesten like (h og h + 1).
            $h = intdiv($b, 2);
            if (!$even && !$this->canLay([$b, $h, $h + 1])) {
                continue;
            }
            $out[] = self::task([['text', 'Kan '], ['rod', $b], ['text', ' bygges med to like staver?  Ja / Nei   Partall / oddetall']],
                $even ? 'ja, ' . ($b / 2) . ' + ' . ($b / 2) . ', partall' : 'nei, oddetall', $even ? [$b, $b / 2, $b / 2] : [$b, $h, $h + 1]);
        }
        // Partall først og oddetall etterpå, så et jevnt utvalg får begge deler.
        usort($out, fn($x, $y) => str_ends_with($y['answer'], 'partall') <=> str_ends_with($x['answer'], 'partall'));
        return $out;
    }

    /** Forhold og proporsjonalitet: a er til b som c er til ?. */
    private function ratioCandidates(): array
    {
        $out = [];
        $L = $this->lengths();
        foreach ($L as $a) {
            foreach ($L as $b) {
                if ($b <= $a || $b % $a !== 0) {
                    continue;
                }
                $k = intdiv($b, $a);
                foreach ($L as $c) {
                    $d = $c * $k;
                    if ($c !== $a && $this->canLay([$a, $b, $c, $d])) {
                        // a : b = c : d  (b er k ganger så lang som a, og d er k ganger så lang som c)
                        $out[] = self::task([['rod', $a], ['text', ' : '], ['rod', $b], ['text', '   =   '], ['rod', $c], ['text', ' : '], ['box']],
                            (string) $d, [$a, $b, $c, $d]);
                    }
                }
            }
        }
        return $out;
    }

    /* ---------------------------------------------------------------- Brøk */

    /**
     * Hele-staver som kan brukes til brøk: lengder W der minst to stammebrøker
     * 1/q (q ≥ 2) finnes som staver (W/q). Best først (flest brøker, så lengst).
     *
     * @return list<array{whole:int,units:array<int,int>}>  units: q → lengden til 1/q
     */
    public function wholes(): array
    {
        $out = [];
        foreach ($this->lengths() as $w) {
            $units = [];
            for ($q = 2; $q <= $w; $q++) {
                if ($w % $q === 0 && $this->has($w / $q) && $w / $q !== $w) {
                    $units[$q] = $w / $q;
                }
            }
            if ($units) {
                $out[] = ['whole' => $w, 'units' => $units];
            }
        }
        usort($out, fn($a, $b) => count($b['units']) <=> count($a['units']) ?: $b['whole'] <=> $a['whole']);
        return $out;
    }

    /** Den beste helen, eller null hvis settet ikke kan vise brøk. */
    public function mainWhole(): ?int
    {
        return $this->wholes()[0]['whole'] ?? null;
    }

    /**
     * En annen hel som deler en stav R med hovedhelen, så eleven ser at R har
     * ulik verdi. @return array{whole:int,rod:int}|null
     */
    public function secondWhole(): ?array
    {
        $w1 = $this->mainWhole();
        if ($w1 === null) {
            return null;
        }
        foreach ($this->wholes() as $c) {
            if ($c['whole'] === $w1) {
                continue;
            }
            foreach (array_reverse($this->lengths()) as $r) {
                if ($r < $c['whole'] && $r < $w1 && $c['whole'] % $r === 0 && $w1 % $r === 0) {
                    return ['whole' => $c['whole'], 'rod' => $r];
                }
            }
        }
        return null;
    }

    private function fractionCandidates(string $level, ?int $w = null): array
    {
        $w ??= $this->mainWhole();
        if ($w === null) {
            return [];
        }
        $out = [];
        $whole = [['text', 'Når '], ['rod', $w], ['text', ' er én hel: ']];
        for ($q = 2; $q <= $w; $q++) {
            if ($w % $q !== 0) {
                continue;
            }
            $unit = $w / $q;
            for ($p = 1; $p < $q; $p++) {
                $len = $p * $unit;
                if (!$this->has($len) || self::gcd($p, $q) !== 1) {
                    continue;
                }
                if ($level === 'lett' && $p === 1) {
                    $out[] = self::task([...$whole, ['text', 'Hvilken stav er '], ['frac', 1, $q], ['text', '? '], ['line']], "stav $len", [$w, $len], ['whole' => $w]);
                } elseif ($level === 'middels' && $p > 1) {
                    $out[] = self::task([...$whole, ['text', 'Hvilken stav er '], ['frac', $p, $q], ['text', '? '], ['line']], "stav $len", [$w, $len], ['whole' => $w]);
                } elseif ($level === 'vanskelig') {
                    $out[] = self::task([...$whole, ['text', 'Hvilken brøk er '], ['rod', $len], ['text', '? '], ['fracbox']], "$p/$q", [$w, $len], ['whole' => $w]);
                }
            }
        }
        return $out;
    }

    /** Fraksjonsoppgaver med en bestemt hel (brukes for den andre helen i PDF-en). */
    public function fractionTasksFor(int $whole, string $level, int $n): array
    {
        if (!$this->has($whole)) {
            return [];
        }
        $out = [];
        foreach (self::pick($this->fractionCandidates($level, $whole), $n) as $c) {
            $out[] = $c + ['type' => 'fraction', 'level' => $level, 'paper' => false];
        }
        return $out;
    }

    /** Likeverdige brøker: 1/q1 = k/q2 når W er én hel. */
    private function equivCandidates(): array
    {
        $out = [];
        foreach ($this->wholes() as ['whole' => $w, 'units' => $units]) {
            foreach ($units as $q1 => $a) {
                foreach ($units as $q2 => $b) {
                    if ($q2 <= $q1 || $q2 % $q1 !== 0) {
                        continue;
                    }
                    $k = intdiv($q2, $q1);
                    if ($this->canLay([$w, $a, ...array_fill(0, $k, $b)])) {
                        $out[] = self::task([['text', 'Når '], ['rod', $w], ['text', ' er én hel: Vis at '], ['frac', 1, $q1], ['text', ' = '], ['frac', $k, $q2],
                            ['text', '. Legg ' . $k . ' '], ['rod', $b], ['text', ' ved siden av '], ['rod', $a], ['text', '.']],
                            "1/$q1 = $k/$q2", [$w, $a, ...array_fill(0, $k, $b)]);
                    }
                }
            }
        }
        return $out;
    }

    /** Fra del til hel: Hvis R er 1/q, hvilken stav er én hel? */
    private function wholeCandidates(): array
    {
        $out = [];
        foreach ($this->lengths() as $r) {
            foreach ($this->lengths() as $w) {
                if ($w > $r && $w % $r === 0) {
                    $q = intdiv($w, $r);
                    $out[] = self::task([['text', 'Hvis '], ['rod', $r], ['text', ' er '], ['frac', 1, $q], ['text', ', hvilken stav er én hel? '], ['line']],
                        "stav $w", [$r, $w]);
                }
            }
        }
        return $out;
    }

    public static function gcd(int $a, int $b): int
    {
        while ($b) {
            [$a, $b] = [$b, $a % $b];
        }
        return abs($a);
    }

    /** Største lengde som kan bygges som et tog av alle stavene i settet. */
    public function totalLength(): int
    {
        $s = 0;
        foreach ($this->counts as $len => $c) {
            $s += $len * $c;
        }
        return $s;
    }
}
