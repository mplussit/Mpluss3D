<?php
declare(strict_types=1);

/**
 * HTTP-API for alle modellgeneratorer. Kalles fra public/api.php.
 *
 * Modellen velges med model=… (se app/config/models.php). Standard er
 * "dice", så alle eldre kall til terninggeneratoren virker som før.
 *
 *   GET  api.php?action=schema[&model=…]          parametere, grenser og standardverdier
 *   POST api.php?action=generate[&model=…] (JSON)  lager STL-delene, svarer med token per del
 *   GET  api.php?action=stl&id=…[&download=1]      henter én STL (forhåndsvisning eller nedlasting)
 *   GET  api.php?action=zip&model=…&…              alle STL-delene + oversikt.txt i én ZIP
 *   GET  api.php?action=pdf[&model=…]&…            PDF fra de samme parameterne
 *   POST api.php?action=zip|pdf&model=… (JSON)     som over, med parameterne i JSON
 *                                                  (prosjekter som er for store for adressen)
 *   GET  api.php?action=describe[&model=…]&…       modellbeskrivelse (JSON) for M+
 *   POST api.php?action=describe&model=… (JSON)    som over, med parameterne i JSON
 */
final class Api
{
    private array $config;
    private ModelRegistry $registry;
    private GeneratedFiles $files;

    public function __construct()
    {
        $this->config = app_config();
        $this->registry = new ModelRegistry(dirname(__DIR__) . '/config');
        $this->files = new GeneratedFiles($this->config['storage_dir'], (int) $this->config['generated_ttl']);
    }

