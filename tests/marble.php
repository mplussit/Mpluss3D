<?php
/**
 * Tester for «Kulespill med ark». Tas inn av tests/run.php og bruker
 * hjelpefunksjonene der (test, ok, eq, near, rejects, pdfContent).
 */
declare(strict_types=1);

echo "\nKulespill: validering, innhold og ark\n";

$marble = MarbleModel::load(__DIR__ . '/../app/config');
$mdef = $marble->definition();
$mp = fn(array $in = []) => $marble->validate($in + ['seed' => 4242]);

/** Alle tegneelementene på alle sidene, med sidenummer. */
$allItems = function (array $pages): Generator {
    foreach ($pages as $i => $p) {
        foreach ($p['items'] as $it) {
            yield $i => $it;
        }
    }
};

test('kulespill: hullene fra SolidCraft-modellen ligger på arket, med plass til innholdet', function () use ($marble, $mdef, $mp) {
    eq(9, count($mdef['holes']));
    $sh = $marble->sheets($mp());
    foreach ($mdef['holes'] as [$x, $y]) {
        [$sx, $sy] = $sh->toSheet($x, $y);
        $r = $mdef['hole_diameter'] / 2;
        ok($sx - $r >= MarbleSheets::SHEET_X && $sx + $r <= MarbleSheets::SHEET_X + 160, "hull ($x, $y) utenfor arket i x");
        ok($sy - $r >= MarbleSheets::SHEET_Y && $sy + $r <= MarbleSheets::SHEET_Y + 250, "hull ($x, $y) utenfor arket i y");
        ok(hypot($x, $y) + $r <= 80 || $y < 0, "hull ($x, $y) utenfor den runde toppen");
    }
    // Arket ligger midt på A4.
    near(25.0, MarbleSheets::SHEET_X, 1e-9);
    near((297 - 250) / 2, MarbleSheets::SHEET_Y, 1e-9);
    // Toppen av arket er toppen av spillet, venstre kant er X = −80.
    eq([25.0, 23.5], $sh->toSheet(-80, 80));
    eq([185.0, 273.5], $sh->toSheet(80, -170));
});

test('kulespill: standardverdier og validering', function () use ($marble, $mp) {
    $p = $marble->validate([]);
    eq(['numbers', 1, 10, true, 1], [$p['content'], $p['num_min'], $p['num_max'], $p['unique'], $p['variants']]);
    eq(['+', '-'], $p['ops']);
    eq(['-', '+'], array_reverse($mp(['ops' => '-,+,+'])['ops']), 'regnearter i fast rekkefølge uten duplikater');
    rejects($marble, ['content' => 'bilder'], 'content');
    rejects($marble, ['num_min' => 8, 'num_max' => 3], 'num_max');
    rejects($marble, ['num_min' => 1, 'num_max' => 5], 'num_max');              // ulike verdier krever 9 tall
    ok($marble->validate(['num_min' => 1, 'num_max' => 5, 'unique' => false])['num_max'] === 5);
    rejects($marble, ['content' => 'letters', 'value_min' => 0, 'value_max' => 7], 'value_max');
    rejects($marble, ['content' => 'expressions', 'ops' => ''], 'ops');
    rejects($marble, ['content' => 'expressions', 'ops' => '+,^'], 'ops');
    rejects($marble, ['content' => 'symbols', 'calc_ops' => []], 'calc_ops');
    rejects($marble, ['variants' => 11], 'variants');
    rejects($marble, ['balls' => 2.5], 'balls');
    rejects($marble, ['seed' => '0'], 'seed');
    rejects($marble, ['seed' => '12; rm'], 'seed');
    rejects($marble, ['expr_max' => 30], 'expr_max');
    rejects($marble, ['unique' => 'kanskje'], 'unique');
});

