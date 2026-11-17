<?php
/**
 * Tester for «Regn med Lego». Tas inn av tests/run.php og bruker
 * hjelpefunksjonene der (test, ok, eq, near, rejects, stlTriangles, pdfContent …).
 */
declare(strict_types=1);

echo "\nRegn med Lego: form, mål og validering\n";

$lego = LegoModel::load(__DIR__ . '/../app/config');
$lf = $lego->frameDefaults();
$legoProject = fn(array ...$frames) => $lego->validate(['frames' => array_map(fn($f) => $f + $lf, $frames)]);
$rect = fn(int $c, int $r) => array_fill(0, $r, str_repeat('1', $c));

test('standardverdier: 8 mm modul, 9,6 mm høyde, vegg 2, bunn 1,5, klaring 0,2', function () use ($lego, $lf) {
    $d = $lego->definition();
    eq([8.0, 9.6], [$d['module'], $d['inner_height']]);
    eq([2.0, true, 1.5, 0.2], [$lf['wall'], $lf['bottom'], $lf['bottom_thickness'], $lf['clearance']]);
    eq(['1111', '1111', '1111', '1111'], $lf['rows']);
});

test('4 × 4: 16 knottplasser, innvendig 32,4 × 32,4 mm, høyde 11,1 mm (9,6 mm uten bunn)', function () use ($lego, $legoProject) {
    $g = $lego->geometry($legoProject([])['frames'][0]);
    eq(16, $g['count']);
    near(32.4, $g['inner'][0], 1e-9);
    near(32.4, $g['inner'][1], 1e-9);
    near(11.1, $g['height'], 1e-9);
    near(9.6, $g['inner_height'], 1e-9);
    $n = $lego->geometry($legoProject(['bottom' => false])['frames'][0]);
    near(9.6, $n['height'], 1e-9);
    eq(0.0, $n['bottom']);
});

test('veggtykkelse endrer bare utvendige mål; bunntykkelse endrer bare total høyde', function () use ($lego, $legoProject) {
    foreach ([1.2, 2, 3] as $w) {
        $g = $lego->geometry($legoProject(['wall' => $w])['frames'][0]);
        near(32.4, $g['inner'][0], 1e-9, "vegg $w: innvendig");
        near(32.4 + 2 * $w, $g['frame_outer'][0], 1e-9, "vegg $w: utvendig");
    }
    foreach ([0.8, 1.5, 4] as $b) {
        $g = $lego->geometry($legoProject(['bottom_thickness' => $b])['frames'][0]);
        near(9.6, $g['inner_height'], 1e-9);
        near($b + 9.6, $g['height'], 1e-9);
    }
    foreach ([0, 0.2, 0.5] as $c) {
        $g = $lego->geometry($legoProject(['clearance' => $c, 'rows' => ['111']])['frames'][0]);
        near(24 + 2 * $c, $g['inner'][0], 1e-9, "klaring $c: innvendig bredde = kolonner · 8 + 2 · klaring");
    }
});

test('form: rektangel, L-form, utsparing og mengde fra antall ruter', function () {
    $r = new LegoShape(['0000', '0110', '0110']);
    eq([2, 2, 4, true, 0], [$r->cols(), $r->rows(), $r->count(), $r->isRectangle(), $r->holes()], 'trimmes til formen');
    $l = new LegoShape(['100', '100', '111']);
    eq([5, false, 0, []], [$l->count(), $l->isRectangle(), $l->holes(), $l->problems()]);
    $ring = new LegoShape(['111', '101', '111']);
    eq([8, 1, []], [$ring->count(), $ring->holes(), $ring->problems()]);
    $u = new LegoShape(['101', '101', '111']);
    eq(0, $u->holes(), 'en åpen bukt er ikke en utsparing');
});

test('ugyldige former gir forståelige meldinger', function () use ($lego, $lf) {
    $msg = function (array $rows, array $extra = []) use ($lego, $lf): string {
        try {
            $lego->validate(['frames' => [['rows' => $rows] + $extra + $lf]]);
        } catch (ValidationException $e) {
            return implode(' ', array_column($e->errors(), 'message'));
        }
        return '';
    };
    ok(str_contains($msg(['0000']), 'ingen ruter'));
    ok(str_contains($msg(['1001']), 'henger sammen'), 'to deler');
    ok(str_contains($msg(['10', '01']), 'henger sammen'), 'bare i hjørnet');
    ok(str_contains($msg(['110', '101', '111']), 'bare i et hjørne'), 'to ruter som møtes i et hjørne inne i formen');
    ok(str_contains($msg(['111', '101', '111'], ['bottom' => false]), 'utsparing'), 'utsparing uten bunn');
    ok(str_contains($msg(['1x1']), 'Rutenettet'), 'ugyldige tegn');
    ok(str_contains($msg(array_fill(0, 21, '1')), 'Rutenettet'), 'for stort');
    ok(str_contains($msg(['11', '1']), 'Rutenettet'), 'ulik radlengde');
});

