<?php
/**
 * Tester for romfigurer og utbrettingsmodeller. Tas inn av tests/run.php og
 * bruker hjelpefunksjonene der (test, ok, eq, near, rejects, stlTriangles …).
 */
declare(strict_types=1);

echo "\nRomfigurer: geometrimodell og validering\n";

$sol = SolidModel::load(__DIR__ . '/../app/config');
$shapes = ['cube', 'cuboid', 'pyramid', 'triprism', 'cylinder', 'cone'];

/** Målsett som dekker grensene for alle figurer. */
$solidSets = [
    ['shape' => 'cube'], ['shape' => 'cube', 'cube_side' => 20], ['shape' => 'cube', 'cube_side' => 100],
    ['shape' => 'cuboid'], ['shape' => 'cuboid', 'cuboid_length' => 150, 'cuboid_width' => 20, 'cuboid_height' => 100],
    ['shape' => 'cuboid', 'cuboid_length' => 20, 'cuboid_width' => 100, 'cuboid_height' => 20],
    ['shape' => 'pyramid'], ['shape' => 'pyramid', 'pyramid_base' => 100, 'pyramid_height' => 20],
    ['shape' => 'pyramid', 'pyramid_base' => 20, 'pyramid_height' => 100], ['shape' => 'pyramid', 'pyramid_base' => 100, 'pyramid_height' => 100],
    ['shape' => 'triprism'], ['shape' => 'triprism', 'prism_base' => 100, 'prism_height' => 20, 'prism_length' => 20],
    ['shape' => 'triprism', 'prism_base' => 20, 'prism_height' => 100, 'prism_length' => 150],
    ['shape' => 'cylinder'], ['shape' => 'cylinder', 'cylinder_diameter' => 20, 'cylinder_height' => 20],
    ['shape' => 'cylinder', 'cylinder_diameter' => 100, 'cylinder_height' => 150],
    ['shape' => 'cone'], ['shape' => 'cone', 'cone_diameter' => 100, 'cone_height' => 20],
    ['shape' => 'cone', 'cone_diameter' => 20, 'cone_height' => 150], ['shape' => 'cone', 'cone_diameter' => 100, 'cone_height' => 150],
];

test('standardverdier og figurer fra spesifikasjonen', function () use ($sol, $shapes) {
    $d = $sol->defaults();
    eq('cube', $d['shape']);
    eq([40.0, 60.0, 40.0, 30.0, 50.0, 50.0, 50.0, 40.0, 70.0, 50.0, 70.0, 60.0, 70.0],
        [$d['cube_side'], $d['cuboid_length'], $d['cuboid_width'], $d['cuboid_height'], $d['pyramid_base'], $d['pyramid_height'],
         $d['prism_base'], $d['prism_height'], $d['prism_length'], $d['cylinder_diameter'], $d['cylinder_height'], $d['cone_diameter'], $d['cone_height']]);
    eq([false, 2.0, 7.0, 2, 'tiles', 'net'], [$d['hollow'], $d['wall'], $d['tab_width'], $d['level'], $d['net_print'], $d['document']]);
    eq($shapes, array_keys($sol->schema()['shapes']));
    foreach ($shapes as $s) {
        ok($sol->solid($sol->validate(['shape' => $s])) instanceof Solid);
    }
});

test('validering: grenser, ugyldige figurer og valg avvises', function () use ($sol) {
    foreach (['cube_side' => [20, 100], 'cuboid_length' => [20, 150], 'cone_height' => [20, 150], 'wall' => [1.2, 4], 'tab_width' => [5, 12]] as $k => [$lo, $hi]) {
        $sol->validate([$k => $lo]);
        $sol->validate([$k => $hi]);
        rejects($sol, [$k => $lo - 0.5], $k);
        rejects($sol, [$k => $hi + 0.5], $k);
    }
    rejects($sol, ['shape' => 'sphere'], 'shape');
    rejects($sol, ['shape' => '../cube'], 'shape');
    rejects($sol, ['cube_side' => '40; rm -rf /'], 'cube_side');
    rejects($sol, ['level' => '4'], 'level');
    rejects($sol, ['net_print' => 'fit'], 'net_print');
    rejects($sol, ['document' => 'x'], 'document');
    rejects($sol, ['hollow' => 'kanskje'], 'hollow');
    eq(3, $sol->validate(['level' => 3])['level']);
    eq(45.5, $sol->validate(['cube_side' => '45,5'])['cube_side']);
});