    public function handle(string $method, string $action): void
    {
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        try {
            match ([$method, $action]) {
                ['GET', 'schema']     => $this->schema(),
                ['POST', 'generate']  => $this->generate(),
                ['GET', 'stl']        => $this->stl(),
                ['GET', 'zip'], ['POST', 'zip'] => $this->zip(),
                ['GET', 'pdf'], ['POST', 'pdf'] => $this->pdf(),
                ['GET', 'describe'], ['POST', 'describe'] => $this->describe(),
                default               => $this->json(['ok' => false, 'error' => 'Ukjent forespørsel.'], 404),
            };
        } catch (ValidationException $e) {
            $this->json(['ok' => false, 'error' => 'Noen verdier er ugyldige.', 'errors' => $e->errors()], 422);
        } catch (GenerationException $e) {
            app_log("[$action] {$e->reason()}: {$e->getMessage()}\n{$e->details()}");
            $this->json(['ok' => false, 'error' => $e->getMessage(), 'reason' => $e->reason()], $e->httpStatus());
        } catch (Throwable $e) {
            app_log("[$action] " . get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            $this->json(['ok' => false, 'error' => 'Det oppstod en uventet feil.'], 500);
        }
    }

    /** Modellen fra ?model=… (standard: terning). */
    private function model(): ParametricModel
    {
        $key = $_GET['model'] ?? 'dice';
        if (!is_string($key) || !preg_match('/^[a-z][a-z0-9_]{0,31}$/', $key) || !$this->registry->has($key)) {
            throw new GenerationException('Ukjent modell.', 'Ukjent modell: ' . json_encode($key), 404, 'not_found');
        }
        return $this->registry->get($key);
    }

    private function builder(ParametricModel $model): ModelBuilder
    {
        return new ModelBuilder(
            $model,
            ScadGenerator::fromConfig($this->config),
            new StlInspector((int) $this->config['stl']['max_triangles']),
            $this->files,
            (float) $this->config['stl']['dimension_tolerance'],
        );
    }

    private function schema(): void
    {
        $this->json(['ok' => true] + $this->model()->schema());
    }

    /** JSON-kroppen i en POST-forespørsel (høyst 64 kB), eller null. */
    private function jsonBody(): ?array
    {
        $raw = file_get_contents('php://input', false, null, 0, 64 * 1024);
        $input = json_decode((string) $raw, true);
        return is_array($input) ? $input : null;
    }

    /** Validerte parametere fra JSON (POST) eller spørrestrengen (GET). */
    private function requestParams(ParametricModel $model): array
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $input = $this->jsonBody();
            if ($input === null) {
                throw new GenerationException('Ugyldig forespørsel.', 'Ugyldig JSON.', 400, 'bad_request');
            }
            return $model->validate($input);
        }
        return $model->validate($this->queryParams($model));
    }

    private function generate(): void
    {
        $model = $this->model();
        $input = $this->jsonBody();
        if ($input === null) {
            $this->json(['ok' => false, 'error' => 'Ugyldig forespørsel.'], 400);
            return;
        }
        $params = $model->validate($input);

        if (random_int(1, 20) === 1) {
            $this->files->cleanup();
        }

        $parts = $model->parts($params);
        $builder = $this->builder($model);
        $built = [];
        $seconds = 0.0;
        $cached = true;
        foreach ($parts as $i => $part) {
            $meta = count($parts) === 1
                ? $builder->buildStl($params)
                : $builder->buildPart($part);
            $seconds += $meta['seconds'];
            $cached = $cached && $meta['cached'];
            $built[] = [
                'key'      => $part['key'],
                'id'       => $meta['token'],
                'filename' => $meta['filename'],
                'stl_url'  => 'api.php?action=stl&id=' . $meta['token'],
                'count'    => $part['count'],
                'check'    => $meta['check'],
                'cached'   => $meta['cached'],
                'info'     => $part['info'],
            ];
        }

        $out = [
            'ok'       => true,
            'model'    => $model->describe($params),
            'params'   => $params,
            'warnings' => $model->warnings($params),
            'parts'    => $built,
            'seconds'  => round($seconds, 3),
            'cached'   => $cached,
        ];
        if (count($built) === 1) {
            // Samme felt som terninggeneratoren alltid har hatt.
            $out += [
                'id'       => $built[0]['id'],
                'filename' => $built[0]['filename'],
                'stl_url'  => $built[0]['stl_url'],
                'check'    => $built[0]['check'],
            ];
        }
        $this->json($out);
    }

    private function stl(): void
    {
        $id = (string) ($_GET['id'] ?? '');
        $file = GeneratedFiles::isValidToken($id) ? $this->files->file($id, 'model.stl') : null;
        $meta = $file ? $this->files->meta($id) : null;
        if (!$file || !$meta) {
            $this->json(['ok' => false, 'error' => 'Filen finnes ikke lenger. Lag modellen på nytt.'], 404);
            return;
        }
        $download = isset($_GET['download']);
        $this->sendFile($file, 'model/stl', $meta['filename'], $download);
    }

    /** Alle delene i én ZIP-fil, med en oversikt over hvor mange som skal skrives ut. */
    private function zip(): void
    {
        if (!class_exists(ZipArchive::class)) {
            throw new GenerationException('ZIP-nedlasting er ikke tilgjengelig på serveren.', 'PHP-utvidelsen zip mangler.', 501, 'no_zip');
        }
        $model = $this->model();
        $params = $this->requestParams($model);
        $parts = $model->parts($params);
        $builder = $this->builder($model);

        [$token, $dir] = $this->files->createWorkspace();
        $zipPath = $dir . '/archive.zip';
        try {
            $zip = new ZipArchive();
            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new GenerationException('Kunne ikke lage ZIP-fila.', 'ZipArchive::open feilet.');
            }
            foreach ($parts as $part) {
                $meta = $builder->buildPart($part);
                $zip->addFile((string) $this->files->file($meta['token'], 'model.stl'), $part['filename']);
            }
            $zip->addFromString('oversikt.txt', $model->archiveReadme($params, $parts));
            $zip->close();
            $this->sendFile($zipPath, 'application/zip', $model->archiveName($params) . '.zip', true);
        } finally {
            $this->files->remove($token);
        }
    }

    private function pdf(): void
    {
        $model = $this->model();
        $params = $this->requestParams($model);
        $pdf = $model->renderPdf($params);
        $name = $model->pdfFilename($params);
        header('Content-Type: application/pdf');
        // Sidenavnene til fanene i forhåndsvisningen (JSON, URL-kodet).
        header('X-Page-Titles: ' . rawurlencode(json_encode(LabelPdf::lastPageTitles(), JSON_UNESCAPED_UNICODE)));
        header('Cache-Control: no-store');
        header('Content-Length: ' . strlen($pdf));
        header('Content-Disposition: ' . (isset($_GET['download']) ? 'attachment' : 'inline') . '; filename="' . $name . '"');
        echo $pdf;
    }

    private function describe(): void
    {
        $model = $this->model();
        $params = $this->requestParams($model);
        $this->json($model->describe($params) + ['warnings' => $model->warnings($params)]);
    }

    /** Henter bare kjente parametere fra spørrestrengen. */
    private function queryParams(ParametricModel $model): array
    {
        $input = [];
        foreach ($model->queryKeys() as $k) {
            if (isset($_GET[$k]) && is_string($_GET[$k])) {
                $input[$k] = $_GET[$k];
            }
        }
        return $input;
    }

    private function sendFile(string $path, string $type, string $filename, bool $download): void
    {
        header('Content-Type: ' . $type);
        header('Content-Length: ' . filesize($path));
        header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="' . $filename . '"');
        readfile($path);
    }

    private function json(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