test('validering av tall, tekst, valg og prosjekt', function () use ($lego, $lf, $legoProject) {
    foreach (['wall' => [1.2, 3], 'bottom_thickness' => [0.8, 4], 'clearance' => [0, 0.5], 'field_depth' => [8, 25], 'text_depth' => [0.4, 1.2]] as $k => [$lo, $hi]) {
        $legoProject([$k => $lo]);
        $legoProject([$k => $hi]);
        rejects($lego, ['frames' => [[$k => $lo - 0.1] + $lf]], $k);
        rejects($lego, ['frames' => [[$k => $hi + 0.1] + $lf]], $k);
    }
    eq(0.25, $legoProject(['clearance' => '0,25'])['frames'][0]['clearance'], 'desimalkomma');
    rejects($lego, ['frames' => [['wall' => '2; cube(1)'] + $lf]], 'wall');
    rejects($lego, ['frames' => [['field' => 'pocket'] + $lf]], 'field');
    rejects($lego, ['frames' => [['operator' => '%'] + $lf]], 'operator');
    rejects($lego, ['frames' => [['text_mode' => 'custom', 'text' => ''] + $lf]], 'text');
    rejects($lego, ['frames' => [['text_mode' => 'custom', 'text' => 'a"); x'] + $lf]], 'text');
    rejects($lego, ['frames' => [['text_mode' => 'custom', 'text' => '1234567890123'] + $lf]], 'text');
    rejects($lego, ['frames' => [['text_mode' => 'blank'] + $lf]], 'text_mode');
    rejects($lego, ['frames' => [['field' => 'label', 'copies' => 0] + $lf]], 'copies');
    rejects($lego, ['frames' => array_fill(0, 13, $lf)], 'frames');
    rejects($lego, ['frames' => []], 'frames');
    rejects($lego, ['project' => '{nei'], 'project');
    eq('8 − 3 × 2', $legoProject(['text_mode' => 'custom', 'text' => '8 - 3 * 2'])['frames'][0]['text'], '- og * gjøres om');
    $p = $lego->validate(['project' => json_encode(['frames' => [['rows' => ['11']]]])]);
    eq(2, $lego->shape($p['frames'][0])->count(), 'prosjekt som JSON (GET)');
    // Feil på en bestemt ramme sier hvilken
    try {
        $lego->validate(['frames' => [$lf, ['wall' => 9] + $lf]]);
        ok(false);
    } catch (ValidationException $e) {
        eq(1, $e->errors()[0]['frame']);
        ok(str_starts_with($e->errors()[0]['message'], 'Ramme 2:'));
    }
});

test('merkefelt utenfor åpningen, tekst som får plass og melding når den ikke gjør det', function () use ($lego, $legoProject, $rect) {
    foreach (['front', 'back', 'left', 'right'] as $side) {
        foreach ([$rect(4, 4), $rect(1, 6), ['100', '100', '111'], ['111', '101', '111']] as $rows) {
            $g = $lego->geometry($legoProject(['rows' => $rows, 'field_side' => $side])['frames'][0]);
            [$fx0, $fy0, $fx1, $fy1] = $g['field']['face'];
            // Feltet ligger utenfor ytterveggen: ingen overlapp med åpningen
            foreach ($g['cells'] as [$c, $yi]) {
                $x0 = $c * 8 - $g['clearance'] + $g['origin'][0];
                $y0 = $yi * 8 - $g['clearance'] + $g['origin'][1];
                $overlap = min($fx1, $x0 + 8.4) - max($fx0, $x0) > 1e-9 && min($fy1, $y0 + 8.4) - max($fy0, $y0) > 1e-9;
                ok(!$overlap, "$side: feltet overlapper åpningen");
            }
            ok($g['text']['fits'], "$side " . json_encode($rows) . ': tallet får plass');
        }
    }
    try {
        $legoProject(['rows' => ['1'], 'text_mode' => 'custom', 'text' => '100 + 200']);
        ok(false, 'for lang tekst skal avvises');
    } catch (ValidationException $e) {
        ok(str_contains($e->errors()[0]['message'], 'for lang'));
    }
});