test('kube: mål, 6 flater (kvadrater), 12 kanter, 8 hjørner, areal og volum', function () use ($sol) {
    $s = $sol->solid($sol->validate(['shape' => 'cube']));
    eq([40.0, 40.0, 40.0], $s->size());
    $c = $s->counts();
    eq([6, 12, 8, 0], [$c['faces'], $c['edges'], $c['vertices'], $c['curved_faces']]);
    eq('6 kvadrater', $s->surfaceShapes());
    near(9600, $s->surfaceArea(), 1e-9);
    near(64000, $s->volume(), 1e-9);
    near(1600, $s->baseArea(), 1e-9);
    foreach ($s->edges() as $e) {
        near(40, $e['length'], 1e-9, 'alle kantene er 40 mm');
        eq(2, count($e['faces']), 'hver kant deles av to flater');
    }
    eq(9, $s->symmetryPlanes());
});

test('rektangulært prisme: dimensjoner, overflate og volum', function () use ($sol) {
    $s = $sol->solid($sol->validate(['shape' => 'cuboid']));
    eq([60.0, 40.0, 30.0], $s->size());
    near(2 * (60 * 40 + 60 * 30 + 40 * 30), $s->surfaceArea(), 1e-9);
    near(72000, $s->volume(), 1e-9);
    eq('6 rektangler', $s->surfaceShapes());
    $c = $s->counts();
    eq([6, 12, 8], [$c['faces'], $c['edges'], $c['vertices']]);
    eq(3, $s->symmetryPlanes());
    eq(5, $sol->solid($sol->validate(['shape' => 'cuboid', 'cuboid_width' => 30]))->symmetryPlanes());
});

test('pyramide: skråhøyde √(h² + (g/2)²), trekantflater, 5 flater, 8 kanter, 5 hjørner, volum', function () use ($sol) {
    $s = $sol->solid($sol->validate(['shape' => 'pyramid', 'pyramid_base' => 60, 'pyramid_height' => 40]));
    near(50.0, $s->slant(), 1e-12, 'skråhøyde (30-40-50)');
    near(sqrt(50 ** 2 + 30 ** 2), $s->lateralEdge(), 1e-12);
    $c = $s->counts();
    eq([5, 8, 5], [$c['faces'], $c['edges'], $c['vertices']]);
    eq('1 kvadrat og 4 trekanter', $s->surfaceShapes());
    foreach ($s->faces() as $f) {
        if ($f['shape'] === 'trekant') {
            near(60 * 50 / 2, $f['area'], 1e-9, 'trekant = g · s / 2, ikke g · h / 2');
            near(60 * 50 / 2, Solid::polygonArea3($f['polygon']), 1e-9, 'flatens punkter har samme areal');
        }
    }
    near(3600 + 2 * 60 * 50, $s->surfaceArea(), 1e-9);
    near(60 * 60 * 40 / 3, $s->volume(), 1e-9);
});

test('trekantet prisme: skrå sider, rektangler og trekanter, 5 flater, 9 kanter, 6 hjørner', function () use ($sol) {
    $s = $sol->solid($sol->validate(['shape' => 'triprism', 'prism_base' => 60, 'prism_height' => 40, 'prism_length' => 70]));
    near(50.0, $s->side(), 1e-12, 'a = √(30² + 40²)');
    $c = $s->counts();
    eq([5, 9, 6], [$c['faces'], $c['edges'], $c['vertices']]);
    $areas = array_map(fn($f) => round($f['area'], 6), $s->faces());
    sort($areas);
    eq([1200.0, 1200.0, 3500.0, 3500.0, 4200.0], $areas, 'to trekanter og rektangler 50 × 70, 50 × 70, 60 × 70');
    near(1200 * 70, $s->volume(), 1e-9);
    near(1200, $s->baseArea(), 1e-9);
    eq(2, $s->symmetryPlanes());
});

test('sylinder: 3 flater (1 krum), 2 sirkelkanter, 0 hjørner, omkrets, volum', function () use ($sol) {
    $s = $sol->solid($sol->validate(['shape' => 'cylinder']));
    $c = $s->counts();
    eq([3, 1, 2, 2, 0], [$c['faces'], $c['curved_faces'], $c['edges'], $c['curved_edges'], $c['vertices']]);
    ok(str_contains((string) $c['note'], 'krumme'));
    near(M_PI * 50, $s->circumference(), 1e-12);
    near(M_PI * 25 ** 2 * 70, $s->volume(), 1e-9);
    near(2 * M_PI * 625 + M_PI * 50 * 70, $s->surfaceArea(), 1e-9);
    ok(is_infinite((float) $s->symmetryPlanes()));
});