test('kulespill: samme frø gir samme ark, nytt frø gir nye verdier, variantene er ulike', function () use ($marble, $mp) {
    foreach (['numbers', 'expressions', 'letters', 'symbols'] as $c) {
        $a = $marble->describe($mp(['content' => $c, 'variants' => 3]));
        $b = $marble->describe($mp(['content' => $c, 'variants' => 3]));
        eq(json_encode($a), json_encode($b), "$c: samme frø");
        $vals = fn($d, $i) => array_column($d['variants'][$i]['holes'], 'value');
        ok($vals($a, 0) !== $vals($a, 1) || $vals($a, 1) !== $vals($a, 2), "$c: variantene skal ha ulike verdier");
        $other = $marble->describe($marble->validate(['content' => $c, 'variants' => 3, 'seed' => 4243]));
        ok($vals($a, 0) !== $vals($other, 0), "$c: nytt frø skal gi nye verdier");
    }
});

test('kulespill: tall i området, ulike når det er valgt', function () use ($marble, $mp) {
    foreach ([[1, 9, true], [0, 100, true], [5, 6, false], [999, 999, false]] as [$lo, $hi, $u]) {
        foreach ($marble->sheets($mp(['num_min' => $lo, 'num_max' => $hi, 'unique' => $u, 'variants' => 5]))->variants() as $v) {
            $vals = array_column($v['holes'], 'value');
            ok(min($vals) >= $lo && max($vals) <= $hi, "tall utenfor {$lo}–{$hi}");
            if ($u) {
                eq(9, count(array_unique($vals)), 'ulike tall');
            }
            foreach ($v['holes'] as $h) {
                eq((string) $h['value'], $h['label']);
            }
        }
    }
});

test('kulespill: regnestykkene i hullene har riktige, hele og positive svar innenfor området', function () use ($marble, $mp) {
    foreach ([10, 20, 50, 100] as $max) {
        foreach ([['+'], ['-'], ['*'], ['/'], ['+', '-', '*', '/']] as $ops) {
            $p = $mp(['content' => 'expressions', 'ops' => $ops, 'expr_max' => $max, 'variants' => 4]);
            foreach ($marble->sheets($p)->variants() as $v) {
                eq(9, count($v['holes']), 'ni stykker');
                eq(9, count(array_unique(array_column($v['holes'], 'label'))), 'ulike stykker');
                foreach ($v['holes'] as $h) {
                    ok((bool) preg_match('/^(\d+) (\+|–|×|:) (\d+)$/u', $h['label'], $m), "ugyldig stykke {$h['label']}");
                    [$a, $b] = [(int) $m[1], (int) $m[3]];
                    $ans = match ($m[2]) { '+' => $a + $b, '–' => $a - $b, '×' => $a * $b, ':' => intdiv($a, $b) };
                    if ($m[2] === ':') {
                        eq(0, $a % $b, "$a : $b går ikke opp");
                    }
                    eq($ans, $h['value'], $h['label']);
                    ok($ans >= 0 && $ans <= $max, "{$h['label']} = $ans er utenfor 0–$max");
                    ok(in_array(array_search($m[2], ['+' => '+', '-' => '–', '*' => '×', '/' => ':']), $ops, true), 'regneart som ikke er valgt');
                    if ($m[2] === '×' || $m[2] === ':') {
                        ok(min($a, $b) <= 10 && ($m[2] === ':' ? $b <= 10 && $ans <= 10 : max($a, $b) <= 10), "{$h['label']} er utenfor gangetabellen");
                    }
                }
            }
        }
    }
});

test('kulespill: «Regn med verdiene» har riktige svar og aldri under null når det er valgt', function () use ($marble, $mp) {
    foreach ([2, 3, 5] as $terms) {
        foreach ([[['+', '-'], true], [['-'], true], [['+', '-'], false], [['+'], true]] as [$ops, $nonneg]) {
            $p = $mp(['content' => 'letters', 'calc_terms' => $terms, 'calc_ops' => $ops, 'no_negative' => $nonneg,
                'calc_count' => 12, 'value_min' => 0, 'value_max' => 20, 'variants' => 3]);
            foreach ($marble->sheets($p)->variants() as $v) {
                $vals = array_column($v['holes'], 'value');
                $seen = [];
                foreach ($v['calc'] as $c) {
                    eq($terms, count($c['terms']));
                    eq(count($c['terms']), count(array_unique($c['terms'])), 'hvert hull én gang per stykke');
                    $t = $vals[$c['terms'][0]];
                    foreach ($c['ops'] as $i => $op) {
                        ok(in_array($op, $ops, true), 'regnetegn som ikke er valgt');
                        $t += $op === '+' ? $vals[$c['terms'][$i + 1]] : -$vals[$c['terms'][$i + 1]];
                        if ($nonneg) {
                            ok($t >= 0, 'under null underveis');
                        }
                    }
                    eq($t, $c['answer'], 'svar');
                    $key = json_encode($c);
                    ok(!isset($seen[$key]), 'samme stykke to ganger');
                    $seen[$key] = true;
                }
            }
        }
    }
    // Bare for bokstaver og symboler.
    eq([], $marble->sheets($mp(['content' => 'numbers']))->variants()[0]['calc']);
});

