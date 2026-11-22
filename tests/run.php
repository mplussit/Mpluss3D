<?php
/**
 * Automatiserte tester for M+ 3D. Ingen eksterne avhengigheter.
 *
 *   php tests/run.php             alle tester
 *   php tests/run.php pdf         bare tester der navnet inneholder «pdf»
 *
 * Tester som krever OpenSCAD hoppes over hvis OpenSCAD ikke finnes.
 */
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

$filter = $argv[1] ?? null;
$results = ['pass' => 0, 'fail' => 0, 'skip' => 0];
$tmp = sys_get_temp_dir() . '/mplus3d-tests-' . bin2hex(random_bytes(4));
mkdir($tmp);

final class SkipTest extends Exception {}

function test(string $name, callable $fn): void
{
    global $filter, $results;
    if ($filter && stripos($name, $filter) === false) {
        return;
    }
    try {
        $fn();
        $results['pass']++;
        echo "  \033[32m✓\033[0m $name\n";
    } catch (SkipTest $e) {
        $results['skip']++;
        echo "  \033[33m-\033[0m $name (hoppet over: {$e->getMessage()})\n";
    } catch (Throwable $e) {
        $results['fail']++;
        echo "  \033[31m✗ $name\033[0m\n      " . $e->getMessage() . "\n";
    }
}

function ok(bool $cond, string $msg = 'forventet sann'): void
{
    if (!$cond) {
        throw new RuntimeException($msg);
    }
}

function eq(mixed $expected, mixed $actual, string $msg = ''): void
{
    if ($expected != $actual) {
        throw new RuntimeException(($msg ? "$msg: " : '') . 'forventet ' . json_encode($expected, JSON_UNESCAPED_UNICODE) . ', fikk ' . json_encode($actual, JSON_UNESCAPED_UNICODE));
    }
}

function near(float $expected, float $actual, float $tol, string $msg = ''): void
{
    if (abs($expected - $actual) > $tol) {
        throw new RuntimeException(($msg ? "$msg: " : '') . "forventet $expected ± $tol, fikk $actual");
    }
}

/** Forventer ValidationException med feil på gitt felt. */
function rejects(ParametricModel $m, array $input, string $field): void
{
    try {
        $m->validate($input);
    } catch (ValidationException $e) {
        $fields = array_column($e->errors(), 'field');
        ok(in_array($field, $fields, true), "forventet feil på «{$field}», fikk " . implode(', ', $fields));
        return;
    }
    throw new RuntimeException('forventet valideringsfeil for ' . json_encode($input));
}

function expectGenerationError(callable $fn, ?string $reason = null): GenerationException
{
    try {
        $fn();
    } catch (GenerationException $e) {
        if ($reason !== null) {
            eq($reason, $e->reason(), 'årsak');
        }
        return $e;
    }
    throw new RuntimeException('forventet GenerationException');
}

/** Lager en binær STL fra en liste trekanter. */
function writeStl(string $path, array $tris): void
{
    $data = str_pad('test', 80, "\0") . pack('V', count($tris));
    foreach ($tris as $t) {
        $data .= pack('g3', 0, 0, 0);
        foreach ($t as $v) {
            $data .= pack('g3', ...$v);
        }
        $data .= "\0\0";
    }
    file_put_contents($path, $data);
}

/** Pakker ut alle innholdsstrømmer i en PDF fra FPDF. */
/**
 * Sidene i en FPDF-PDF med innhold og størrelse i mm.
 * @return list<array{c:string,w:float,h:float}>
 */