test('kjegle: sidelinje, sektorvinkel 2π · r / s, buelengde = omkrets, 2 flater, 1 kant, 1 toppunkt', function () use ($sol) {
    $s = $sol->solid($sol->validate(['shape' => 'cone', 'cone_diameter' => 60, 'cone_height' => 40]));
    near(50.0, $s->slant(), 1e-12, 's = √(40² + 30²)');
    near(2 * M_PI * 30 / 50, $s->sectorAngle(), 1e-12);
    near(216.0, $s->measures()['sector_angle']['value'], 1e-9, '360° · 30 / 50');
    near(2 * M_PI * 30, $s->sectorAngle() * $s->slant(), 1e-9, 'buelengde = omkrets');
    $c = $s->counts();
    eq([2, 1, 1, 1, 1, 'toppunkt'], [$c['faces'], $c['curved_faces'], $c['edges'], $c['curved_edges'], $c['vertices'], $c['vertex_word']]);
    near(M_PI * 900 * 40 / 3, $s->volume(), 1e-9);
    near(M_PI * 900 + M_PI * 30 * 50, $s->surfaceArea(), 1e-9);
});

test('Eulers formel F − K + H = 2 for alle mangeflatene, ved alle mål', function () use ($sol, $solidSets) {
    foreach ($solidSets as $in) {
        if (in_array($in['shape'], ['cylinder', 'cone'], true)) {
            continue;
        }
        $c = $sol->solid($sol->validate($in))->counts();
        eq(2, $c['faces'] - $c['edges'] + $c['vertices'], json_encode($in));
    }
});

test('flatenes plan: normalene peker ut, og alle hjørnene ligger i planet', function () use ($sol, $shapes) {
    foreach ($shapes as $sh) {
        $s = $sol->solid($sol->validate(['shape' => $sh]));
        $c = [0, 0, $s->size()[2] / 2];
        foreach ($s->faces() as $f) {
            if (!isset($f['polygon'])) {
                continue;
            }
            ok(Solid::dot($f['plane']['n'], Solid::sub(Solid::centroid3($f['polygon']), $c)) > 0, "$sh {$f['id']}: normalen peker innover");
            foreach ($f['polygon'] as $p) {
                near($f['plane']['d'], Solid::dot($f['plane']['n'], $p), 1e-9, "$sh {$f['id']}: punkt utenfor planet");
            }
        }
    }
});

/* ===================================================================== Nett */

echo "\nRomfigurer: utbrettingsnett og A4\n";

/** Flatene i nettet: [flate-id => [areal, sidelengder]] */
$netFaces = function (SolidNet $net): array {
    $out = [];
    foreach ($net->regions as $i => $r) {
        if ($r['kind'] !== 'face') {
            continue;
        }
        $lens = [];
        foreach ($r['edges'] as $e) {
            if ($e[0] === 'L') {
                $lens[] = round(hypot($e[2][0] - $e[1][0], $e[2][1] - $e[1][1]), 6);
            }
        }
        sort($lens);
        $out[$r['face']] = ['area' => $net->area($i), 'lens' => $lens, 'edges' => $r['edges']];
    }
    return $out;
};

test('alle nettvarianter: hver flate er med, med samme areal og sidelengder som i 3D', function () use ($sol, $solidSets, $netFaces) {
    foreach ($solidSets as $in) {
        $p = $sol->validate($in);
        $s = $sol->solid($p);
        foreach ($s->nets($p['tab_width']) as $net) {
            $faces = $netFaces($net);
            eq(count($s->faces()), count($faces), json_encode($in) . " {$net->variant}: antall flater");
            foreach ($s->faces() as $f) {
                ok(isset($faces[$f['id']]), "{$net->variant}: mangler {$f['id']}");
                near($f['area'], $faces[$f['id']]['area'], 1e-6 * max(1, $f['area']), json_encode($in) . " {$net->variant} {$f['id']}: areal");
                if (isset($f['polygon'])) {
                    $lens = [];
                    $n = count($f['polygon']);
                    for ($i = 0; $i < $n; $i++) {
                        $lens[] = round(Solid::dist($f['polygon'][$i], $f['polygon'][($i + 1) % $n]), 6);
                    }
                    sort($lens);
                    eq($lens, $faces[$f['id']]['lens'], json_encode($in) . " {$net->variant} {$f['id']}: sidelengder");
                }
            }
        }
    }
});