test('kulespill: «Finn hullene» har ulike summer og alle løsningene', function () use ($marble, $mp) {
    foreach (['numbers', 'expressions', 'symbols'] as $c) {
        foreach ([2, 3] as $k) {
            foreach ($marble->sheets($mp(['content' => $c, 'find_size' => $k, 'find_count' => 8, 'variants' => 3]))->variants() as $v) {
                $vals = array_column($v['holes'], 'value');
                ok(count($v['find']) > 0, 'ingen oppgaver');
                eq(count($v['find']), count(array_unique(array_column($v['find'], 'target'))), 'ulike summer');
                foreach ($v['find'] as $f) {
                    $all = array_values(array_filter(MarbleSheets::combinations(9, $k),
                        fn($cc) => array_sum(array_map(fn($i) => $vals[$i], $cc)) === $f['target']));
                    ok(count($all) >= 1);
                    eq($all, $f['solutions'], 'alle løsningene');
                }
            }
        }
    }
});

test('kulespill: sidene – hullark per variant, eller ett felles med bokstaver og symboler', function () use ($marble, $mp) {
    $kinds = fn(array $in) => array_column($marble->describe($mp($in))['pages'], 'kind');
    eq(['holes', 'tasks', 'key'], $kinds([]));
    eq(['holes', 'tasks', 'holes', 'tasks', 'key'], $kinds(['variants' => 2]));
    eq(['holes', 'values', 'tasks', 'values', 'tasks', 'key'], $kinds(['content' => 'letters', 'variants' => 2]));
    eq(['holes', 'values'], $kinds(['content' => 'symbols', 'task_round' => false, 'task_calc' => false, 'task_find' => false, 'answer_key' => false]));
    eq(['holes'], $kinds(['task_round' => false, 'task_find' => false, 'answer_key' => false]));
    // Mange oppgaver: oppgavearket fortsetter på en ny side.
    $long = $kinds(['content' => 'letters', 'balls' => 8, 'calc_count' => 12, 'calc_terms' => 5, 'find_count' => 8, 'find_size' => 3]);
    ok(count(array_keys($long, 'tasks')) >= 2, 'lange oppgaver skal gå over flere sider');
});