test('flere rammer: regnestykke, filnavn, duplikater og beskrivelse', function () use ($lego, $legoProject, $rect) {
    $p = $legoProject(['rows' => $rect(4, 2), 'operator' => '+'], ['rows' => $rect(2, 2), 'operator' => '='], ['rows' => $rect(4, 3)]);
    eq('8 + 4 = 12', $lego->equation($p));
    $files = array_column($lego->parts($p), 'filename');
    eq(['lego-ramme-01-4x2-8.stl', 'lego-ramme-02-2x2-4.stl', 'lego-ramme-03-4x3-12.stl'], $files);
    eq('lego-ramme-01-8.stl', $lego->filename($legoProject(['rows' => ['100', '100', '111', '111']])['frames'][0], 0), 'uregelmessig: bare mengden (1 + 1 + 3 + 3)');
    eq('regn-med-lego-3-rammer', $lego->archiveName($p));
    $d = $lego->describe($p);
    eq([3, 24], [count($d['frames']), $d['total']]);
    ok(str_contains($lego->archiveReadme($p, $lego->parts($p)), 'uprøvd'));
    // Like rammer deler hurtigbuffer; regnetegn og etiketter påvirker ikke STL-fila
    $a = $legoProject(['operator' => '+', 'copies' => 3, 'field' => 'label']);
    $b = $legoProject(['operator' => '=', 'copies' => 9, 'field' => 'label']);
    eq($lego->parts($a)[0]['cache_key'], $lego->parts($b)[0]['cache_key']);
});

test('SCAD-variabler er bare tall og lister med tall (formen og teksten som tegnkoder)', function () use ($lego, $legoProject) {
    $v = $lego->scadVariables($legoProject(['text_mode' => 'custom', 'text' => '8 + 8'])['frames'][0]);
    foreach ($v as $k => $x) {
        ok(is_int($x) || is_float($x) || (is_array($x) && array_is_list($x) && !array_filter($x, fn($y) => !is_int($y) && !is_float($y))), "$k er ikke tall");
    }
    eq([56, 32, 43, 32, 56], $v['text_codes']);
    expectGenerationError(fn() => ScadGenerator::fromConfig(app_config())->render('lego/frame.scad', ['cells' => ['1; x']], sys_get_temp_dir() . '/x.stl'));
});

/* ===================================================================== STL */

echo "\nRegn med Lego: STL (OpenSCAD)\n";

$legoBuild = function (array $frame) use ($lego, $lf, $scadFor, $tmp, $config): array {
    $files = new GeneratedFiles("$tmp/storage-lego", 3600);
    $b = new ModelBuilder($lego, $scadFor($config['models_dir']), new StlInspector(), $files);
    $p = $lego->validate(['frames' => [$frame + $lf]]);
    $meta = $b->buildPart($lego->parts($p)[0]);
    $meta['path'] = $files->file($meta['token'], 'model.stl');
    return [$p['frames'][0], $lego->geometry($p['frames'][0]), $meta];
};

/** Hjørnepunktene i en STL (unike). */
$stlPoints = function (string $file): array {
    $pts = [];
    foreach (stlTriangles($file) as $t) {
        foreach ($t as $p) {
            $pts[sprintf('%.3f,%.3f,%.3f', ...$p)] = $p;
        }
    }
    return array_values($pts);
};

test('4 × 4 med og uten bunn: lukket, riktige mål, volum lik beregningen, åpen gjennom uten bunn', function () use ($legoBuild, $stlPoints) {
    foreach ([true, false] as $bottom) {
        [, $g, $m] = $legoBuild(['bottom' => $bottom, 'field' => 'none']);
        $r = $m['check'];
        ok($r['ok'], implode('; ', $r['problems']));
        eq([36.4, 36.4, $bottom ? 11.1 : 9.6], $r['size_mm']);
        near($g['volume'], $r['volume_mm3'], 0.05, 'volum');
        eq($bottom ? 2 : 0, $r['euler'], $bottom ? 'lomme' : 'åpen gjennom (hull)');
        // Åpningens kanter ligger ved ±16,2 mm (32,4 mm innvendig)
        $xs = array_unique(array_map(fn($p) => round(abs($p[0]), 3), $stlPoints($m['path'])));
        ok(in_array(16.2, $xs, false) && in_array(18.2, $xs, false), 'innvendig 32,4 og utvendig 36,4 mm');
        if ($bottom) {
            $zs = array_unique(array_map(fn($p) => round($p[2], 3), $stlPoints($m['path'])));
            sort($zs);
            eq([0.0, 1.5, 11.1], array_values($zs), 'bunn på 1,5 mm, innvendig høyde 9,6 mm');
        }
    }
});