test('kube: klassisk korsnett med seks kvadrater, 5 bretter mellom flater og 7 limfliker', function () use ($sol, $netFaces) {
    $p = $sol->validate(['shape' => 'cube']);
    $net = $sol->solid($p)->nets(7)[0];
    eq('kors', $net->variant);
    $faces = $netFaces($net);
    eq(6, count($faces));
    foreach ($faces as $f) {
        eq([40.0, 40.0, 40.0, 40.0], $f['lens']);
    }
    eq(7, count(array_filter($net->regions, fn($r) => $r['kind'] === 'tab')));
    $lines = $net->lines();
    eq(12, count($lines['folds']), '5 bretter mellom flater + 7 under limflikene');
    eq(120.0, round($net->width(), 6), 'bredde uten flikene på sidene: 3 kvadrater');
});

test('sylinder: rektangelet er π · d langt og h høyt; sirklene har diameter d', function () use ($sol, $netFaces) {
    $p = $sol->validate(['shape' => 'cylinder']);
    foreach ($sol->solid($p)->nets(7) as $net) {
        $f = $netFaces($net);
        eq([70.0, 70.0, round(M_PI * 50, 6), round(M_PI * 50, 6)], $f['side']['lens'], $net->variant);
        foreach (['top', 'bottom'] as $id) {
            eq('A', $f[$id]['edges'][0][0]);
            near(25.0, $f[$id]['edges'][0][2], 1e-12);
            near(2 * M_PI, $f[$id]['edges'][0][4] - $f[$id]['edges'][0][3], 1e-12);
        }
    }
});

test('kjegle: sektorradius = s, sektorvinkel = 2π · r / s, buelengde = π · d', function () use ($sol, $netFaces) {
    $p = $sol->validate(['shape' => 'cone']);
    $s = $sol->solid($p);
    foreach ($s->nets(7) as $net) {
        $f = $netFaces($net)['side'];
        $arc = array_values(array_filter($f['edges'], fn($e) => $e[0] === 'A'))[0];
        near($s->slant(), $arc[2], 1e-9, 'radius');
        near($s->sectorAngle(), $arc[4] - $arc[3], 1e-9, 'vinkel');
        near(M_PI * 60, $arc[2] * ($arc[4] - $arc[3]), 1e-9, 'buelengde');
        eq([round($s->slant(), 6), round($s->slant(), 6)], $f['lens'], 'de rette kantene er s');
    }
});

test('pyramide: trekantene i nettet har høyden s (skråhøyden), ikke h', function () use ($sol, $netFaces) {
    $p = $sol->validate(['shape' => 'pyramid', 'pyramid_base' => 60, 'pyramid_height' => 40]);
    foreach ($sol->solid($p)->nets(7) as $net) {
        foreach (['front', 'right', 'back', 'left'] as $id) {
            $f = $netFaces($net)[$id];
            near(1500.0, $f['area'], 1e-6, 'g · s / 2 = 60 · 50 / 2');
            eq([round(sqrt(2500 + 900), 6), round(sqrt(2500 + 900), 6), 60.0], $f['lens']);
        }
    }
});

test('limfliker kolliderer ikke med flater eller andre flikker (alle varianter, ytterverdier, 5–12 mm)', function () use ($sol, $solidSets) {
    foreach ($solidSets as $in) {
        foreach ([5, 12] as $tw) {
            $p = $sol->validate($in + ['tab_width' => $tw]);
            $nets = $sol->solid($p)->nets($tw);
            // Den valgte varianten og de to første (de andre er rotasjoner av dem)
            $check = array_unique([0, 1, $sol->net($p)['plan']['variant']]);
            foreach ($check as $vi) {
                if (!isset($nets[$vi])) {
                    continue;
                }
                $net = $nets[$vi];
                $n = count($net->regions);
                for ($i = 0; $i < $n; $i++) {
                    for ($j = $i + 1; $j < $n; $j++) {
                        $a = $net->overlapArea($i, $j, 1.0);
                        ok($a < 0.05, json_encode($in) . " flik $tw {$net->variant}: region $i og $j overlapper ($a mm²)");
                    }
                }
            }
        }
    }
});

