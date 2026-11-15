<?php
/**
 * Genererer en modell fra kommandolinjen (nyttig for testing og feilsøking).
 *
 *   php bin/generate.php size=30 corner_radius=2 pip_diameter=3 pip_depth=0.5
 *   php bin/generate.php model=fractions diameter=100 denominators=2,3,4 labels=1
 *   php bin/generate.php model=base10 unit=10 types=ener,tier,hundrer,tusener count_tusener=1
 *   php bin/generate.php model=rods unit=10 lengths=1,2,5,10 grid=1 labels=1
 *   php bin/generate.php model=solids shape=cone cone_diameter=60 cone_height=70 hollow=1
 *
 * model= velger modell fra app/config/models.php (standard: dice).
 * Skriver ut kontrollrapporten som JSON, og stien til hver STL-fil.
 */
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

$input = [];
foreach (array_slice($argv, 1) as $arg) {
    if (str_contains($arg, '=')) {
        [$k, $v] = explode('=', $arg, 2);
        $input[$k] = $v;
    }
}

$config = app_config();
try {
    $model = (new ModelRegistry(__DIR__ . '/../app/config'))->get($input['model'] ?? 'dice');
    unset($input['model']);
    $params = $model->validate($input);
    $scad = ScadGenerator::fromConfig($config);
    $files = new GeneratedFiles($config['storage_dir'], $config['generated_ttl']);
    $builder = new ModelBuilder($model, $scad, new StlInspector($config['stl']['max_triangles']), $files, $config['stl']['dimension_tolerance']);
    if ($model instanceof DiceModel) {
        $meta = $builder->buildStl($params);
        $meta['stl_path'] = $files->file($meta['token'], 'model.stl');
    } else {
        $meta = ['model' => $model->describe($params), 'warnings' => $model->warnings($params), 'parts' => []];
        foreach ($builder->buildAll($params) as $part) {
            $part['stl_path'] = $files->file($part['token'], 'model.stl');
            $meta['parts'][] = $part;
        }
    }
    $meta['openscad'] = $scad->binary();
    echo json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
} catch (ValidationException $e) {
    fwrite(STDERR, "Ugyldige parametere:\n - " . implode("\n - ", array_column($e->errors(), 'message')) . "\n");
    exit(2);
} catch (GenerationException $e) {
    fwrite(STDERR, $e->getMessage() . "\n" . $e->details() . "\n");
    exit(1);
}