test('L-form: ingen innvendige skillevegger, klaring i innvendige hjørner', function () use ($legoBuild, $stlPoints) {
    [, $g, $m] = $legoBuild(['rows' => ['100', '100', '111'], 'field' => 'none']);
    ok($m['check']['ok'], implode('; ', $m['check']['problems']));
    near($g['volume'], $m['check']['volume_mm3'], 0.05);
    [$ox, $oy] = $g['origin'];
    $cl = $g['clearance'];
    // Ingen punkter over bunnen inne i åpningen (en skillevegg ville hatt det)
    foreach ($stlPoints($m['path']) as [$x, $y, $z]) {
        if ($z <= $g['bottom'] + 1e-3) {
            continue;
        }
        $lx = $x - $ox;
        $ly = $y - $oy;
        $inside = ($lx > -$cl + 0.05 && $lx < 8 + $cl - 0.05 && $ly > -$cl + 0.05 && $ly < 24 + $cl - 0.05)
            || ($lx > -$cl + 0.05 && $lx < 24 + $cl - 0.05 && $ly > -$cl + 0.05 && $ly < 8 + $cl - 0.05);
        ok(!$inside, sprintf('punkt inne i åpningen (%.2f, %.2f, %.2f)', $lx, $ly, $z));
    }
    // Det innvendige hjørnet i L-en har klaring: (8 + 0,2, 8 + 0,2)
    $found = false;
    foreach ($stlPoints($m['path']) as [$x, $y]) {
        $found = $found || (abs($x - $ox - (8 + $cl)) < 1e-3 && abs($y - $oy - (8 + $cl)) < 1e-3);
    }
    ok($found, 'innvendig hjørne flyttet med klaringen');
});

test('utsparing: hull gjennom rammen og bunnen, med klaring mot klossene', function () use ($legoBuild, $stlPoints) {
    [, $g, $m] = $legoBuild(['rows' => ['111', '101', '111'], 'field' => 'none']);
    ok($m['check']['ok'], implode('; ', $m['check']['problems']));
    eq(0, $m['check']['euler'], 'ett hull gjennom');
    near($g['volume'], $m['check']['volume_mm3'], 0.05);
    [$ox, $oy] = $g['origin'];
    $xs = [];
    foreach ($stlPoints($m['path']) as [$x, $y]) {
        $xs[(string) round($x - $ox, 3)] = true;
    }
    ok(isset($xs['8.2']) && isset($xs['15.8']), 'veggen rundt utsparingen står 0,2 mm fra knottplassene');
    ok(isset($xs['10.2']) && isset($xs['13.8']), 'hullet er 3,6 mm (8 − 2 · 2,2)');
});

test('vegg 1,2–3 mm, klaring 0–0,5, bunn 0,8–4: gyldige og målriktige', function () use ($legoBuild) {
    foreach ([['wall' => 1.2, 'clearance' => 0], ['wall' => 3, 'clearance' => 0.5, 'bottom_thickness' => 4], ['wall' => 2, 'bottom_thickness' => 0.8]] as $in) {
        [, $g, $m] = $legoBuild($in + ['field' => 'none', 'rows' => ['1110', '1111', '0111']]);
        ok($m['check']['ok'], json_encode($in) . ': ' . implode('; ', $m['check']['problems']));
        near($g['volume'], $m['check']['volume_mm3'], 0.05, json_encode($in));
    }
});

test('innfelt og opphøyd tekst ligger innenfor merkefeltet, alle sider, også lange uttrykk', function () use ($legoBuild, $stlPoints) {
    $cases = [
        ['field_side' => 'front'], ['field_side' => 'back', 'text_mode' => 'custom', 'text' => '8 + 8'],
        ['field_side' => 'left', 'rows' => ['11', '11', '11', '11', '11']], ['field_side' => 'right', 'text_mode' => 'custom', 'text' => '12 ÷ 3'],
        ['field_side' => 'front', 'text_style' => 'raised', 'text_mode' => 'custom', 'text' => '3 × 4 = 12', 'rows' => ['111111']],
        ['field_side' => 'back', 'rows' => ['1'], 'bottom' => false],
        ['field_side' => 'front', 'text_mode' => 'custom', 'text' => 'Åtte', 'field_depth' => 20],
    ];
    foreach ($cases as $in) {
        [$f, $g, $m] = $legoBuild($in);
        ok($m['check']['ok'], json_encode($in) . ': ' . implode('; ', $m['check']['problems']));
        $raised = $f['text_style'] === 'raised';
        $z = $raised ? $g['height'] + $g['text']['depth'] : $g['height'] - $g['text']['depth'];
        [$x0, $y0, $x1, $y1] = $g['field']['face'];
        $n = 0;
        foreach ($stlPoints($m['path']) as [$x, $y, $pz]) {
            if (abs($pz - $z) > 1e-3) {
                continue;
            }
            $n++;
            ok($x > $x0 + 0.3 && $x < $x1 - 0.3 && $y > $y0 + 0.3 && $y < $y1 - 0.3,
                json_encode($in) . sprintf(': tekst utenfor feltet (%.2f, %.2f) felt [%.1f, %.1f, %.1f, %.1f]', $x, $y, $x0, $y0, $x1, $y1));
        }
        ok($n > 10, json_encode($in) . ': fant ikke teksten');
        if ($raised) {
            ok($m['check']['volume_mm3'] > $g['volume'], 'opphøyd tekst legger til');
        } else {
            ok($m['check']['volume_mm3'] < $g['volume'] && $m['check']['volume_mm3'] > $g['volume'] * 0.97, 'innfelt tekst tar litt bort');
        }
    }
});