test('limflikene endrer ikke flatene (samme nett med 5 og 12 mm flik)', function () use ($sol, $shapes, $netFaces) {
    foreach ($shapes as $sh) {
        $a = $netFaces($sol->solid($sol->validate(['shape' => $sh]))->nets(5)[0]);
        $b = $netFaces($sol->solid($sol->validate(['shape' => $sh]))->nets(12)[0]);
        foreach ($a as $id => $f) {
            near($f['area'], $b[$id]['area'], 1e-6, "$sh $id");
            eq($f['lens'], $b[$id]['lens'], "$sh $id");
        }
    }
});

test('klipp og brett: hver limflik har nøyaktig én brettelinje, resten klippes', function () use ($sol, $shapes) {
    foreach ($shapes as $sh) {
        $net = $sol->net($sol->validate(['shape' => $sh]))['net'];
        $lines = $net->lines();
        ok(count($lines['cuts']) > 0 && count($lines['folds']) > 0, $sh);
        // Hver flik deler kant med nettet (brett) og har resten klippet.
        foreach ($net->regions as $i => $r) {
            if ($r['kind'] !== 'tab') {
                continue;
            }
            $same = function (array $f, array $e): bool {
                if ($f[0] !== $e[0]) {
                    return false;
                }
                if ($f[0] === 'L') {
                    $d = fn($p, $q) => hypot($p[0] - $q[0], $p[1] - $q[1]);
                    return ($d($f[1], $e[1]) < 1e-6 && $d($f[2], $e[2]) < 1e-6) || ($d($f[1], $e[2]) < 1e-6 && $d($f[2], $e[1]) < 1e-6);
                }
                return abs($f[2] - $e[2]) < 1e-6 && abs($f[3] - $e[3]) < 1e-6 && abs($f[4] - $e[4]) < 1e-6;
            };
            $shared = 0;
            foreach ($r['edges'] as $e) {
                foreach ($lines['folds'] as $f) {
                    $shared += (int) $same($f, $e);
                }
            }
            eq(1, $shared, "$sh: limflik $i skal ha én brettelinje");
        }
    }
});

test('A4: standardmålene får plass på ett ark i målestokk 1:1', function () use ($sol, $shapes) {
    foreach ($shapes as $sh) {
        $plan = $sol->net($sol->validate(['shape' => $sh]))['plan'];
        eq('single', $plan['mode'], $sh);
        eq(1.0, $plan['scale'], $sh);
        [$ax, $ay, $aw, $ah] = NetLayout::area($plan['orientation']);
        [$ox, $oy] = $plan['pages'][0]['origin'];
        ok($ox >= $ax - 1e-9 && $oy >= $ay - 1e-9 && $ox + $plan['size'][0] <= $ax + $aw + 1e-9 && $oy + $plan['size'][1] <= $ay + $ah + 1e-9, "$sh: utenfor arket");
    }
});

test('A4: store nett deles på flere ark i 1:1 som dekker hele nettet, eller skaleres bare når det er valgt', function () use ($sol) {
    foreach ([['shape' => 'cylinder', 'cylinder_diameter' => 100, 'cylinder_height' => 150], ['shape' => 'cube', 'cube_side' => 100],
              ['shape' => 'cone', 'cone_diameter' => 100, 'cone_height' => 150]] as $in) {
        $p = $sol->validate($in);
        $plan = $sol->net($p)['plan'];
        eq('tiles', $plan['mode'], json_encode($in));
        eq(1.0, $plan['scale'], 'flere ark er alltid 1:1');
        ok(count($plan['pages']) >= 2);
        $w = array_map(fn($t) => $t['window'], $plan['pages']);
        ok(min(array_column($w, 0)) <= 0 && min(array_column($w, 1)) <= 0, 'dekker øvre venstre hjørne');
        ok(max(array_map(fn($r) => $r[0] + $r[2], $w)) >= $plan['size'][0] && max(array_map(fn($r) => $r[1] + $r[3], $w)) >= $plan['size'][1], 'dekker hele nettet');
        ok((bool) array_filter($sol->warnings($p), fn($x) => str_contains($x, 'ark')), 'varsel om flere ark');

        $sp = $sol->validate($in + ['net_print' => 'scaled']);
        $scaled = $sol->net($sp)['plan'];
        eq('scaled', $scaled['mode']);
        ok($scaled['scale'] < 1 && str_starts_with($scaled['scale_label'], '1 : '));
        ok((bool) array_filter($sol->warnings($sp), fn($x) => str_contains($x, 'forminsket')), 'varsel om skalering');
    }
});

/* ===================================================================== PDF */

echo "\nRomfigurer: PDF\n";