function pdfPages(string $pdf): array
{
    $k = 72 / 25.4;
    $objects = [];
    preg_match_all('/(\d+) 0 obj\s*<<(.*?)>>\s*(?:stream\r?\n)?/s', $pdf, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
    foreach ($m as $hit) {
        $dict = $hit[2][0];
        $stream = null;
        if (preg_match('/\/Length (\d+)/', $dict, $len) && str_ends_with(rtrim($hit[0][0], "\r\n"), 'stream')) {
            $raw = substr($pdf, $hit[0][1] + strlen($hit[0][0]), (int) $len[1]);
            $u = @gzuncompress($raw);
            $stream = $u === false ? $raw : $u;
        }
        $objects[(int) $hit[1][0]] = ['dict' => $dict, 'stream' => $stream];
    }
    $default = [210.0, 297.0];
    foreach ($objects as $o) {
        if (str_contains($o['dict'], '/Type /Pages') && preg_match('/\/MediaBox \[0 0 ([\d.]+) ([\d.]+)\]/', $o['dict'], $mb)) {
            $default = [$mb[1] / $k, $mb[2] / $k];
        }
    }
    $pages = [];
    foreach ($objects as $o) {
        if (!preg_match('/\/Type \/Page\b(?!s)/', $o['dict'])) {
            continue;
        }
        [$w, $h] = preg_match('/\/MediaBox \[0 0 ([\d.]+) ([\d.]+)\]/', $o['dict'], $mb) ? [$mb[1] / $k, $mb[2] / $k] : $default;
        preg_match('/\/Contents (\d+) 0 R/', $o['dict'], $cn);
        $pages[] = ['c' => (string) ($objects[(int) ($cn[1] ?? 0)]['stream'] ?? ''), 'w' => round($w, 1), 'h' => round($h, 1)];
    }
    return $pages;
}

/** Tekst som ligger utenfor siden (marg: x 10 mm, topp 8 mm, bunn 5 mm). @return list<string> */
function textOutside(array $page, float $mx = 10, float $mtop = 8, float $mbottom = 5): array
{
    $k = 72 / 25.4;
    $bad = [];
    preg_match_all('/BT ([\d.\-]+) ([\d.\-]+) Td/', $page['c'], $m, PREG_SET_ORDER);
    foreach ($m as [, $x, $y]) {
        $xm = $x / $k;
        $ym = $page['h'] - $y / $k;
        if ($xm < $mx || $xm > $page['w'] - $mx || $ym < $mtop || $ym > $page['h'] - $mbottom) {
            $bad[] = sprintf('(%.1f, %.1f)', $xm, $ym);
        }
    }
    return $bad;
}

function pdfContent(string $pdf): string
{
    // Les nøyaktig /Length byte (komprimerte data kan slutte med \r).
    $out = '';
    preg_match_all('/\/Length (\d+)[^>]*>>\s*stream\r?\n/', $pdf, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
    foreach ($m as $hit) {
        $s = substr($pdf, $hit[0][1] + strlen($hit[0][0]), (int) $hit[1][0]);
        $u = @gzuncompress($s);
        $out .= ($u === false ? $s : $u) . "\n";
    }
    return $out;
}

$config = app_config();
$model = DiceModel::load(__DIR__ . '/../app/config');
$binary = $config['openscad']['binary'] ?: ScadGenerator::detectBinary();

// Tetraeder: lukket og riktig orientert (normaler utover).
$tetra = [
    [[0, 0, 0], [0, 1, 0], [1, 0, 0]],
    [[0, 0, 0], [1, 0, 0], [0, 0, 1]],
    [[0, 0, 0], [0, 0, 1], [0, 1, 0]],
    [[1, 0, 0], [0, 1, 0], [0, 0, 1]],
];

echo "\nParametere og validering\n";

test('standardverdier stemmer med spesifikasjonen', function () use ($model) {
    $d = $model->defaults();
    eq([30.0, 30.0, 30.0, 2.0, 3.0, 0.5], [$d['width'], $d['height'], $d['depth'], $d['corner_radius'], $d['pip_diameter'], $d['pip_depth']]);
    eq('pips', $d['decor']);
    $p = $model->validate([]);
    eq(30.0, $p['width']);
    eq(true, $p['label_auto']);
});

test('automatisk etikett = flat del minus 2 × 0,3 mm klaring', function () use ($model) {
    eq(25.4, $model->validate([])['label_size']);
    eq(29.4, $model->validate(['corner_radius' => 0])['label_size']);
    eq(41.4, $model->validate(['size' => 50, 'corner_radius' => 4, 'pip_diameter' => 5])['label_size']);
});

test('grenseverdier godtas', function () use ($model) {
    foreach ($model->definition()['parameters'] as $key => $spec) {
        foreach ([$spec['min'], $spec['max']] as $v) {
            $input = [$key => $v];
            if ($key === 'pip_diameter' && $v == 5) {
                $input['corner_radius'] = 0;
            }
            if (in_array($key, ['width', 'height', 'depth'], true) && $v == 15) {
                $input += ['pip_diameter' => 2, 'corner_radius' => 1];
            }
            $p = $model->validate($input);
            eq((float) $v, $p[$key], $key);
        }
    }
});

test('verdier utenfor grensene avvises', function () use ($model) {
    foreach ($model->definition()['parameters'] as $key => $spec) {
        rejects($model, [$key => $spec['min'] - 0.01], $key);
        rejects($model, [$key => $spec['max'] + 0.01], $key);
    }
});

test('ikke-numeriske og farlige verdier avvises', function () use ($model) {
    foreach (['abc', '30; rm -rf /', '30 -o /tmp/x', '1e3', 'NaN', 'INF', '0x1E', '', ' ', '30mm', [30], true] as $bad) {
        if ($bad === '' || $bad === ' ') {
            // tom verdi betyr standardverdi
            continue;
        }
        rejects($model, ['width' => $bad], 'width');
    }
    rejects($model, ['width' => NAN], 'width');
    rejects($model, ['width' => INF], 'width');
});

test('numeriske strenger og desimalkomma godtas', function () use ($model) {
    $p = $model->validate(['size' => '25', 'pip_depth' => '0,8']);
    eq(25.0, $p['depth']);
    eq(0.8, $p['pip_depth']);
});

test('ukjente parametere ignoreres og sendes aldri til OpenSCAD', function () use ($model) {
    $p = $model->validate(['evil' => 'import("/etc/passwd")', 'size' => 30]);
    ok(!isset($p['evil']));
    $vars = $model->scadVariables($p);
    foreach ($vars as $name => $value) {
        ok((bool) preg_match('/^[a-z_][a-z0-9_]*$/', $name), "variabelnavn $name");
        ok(is_int($value) || is_float($value), "$name er ikke et tall");
    }
});

test('kryssvalidering: for store prikker og for stor radius', function () use ($model) {
    rejects($model, ['size' => 15, 'pip_diameter' => 5, 'corner_radius' => 0], 'pip_diameter');
    rejects($model, ['size' => 15, 'pip_diameter' => 3, 'corner_radius' => 4], 'corner_radius');
    // Uten prikker er store radier og prikkverdier uten betydning
    $p = $model->validate(['size' => 15, 'pip_diameter' => 5, 'corner_radius' => 4, 'pip_depth' => 0]);
    eq(0.0, $p['pip_depth']);
});

test('standardinnstillinger er gyldige for alle størrelser 15–50 mm', function () use ($model) {
    for ($s = 15; $s <= 50; $s++) {
        $model->validate(['size' => $s]);
    }
});

test('etikettstørrelse og dekor valideres', function () use ($model) {
    eq(29.4, $model->validate(['label' => '29.4'])['label_size']);
    eq(false, $model->validate(['label' => 29.4])['label_auto']);
    rejects($model, ['label' => 4.9], 'label');
    rejects($model, ['label' => 50.1], 'label');
    rejects($model, ['label' => 'stor'], 'label');
    rejects($model, ['decor' => 'emoji'], 'decor');
    foreach (['pips', 'numbers', 'blank'] as $d) {
        eq($d, $model->validate(['decor' => $d])['decor']);
    }
});

test('advarsel når etiketten dekker avrundede kanter', function () use ($model) {
    eq([], $model->warnings($model->validate([])));
    ok(count($model->warnings($model->validate(['label' => 29.4]))) === 1);
});

test('modellbeskrivelse (JSON) har avtalt struktur', function () use ($model) {
    $d = $model->describe($model->validate([]));
    eq('dice_cube', $d['model']);
    eq(1, $d['version']);
    eq(['width' => 30.0, 'height' => 30.0, 'depth' => 30.0], $d['dimensions']);
    eq(['top' => '1', 'bottom' => '6', 'front' => '2', 'back' => '5', 'right' => '3', 'left' => '4'], $d['faces']);
    eq(25.4, $d['label']['width']);
    ok(json_encode($d) !== false);
});

test('motstående sider summerer til 7', function () use ($model) {
    $f = $model->definition()['faces'];
    eq(7, $f['top'] + $f['bottom']);
    eq(7, $f['front'] + $f['back']);
    eq(7, $f['left'] + $f['right']);
});

test('filnavn', function () use ($model) {
    eq('mplus-terning-30mm', $model->filename($model->validate([])));
    eq('mplus-terning-20x30x40mm', $model->filename($model->validate(['width' => 20, 'depth' => 30, 'height' => 40, 'corner_radius' => 1])));
});

echo "\nSTL-kontroll\n";

test('lukket tetraeder godkjennes (Euler = 2)', function () use ($tetra, $tmp) {
    writeStl("$tmp/tetra.stl", $tetra);
    $r = (new StlInspector())->inspect("$tmp/tetra.stl");
    ok($r['ok'], implode(' ', $r['problems']));
    eq(2, $r['euler']);
    near(1 / 6, $r['volume_mm3'], 0.01, 'volum');
});

test('åpent nett avvises', function () use ($tetra, $tmp) {
    writeStl("$tmp/open.stl", array_slice($tetra, 0, 3));
    $r = (new StlInspector())->inspect("$tmp/open.stl");
    ok(!$r['ok']);
    ok($r['boundary_edges'] === 3, 'åpne kanter');
});

test('feilorientert trekant avvises', function () use ($tetra, $tmp) {
    $bad = $tetra;
    $bad[3] = [$bad[3][0], $bad[3][2], $bad[3][1]];
    writeStl("$tmp/flip.stl", $bad);
    ok(!(new StlInspector())->inspect("$tmp/flip.stl")['ok']);
});

test('innoverpekende normaler (negativt volum) avvises', function () use ($tetra, $tmp) {
    writeStl("$tmp/inv.stl", array_map(fn($t) => [$t[0], $t[2], $t[1]], $tetra));
    $r = (new StlInspector())->inspect("$tmp/inv.stl");
    ok(!$r['ok']);
    ok($r['volume_mm3'] < 0);
});

test('degenerert trekant avvises', function () use ($tetra, $tmp) {
    writeStl("$tmp/degen.stl", [...$tetra, [[0, 0, 0], [1, 0, 0], [2, 0, 0]]]);
    $r = (new StlInspector())->inspect("$tmp/degen.stl");
    ok(!$r['ok']);
    eq(1, $r['degenerate']);
});

test('feil mål avvises', function () use ($tetra, $tmp) {
    writeStl("$tmp/tetra.stl", $tetra);
    $r = (new StlInspector())->inspect("$tmp/tetra.stl", ['min' => [0, 0, 0], 'max' => [1, 1, 1.5]]);
    ok(!$r['ok']);
});

test('ASCII-STL leses', function () use ($tetra, $tmp) {
    $s = "solid t\n";
    foreach ($tetra as $t) {
        $s .= "facet normal 0 0 0\nouter loop\n";
        foreach ($t as $v) {
            $s .= "vertex {$v[0]} {$v[1]} {$v[2]}\n";
        }
        $s .= "endloop\nendfacet\n";
    }
    file_put_contents("$tmp/ascii.stl", $s . "endsolid t\n");
    ok((new StlInspector())->inspect("$tmp/ascii.stl")['ok']);
});

test('tom eller manglende fil avvises', function () use ($tmp) {
    ok(!(new StlInspector())->inspect("$tmp/finnes-ikke.stl")['ok']);
    file_put_contents("$tmp/empty.stl", '');
    ok(!(new StlInspector())->inspect("$tmp/empty.stl")['ok']);
});

echo "\nOpenSCAD og STL-generering\n";

$scadFor = function (string $modelsDir, int $timeout = 60) use ($binary, $config): ScadGenerator {
    if (!$binary) {
        throw new SkipTest('OpenSCAD ikke funnet');
    }
    $args = $config['openscad']['args'] ?? (ScadGenerator::supportsManifold($binary, $config['storage_dir']) ? ['--backend=manifold'] : []);
    return new ScadGenerator($binary, $modelsDir, $args, $timeout);
};

$build = function (array $input) use ($model, $scadFor, $tmp, $config): array {
    $files = new GeneratedFiles("$tmp/storage", 3600);
    $builder = new ModelBuilder($model, $scadFor($config['models_dir']), new StlInspector(), $files);
    $meta = $builder->buildStl($model->validate($input));
    $meta['path'] = $files->file($meta['token'], 'model.stl');
    return $meta;
};

test('30 mm standardterning: gyldig, lukket og 30 × 30 × 30 mm', function () use ($build) {
    $m = $build([]);
    ok($m['check']['ok'], implode(' ', $m['check']['problems']));
    eq([30, 30, 30], $m['check']['size_mm']);
    eq(2, $m['check']['euler']);
    eq(0, $m['check']['boundary_edges']);
    ok($m['check']['triangles'] < 20000, 'unødvendig mange trekanter');
    eq('mplus-terning-30mm.stl', $m['filename']);
    ok(GeneratedFiles::isValidToken($m['token']));
});

test('prikkene er riktige: volumet minker med 21 kulekalotter', function () use ($build, $model, $scadFor, $config, $tmp) {
    $with = $build(['corner_radius' => 0])['check']['volume_mm3'];
    // Én prikk alene (bare TOPP = 1, resten blanke)
    $vars = ['face_top' => 1, 'face_bottom' => 0, 'face_front' => 0, 'face_back' => 0, 'face_left' => 0, 'face_right' => 0]
        + $model->scadVariables($model->validate(['corner_radius' => 0]));
    $scadFor($config['models_dir'])->render('dice/cube.scad', $vars, "$tmp/onepip.stl");
    $single = 27000 - (new StlInspector())->inspect("$tmp/onepip.stl")['volume_mm3'];
    $without = $build(['corner_radius' => 0, 'pip_depth' => 0])['check']['volume_mm3'];
    near(27000, $without, 0.01, 'blank kube');
    // Kulekalott: V = π h² (3R − h) / 3, R = (r² + h²) / 2h
    // Kula er tessellert (pip_segments = 24), så den fjerner litt mindre enn en
    // ideell kalott (≈ 7 %). Dybden er eksakt (polpunkt); diameteren ≈ 2,93 mm.
    $r = 1.5; $h = 0.5; $R = ($r * $r + $h * $h) / (2 * $h);
    $cap = M_PI * $h * $h * (3 * $R - $h) / 3;
    $removed = $without - $with;
    ok($removed > 21 * $cap * 0.85 && $removed <= 21 * $cap, "fjernet volum $removed, ideelt " . round(21 * $cap, 2));
    near(21, $removed / $single, 0.3, 'antall prikker');   // volumene er avrundet til 0,01 mm³
});

test('rektangulær variant har riktige mål', function () use ($build) {
    $m = $build(['width' => 20, 'depth' => 30, 'height' => 40, 'corner_radius' => 1]);
    ok($m['check']['ok']);
    eq([20, 30, 40], $m['check']['size_mm']);
    eq(0.0, $m['check']['bounds']['min'][2], 'står på z = 0');
});

test('ytterpunkter 15 mm og 50 mm', function () use ($build) {
    foreach ([['size' => 15], ['size' => 50, 'corner_radius' => 4, 'pip_diameter' => 5, 'pip_depth' => 1]] as $in) {
        $m = $build($in);
        ok($m['check']['ok'], implode(' ', $m['check']['problems']));
        eq(array_fill(0, 3, $in['size']), $m['check']['size_mm']);
    }
});

test('hurtigbuffer gjenbruker samme fil for like parametere', function () use ($model, $scadFor, $tmp, $config) {
    $files = new GeneratedFiles("$tmp/storage-cache", 3600);
    $b = new ModelBuilder($model, $scadFor($config['models_dir']), new StlInspector(), $files);
    $a = $b->buildStl($model->validate(['size' => 22]));
    $c = $b->buildStl($model->validate(['size' => 22, 'decor' => 'numbers', 'label' => 10]));
    eq($a['token'], $c['token']);
    ok($c['cached']);
});

test('OpenSCAD-binæren mangler: forståelig feil', function () use ($config, $tmp) {
    $g = new ScadGenerator(__DIR__ . '/finnes-ikke/openscad', $config['models_dir']);
    $e = expectGenerationError(fn() => $g->render('dice/cube.scad', [], "$tmp/missing.stl"));
    ok($e->getMessage() !== '' && !str_contains($e->getMessage(), __DIR__), 'feilmeldingen skal ikke vise filstier');
});

test('syntaksfeil i SCAD gir GenerationException', function () use ($scadFor, $tmp) {
    expectGenerationError(fn() => $scadFor(__DIR__ . '/fixtures')->render('broken.scad', [], "$tmp/broken.stl"));
    ok(!is_file("$tmp/broken.stl"));
});

test('modell uten geometri gir GenerationException', function () use ($scadFor, $tmp) {
    expectGenerationError(fn() => $scadFor(__DIR__ . '/fixtures')->render('empty.scad', [], "$tmp/empty-out.stl"));
});

test('tidsavbrudd stopper OpenSCAD', function () use ($scadFor, $tmp) {
    $start = microtime(true);
    expectGenerationError(fn() => $scadFor(__DIR__ . '/fixtures', 2)->render('slow.scad', [], "$tmp/slow.stl"), 'timeout');
    ok(microtime(true) - $start < 8, 'brukte for lang tid');
});

test('modellfiler utenfor models/ kan ikke brukes', function () use ($scadFor, $tmp, $config) {
    expectGenerationError(fn() => $scadFor($config['models_dir'])->render('../tests/fixtures/broken.scad', [], "$tmp/x.stl"), 'not_found');
    expectGenerationError(fn() => $scadFor($config['models_dir'])->render('dice/finnes-ikke.scad', [], "$tmp/x.stl"), 'not_found');
});

test('ugyldige variabelnavn og -verdier stoppes før prosessen startes', function () use ($scadFor, $tmp, $config) {
    expectGenerationError(fn() => $scadFor($config['models_dir'])->render('dice/cube.scad', ['size; x' => 1.0], "$tmp/x.stl"));
    expectGenerationError(fn() => $scadFor($config['models_dir'])->render('dice/cube.scad', ['size' => '30'], "$tmp/x.stl"));
});

echo "\nPDF\n";

$k = 72 / 25.4;

test('PDF er gyldig A4 stående (210 × 297 mm)', function () use ($model) {
    $pdf = (new PdfGenerator($model))->render($model->validate([]));
    ok(str_starts_with($pdf, '%PDF-'));
    ok(str_contains($pdf, '%%EOF'));
    ok((bool) preg_match('/\/MediaBox \[0 0 ([\d.]+) ([\d.]+)\]/', $pdf, $m), 'MediaBox mangler');
    near(210, $m[1] / (72 / 25.4), 0.05, 'bredde');
    near(297, $m[2] / (72 / 25.4), 0.05, 'høyde');
});

test('PDF inneholder seks etiketter med eksakt størrelse', function () use ($model, $k) {
    foreach ([25.4, 29.4, 41.4, 12.5] as $L) {
        $content = pdfContent((new PdfGenerator($model))->render($model->validate(['label' => $L])));
        $needle = sprintf(' %.3F %.3F re S', $L * $k, -$L * $k);
        eq(6, substr_count($content, $needle), "etiketter på $L mm");
    }
});

test('PDF har målestokk på nøyaktig 100 mm', function () use ($model, $k) {
    $content = pdfContent((new PdfGenerator($model))->render($model->validate([])));
    ok(preg_match('/([\d.]+) ([\d.]+) m ([\d.]+) \2 l S/', $content, $m) === 1);
    $found = false;
    preg_match_all('/([\d.]+) ([\d.]+) m ([\d.]+) \2 l S/', $content, $all, PREG_SET_ORDER);
    foreach ($all as $line) {
        if (abs(($line[3] - $line[1]) / $k - 100) < 0.01) {
            $found = true;
        }
    }
    ok($found, 'fant ingen 100 mm linje');
});

test('PDF navngir alle seks sider og varsler om 100 % utskrift', function () use ($model) {
    $content = pdfContent((new PdfGenerator($model))->render($model->validate([])));
    foreach (['TOPP', 'BUNN', 'FRONT', 'BAK', 'VENSTRE', LabelPdf::enc('HØYRE'), 'Faktisk st', '100 %'] as $s) {
        ok(str_contains($content, $s), "mangler «{$s}»");
    }
});

test('PDF med prikker har 21 prikker på etikettene', function () use ($model) {
    // Hver sirkel tegnes med 4 Bézier-kurver og fylles med «f». Etikettene og
    // det utbrettede korset har 21 prikker hver = 42 sirkler.
    $content = pdfContent((new PdfGenerator($model))->render($model->validate(['decor' => 'pips'])));
    preg_match_all('/ c\nf\n/', $content, $m);
    eq(42, count($m[0]));
});

test('PDF med tall og tomme etiketter genereres', function () use ($model) {
    foreach (['numbers', 'blank'] as $d) {
        foreach ([['size' => 15], ['size' => 50, 'corner_radius' => 4, 'pip_diameter' => 5]] as $in) {
            $pdf = (new PdfGenerator($model))->render($model->validate($in + ['decor' => $d]));
            ok(str_starts_with($pdf, '%PDF-'));
        }
    }
    $blank = pdfContent((new PdfGenerator($model))->render($model->validate(['decor' => 'blank'])));
    preg_match_all('/ c\nf\n/', $blank, $m);
    eq(0, count($m[0]), 'tomme etiketter skal ikke ha prikker');
});

test('PDF-mønstre er identiske med SCAD-mønstrene', function () {
    $scad = (string) file_get_contents(__DIR__ . '/../models/dice/cube.scad');
    foreach (PdfGenerator::PIP_PATTERNS as $n => $pattern) {
        $expected = 'n == ' . $n . ' ? ' . json_encode($pattern);
        ok(str_contains(str_replace(', ', ',', $scad), str_replace(', ', ',', $expected)), "mønster $n avviker");
    }
});

/* ======================================================================
 * Felles arkitektur
 * ==================================================================== */

echo "\nModellregister og API\n";

$registry = new ModelRegistry(__DIR__ . '/../app/config');

test('registeret har terning, brøksirkler, base 10, tallstaver, romfigurer, Regn med Lego og kulespill', function () use ($registry) {
    eq(['dice', 'fractions', 'base10', 'rods', 'solids', 'lego', 'marble'], $registry->keys());
    ok($registry->get('lego') instanceof LegoModel);
    ok($registry->get('solids') instanceof SolidModel);
    ok($registry->get('marble') instanceof MarbleModel);
    ok($registry->get('base10') instanceof Base10Model);
    ok($registry->get('rods') instanceof RodModel);
    ok($registry->get('dice') instanceof DiceModel);
    ok($registry->get('fractions') instanceof FractionModel);
    foreach ($registry->keys() as $k) {
        eq($k, $registry->get($k)->key());
    }
});

test('ukjent modell gir GenerationException (404)', function () use ($registry) {
    $e = expectGenerationError(fn() => $registry->get('../dice'), 'not_found');
    eq(404, $e->httpStatus());
});

test('terningens felles grensesnitt: én del, samme fil og antall 1', function () use ($model) {
    $p = $model->validate([]);
    $parts = $model->parts($p);
    eq(1, count($parts));
    eq('mplus-terning-30mm.stl', $parts[0]['filename']);
    eq(1, $parts[0]['count']);
    eq('dice/cube.scad', $parts[0]['scad']);
    eq($model->scadVariables($p), $parts[0]['variables']);
    eq('mplus-terning-30mm-dekor.pdf', $model->pdfFilename($p));
});

test('sidene i menyen peker på registrerte modeller og maler', function () use ($registry) {
    $site = require __DIR__ . '/../app/config/pages.php';
    foreach ($site['pages'] as $slug => $page) {
        ok($registry->has($page['model']), "$slug: ukjent modell");
        ok(is_file(__DIR__ . '/../templates/pages/' . $page['template']), "$slug: mal mangler");
        ok(is_file(__DIR__ . '/../public/' . $page['script']), "$slug: skript mangler");
        ok(is_file(__DIR__ . '/../public/' . $page['preview']), "$slug: forhåndsbilde mangler");
        ok(isset($site['groups'][$page['group']]), "$slug: ukjent gruppe");
        foreach ($page['themes'] as $t) {
            ok(isset($site['themes'][$t]), "$slug: ukjent tema $t");
        }
    }
});

/* ======================================================================
 * Brøksirkler
 * ==================================================================== */

echo "\nBrøksirkler: validering og geometri\n";

$frac = FractionModel::load(__DIR__ . '/../app/config');

test('standardverdier: Ø100, 3 mm, klaring 0,3, avrunding 0,5, merking 0,4, nevnere 2,3,4,6,8', function () use ($frac) {
    $p = $frac->validate([]);
    eq([100.0, 3.0, 0.3, 0.5, 0.4], [$p['diameter'], $p['thickness'], $p['clearance'], $p['edge_radius'], $p['label_depth']]);
    eq([2, 3, 4, 6, 8], $p['denominators']);
    ok($p['labels'] === true);
});

test('nevnere: tekst, liste, sortering og duplikater', function () use ($frac) {
    eq([2, 4, 12], $frac->validate(['denominators' => '12,4,2,4'])['denominators']);
    eq([1, 7], $frac->validate(['denominators' => [7, '1']])['denominators']);
    rejects($frac, ['denominators' => ''], 'denominators');
    rejects($frac, ['denominators' => []], 'denominators');
    rejects($frac, ['denominators' => '0,2'], 'denominators');
    rejects($frac, ['denominators' => '13'], 'denominators');
    rejects($frac, ['denominators' => '2;rm -rf'], 'denominators');
    rejects($frac, ['denominators' => [2.5]], 'denominators');
});

test('grenser for tallparametere', function () use ($frac) {
    foreach (['diameter' => [49, 151], 'thickness' => [1.5, 8.5], 'clearance' => [-0.1, 0.81],
              'edge_radius' => [-0.1, 1.1], 'label_depth' => [0.1, 0.9]] as $f => [$lo, $hi]) {
        rejects($frac, [$f => $lo], $f);
        rejects($frac, [$f => $hi], $f);
    }
    rejects($frac, ['diameter' => 'abc'], 'diameter');
    $p = $frac->validate(['diameter' => '50', 'thickness' => '8', 'clearance' => '0,8', 'edge_radius' => 1, 'label_depth' => '0.2']);
    eq([50.0, 8.0, 0.8, 1.0, 0.2], [$p['diameter'], $p['thickness'], $p['clearance'], $p['edge_radius'], $p['label_depth']]);
});

test('brøkmerking på/av', function () use ($frac) {
    foreach (['0' => false, '1' => true, 'off' => false, 'on' => true, 'none' => false, 'engraved' => true] as $in => $out) {
        ok($frac->validate(['labels' => (string) $in])['labels'] === $out, "labels=$in");
    }
    ok($frac->validate(['labels' => false])['labels'] === false);
    rejects($frac, ['labels' => 'kanskje'], 'labels');
});

test('én STL per nevner, med antall og filnavn', function () use ($frac) {
    $parts = $frac->parts($frac->validate(['denominators' => '2,4,8']));
    eq(['broeksirkel_1-2.stl', 'broeksirkel_1-4.stl', 'broeksirkel_1-8.stl'], array_column($parts, 'filename'));
    eq([2, 4, 8], array_column($parts, 'count'));
    eq(14, $frac->describe($frac->validate(['denominators' => '2,4,8']))['total_pieces']);
    eq(count($parts), count(array_unique(array_column($parts, 'cache_key'))));
});

test('ytterbuen har partall segmenter (punkt på +Y) og helsirkelen deles i 4', function () use ($frac) {
    foreach ([50, 100, 150] as $D) {
        foreach (range(1, 12) as $n) {
            $s = $frac->arcSegments($n, $frac->validate(['diameter' => $D, 'clearance' => 0.8]));
            ok($s % 2 === 0 && $s >= 2, "n=$n: $s");
            if ($n === 1) {
                eq(0, $s % 4);
            }
        }
    }
});

test('forventede mål: diameter bevares, klaringen flytter de radielle kantene', function () use ($frac) {
    $p = $frac->validate(['clearance' => 0.6]);
    $b = $frac->expectedBounds(2, $p);
    near(50.0, $b['max'][1], 1e-9, 'halvsirkel når ytterradius');
    near(0.3, $b['min'][1], 1e-9, 'halvsirkel: korden ligger klaring/2 fra sentrum');
    $b1 = $frac->expectedBounds(1, $p);
    eq([-50.0, -50.0], [round($b1['min'][0], 9), round($b1['min'][1], 9)]);
    $b4 = $frac->expectedBounds(4, $p);
    near(0.3 / sin(M_PI / 4), $b4['min'][1], 1e-9, 'spissen ligger klaring/2 / sin(45°) fra sentrum');
});

test('brøkmerket ligger alltid på den flate toppen, ellers utelates det', function () use ($frac) {
    $m = 1.0;
    foreach ([50, 75, 100, 150] as $D) {
        foreach ([[0, 0], [0.8, 1], [0.3, 0.5]] as [$c, $r]) {
            $p = $frac->validate(['diameter' => $D, 'clearance' => $c, 'edge_radius' => $r, 'denominators' => range(1, 12)]);
            foreach (range(1, 12) as $n) {
                $l = $frac->labelLayout($n, $p);
                if (!$l['shown']) {
                    eq('too_small', $l['reason'], "D=$D n=$n");
                    continue;
                }
                ok($l['height'] >= 4 - 1e-9, "D=$D n=$n: for lav tekst");
                [$as, $aq] = $l['rotate'] ? [$l['width'] / 2, $l['height'] / 2] : [$l['height'] / 2, $l['width'] / 2];
                $R = $D / 2;
                // alle fire hjørner innenfor ytterkant og radielle kanter
                foreach ([[-1, -1], [-1, 1], [1, -1], [1, 1]] as [$ss, $qq]) {
                    $s = $l['dist'] + $ss * $as;
                    $q = $qq * $aq;
                    ok(hypot($s, $q) <= $R - $r - $m + 0.02, "D=$D n=$n: utenfor buen");
                    if ($n > 1) {
                        $phi = M_PI / $n;
                        $dist = $s * sin($phi) - abs($q) * cos($phi);
                        ok($dist >= $c / 2 + $r + $m - 0.02, "D=$D n=$n: for nær radiell kant ($dist)");
                    }
                }
            }
        }
    }
});

test('store brikker får merking, smale brikker får mindre eller ingen', function () use ($frac) {
    $p = $frac->validate(['diameter' => 100]);
    foreach ([2, 3, 4, 6, 8] as $n) {
        ok($frac->labelLayout($n, $p)['shown'], "1/$n mangler merking");
    }
    $small = $frac->validate(['diameter' => 50, 'denominators' => range(1, 12)]);
    ok(!$frac->labelLayout(12, $small)['shown'], '1/12 ved 50 mm skal ikke ha merking');
    ok((bool) array_filter($frac->warnings($small), fn($w) => str_contains($w, 'utelatt')));
    $off = $frac->validate(['labels' => '0']);
    eq(0, $frac->parts($off)[0]['variables']['label_on']);
    eq([], array_filter($frac->warnings($off), fn($w) => str_contains($w, 'merking')));
});

echo "\nBrøksirkler: STL (OpenSCAD)\n";

/** Leser trekanter fra en binær STL. */
function stlTriangles(string $file): array
{
    $d = (string) file_get_contents($file);
    $n = unpack('V', substr($d, 80, 4))[1];
    $out = [];
    for ($i = 0; $i < $n; $i++) {
        $f = unpack('g9', substr($d, 84 + $i * 50 + 12, 36));
        $out[] = [[$f[1], $f[2], $f[3]], [$f[4], $f[5], $f[6]], [$f[7], $f[8], $f[9]]];
    }
    return $out;
}

/** Flateareal av et polygon (skolissformelen). */
function polygonArea(array $pts): float
{
    $a = 0.0;
    $n = count($pts);
    for ($i = 0; $i < $n; $i++) {
        [$x1, $y1] = $pts[$i];
        [$x2, $y2] = $pts[($i + 1) % $n];
        $a += $x1 * $y2 - $x2 * $y1;
    }
    return abs($a) / 2;
}

$fracBuild = function (array $input) use ($frac, $scadFor, $tmp, $config): array {
    $files = new GeneratedFiles("$tmp/storage-frac", 3600);
    $b = new ModelBuilder($frac, $scadFor($config['models_dir']), new StlInspector(), $files);
    $p = $frac->validate($input);
    $out = [];
    foreach ($b->buildAll($p) as $meta) {
        $meta['path'] = $files->file($meta['token'], 'model.stl');
        $out[$meta['part']] = $meta;
    }
    return [$p, $out];
};

test('halv-, tredel-, firedel- og tolvdelsirkel: lukkede, manifold, riktige mål', function () use ($fracBuild, $frac) {
    [$p, $built] = $fracBuild(['denominators' => '2,3,4,12']);
    foreach ([2, 3, 4, 12] as $n) {
        $c = $built["1-$n"]['check'];
        ok($c['ok'], "1/$n: " . implode(' ', $c['problems']));
        eq(2, $c['euler'], "1/$n euler");
        eq(0, $c['boundary_edges']);
        $b = $frac->expectedBounds($n, $p);
        near($b['max'][1] - $b['min'][1], $c['size_mm'][1], 0.02, "1/$n dybde");
        near(3.0, $c['size_mm'][2], 0.001, "1/$n tykkelse");
        eq(0.0, $c['bounds']['min'][2], "1/$n ligger på z = 0");
    }
});

test('minimum og maksimum diameter og tykkelse', function () use ($fracBuild) {
    foreach ([['diameter' => 50, 'thickness' => 2], ['diameter' => 150, 'thickness' => 8]] as $in) {
        [, $built] = $fracBuild($in + ['denominators' => '1,2,12']);
        foreach ($built as $key => $m) {
            ok($m['check']['ok'], "$key " . json_encode($in) . ': ' . implode(' ', $m['check']['problems']));
            near($in['thickness'], $m['check']['size_mm'][2], 0.001);
        }
        near($in['diameter'], $built['1-1']['check']['size_mm'][0], 0.02, 'helsirkel = diameter');
    }
});

test('klaring 0 og 0,8 mm: spalten mot nabobrikka er nøyaktig klaringen', function () use ($fracBuild) {
    foreach ([0.0, 0.8] as $c) {
        [, $built] = $fracBuild(['clearance' => $c, 'edge_radius' => 0, 'labels' => 0, 'denominators' => '4']);
        ok($built['1-4']['check']['ok']);
        // Den høyre radielle kanten ligger langs vinkelen 45° (sektoren går fra 45° til 135°).
        $min = INF;
        foreach (stlTriangles($built['1-4']['path']) as $t) {
            foreach ($t as [$x, $y]) {
                $min = min($min, -$x * sin(M_PI / 4) + $y * cos(M_PI / 4));
            }
        }
        near($c / 2, $min, 0.002, "klaring $c: avstand til kanten");
    }
});

test('merking av: volumet er nøyaktig areal × tykkelse', function () use ($fracBuild, $frac) {
    [$p, $built] = $fracBuild(['edge_radius' => 0, 'labels' => 0, 'denominators' => '3']);
    $area = polygonArea(FractionModel::outline(3, 0.15, 50, $frac->arcSegments(3, $p)));
    near($area * 3, $built['1-3']['check']['volume_mm3'], 0.5);
});

test('merking på: nedsenket tekst fjerner volum, men modellen er fortsatt gyldig', function () use ($fracBuild) {
    [, $off] = $fracBuild(['labels' => 0, 'denominators' => '2,8']);
    [, $on] = $fracBuild(['labels' => 1, 'label_depth' => 0.8, 'denominators' => '2,8']);
    foreach (['1-2', '1-8'] as $k) {
        ok($on[$k]['check']['ok'], implode(' ', $on[$k]['check']['problems']));
        $removed = $off[$k]['check']['volume_mm3'] - $on[$k]['check']['volume_mm3'];
        ok($removed > 5, "$k: merkingen fjernet bare $removed mm³");
        near($off[$k]['check']['size_mm'][2], $on[$k]['check']['size_mm'][2], 0.001, 'samme høyde');
    }
});

test('ingen overheng: alle flater som peker nedover ligger på byggeplaten', function () use ($fracBuild) {
    [, $built] = $fracBuild(['edge_radius' => 1, 'denominators' => '1,3,12', 'diameter' => 60]);
    foreach ($built as $key => $m) {
        foreach (stlTriangles($m['path']) as [$a, $b, $c]) {
            $u = [$b[0] - $a[0], $b[1] - $a[1], $b[2] - $a[2]];
            $v = [$c[0] - $a[0], $c[1] - $a[1], $c[2] - $a[2]];
            $nz = $u[0] * $v[1] - $u[1] * $v[0];
            $len = sqrt(($u[1] * $v[2] - $u[2] * $v[1]) ** 2 + ($u[2] * $v[0] - $u[0] * $v[2]) ** 2 + $nz ** 2);
            if ($len > 1e-9 && $nz / $len < -0.01) {
                ok(max(abs($a[2]), abs($b[2]), abs($c[2])) < 1e-4, "$key: nedoverpekende flate over z = 0");
            }
        }
    }
});

test('brikkene bufres hver for seg (ny nevner lager bare én ny fil)', function () use ($frac, $scadFor, $tmp, $config) {
    $files = new GeneratedFiles("$tmp/storage-frac-cache", 3600);
    $b = new ModelBuilder($frac, $scadFor($config['models_dir']), new StlInspector(), $files);
    $b->buildAll($frac->validate(['denominators' => '2,3']));
    $again = $b->buildAll($frac->validate(['denominators' => '2,3,5']));
    eq([true, true, false], array_column($again, 'cached'));
});

echo "\nBrøksirkler: oppgaver og PDF\n";

$combos = [[1], [2], [7], [2, 4], [3, 5, 7], [2, 3], [2, 3, 4, 6, 8], range(1, 12), [1, 12], [5, 10], [6, 9, 11]];

test('oppgavene bruker bare brikker som finnes i settet', function () use ($frac, $combos) {
    foreach ($combos as $dens) {
        $t = new FractionTasks($frac, $dens);
        $all = [];
        foreach ($t->compareSections() as $s) {
            $all = array_merge($all, $s['tasks']);
        }
        $all = array_merge($all, $t->equivalences(), $t->extraComparisons());
        foreach ($all as $task) {
            foreach ($task['needs'] as $n => $count) {
                ok(in_array($n, $dens, true), json_encode($dens) . ": oppgaven trenger 1/$n");
                ok($count <= $n, json_encode($dens) . ": trenger $count av 1/$n");
            }
            foreach ($task['tokens'] as $tok) {
                if ($tok[0] === 'frac' || $tok[0] === 'fracbox') {
                    $den = $tok[0] === 'frac' ? $tok[2] : $tok[1];
                    ok(in_array($den, $dens, true), json_encode($dens) . ": viser nevner $den");
                }
            }
        }
    }
});

test('likeverdige brøker har riktige svar, og bare når nevnerne går opp i hverandre', function () use ($frac) {
    $eq = (new FractionTasks($frac, [2, 4, 8]))->equivalences();
    ok(count($eq) >= 3);
    foreach ($eq as $e) {
        eq($e['k'] * $e['b'], $e['answer'] * $e['a'], "{$e['k']}/{$e['a']} = {$e['answer']}/{$e['b']}");
    }
    $pairs = array_map(fn($e) => "{$e['k']}/{$e['a']}={$e['answer']}/{$e['b']}", $eq);
    ok(in_array('1/2=2/4', $pairs, true) && in_array('2/4=4/8', $pairs, true), implode(' ', $pairs));
    eq([], (new FractionTasks($frac, [3, 5, 7]))->equivalences());
    eq([], (new FractionTasks($frac, [1, 2]))->equivalences());
});

test('hel sirkel av to brøksett', function () {
    eq([1, 2], FractionTasks::wholeFrom(2, 4));
    eq(null, FractionTasks::wholeFrom(2, 3));
    eq(null, FractionTasks::wholeFrom(3, 5));
    [$x, $y] = FractionTasks::wholeFrom(4, 6);
    eq(12, 3 * $x + 2 * $y);
});

test('PDF: fire A4-sider (oppgavesidene liggende) for alle kombinasjoner, tekst innenfor arket', function () use ($frac, $combos, $k) {
    foreach ($combos as $dens) {
        foreach ([50, 150] as $D) {
            $pdf = $frac->renderPdf($frac->validate(['denominators' => $dens, 'diameter' => $D]));
            ok(str_starts_with($pdf, '%PDF-'));
            $pages = pdfPages($pdf);
            eq(4, count($pages), json_encode($dens) . ' sider');
            // Oversikt og arbeidsflate (1:1) er stående, oppgavesidene liggende.
            eq(['P', 'L', 'L', 'P'], array_map(fn($p) => $p['w'] > $p['h'] ? 'L' : 'P', $pages), 'retning');
            foreach ($pages as $i => $p) {
                ok(!textOutside($p), json_encode($dens) . ' side ' . ($i + 1) . ': tekst utenfor arket ' . implode(' ', textOutside($p)));
            }
        }
    }
});

test('PDF: sirklene på arbeidsflaten har riktig diameter i full størrelse', function () use ($frac, $k) {
    foreach ([50, 100, 150] as $D) {
        $c = pdfContent($frac->renderPdf($frac->validate(['diameter' => $D])));
        // Circle(): m (x + r, y), deretter fire Bézier-kurver; kurve 2 ender i (x − r, y).
        preg_match_all('/([\d.]+) ([\d.]+) m\n[\d. ]+ c\n[\d. ]+ ([\d.]+) ([\d.]+) c\n/', $c, $m, PREG_SET_ORDER);
        $diams = array_map(fn($g) => round(($g[1] - $g[3]) / $k, 2), $m);
        ok(in_array((float) $D, $diams, true), "Ø$D: fant " . json_encode(array_values(array_unique($diams))));
        ok(!str_contains($c, 'skalert'), 'skal ikke være skalert');
    }
});

test('PDF: siden med likeverdige brøker byttes ut når settet ikke har slike', function () use ($frac) {
    $with = pdfContent($frac->renderPdf($frac->validate(['denominators' => '2,4'])));
    $without = pdfContent($frac->renderPdf($frac->validate(['denominators' => '3,5,7'])));
    ok(str_contains($with, 'Likeverdige br'), 'mangler «Likeverdige brøker»');
    ok(str_contains($without, 'Flere sammenligninger') && !str_contains($without, 'Likeverdige br'));
});

test('ZIP-oversikt viser fil, brøk og antall', function () use ($frac) {
    $p = $frac->validate(['denominators' => '2,4']);
    $txt = $frac->archiveReadme($p, $frac->parts($p));
    ok(str_contains($txt, 'broeksirkel_1-2.stl') && str_contains($txt, 'broeksirkel_1-4.stl'));
    ok((bool) preg_match('/broeksirkel_1-4\.stl\s+1\/4\s+4\s/', $txt));
    ok(str_contains($txt, 'Totalt: 6 brikker.'));
    eq('broeksirkler-100mm', $frac->archiveName($p));
});

/* ======================================================================
 * Base 10
 * ==================================================================== */

echo "\nBase 10: validering\n";

$b10 = Base10Model::load(__DIR__ . '/../app/config');

test('standardverdier: 10 mm, 0,3 mm, rutenett 0,4 × 0,6, hul tusener 2 mm, 20/10/10/0', function () use ($b10) {
    $p = $b10->validate([]);
    eq([10.0, 0.3, 0.4, 0.6, 2.0], [$p['unit'], $p['edge_radius'], $p['groove_depth'], $p['groove_width'], $p['wall']]);
    ok($p['grid'] && $p['hollow']);
    eq(['ener', 'tier', 'hundrer'], $p['types']);
    eq(['ener' => 20, 'tier' => 10, 'hundrer' => 10, 'tusener' => 0], $p['counts']);
});

test('grenser for tallparametere', function () use ($b10) {
    foreach ([['unit', 5.9], ['unit', 15.1], ['edge_radius', -0.1], ['edge_radius', 1.1], ['groove_depth', 0.1],
              ['groove_depth', 0.9], ['groove_width', 0.2], ['groove_width', 1.3], ['wall', 1.1], ['wall', 4.1], ['unit', 'abc']] as [$k, $v]) {
        rejects($b10, [$k => $v], $k);
    }
    $b10->validate(['unit' => 6, 'groove_depth' => 0.6, 'groove_width' => 0.9]);
    $b10->validate(['unit' => 15, 'edge_radius' => 1, 'groove_depth' => 0.8, 'groove_width' => 1.2, 'wall' => 4]);
});

test('objekttyper og antall', function () use ($b10) {
    $p = $b10->validate(['types' => 'tusener,ener', 'count_tusener' => '3', 'count_tier' => 50]);
    eq(['ener', 'tusener'], $p['types'], 'sortert fra minst til størst');
    eq(['ener' => 20, 'tier' => 0, 'hundrer' => 0, 'tusener' => 3], $p['counts'], 'ikke valgte typer får 0');
    rejects($b10, ['types' => ''], 'types');
    rejects($b10, ['types' => 'ener,femmer'], 'types');
    rejects($b10, ['types' => 'tusener'], 'count_tusener');          // standardantall 0
    rejects($b10, ['count_ener' => 201], 'count_ener');
    rejects($b10, ['count_ener' => '2.5'], 'count_ener');
    rejects($b10, ['count_tier' => -1], 'count_tier');
    eq(true, $b10->validate(['grid' => 'on'])['grid']);
    eq(false, $b10->validate(['hollow' => '0'])['hollow']);
    rejects($b10, ['grid' => 'kanskje'], 'grid');
});

test('riller og vegger kan ikke svekke små klosser', function () use ($b10) {
    rejects($b10, ['unit' => 6, 'groove_depth' => 0.7], 'groove_depth');         // > 10 % av 6 mm
    rejects($b10, ['unit' => 6, 'groove_width' => 1.0], 'groove_width');         // > 15 % av 6 mm
    rejects($b10, ['types' => 'tusener', 'count_tusener' => 1, 'wall' => 1.2, 'groove_depth' => 0.5], 'wall');
    // Samme verdier er greit når de ikke brukes:
    $b10->validate(['unit' => 6, 'groove_depth' => 0.8, 'grid' => 0]);
    $b10->validate(['types' => 'ener', 'unit' => 6, 'groove_depth' => 0.8]);
    $b10->validate(['types' => 'tusener', 'count_tusener' => 1, 'wall' => 1.2, 'groove_depth' => 0.4]);
    $b10->validate(['types' => 'tusener', 'count_tusener' => 1, 'wall' => 1.2, 'groove_depth' => 0.8, 'hollow' => 0]);
});

test('én STL per valgt type med filnavn, antall og riktige relative mål', function () use ($b10) {
    $p = $b10->validate(['types' => 'ener,tier,hundrer,tusener', 'count_tusener' => 2, 'unit' => 8]);
    $parts = $b10->parts($p);
    eq(['base10_ener.stl', 'base10_tier.stl', 'base10_hundrer.stl', 'base10_tusener.stl'], array_column($parts, 'filename'));
    eq([20, 10, 10, 2], array_column($parts, 'count'));
    eq([[8, 8, 8], [80, 8, 8], [80, 80, 8], [80, 80, 80]], array_column(array_column($parts, 'info'), 'size_mm'));
    // Volum i enheter: 1, 10, 100, 1000
    foreach ($parts as $part) {
        [$x, $y, $z] = $part['info']['size_mm'];
        near($part['info']['value'], $x * $y * $z / 8 ** 3, 1e-9);
    }
    // Enerkuben har aldri riller; bare tusener kan være hul
    eq(0, $parts[0]['variables']['grid_on']);
    eq([0, 0, 0, 1], array_column(array_column($parts, 'variables'), 'hollow'));
});

test('like klosser deler hurtigbuffer når irrelevante verdier endres', function () use ($b10) {
    $a = $b10->parts($b10->validate(['wall' => 2]));
    $b = $b10->parts($b10->validate(['wall' => 3, 'hollow' => 0]));
    eq(array_column($a, 'cache_key'), array_column($b, 'cache_key'), 'veggtykkelse påvirker ikke ener/tier/hundrer');
    $c = $b10->parts($b10->validate(['groove_depth' => 0.6]));
    eq($a[0]['cache_key'], $c[0]['cache_key'], 'rilledybde påvirker ikke enerkuben');
    ok($a[1]['cache_key'] !== $c[1]['cache_key']);
});

test('ZIP-oversikt har parametere og utskriftsoversikt', function () use ($b10) {
    $p = $b10->validate(['types' => 'ener,tusener', 'count_tusener' => 1]);
    $txt = $b10->archiveReadme($p, $b10->parts($p));
    ok(str_contains($txt, 'Enhetslengde:    10 mm'));
    ok(str_contains($txt, 'hul, veggtykkelse 2 mm'));
    ok((bool) preg_match('/base10_ener\.stl\s+ener\s+1\s+10 × 10 × 10\s+20\s/u', $txt), $txt);
    ok((bool) preg_match('/base10_tusener\.stl\s+tusener\s+1000\s+100 × 100 × 100\s+1\s/u', $txt));
    ok(str_contains($txt, 'Totalt: 21 klosser'));
    eq('base10-10mm', $b10->archiveName($p));
    eq('base10-7_5mm', $b10->archiveName($b10->validate(['unit' => 7.5])));
});

echo "\nBase 10: STL (OpenSCAD)\n";

$b10Build = function (array $input) use ($b10, $scadFor, $tmp, $config): array {
    $files = new GeneratedFiles("$tmp/storage-b10", 3600);
    $b = new ModelBuilder($b10, $scadFor($config['models_dir']), new StlInspector(), $files);
    $p = $b10->validate($input);
    $out = [];
    foreach ($b->buildAll($p) as $meta) {
        $meta['path'] = $files->file($meta['token'], 'model.stl');
        $out[$meta['part']] = $meta;
    }
    return [$p, $out];
};

/**
 * Rillene på en flate: trekanter som ligger helt i rillebunnen (planet
 * coord[$axis] = $value), gruppert etter hvilken enhetsgrense k de ligger på
 * langs $along. Gir de k-ene som har rille.
 */
function grooveLines(array $tris, int $axis, float $value, int $along, float $start, float $u, float $w): array
{
    $ks = [];
    foreach ($tris as $t) {
        if (abs($t[0][$axis] - $value) > 1e-3 || abs($t[1][$axis] - $value) > 1e-3 || abs($t[2][$axis] - $value) > 1e-3) {
            continue;
        }
        $c = ($t[0][$along] + $t[1][$along] + $t[2][$along]) / 3;
        $k = (int) round(($c - $start) / $u);
        if (abs($c - $start - $k * $u) <= $w / 2 + 1e-3) {
            $ks[$k] = true;
        }
    }
    ksort($ks);
    return array_keys($ks);
}

test('alle fire klosser: lukkede, manifold og riktige mål (10 mm)', function () use ($b10Build) {
    [, $built] = $b10Build(['types' => 'ener,tier,hundrer,tusener', 'count_tusener' => 1, 'hollow' => 0]);
    $expect = ['ener' => [10, 10, 10], 'tier' => [100, 10, 10], 'hundrer' => [100, 100, 10], 'tusener' => [100, 100, 100]];
    foreach ($expect as $t => $size) {
        $c = $built[$t]['check'];
        ok($c['ok'], "$t: " . implode(' ', $c['problems']));
        eq(2, $c['euler'], "$t euler");
        eq(0, $c['boundary_edges'], "$t åpne kanter");
        eq(0, $c['non_manifold_edges'], "$t ikke-manifold");
        foreach ($size as $i => $v) {
            near($v, $c['size_mm'][$i], 0.001, "$t mål $i");
        }
        eq(0.0, $c['bounds']['min'][2], "$t ligger på z = 0");
    }
});

test('ener og tier med minste (6 mm) og største (15 mm) enhetslengde', function () use ($b10Build) {
    foreach ([[6, 0.6, 0.9], [15, 0.8, 1.2]] as [$u, $d, $w]) {
        [, $built] = $b10Build(['types' => 'ener,tier', 'unit' => $u, 'groove_depth' => $d, 'groove_width' => $w, 'edge_radius' => 1]);
        ok($built['ener']['check']['ok'] && $built['tier']['check']['ok'], "enhet $u");
        eq([$u, $u, $u], array_map(fn($x) => round($x, 3), $built['ener']['check']['size_mm']));
        eq([10 * $u, $u, $u], array_map(fn($x) => round($x, 3), $built['tier']['check']['size_mm']));
    }
});

test('tieren har ti synlige inndelinger: ni riller rundt staven', function () use ($b10Build) {
    [$p, $built] = $b10Build(['types' => 'tier']);
    $tris = stlTriangles($built['tier']['path']);
    $d = $p['groove_depth'];
    $w = $p['groove_width'];
    eq(range(1, 9), grooveLines($tris, 2, 10 - $d, 0, -50, 10, $w), 'oversiden');
    eq(range(1, 9), grooveLines($tris, 2, $d, 0, -50, 10, $w), 'undersiden');
    eq(range(1, 9), grooveLines($tris, 1, -5 + $d, 0, -50, 10, $w), 'forsiden');
    eq(range(1, 9), grooveLines($tris, 1, 5 - $d, 0, -50, 10, $w), 'baksiden');
});

test('hundreren har 10 × 10-rutenett på begge de store flatene', function () use ($b10Build) {
    [$p, $built] = $b10Build(['types' => 'hundrer']);
    $tris = stlTriangles($built['hundrer']['path']);
    [$d, $w] = [$p['groove_depth'], $p['groove_width']];
    foreach ([10 - $d, $d] as $z) {
        eq(range(1, 9), grooveLines($tris, 2, $z, 0, -50, 10, $w), "linjer langs Y, z = $z");
        eq(range(1, 9), grooveLines($tris, 2, $z, 1, -50, 10, $w), "linjer langs X, z = $z");
    }
});

test('massiv tusener: 10 × 10-rutenett på alle seks sider', function () use ($b10Build) {
    [$p, $built] = $b10Build(['types' => 'tusener', 'count_tusener' => 1, 'hollow' => 0]);
    $tris = stlTriangles($built['tusener']['path']);
    [$d, $w] = [$p['groove_depth'], $p['groove_width']];
    eq(range(1, 9), grooveLines($tris, 2, 100 - $d, 0, -50, 10, $w), 'toppen');
    eq(range(1, 9), grooveLines($tris, 2, $d, 1, -50, 10, $w), 'bunnen');
    eq(range(1, 9), grooveLines($tris, 0, 50 - $d, 2, 0, 10, $w), 'høyre side, vannrette linjer');
    eq(range(1, 9), grooveLines($tris, 1, -50 + $d, 0, -50, 10, $w), 'forsiden, loddrette linjer');
    near(1e6, $built['tusener']['check']['volume_mm3'], 1e6 * 0.01, 'nesten massiv');
});

test('hul tusener: åpen overside, fem sider med rutenett, ingen hakk i åpningen', function () use ($b10Build, $b10) {
    [$p, $built] = $b10Build(['types' => 'tusener', 'count_tusener' => 1, 'wall' => 2]);
    $c = $built['tusener']['check'];
    ok($c['ok'], implode(' ', $c['problems']));
    eq([100, 100, 100], array_map(fn($x) => round($x, 3), $c['size_mm']));
    $tris = stlTriangles($built['tusener']['path']);
    [$d, $w] = [$p['groove_depth'], $p['groove_width']];
    eq(range(1, 9), grooveLines($tris, 2, $d, 0, -50, 10, $w), 'bunnen (lukket side)');
    eq(range(1, 9), grooveLines($tris, 0, -50 + $d, 2, 0, 10, $w), 'venstre side');
    eq([], grooveLines($tris, 2, 100 - $d, 0, -50, 10, $w), 'ingen riller oppå åpningen');
    // Bunnen i hulrommet ligger på z = veggtykkelsen og er åpen opp
    $floor = array_filter($tris, fn($t) => abs($t[0][2] - 2) < 1e-3 && abs($t[1][2] - 2) < 1e-3 && abs($t[2][2] - 2) < 1e-3);
    ok(count($floor) > 0, 'fant ikke bunnen i hulrommet');
    // Volum: ca. 10 % av massiv kube, og overslaget stemmer innen 1 %
    ok($c['volume_mm3'] < 0.12e6, 'for stort volum: ' . $c['volume_mm3']);
    near($b10->estimate('tusener', $p)['volume'], $c['volume_mm3'], $c['volume_mm3'] * 0.01, 'overslag');
});

test('rutenett av: ingen riller, volumet er boks minus avrunding', function () use ($b10Build, $b10) {
    [$p, $built] = $b10Build(['types' => 'tier,hundrer', 'grid' => 0]);
    foreach (['tier', 'hundrer'] as $t) {
        $tris = stlTriangles($built[$t]['path']);
        eq([], grooveLines($tris, 2, 10 - 0.4, 0, -50, 10, 0.6), "$t uten riller");
        near($b10->estimate($t, $p)['volume'], $built[$t]['check']['volume_mm3'], $built[$t]['check']['volume_mm3'] * 0.002, "$t volum");
    }
});

test('kantavrunding 0, 0,5 og 1 mm: gyldig, mål uendret, volumoverslag innen 1 %', function () use ($b10Build, $b10) {
    foreach ([0, 0.5, 1] as $r) {
        [$p, $built] = $b10Build(['edge_radius' => $r]);
        foreach ($built as $t => $m) {
            ok($m['check']['ok'], "$t r=$r: " . implode(' ', $m['check']['problems']));
            near($b10->estimate($t, $p)['volume'], $m['check']['volume_mm3'], $m['check']['volume_mm3'] * 0.01, "$t r=$r volum");
        }
    }
});

test('ingen overheng: nedoverpekende flater er bunnen, 45°-fasen eller smale rilletak', function () use ($b10Build) {
    [$p, $built] = $b10Build(['types' => 'ener,tier,hundrer,tusener', 'count_tusener' => 1, 'edge_radius' => 1, 'groove_width' => 1.2, 'groove_depth' => 0.8]);
    $w = $p['groove_width'];
    foreach ($built as $key => $m) {
        foreach (stlTriangles($m['path']) as [$a, $b, $c]) {
            $u = [$b[0] - $a[0], $b[1] - $a[1], $b[2] - $a[2]];
            $v = [$c[0] - $a[0], $c[1] - $a[1], $c[2] - $a[2]];
            $nz = $u[0] * $v[1] - $u[1] * $v[0];
            $len = sqrt(($u[1] * $v[2] - $u[2] * $v[1]) ** 2 + ($u[2] * $v[0] - $u[0] * $v[2]) ** 2 + $nz ** 2);
            if ($len < 1e-9 || $nz / $len > -0.75) {
                continue;                                     // ikke brattere enn 45° fra loddrett
            }
            $z = max($a[2], $b[2], $c[2]);
            if ($z < 1e-4) {
                continue;                                     // ligger på byggeplaten
            }
            // Rilletak: under en rille i bunnen (z = dybden), eller i en vannrett rille på siden (bredde w)
            $k = round($z / 10);
            $inSideGroove = abs($z - ($k * 10 + $w / 2)) < 1e-3;
            $inBottomGroove = abs($z - $p['groove_depth']) < 1e-3;
            ok($inSideGroove || $inBottomGroove, "$key: overheng ved z = $z");
        }
    }
});

echo "\nBase 10: tallbygger, oppgaver og PDF\n";

test('tallbygger: plassverdier for 0, 7, 24, 105, 243, 999, 1234, 9999', function () {
    $cases = [0 => [0, 0, 0, 0], 7 => [0, 0, 0, 7], 24 => [0, 0, 2, 4], 105 => [0, 1, 0, 5], 243 => [0, 2, 4, 3],
              999 => [0, 9, 9, 9], 1234 => [1, 2, 3, 4], 9999 => [9, 9, 9, 9]];
    foreach ($cases as $n => $d) {
        eq(array_combine([1000, 100, 10, 1], $d), Base10Tasks::digits($n), (string) $n);
        eq($n, Base10Tasks::value(Base10Tasks::digits($n)));
    }
    $t = new Base10Tasks([1 => 20, 10 => 10, 100 => 10, 1000 => 0]);
    ok($t->canBuild(999) && !$t->canBuild(1234) && $t->canBuild(0));
    eq(999, $t->largestCanonical());
});

test('tallord på norsk', function () {
    $w = [0 => 'null', 7 => 'sju', 24 => 'tjuefire', 105 => 'ett hundre og fem', 243 => 'to hundre og førtitre',
          999 => 'ni hundre og nittini', 1234 => 'ett tusen to hundre og trettifire', 9999 => 'ni tusen ni hundre og nittini',
          1005 => 'ett tusen og fem', 1200 => 'ett tusen to hundre', 70 => 'sytti', 11 => 'elleve'];
    foreach ($w as $n => $s) {
        eq($s, Base10Tasks::words($n), (string) $n);
    }
});

test('regning med klosser: veksling simuleres, og mangel på klosser oppdages', function () {
    $t = new Base10Tasks([1 => 20, 10 => 10, 100 => 10, 1000 => 0]);
    ok($t->canAdd(27, 15), '27 + 15');
    ok(!$t->canAdd(156, 267), '156 + 267 trenger 11 tiere og 4 hundrere … og mer enn 10 tiere på bordet');
    ok($t->canSubtract(42, 17), '42 − 17');
    ok($t->canSubtract(302, 7), '302 − 7: hundrer → tiere → enere');
    ok(!$t->canSubtract(231, 145), '231 − 145 krever 12 tiere');
    ok((new Base10Tasks([1 => 20, 10 => 20, 100 => 10]))->canSubtract(231, 145));
    ok(!$t->canSubtract(5, 7), 'negativt svar');
    $ones = new Base10Tasks([1 => 12]);
    ok($ones->canAdd(3, 4) && !$ones->canAdd(8, 5), 'bare enere: ingen veksling mulig');
});

$b10Sets = [
    [],
    ['types' => 'ener', 'count_ener' => 5],
    ['types' => 'ener', 'count_ener' => 12],
    ['types' => 'ener,tier', 'count_ener' => 30, 'count_tier' => 15],
    ['types' => 'tier,hundrer', 'count_tier' => 3, 'count_hundrer' => 1],
    ['types' => 'ener,tier,hundrer,tusener', 'count_tusener' => 2],
    ['types' => 'ener,tier,hundrer,tusener', 'count_ener' => 200, 'count_tier' => 100, 'count_hundrer' => 50, 'count_tusener' => 20, 'unit' => 15, 'hollow' => 0],
    ['types' => 'hundrer,tusener', 'count_hundrer' => 12, 'count_tusener' => 1],
];

test('oppgavene passer til klossene i settet (alle sett)', function () use ($b10, $b10Sets) {
    foreach ($b10Sets as $in) {
        $p = $b10->validate($in);
        $c = $b10->countsByValue($p);
        $t = new Base10Tasks($c);
        $label = json_encode($in);
        $build = $t->buildNumbers(8);
        ok(count($build) > 0, "$label: ingen bygg-oppgaver");
        foreach ($build as $n) {
            ok($t->canBuild($n) && $n > 0, "$label: kan ikke bygge $n");
        }
        foreach ($t->wordNumbers(4, $build) as $n) {
            ok($t->canBuild($n) && !in_array($n, $build, true), "$label: tallord $n");
        }
        foreach ($t->readTasks(7) as $r) {
            ok($t->canLay($r['rep']), "$label: «Hvilket tall?» bruker flere klosser enn settet har");
            eq(Base10Tasks::value($r['rep']), $r['answer'], "$label: svar");
            ok($r['challenge'] === (max($r['rep']) > 9), "$label: utfordring = flere enn ni av én type");
        }
        foreach (['add' => '+', 'sub' => '–'] as $op => $sign) {
            foreach ($t->arithmeticTasks(5)[$op] as $task) {
                eq($sign === '+' ? $task['a'] + $task['b'] : $task['a'] - $task['b'], $task['answer'], "$label: svar");
                $possible = $sign === '+' ? $t->canAdd($task['a'], $task['b']) : $t->canSubtract($task['a'], $task['b']);
                ok($task['paper'] || $possible, "$label: {$task['a']} $sign {$task['b']} kan ikke gjøres med klossene");
                ok(!$task['paper'] || $task['tokens'][0] === ['tag', 'PAPIROPPGAVE'], "$label: papiroppgave er ikke merket");
                ok($task['answer'] >= 0);
            }
        }
        $names = ['tusener' => 1000, 'hundrer' => 100, 'tier' => 10, 'ener' => 1];
        foreach ($t->exchangeTasks($b10) as $task) {
            $text = implode(' ', array_map(fn($tok) => $tok[1] ?? '', array_filter($task['tokens'], fn($tok) => $tok[0] === 'text')));
            ok(!$task['paper'] || $task['tokens'][0] === ['tag', 'PAPIROPPGAVE'], "$label: papiroppgave er ikke merket");
            if ($task['paper']) {
                continue;
            }
            foreach ($names as $name => $v) {
                if (preg_match('/\b' . $name . '(e|en|ene)?\b/u', $text)) {
                    ok($t->has($v), "$label: «{$text}» bruker $name, som ikke er i settet");
                }
            }
            if (preg_match('/Legg (\d+) (\w+)/u', $text, $m)) {
                $v = $names[rtrim($m[2], 'e')] ?? $names[$m[2]] ?? null;
                ok($v === null || $t->count($v) >= (int) $m[1], "$label: «{$text}» krever {$m[1]} {$m[2]}");
            }
        }
    }
});

test('PDF: seks A4-sider (oppgavesidene liggende) for små og store sett, tekst innenfor arket', function () use ($b10, $b10Sets, $k) {
    foreach ($b10Sets as $in) {
        $pdf = $b10->renderPdf($b10->validate($in));
        ok(str_starts_with($pdf, '%PDF-'));
        eq(6, preg_match_all('/\/Type \/Page\b/', $pdf), json_encode($in) . ' sider');
        $pages = pdfPages($pdf);
        eq(6, count($pages));
        foreach ($pages as $i => $p) {
            ok(!textOutside($p), json_encode($in) . ' side ' . ($i + 1) . ': tekst utenfor arket ' . implode(' ', textOutside($p)));
            // Oversikten er stående, oppgavesidene liggende.
            eq($i === 0 ? 'P' : 'L', $p['w'] > $p['h'] ? 'L' : 'P', 'side ' . ($i + 1) . ' retning');
        }
        // Side 6 er liggende
        ok((bool) preg_match('/\/MediaBox \[0 0 841\.89 595\.28\]/', $pdf) || str_contains($pdf, '/Rotate'), 'side 6 skal være liggende');
    }
});

test('PDF: oppgavesidene og papiroppgaver', function () use ($b10) {
    $c = pdfContent($b10->renderPdf($b10->validate([])));
    foreach (['(Bygg tall)', '(Hvilket tall?)', '(Veksling)', '(Addisjon og subtraksjon)', '(Plassverditabell)', '(24) Tj', '(136) Tj', '(405) Tj', '(23 ) Tj', '(14 ) Tj'] as $s) {
        ok(str_contains($c, $s), "mangler «{$s}»");
    }
    ok(substr_count($c, '(PAPIROPPGAVE) Tj') === 1 && str_contains($c, '(Tegn ) Tj'), 'standardsettet (uten tusener): bare vekslingen til tusener er papiroppgave');
    $all = pdfContent($b10->renderPdf($b10->validate(['types' => 'ener,tier,hundrer,tusener', 'count_tusener' => 1])));
    ok(!str_contains($all, '(PAPIROPPGAVE) Tj'), 'sett med alle typer skal ikke trenge papiroppgaver');
    $small = pdfContent($b10->renderPdf($b10->validate(['types' => 'ener', 'count_ener' => 12])));
    ok(str_contains($small, '(PAPIROPPGAVE) Tj'), 'lite sett skal ha merkede papiroppgaver');
});

/* ======================================================================
 * Tallstaver
 * ==================================================================== */

echo "\nTallstaver: validering\n";

$rods = RodModel::load(__DIR__ . '/../app/config');

test('standardverdier: 10 mm, 0,5 mm, glatte sider uten tall, 1–10 med 10 av hver, klassiske farger', function () use ($rods) {
    $p = $rods->validate([]);
    eq([10.0, 0.5, 0.3, 0.5, 0.4], [$p['unit'], $p['edge_radius'], $p['groove_depth'], $p['groove_width'], $p['label_depth']]);
    ok(!$p['grid'] && !$p['labels'], 'inndeling og tall skal være av');
    eq(range(1, 10), $p['lengths']);
    eq(array_fill(1, 10, 10), $p['counts']);
    eq('classic', $p['color_mode']);
    $names = array_column($rods->colors($p), 'name');
    eq(['hvit', 'rød', 'lysegrønn', 'lilla', 'gul', 'mørkegrønn', 'svart', 'brun', 'blå', 'oransje'], $names);
});

test('grenser for tallparametere', function () use ($rods) {
    foreach ([['unit', 5.9], ['unit', 20.1], ['edge_radius', -0.1], ['edge_radius', 1.6], ['groove_depth', 0.1], ['groove_depth', 0.9],
              ['groove_width', 0.2], ['groove_width', 1.3], ['label_depth', 0.1], ['label_depth', 0.9], ['unit', 'abc'], ['unit', '1e3']] as [$k, $v]) {
        rejects($rods, [$k => $v], $k);
    }
    $rods->validate(['unit' => 6, 'edge_radius' => 1.2, 'grid' => 1, 'groove_depth' => 0.6, 'groove_width' => 0.9]);
    $rods->validate(['unit' => 20, 'edge_radius' => 1.5, 'grid' => 1, 'groove_depth' => 0.8, 'groove_width' => 1.2, 'labels' => 1, 'label_depth' => 0.8]);
    eq(7.5, $rods->validate(['unit' => '7,5'])['unit']);
});

test('stavlengder og antall', function () use ($rods) {
    $p = $rods->validate(['lengths' => '5, 2,5,10', 'count_2' => '3', 'count_7' => 50]);
    eq([2, 5, 10], $p['lengths'], 'sortert uten duplikater');
    eq([1 => 0, 2 => 3, 3 => 0, 4 => 0, 5 => 10, 6 => 0, 7 => 0, 8 => 0, 9 => 0, 10 => 10], $p['counts'], 'ikke valgte lengder får 0');
    eq([1, 3], $rods->validate(['lengths' => [3, 1]])['lengths']);
    rejects($rods, ['lengths' => ''], 'lengths');
    rejects($rods, ['lengths' => '0,1'], 'lengths');
    rejects($rods, ['lengths' => '11'], 'lengths');
    rejects($rods, ['lengths' => '2.5'], 'lengths');
    rejects($rods, ['lengths' => '1;rm -rf'], 'lengths');
    rejects($rods, ['count_1' => 101], 'count_1');
    rejects($rods, ['count_1' => -1], 'count_1');
    rejects($rods, ['count_1' => '1.5'], 'count_1');
    rejects($rods, ['lengths' => '4', 'count_4' => 0], 'count_4');
    $rods->validate(['lengths' => '4', 'count_3' => 0]);       // ikke valgt: antall 0 er greit
});

test('farger: klassiske, én farge og egne farger (bare visning)', function () use ($rods) {
    $p = $rods->validate(['color_mode' => 'single', 'color' => '#ABCDEF']);
    eq('#abcdef', $p['color']);
    eq(['#abcdef'], array_values(array_unique(array_column($rods->colors($p), 'hex'))));
    $c = $rods->validate(['color_mode' => 'custom', 'color_3' => '123456', 'color_4' => '#8e4fb0']);
    $colors = $rods->colors($c);
    eq(['hex' => '#123456', 'name' => 'egen farge'], $colors[3]);
    eq('lilla', $colors[4]['name'], 'egen farge lik en klassisk farge får det klassiske navnet');
    eq('rød', $colors[2]['name'], 'egne farger starter med de klassiske');
    rejects($rods, ['color_mode' => 'regnbue'], 'color_mode');
    rejects($rods, ['color' => 'red'], 'color');
    rejects($rods, ['color_5' => '#12345'], 'color_5');
    rejects($rods, ['color_5' => '#1234567'], 'color_5');
    rejects($rods, ['color_5' => '"><script>'], 'color_5');
    // Fargene påvirker ikke geometrien: samme hurtigbuffer
    eq(array_column($rods->parts($rods->validate([])), 'cache_key'), array_column($rods->parts($c), 'cache_key'));
});

test('avrunding og riller kan ikke svekke små staver', function () use ($rods) {
    rejects($rods, ['unit' => 6, 'edge_radius' => 1.3], 'edge_radius');                    // > 20 % av 6 mm
    rejects($rods, ['unit' => 6, 'grid' => 1, 'groove_depth' => 0.7], 'groove_depth');      // > 10 %
    rejects($rods, ['unit' => 6, 'grid' => 1, 'groove_width' => 1.0], 'groove_width');      // > 15 %
    $rods->validate(['unit' => 6, 'groove_depth' => 0.8, 'groove_width' => 1.2]);           // inndeling av
    $rods->validate(['unit' => 6, 'grid' => 1, 'groove_depth' => 0.8, 'lengths' => '1']);  // stav 1 har ingen riller
});

test('én STL per valgt lengde: filnavn, antall og mål n · enhet', function () use ($rods) {
    $p = $rods->validate(['lengths' => '1,2,3,10', 'count_3' => 4, 'unit' => 12]);
    $parts = $rods->parts($p);
    eq(['tallstav_01.stl', 'tallstav_02.stl', 'tallstav_03.stl', 'tallstav_10.stl'], array_column($parts, 'filename'));
    eq(['1', '2', '3', '10'], array_column($parts, 'key'));
    eq([10, 10, 4, 10], array_column($parts, 'count'));
    eq([[12, 12, 12], [24, 12, 12], [36, 12, 12], [120, 12, 12]], array_column(array_column($parts, 'info'), 'size_mm'));
    foreach ($parts as $part) {
        eq('rods/rod.scad', $part['scad']);
        eq(['min' => [-$part['info']['size_mm'][0] / 2, -6, 0.0], 'max' => [$part['info']['size_mm'][0] / 2, 6, 12.0]], $part['bounds']);
    }
    // Antall påvirker ikke geometrien
    eq(array_column($parts, 'cache_key'), array_column($rods->parts($rods->validate(['lengths' => '1,2,3,10', 'unit' => 12])), 'cache_key'));
    // Stav 1 har aldri riller; uten tallmerking er tallvariablene faste
    $g = $rods->parts($rods->validate(['grid' => 1, 'lengths' => '1,2']));
    eq([0, 1], array_column(array_column($g, 'variables'), 'grid_on'));
    eq(0.0, $g[0]['variables']['groove_depth']);
    eq([0, 0], array_column(array_column($g, 'variables'), 'label_on'));
});

test('tallmerking: på den flate toppen, aldri over en rille, utelates når den blir for liten', function () use ($rods) {
    foreach ([6, 8, 10, 15, 20] as $u) {
        foreach ([0, 0.5, 1.2] as $r) {
            foreach ([false, true] as $grid) {
                $p = $rods->validate(['unit' => $u, 'edge_radius' => $r, 'grid' => $grid, 'labels' => 1]);
                foreach (range(1, 10) as $n) {
                    $l = $rods->labelLayout($n, $p);
                    $tag = "u=$u r=$r grid=" . (int) $grid . " stav $n";
                    if (!$l['shown']) {
                        eq('too_small', $l['reason'], $tag);
                        continue;
                    }
                    ok($l['height'] >= 3 - 1e-9 && $l['height'] <= 0.6 * $u + 1e-9, "$tag: høyde {$l['height']}");
                    ok($l['height'] / 2 <= $u / 2 - $r - 0.8 + 1e-9, "$tag: utenfor den flate toppen på tvers");
                    $x0 = $l['x'] - $l['width'] / 2;
                    $x1 = $l['x'] + $l['width'] / 2;
                    ok($x0 >= -$n * $u / 2 + 0.8 - 1e-6 && $x1 <= $n * $u / 2 - 0.8 + 1e-6, "$tag: for nær enden");
                    if ($grid && $n > 1) {
                        for ($k = 1; $k < $n; $k++) {
                            $g = -$n * $u / 2 + $k * $u;
                            ok($x1 <= $g - $p['groove_width'] / 2 - 0.8 + 1e-6 || $x0 >= $g + $p['groove_width'] / 2 + 0.8 - 1e-6, "$tag: overlapper rille $k");
                        }
                    } else {
                        near(0.0, $l['x'], 1e-9, "$tag: sentrert");
                    }
                }
            }
        }
    }
    $big = $rods->validate(['labels' => 1]);
    ok($rods->labelLayout(10, $big)['shown'] && $rods->labelLayout(10, $big)['height'] === 6.0, 'stav 10 ved 10 mm: 6 mm høyt tall');
    $small = $rods->validate(['unit' => 6, 'grid' => 1, 'labels' => 1]);
    ok(!$rods->labelLayout(10, $small)['shown'], '«10» får ikke plass i én enhet på 6 mm');
    ok((bool) array_filter($rods->warnings($small), fn($w) => str_contains($w, 'utelatt på stav 10')));
});

test('ZIP-oversikt: parametere, utskriftsliste og filamentfarger', function () use ($rods) {
    $p = $rods->validate(['lengths' => '1,7', 'count_7' => 3, 'grid' => 1]);
    $txt = $rods->archiveReadme($p, $rods->parts($p));
    ok(str_contains($txt, 'Enhetslengde:    10 mm'));
    ok(str_contains($txt, 'riller 0,3 mm dype og 0,5 mm brede'));
    ok((bool) preg_match('/tallstav_07\.stl\s+7\s+70 × 10 × 10\s+3\s+svart \(#2a2a2a\)/u', $txt), $txt);
    ok(str_contains($txt, 'Totalt: 13 staver'));
    ok(str_contains($txt, 'hvit (#f2efe8)') && str_contains($txt, 'STL-filer har ingen farge'));
    eq('tallstaver-10mm', $rods->archiveName($p));
    eq('tallstaver-7_5mm.pdf', $rods->pdfFilename($rods->validate(['unit' => 7.5])));
});

test('modellbeskrivelse (JSON)', function () use ($rods) {
    $d = $rods->describe($rods->validate(['lengths' => '2,4,8', 'count_8' => 2]));
    eq('number_rods', $d['model']);
    eq(22, $d['total_pieces']);
    eq(2 * 10 + 4 * 10 + 8 * 2, $d['total_length']);
    eq(8, $d['fraction_whole']);
    eq(['length', 'size_mm', 'count', 'stl', 'color', 'color_name', 'label', 'material_g'], array_keys($d['rods'][0]));
});

echo "\nTallstaver: STL (OpenSCAD)\n";

$rodBuild = function (array $input) use ($rods, $scadFor, $tmp, $config): array {
    $files = new GeneratedFiles("$tmp/storage-rods", 3600);
    $b = new ModelBuilder($rods, $scadFor($config['models_dir']), new StlInspector(), $files);
    $p = $rods->validate($input);
    $out = [];
    foreach ($b->buildAll($p) as $meta) {
        $meta['path'] = $files->file($meta['token'], 'model.stl');
        $out[(int) $meta['part']] = $meta;
    }
    return [$p, $out];
};

/** Areal av trekantene som ligger i planet coord[$axis] = $value. */
function planeArea(array $tris, int $axis, float $value): float
{
    $area = 0.0;
    foreach ($tris as [$a, $b, $c]) {
        if (abs($a[$axis] - $value) > 1e-4 || abs($b[$axis] - $value) > 1e-4 || abs($c[$axis] - $value) > 1e-4) {
            continue;
        }
        $u = [$b[0] - $a[0], $b[1] - $a[1], $b[2] - $a[2]];
        $v = [$c[0] - $a[0], $c[1] - $a[1], $c[2] - $a[2]];
        $area += 0.5 * sqrt(($u[1] * $v[2] - $u[2] * $v[1]) ** 2 + ($u[2] * $v[0] - $u[0] * $v[2]) ** 2 + ($u[0] * $v[1] - $u[1] * $v[0]) ** 2);
    }
    return $area;
}

test('alle ti staver: lukkede, manifold og nøyaktig n × 10 × 10 mm', function () use ($rodBuild) {
    [, $built] = $rodBuild([]);
    foreach (range(1, 10) as $n) {
        $c = $built[$n]['check'];
        ok($c['ok'], "stav $n: " . implode(' ', $c['problems']));
        eq(2, $c['euler'], "stav $n euler");
        eq(0, $c['boundary_edges'], "stav $n åpne kanter");
        eq(0, $c['non_manifold_edges'], "stav $n ikke-manifold");
        eq([10.0 * $n, 10.0, 10.0], $c['size_mm'], "stav $n mål");
        eq(0.0, $c['bounds']['min'][2], "stav $n ligger på z = 0");
    }
});

test('minste (6 mm) og største (20 mm) enhet med største avrunding, inndeling og tall', function () use ($rodBuild) {
    foreach ([[6, 1.2, 0.6, 0.9], [20, 1.5, 0.8, 1.2]] as [$u, $r, $d, $w]) {
        [, $built] = $rodBuild(['unit' => $u, 'edge_radius' => $r, 'grid' => 1, 'groove_depth' => $d, 'groove_width' => $w,
                                'labels' => 1, 'label_depth' => 0.8, 'lengths' => '1,7,10']);
        foreach ([1, 7, 10] as $n) {
            $c = $built[$n]['check'];
            ok($c['ok'], "u=$u stav $n: " . implode(' ', $c['problems']));
            eq([$n * $u, (float) $u, (float) $u], array_map('floatval', $c['size_mm']), "u=$u stav $n mål");
        }
    }
});

test('endeflatene er plane og hele: to staver ende mot ende er like lange som summen', function () use ($rodBuild, $rods) {
    [$p, $built] = $rodBuild(['lengths' => '2,3,5', 'edge_radius' => 1, 'grid' => 1, 'labels' => 1]);
    $profile = $rods->estimate(1, $rods->validate(['edge_radius' => 1]))['volume'] / 10;   // tverrsnittsareal
    foreach ([2, 3, 5] as $n) {
        $tris = stlTriangles($built[$n]['path']);
        foreach ([-1, 1] as $side) {
            near($profile, planeArea($tris, 0, $side * $n * 5), 0.01, "stav $n ende $side: hel, plan endeflate");
        }
    }
    eq($built[5]['check']['size_mm'][0], $built[2]['check']['size_mm'][0] + $built[3]['check']['size_mm'][0], '2 + 3 = 5');
});

test('enhetsinndeling: n − 1 riller på oversiden ved k · enhet, ingen på sider, bunn eller ender', function () use ($rodBuild) {
    [$p, $built] = $rodBuild(['grid' => 1, 'lengths' => '1,2,3,10']);
    [$d, $w] = [$p['groove_depth'], $p['groove_width']];
    foreach ([1, 2, 3, 10] as $n) {
        $tris = stlTriangles($built[$n]['path']);
        $L = $n * 10;
        eq($n > 1 ? range(1, $n - 1) : [], grooveLines($tris, 2, 10 - $d, 0, -$L / 2, 10, $w), "stav $n: riller på oversiden");
        eq([], grooveLines($tris, 2, $d, 0, -$L / 2, 10, $w), "stav $n: ingen riller i bunnen");
        eq([], grooveLines($tris, 1, -5 + $d, 0, -$L / 2, 10, $w), "stav $n: ingen riller på siden");
    }
});

test('tallmerking: nedsenket i oversiden, innenfor boksen og utenfor rillene', function () use ($rodBuild, $rods) {
    [$p, $built] = $rodBuild(['grid' => 1, 'labels' => 1, 'lengths' => '1,4,10']);
    foreach ([1, 4, 10] as $n) {
        $l = $rods->labelLayout($n, $p);
        $floor = array_filter(stlTriangles($built[$n]['path']), fn($t) => abs($t[0][2] - (10 - $p['label_depth'])) < 1e-4
            && abs($t[1][2] - $t[0][2]) < 1e-4 && abs($t[2][2] - $t[0][2]) < 1e-4);
        ok(count($floor) > 0, "stav $n: fant ikke bunnen av tallet");
        foreach ($floor as $t) {
            foreach ($t as $v) {
                ok(abs($v[0] - $l['x']) <= $l['width'] / 2 + 0.25 && abs($v[1]) <= $l['height'] / 2 + 0.05, "stav $n: tall utenfor boksen");
            }
        }
        ok($built[$n]['check']['volume_mm3'] < $rods->estimate($n, $rods->validate(['grid' => 1, 'lengths' => (string) $n]))['volume'], "stav $n: tallet fjerner volum");
    }
});

test('ingen overheng: bare bunnen og 45°-fasen peker nedover', function () use ($rodBuild) {
    [, $built] = $rodBuild(['edge_radius' => 1.5, 'grid' => 1, 'groove_width' => 1.2, 'labels' => 1, 'lengths' => '1,6,10']);
    foreach ($built as $n => $m) {
        foreach (stlTriangles($m['path']) as [$a, $b, $c]) {
            $u = [$b[0] - $a[0], $b[1] - $a[1], $b[2] - $a[2]];
            $v = [$c[0] - $a[0], $c[1] - $a[1], $c[2] - $a[2]];
            $nz = $u[0] * $v[1] - $u[1] * $v[0];
            $len = sqrt(($u[1] * $v[2] - $u[2] * $v[1]) ** 2 + ($u[2] * $v[0] - $u[0] * $v[2]) ** 2 + $nz ** 2);
            if ($len < 1e-9 || $nz / $len > -0.71) {
                continue;                                  // ikke brattere enn 45°
            }
            ok(max($a[2], $b[2], $c[2]) < 1e-4, "stav $n: overheng ved z = " . max($a[2], $b[2], $c[2]));
        }
    }
});

test('volumoverslaget stemmer innen 0,5 % (avrunding 0, 0,5 og 1,5 mm, med og uten riller)', function () use ($rodBuild, $rods) {
    foreach ([[0, false], [0.5, false], [1.5, true]] as [$r, $grid]) {
        [$p, $built] = $rodBuild(['edge_radius' => $r, 'grid' => $grid, 'lengths' => '1,5,10']);
        foreach ($built as $n => $m) {
            near($rods->estimate($n, $p)['volume'], $m['check']['volume_mm3'], $m['check']['volume_mm3'] * 0.005, "r=$r stav $n");
        }
    }
});

echo "\nTallstaver: kombinasjoner og oppgaver\n";

test('kombinasjoner: alle måter, antall tas hensyn til, bare lengder i settet', function () {
    $full = new RodTasks(array_fill(1, 10, 10));
    eq([[4, 1], [3, 2], [3, 1, 1], [2, 2, 1], [2, 1, 1, 1], [1, 1, 1, 1, 1]], $full->partitions(5));
    eq(41, count($full->partitions(10)), 'p(10) − 1 (uten stav 10 alene)');
    $two = new RodTasks([1 => 2, 2 => 10, 3 => 10, 4 => 10]);
    ok(!in_array([2, 1, 1, 1], $two->partitions(5), true) && in_array([3, 1, 1], $two->partitions(5), true), 'bare to enere');
    eq([[3, 2]], (new RodTasks([2 => 10, 3 => 10, 5 => 1]))->partitions(5));
    eq([], (new RodTasks([7 => 5]))->partitions(5));
    foreach ($full->partitions(13) as $p) {
        eq(13, array_sum($p));
        ok($full->canLay($p));
    }
    // Utvalget er variert og like stort som bedt om
    $show = RodTasks::showcase($full->partitions(20), 6);
    eq(6, count($show));
    ok(count(array_unique(array_map('count', $show))) >= 4, 'flere størrelser');
    eq([3, 1, 5, 0, 4, 2, 6], RodTasks::spread(7));
});

test('kombinasjoner i nettleseren (rod-combos.js) er identiske med PHP', function () {
    $node = trim((string) shell_exec(PHP_OS_FAMILY === 'Windows' ? 'where node 2>NUL' : 'command -v node 2>/dev/null'));
    if ($node === '') {
        throw new SkipTest('Node.js ikke funnet');
    }
    $sets = [array_fill(1, 10, 10), [1 => 2, 2 => 3, 5 => 1, 7 => 4], [3 => 5, 4 => 2, 6 => 1], [1 => 20, 10 => 1]];
    $js = 'import { partitions, showcase } from ' . json_encode('file:///' . str_replace('\\', '/', realpath(__DIR__ . '/../public/js/rod-combos.js'))) . ';'
        . 'const sets = ' . json_encode($sets, JSON_FORCE_OBJECT) . '; const out = [];'
        . 'for (const s of Object.values(sets)) for (let t = 2; t <= 20; t++) { const all = partitions(t, s); out.push([all, showcase(all, 6)]); }'
        . 'console.log(JSON.stringify(out));';
    $f = sys_get_temp_dir() . '/rodcombos-' . bin2hex(random_bytes(4)) . '.mjs';
    file_put_contents($f, $js);
    $out = json_decode((string) shell_exec(escapeshellarg(explode("\n", $node)[0]) . ' ' . escapeshellarg($f)), true);
    @unlink($f);
    ok(is_array($out), 'ingen utdata fra node');
    $i = 0;
    foreach ($sets as $s) {
        $t = new RodTasks($s);
        for ($target = 2; $target <= 20; $target++, $i++) {
            $all = $t->partitions($target);
            eq($all, $out[$i][0], "mål $target");
            eq(RodTasks::showcase($all, 6), $out[$i][1], "utvalg $target");
        }
    }
});

/** Tallstavene i en oppgave (rod-tokens), i rekkefølge. */
function rodTokens(array $task): array
{
    return array_values(array_map(fn($t) => $t[1], array_filter($task['tokens'], fn($t) => $t[0] === 'rod')));
}

/** Kontrollerer at fasiten stemmer med stavene og tallene i oppgaven. */
function checkAnswer(array $t): void
{
    $r = rodTokens($t);
    $a = $t['answer'];
    $text = implode('', array_map(fn($x) => $x[1] ?? '', array_filter($t['tokens'], fn($x) => $x[0] === 'text' || $x[0] === 'strong')));
    $fr = array_values(array_filter($t['tokens'], fn($x) => $x[0] === 'frac'));
    $label = "{$t['type']}/{$t['level']}: " . json_encode($t['tokens'], JSON_UNESCAPED_UNICODE) . " → $a";
    switch ($t['type']) {
        case 'find':
            if ($t['level'] === 'lett') {
                ok((bool) preg_match('/er (\d+) enhet/u', $text, $m), $label);
                eq("stav {$m[1]}", $a, $label);
            } elseif ($t['level'] === 'middels') {
                eq('stav ' . ($r[0] + $r[1]), $a, $label);
            } else {
                ok((bool) preg_match('/lang som (\d+) /u', $text, $m), $label);
                eq('stav ' . ((int) $m[1] * $r[0]), $a, $label);
            }
            break;
        case 'build':
            if ($t['level'] === 'vanskelig') {
                ok((bool) preg_match('/^Lengden (\d+) er like lang som ([\d +]+)\./u', $text, $m), $label);
                $target = (int) $m[1];
                eq($target, array_sum(array_map('intval', explode(' + ', $m[2]))), $label);
            } else {
                $target = $r[0];
            }
            foreach (preg_split('/; | eller /u', $a) as $sum) {
                eq($target, array_sum(array_map('intval', explode(' + ', $sum))), $label);
            }
            break;
        case 'add':
            eq($t['level'] === 'vanskelig' ? ($r[0] + $r[1] - 10) . ', ' . ($r[0] + $r[1]) : (string) array_sum($r), $a, $label);
            break;
        case 'sub':
            eq($t['level'] === 'vanskelig' ? (string) ($r[2] - $r[0] - $r[1]) : (string) abs($r[1] - $r[0]), $a, $label);
            break;
        case 'mul':
            if ($t['level'] === 'lett') {
                ok((bool) preg_match('/^Legg (\d+) /u', $text, $m), $label);
                ok(str_starts_with($a, (string) ((int) $m[1] * $r[0])), $label);
            } elseif ($t['level'] === 'middels') {
                ok((bool) preg_match('/(\d+) × (\d+)/u', $text, $m), $label);
                eq((string) ($m[1] * $m[2]), $a, $label);
            } else {
                ok((bool) preg_match('/lengden (\d+)/u', $text, $m), $label);
                foreach (explode(' og ', $a) as $prod) {
                    [$k, $d] = array_map('intval', explode(' × ', $prod));
                    eq((int) $m[1], $k * $d, $label);
                }
            }
            break;
        case 'div':
            $q = intdiv($r[1], $r[0]);
            eq($r[1] % $r[0] ? "$q, rest " . ($r[1] % $r[0]) : (string) $q, $a, $label);
            break;
        case 'parity':
            eq($r[0] % 2 === 0, str_ends_with($a, 'partall'), $label);
            break;
        case 'ratio':
            eq((string) ($r[2] * $r[1] / $r[0]), $a, $label);
            break;
        case 'fraction':
            $w = $t['whole'];
            if ($fr) {
                eq('stav ' . ($w * $fr[0][1] / $fr[0][2]), $a, $label);
            } else {
                $len = end($r);
                $g = RodTasks::gcd($len, $w);
                eq(($len / $g) . '/' . ($w / $g), $a, $label);
            }
            break;
        case 'equiv':
            ok($fr[0][1] * $fr[1][2] === $fr[1][1] * $fr[0][2], $label);
            eq($r[0] / $fr[0][2], (float) $r[2], "$label: 1/q1 av helen");
            eq($r[0] / $fr[1][2], (float) $r[1], "$label: 1/q2 av helen");
            break;
        case 'whole':
            eq('stav ' . ($r[0] * $fr[0][2]), $a, $label);
            break;
    }
}

$rodSets = [
    [],
    ['lengths' => '1,2,3,5', 'count_1' => 3, 'count_2' => 2, 'count_3' => 1, 'count_5' => 1],
    ['lengths' => '3,7', 'count_3' => 2, 'count_7' => 2],
    ['lengths' => '2,4,8'],
    ['lengths' => '1', 'count_1' => 100],
    ['lengths' => '10', 'count_10' => 1],
    ['lengths' => '1,2,3,4,5,6,7,8,9,10', 'count_1' => 1, 'count_2' => 1, 'count_3' => 1, 'count_4' => 1, 'count_5' => 1,
     'count_6' => 1, 'count_7' => 1, 'count_8' => 1, 'count_9' => 1, 'count_10' => 1],
    ['lengths' => '5,6,9,10', 'count_5' => 4],
];

test('oppgavegeneratoren: alle typer og nivåer, alle sett – gyldige staver og riktige svar', function () use ($rods, $rodSets) {
    foreach ($rodSets as $in) {
        $p = $rods->validate($in);
        $t = new RodTasks($p['counts']);
        $setLabel = json_encode($in);
        foreach (RodTasks::TYPES as $type) {
            foreach (RodTasks::LEVELS as $level) {
                foreach ($t->tasks($type, $level, 50) as $task) {
                    $label = "$setLabel $type/$level";
                    if ($task['paper']) {
                        eq(['tag', 'TEGNEOPPGAVE'], $task['tokens'][0], "$label: tegneoppgave er ikke merket");
                        ok(!$t->generate($type, $level, 1), "$label: tegneoppgave selv om settet har stavene");
                        $task['tokens'] = array_slice($task['tokens'], 2);
                    } else {
                        ok($t->canLay($task['rods']), "$label: krever " . json_encode($task['rods']));
                        foreach (rodTokens($task) as $n) {
                            ok($t->has($n), "$label: viser stav $n, som ikke er i settet");
                        }
                    }
                    checkAnswer($task);
                }
            }
        }
    }
});

test('oppgaver tilpasses settet: stav 7 brukes aldri uten stav 7, og antall teller', function () {
    $t = new RodTasks([1 => 10, 2 => 10, 3 => 10, 5 => 10]);
    foreach (RodTasks::TYPES as $type) {
        foreach (RodTasks::LEVELS as $level) {
            foreach ($t->generate($type, $level, 50) as $task) {
                ok(!in_array(7, $task['rods'], true) && !in_array(4, rodTokens($task), true), "$type/$level bruker en stav som mangler");
            }
        }
    }
    ok(in_array('5', array_column($t->generate('add', 'lett', 50), 'answer'), true), '2 + 3 = 5 finnes');
    // Bare én stav 4: «Legg 2 [4] etter hverandre» og «to staver 4 = stav 8» kan ikke lages
    $one = new RodTasks([4 => 1, 8 => 5]);
    eq([], $one->generate('mul', 'lett', 10));
    eq([], $one->generate('div', 'lett', 10));
    ok((new RodTasks([4 => 2, 8 => 5]))->generate('div', 'lett', 10) !== [], 'to staver 4 = stav 8');
});

test('brøk: bare brøker som kan vises nøyaktig, og helen kan byttes', function () {
    $t = new RodTasks(array_fill(1, 10, 10));
    eq(10, $t->mainWhole());
    eq(['whole' => 8, 'rod' => 2], $t->secondWhole());
    $no5 = new RodTasks([1 => 10, 2 => 10, 10 => 1]);
    foreach ($no5->generate('fraction', 'lett', 20) as $task) {
        ok(!str_contains($task['answer'], 'stav 5'), 'stav 5 finnes ikke');
    }
    eq(['1/5', '1/10'], array_map(fn($x) => '1/' . array_values(array_filter($x['tokens'], fn($tk) => $tk[0] === 'frac'))[0][2], $no5->generate('fraction', 'lett', 20)));
    eq(null, (new RodTasks([3 => 5, 7 => 5]))->mainWhole(), '3 og 7 går ikke opp i hverandre');
    $paper = (new RodTasks([3 => 5, 7 => 5]))->tasks('fraction', 'lett', 2);
    ok($paper && $paper[0]['paper'], 'uten brøker: tegneoppgaver');
});

test('PDF: sju A4-sider (oppgavesidene liggende), tekst innenfor arket, for alle sett', function () use ($rods, $rodSets, $k) {
    foreach ([...$rodSets, ['unit' => 20, 'grid' => 1, 'labels' => 1, 'color_mode' => 'single'], ['unit' => 6, 'color_mode' => 'custom', 'color_1' => '#000000']] as $in) {
        $pdf = $rods->renderPdf($rods->validate($in));
        ok(str_starts_with($pdf, '%PDF-'));
        eq(7, preg_match_all('/\/Type \/Page\b/', $pdf), json_encode($in) . ' sider');
        $pages = pdfPages($pdf);
        eq(7, count($pages));
        foreach ($pages as $i => $p) {
            ok(!textOutside($p), json_encode($in) . ' side ' . ($i + 1) . ': tekst utenfor arket ' . implode(' ', textOutside($p)));
            // Oversikten er stående, oppgavesidene liggende.
            eq($i === 0 ? 'P' : 'L', $p['w'] > $p['h'] ? 'L' : 'P', 'side ' . ($i + 1) . ' retning');
        }
    }
});

test('PDF: sidene, tegneoppgaver bare når settet mangler staver, arbeidsark i full størrelse', function () use ($rods, $k) {
    $c = pdfContent($rods->renderPdf($rods->validate([])));
    foreach (['lærerveiledning)', '(Bygg tall)', '(Addisjon)', '(Subtraksjon)', '(Multiplikasjon og divisjon)', '(Brøk)', '(Arbeidsark)', '(oransje)', '(tallstav_10.stl)'] as $s) {
        ok(str_contains($c, LabelPdf::enc($s)), "mangler «{$s}»");
    }
    ok(!str_contains($c, '(TEGNEOPPGAVE) Tj'), 'fullt sett skal ikke trenge tegneoppgaver');
    ok(!str_contains($c, 'SKALERT'), 'arbeidsarket skal være i full størrelse');
    $small = pdfContent($rods->renderPdf($rods->validate(['lengths' => '3,7', 'count_3' => 2, 'count_7' => 2])));
    ok(str_contains($small, '(TEGNEOPPGAVE) Tj'), 'lite sett skal ha merkede tegneoppgaver');
    // Banene på arbeidsarket er like brede som stavene (her 10 og 20 mm)
    foreach ([10, 20] as $u) {
        $w = pdfContent($rods->renderPdf($rods->validate(['unit' => $u])));
        $h = sprintf('%.3F', -$u * $k);
        ok((bool) preg_match('/ [\d.]+ ' . preg_quote($h, '/') . ' re B/', $w), "bane med høyde $u mm finnes ikke");
        ok(str_contains($w, '(Kontroller utskriften) Tj'), 'linjal for kontroll av utskriften');
    }
});

require __DIR__ . '/solids.php';
require __DIR__ . '/lego.php';
require __DIR__ . '/marble.php';

// Rydd opp
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($it as $f) {
    $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
}
@rmdir($tmp);

printf("\n%d bestått, %d feilet, %d hoppet over\n", $results['pass'], $results['fail'], $results['skip']);
exit($results['fail'] > 0 ? 1 : 0);