test('kulespill: alt ligger innenfor A4 og over bunnteksten, og teksten i hullene får plass', function () use ($marble, $mdef, $mp, $allItems) {
    $cases = [
        ['content' => 'numbers', 'num_min' => 990, 'num_max' => 999, 'unique' => true],
        ['content' => 'expressions', 'ops' => ['+', '-', '*', '/'], 'expr_max' => 100, 'variants' => 4],
        ['content' => 'letters', 'value_min' => 90, 'value_max' => 99, 'balls' => 8, 'calc_count' => 12, 'calc_terms' => 5, 'find_count' => 8, 'find_size' => 3, 'variants' => 3],
        ['content' => 'symbols', 'calc_terms' => 2, 'calc_count' => 1, 'find_count' => 1],
    ];
    foreach ($cases as $in) {
        $sh = $marble->sheets($mp($in));
        $built = $sh->build();
        foreach ($allItems($built['pages']) as $pi => $it) {
            $label = $built['pages'][$pi]['label'];
            $PW = $built['pages'][$pi]['w'];
            $PH = $built['pages'][$pi]['h'];
            if ($it['k'] === 'text') {
                $w = $sh->tw($it['t'], $it['sz'], $it['b']);
                $x0 = match ($it['a']) { 'C' => $it['x'] - $w / 2, 'R' => $it['x'] - $w, default => $it['x'] };
                ok($x0 >= 8 && $x0 + $w <= $PW - 8, "$label: «{$it['t']}» går utenfor arket ($x0 + $w)");
                ok($it['y'] <= $PH - 5 && $it['y'] - $it['sz'] >= 5, "$label: «{$it['t']}» utenfor arket i høyden");
                ok($it['y'] < $PH - 12 || $it['sz'] <= 2.6, "$label: «{$it['t']}» i bunnteksten");
            } elseif ($it['k'] === 'rect') {
                ok($it['x'] >= 8 && $it['x'] + $it['w'] <= $PW - 8 && $it['y'] >= 5 && $it['y'] + $it['h'] <= $PH - 19, "$label: rute utenfor");
            }
        }
        // Teksten i hullene ligger innenfor content_diameter.
        $holes = $built['pages'][0];
        $Rc = $mdef['content_diameter'] / 2;
        foreach ($mdef['holes'] as [$x, $y]) {
            [$cx, $cy] = $sh->toSheet($x, $y);
            foreach ($holes['items'] as $it) {
                if ($it['k'] === 'text' && abs($it['x'] - $cx) < 1e-6 && abs($it['y'] - $cy) < 9) {
                    $w = $sh->tw($it['t'], $it['sz'], true);
                    ok(hypot($w / 2, 0.359 * $it['sz']) <= $Rc + 1e-6, "«{$it['t']}» får ikke plass i hullet");
                    ok($it['sz'] >= 3.2, "«{$it['t']}» er for liten ({$it['sz']} mm)");
                }
            }
        }
    }
});

test('kulespill: symbolene ligger innenfor hullet', function () use ($marble, $mdef, $mp) {
    $sh = $marble->sheets($mp(['content' => 'symbols']));
    $Rc = $mdef['content_diameter'] / 2;
    foreach (array_keys($mdef['symbols']) as $i) {
        foreach ($sh->symbol($i, 0, 0, $Rc * 0.86) as $it) {
            $pts = $it['k'] === 'circle' ? [[$it['r'], 0]] : $it['p'];
            foreach ($pts as [$x, $y]) {
                ok(hypot($x, $y) <= $Rc + 1e-6, "symbol {$mdef['symbols'][$i]['name']} går utenfor hullet");
            }
        }
    }
});

test('kulespill: PDF-en har de samme sidene og tekstene som forhåndsvisningen', function () use ($marble, $mp, $allItems) {
    foreach (['numbers', 'letters'] as $c) {
        $p = $mp(['content' => $c, 'variants' => 2]);
        $pdf = $marble->renderPdf($p);
        $pages = $marble->describe($p)['pages'];
        eq(count($pages), preg_match_all('/\/Type \/Page\b/', $pdf), 'antall sider');
        ok(str_contains($pdf, '/MediaBox [0 0 595.28 841.89]'), 'A4');
        $content = pdfContent($pdf);
        foreach ($allItems($pages) as $it) {
            if ($it['k'] === 'text') {
                $s = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], LabelPdf::enc($it['t']));
                ok(str_contains($content, "($s) Tj"), "teksten «{$it['t']}» mangler i PDF-en");
            }
        }
    }
    eq('kulespill-ark-symboler.pdf', $marble->pdfFilename($mp(['content' => 'symbols'])));
});

test('kulespill: STL-filene og forhåndsbildet finnes, og ingen OpenSCAD-deler', function () use ($marble, $mdef, $mp) {
    foreach ($mdef['files'] as $f) {
        $path = __DIR__ . '/../public/' . $f['path'];
        ok(is_file($path), "{$f['path']} mangler");
        $bin = file_get_contents($path);
        $n = unpack('V', substr($bin, 80, 4))[1];
        eq(84 + 50 * $n, strlen($bin), "{$f['filename']}: binær STL med $n trekanter");
    }
    eq([], $marble->parts($mp()));
});