/** Linjestykker (mm) i PDF-innholdet: «x y m x y l». */
$pdfSegments = function (string $c, float $k): array {
    preg_match_all('/([\d.\-]+) ([\d.\-]+) m ([\d.\-]+) ([\d.\-]+) l/', $c, $m, PREG_SET_ORDER);
    return array_map(fn($s) => hypot(($s[3] - $s[1]) / $k, ($s[4] - $s[2]) / $k), $m);
};

test('PDF-nett: A4, målestokk 1:1, kontrollinje på nøyaktig 50 mm, kanter i full størrelse', function () use ($sol, $k, $pdfSegments) {
    $pdf = $sol->renderPdf($sol->validate(['shape' => 'cube']));
    ok(str_starts_with($pdf, '%PDF'));
    ok(str_contains($pdf, '/MediaBox [0 0 595.28 841.89]'), 'A4 stående');
    $c = pdfContent($pdf);
    foreach (['(Målestokk 1:1. Skriv ut i 100 %', '(Kontrollinje 50 mm:)', '(Klipp)', '(Brett bakover', '(Limflik', '(Bunn)', '(40 × 40 mm)'] as $s) {
        ok(str_contains($c, LabelPdf::enc($s)), "mangler «{$s}»");
    }
    $segs = $pdfSegments($c, $k);
    ok((bool) array_filter($segs, fn($l) => abs($l - 50) < 0.002), 'kontrollinje 50 mm');
    ok(count(array_filter($segs, fn($l) => abs($l - 40) < 0.002)) >= 12, 'kubens kanter er 40 mm');
    ok(!str_contains($c, 'SKALERT'), 'ingen skalering');
    // Brettelinjer er stiplet
    ok(str_contains($c, '] 0 d'), 'stiplede brettelinjer');
});

test('PDF-nett: sylinder i 1:1 – rektangelet er π · d langt', function () use ($sol, $k, $pdfSegments) {
    $c = pdfContent($sol->renderPdf($sol->validate(['shape' => 'cylinder'])));
    ok((bool) array_filter($pdfSegments($c, $k), fn($l) => abs($l - M_PI * 50) < 0.003), 'π · 50 mm');
});

test('PDF-nett: store nett gir oversikt + ark i 1:1 med skjøter; skalert er tydelig merket', function () use ($sol) {
    $in = ['shape' => 'cylinder', 'cylinder_diameter' => 100, 'cylinder_height' => 150];
    $p = $sol->validate($in);
    $plan = $sol->net($p)['plan'];
    $pdf = $sol->renderPdf($p);
    eq(count($plan['pages']) + 1, substr_count($pdf, '/Type /Page') - substr_count($pdf, '/Type /Pages'));
    $c = pdfContent($pdf);
    ok(str_contains($c, LabelPdf::enc('(Oversikt – forminsket')), 'oversikten er merket');
    ok(str_contains($c, LabelPdf::enc('målestokk 1:1. Skriv ut i 100 %')), 'arkene er 1:1');
    ok(str_contains($c, 'Klipp her og legg over A1'), 'skjøtetekst');
    $s = pdfContent($sol->renderPdf($sol->validate($in + ['net_print' => 'scaled'])));
    ok(str_contains($s, '(SKALERT'), 'skalert er merket');
});

test('PDF-oppgaver: seks A4-sider for alle figurer og nivåer, tekst innenfor arket', function () use ($sol, $shapes, $k) {
    foreach ($shapes as $sh) {
        foreach ([1, 2, 3] as $lv) {
            $pdf = $sol->renderPdf($sol->validate(['shape' => $sh, 'document' => 'tasks', 'level' => $lv]));
            $c = pdfContent($pdf);
            $pages = preg_split('/(?=BT \/F\d+ [\d.]+ Tf ET\s*\n?.*?M\+ 3D)/s', $c);
            eq(6, substr_count($pdf, '/Type /Page') - substr_count($pdf, '/Type /Pages'), "$sh nivå $lv");
            foreach (pdfPages($pdf) as $i => $pg) {
                ok(!textOutside($pg), "$sh nivå $lv side " . ($i + 1) . ': tekst utenfor arket ' . implode(' ', textOutside($pg)));
                ok($pg['w'] > $pg['h'], "$sh nivå $lv side " . ($i + 1) . ': skal være liggende');
            }
            foreach (['(Undersøk figuren)', '(Mål figuren)', '(Overflateareal)', '(Volum)', '(Fra papir til romfigur)', '(Fasit)'] as $t) {
                ok(str_contains($c, LabelPdf::enc($t)), "$sh nivå $lv: mangler $t");
            }
        }
    }
});