test('innfelt tekst går aldri gjennom: dybden er høyst høyden minus 1,5 mm', function () use ($lego, $legoProject) {
    $g = $lego->geometry($legoProject(['bottom' => false, 'text_depth' => 1.2])['frames'][0]);
    ok($g['text']['depth'] <= $g['height'] - 1.5);
    eq(1.2, $g['text']['depth']);
});

/* ===================================================================== PDF */

echo "\nRegn med Lego: PDF\n";

test('PDF: oversikt og etiketter i faktisk størrelse, klippelinjer, 100 %-varsel, kontrollinje, regnetegn-kort', function () use ($lego, $legoProject, $rect, $k) {
    $p = $legoProject(['rows' => $rect(4, 2), 'field' => 'label', 'copies' => 2, 'operator' => '+'],
        ['rows' => $rect(2, 2), 'field' => 'label', 'text_mode' => 'blank', 'operator' => '='],
        ['rows' => $rect(4, 3), 'field' => 'text']);
    $pdf = $lego->renderPdf($p);
    ok(str_starts_with($pdf, '%PDF'));
    $c = pdfContent($pdf);
    foreach (['(Regn med Lego)', '(Etiketter)', '(Skriv ut i 100 %', '(Kontrollinje 100 mm)', '(Ramme 1  ·  plass til 8 knotter)',
              '(STL: lego-ramme-01-4x2-8.stl)', '(Mellom ramme 1 og 2)', '(Ramme 1 · 1/2'] as $s) {
        ok(str_contains($c, LabelPdf::enc($s)), "mangler «{$s}»");
    }
    // Etikettene har nøyaktig målene til etikettfeltene (felt − 2 · 1 mm)
    foreach ([0, 1] as $i) {
        $g = $lego->geometry($p['frames'][$i]);
        $w = $g['field']['length'] - 2;
        $h = $g['field']['depth'] - 2;
        eq([$w, $h], [$g['label']['width'], $g['label']['height']]);
        $re = sprintf('/ %s %s re S/', preg_quote(sprintf('%.3F', $w * $k), '/'), preg_quote(sprintf('%.3F', -$h * $k), '/'));
        ok((bool) preg_match($re, $c), "etikett $w × $h mm finnes ikke i PDF-en");
    }
    // 2 + 1 etiketter og 2 regnetegn-kort på 30 mm
    $card = sprintf('%.3F %.3F re B', 30 * $k, -30 * $k);
    eq(2, substr_count($c, $card), 'regnetegn-kort');
    $noCards = pdfContent($lego->renderPdf($lego->validate(['frames' => $p['frames'], 'operator_cards' => false])));
    eq(0, substr_count($noCards, $card));
});

test('PDF: tekst innenfor arket for ett og tolv rammer', function () use ($lego, $lf, $k) {
    foreach ([1, 12] as $n) {
        $frames = array_fill(0, $n, ['field' => 'label', 'copies' => 4, 'operator' => '+'] + $lf);
        $c = pdfContent($lego->renderPdf($lego->validate(['frames' => $frames])));
        preg_match_all('/BT ([\d.\-]+) ([\d.\-]+) Td/', $c, $m, PREG_SET_ORDER);
        foreach ($m as [, $x, $y]) {
            $xm = $x / $k;
            $ym = 297 - $y / $k;
            ok($xm >= 8 && $xm <= 202 && $ym >= 8 && $ym <= 292, "$n rammer: tekst utenfor arket ($xm, $ym)");
        }
    }
});