test('oppgaver tilpasses figur og nivå, og fasiten kommer fra geometrimodellen', function () use ($sol) {
    $all = function (string $sh, int $lv) use ($sol): array {
        $pages = (new SolidTasks($sol->solid($sol->validate(['shape' => $sh])), $lv))->pages();
        $tasks = [];
        foreach ($pages as $pg) {
            foreach ($pg['sections'] as $s) {
                foreach ($s['tasks'] as $t) {
                    $tasks[] = ['q' => implode('', array_map(fn($x) => $x[1] ?? '', $t['tokens'])), 'a' => (string) $t['answer']];
                }
            }
        }
        return $tasks;
    };
    $find = fn(array $tasks, string $needle) => array_values(array_filter($tasks, fn($t) => str_contains($t['q'], $needle)));

    // Euler bare for mangeflater, og bare på nivå 3
    ok((bool) $find($all('cube', 3), 'antall flater − antall kanter'));
    ok(!$find($all('cube', 1), 'antall flater − antall kanter'));
    ok(!$find($all('cylinder', 3), 'antall flater − antall kanter'), 'ikke Euler for sylinder');
    // Kjeglen spør om toppunkt, ikke hjørner
    ok((bool) $find($all('cone', 1), 'toppunkt') || (bool) $find($all('cone', 1), 'spiss'));
    ok(!$find($all('cone', 1), 'Hvor mange hjørner'));
    // Formler bare på nivå 3, telling av terninger på nivå 1
    ok((bool) $find($all('cube', 3), 'O = 6 · s²'));
    ok(!$find($all('cube', 2), 'O = 6 · s²'));
    $cubes = $find($all('cube', 1), 'terninger trengs til sammen');
    ok(str_starts_with($cubes[0]['a'], '64 '), $cubes[0]['a']);
    // Svarene er de samme tallene som geometrimodellen
    $v = $find($all('cone', 3), 'Regn ut volumet');
    eq('66 cm³', $v[0]['a']);           // 65,97 cm³
    $sect = $find($all('cone', 3), 'vinkelen i sirkelsektoren');
    eq('141,8°', $sect[0]['a']);
    $slant = $find($all('pyramid', 3), 'skråhøyden');
    eq('5,6 cm', $slant[0]['a']);
    $o = $find($all('cylinder', 2), 'Legg sammen arealene');
    eq('149,2 cm²', $o[0]['a']);
    // Pyramide og kjegle: en tredjedel
    ok((bool) $find($all('pyramid', 1), 'Hvor mange pyramider med sand'));
    ok(!$find($all('cube', 1), 'med sand'));
});

/* ===================================================================== STL */

echo "\nRomfigurer: STL (OpenSCAD)\n";

$solBuild = function (array $input) use ($sol, $scadFor, $tmp, $config): array {
    $files = new GeneratedFiles("$tmp/storage-solids", 3600);
    $b = new ModelBuilder($sol, $scadFor($config['models_dir']), new StlInspector(), $files);
    $p = $sol->validate($input);
    $meta = $b->buildPart($sol->parts($p)[0]);
    $meta['path'] = $files->file($meta['token'], 'model.stl');
    return [$p, $meta];
};

test('alle figurer, massive og hule: lukkede, manifold, riktige mål og volum', function () use ($sol, $solBuild, $solidSets) {
    foreach ($solidSets as $in) {
        foreach ([false, true] as $hollow) {
            [$p, $meta] = $solBuild($in + ['hollow' => $hollow]);
            $r = $meta['check'];
            ok($r['ok'], json_encode($in) . ($hollow ? ' hul' : '') . ': ' . implode('; ', $r['problems']));
            eq($hollow ? 4 : 2, $r['euler'], 'hul figur har to lukkede skall');
            $s = $sol->solid($p);
            near(0.0, max(array_map(fn($a, $b) => abs($a - $b), $r['size_mm'], $s->size())), 0.002, 'mål');
            $expected = $s->polygonalVolume() - ($hollow ? $s->cavity($p['wall'])['volume'] : 0);
            near($expected, $r['volume_mm3'], max(0.5, $expected * 0.001), json_encode($in) . ($hollow ? ' hul' : '') . ': volum');
        }
    }
});

test('sylinder og kjegle: 128 sider, volum nær det matematiske (forskjellen er dokumentert)', function () use ($sol, $solBuild) {
    foreach (['cylinder', 'cone'] as $sh) {
        [$p, $meta] = $solBuild(['shape' => $sh]);
        $s = $sol->solid($p);
        $rel = ($s->volume() - $meta['check']['volume_mm3']) / $s->volume();
        ok($rel > 0 && $rel < 0.0005, "$sh: STL-volumet er litt mindre enn πr²h (mangekant): $rel");
        ok($meta['check']['triangles'] >= 250);
    }
});

test('ingen overheng: nedoverpekende flater ligger på byggeplaten eller heller minst 45° (også i hulrommet)', function () use ($solBuild, $solidSets) {
    foreach ($solidSets as $in) {
        foreach ([false, true] as $hollow) {
            [, $meta] = $solBuild($in + ['hollow' => $hollow]);
            foreach (stlTriangles($meta['path']) as [$a, $b, $c]) {
                $n = [($b[1] - $a[1]) * ($c[2] - $a[2]) - ($b[2] - $a[2]) * ($c[1] - $a[1]),
                      ($b[2] - $a[2]) * ($c[0] - $a[0]) - ($b[0] - $a[0]) * ($c[2] - $a[2]),
                      ($b[0] - $a[0]) * ($c[1] - $a[1]) - ($b[1] - $a[1]) * ($c[0] - $a[0])];
                $len = sqrt($n[0] ** 2 + $n[1] ** 2 + $n[2] ** 2);
                if ($len < 1e-9 || $n[2] / $len > -0.7072) {
                    continue;
                }
                ok(max($a[2], $b[2], $c[2]) < 1e-4, json_encode($in) . ($hollow ? ' hul' : '') . ': overheng i z = ' . round($a[2], 2));
            }
        }
    }
});

test('hul modell: veggen er minst så tykk som valgt (punkter i hulrommet ligger innenfor figuren)', function () use ($sol, $shapes) {
    foreach ($shapes as $sh) {
        foreach ([1.2, 2, 4] as $t) {
            $p = $sol->validate(['shape' => $sh, 'hollow' => true, 'wall' => $t]);
            $c = $sol->solid($p)->cavity($t);
            ok($c['ok'] && $c['volume'] > 0, "$sh $t");
            ok($c['vars']['c_top'] <= $sol->solid($p)->size()[2] - $t + 1e-9, "$sh: taket i hulrommet er minst $t mm under toppen");
        }
    }
});

test('STL-kontroll: to lukkede skall godtas bare når det er forventet', function () use ($solBuild) {
    [, $meta] = $solBuild(['shape' => 'cube', 'hollow' => true]);
    ok(!(new StlInspector())->inspect($meta['path'], null, 0.02, 1)['ok'], 'hul figur skal avvises som ett skall');
    ok((new StlInspector())->inspect($meta['path'], null, 0.02, 2)['ok']);
});

test('filnavn, hurtigbuffer og modellbeskrivelse (JSON)', function () use ($sol) {
    eq('romfigur-kube-40mm.stl', $sol->parts($sol->validate([]))[0]['filename']);
    eq('romfigur-prisme-60x40x30mm-hul.stl', $sol->parts($sol->validate(['shape' => 'cuboid', 'hollow' => 1]))[0]['filename']);
    eq('romfigur-kjegle-60x70mm-nett.pdf', $sol->pdfFilename($sol->validate(['shape' => 'cone'])));
    eq('romfigur-kjegle-60x70mm-oppgaver-niva3.pdf', $sol->pdfFilename($sol->validate(['shape' => 'cone', 'document' => 'tasks', 'level' => 3])));
    // Nett, nivå og andre figurers mål påvirker ikke STL-fila
    $a = $sol->parts($sol->validate(['shape' => 'cube']))[0]['cache_key'];
    eq($a, $sol->parts($sol->validate(['shape' => 'cube', 'tab_width' => 10, 'level' => 3, 'cone_height' => 100, 'wall' => 3]))[0]['cache_key']);
    $d = $sol->describe($sol->validate(['shape' => 'pyramid']));
    foreach (['model', 'shape', 'dimensions', 'geometry', 'net', 'layout', 'stl', 'material_g'] as $key) {
        ok(array_key_exists($key, $d), "mangler $key");
    }
    eq(5, count($d['geometry']['faces']));
    eq(8, count($d['geometry']['edges']));
    ok(json_encode($d) !== false);
});
