<?php
declare(strict_types=1);

/**
 * Bygger STL-delene for en validert modell: hurtigbuffer → OpenSCAD → kontroll.
 *
 * Felles for alle modeller. Hver del (se ParametricModel::parts()) får sitt
 * eget arbeidsområde og token, og bufres for seg. Når læreren slår på én
 * nevner til i brøksirklene, lages derfor bare den nye brikka.
 */
final class ModelBuilder
{
    public function __construct(
        private ParametricModel $model,
        private ScadGenerator $scad,
        private StlInspector $inspector,
        private GeneratedFiles $files,
        private float $tolerance = 0.02,
    ) {
    }

    /**
     * Bygger første (eneste) del. Brukes av terningen.
     * @return array<string,mixed> metadata (token, filnavn, kontrollrapport ...)
     * @throws GenerationException
     */
    public function buildStl(array $params): array
    {
        $part = $this->model->parts($params)[0];
        return $this->buildPart($part, ['model' => $this->model->describe($params)]);
    }

    /**
     * Bygger alle delene.
     * @return list<array<string,mixed>>
     * @throws GenerationException
     */
    public function buildAll(array $params): array
    {
        $out = [];
        foreach ($this->model->parts($params) as $part) {
            $out[] = $this->buildPart($part);
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $part en del fra ParametricModel::parts()
     * @param array<string,mixed> $extraMeta lagres sammen med delen
     * @return array<string,mixed>
     * @throws GenerationException
     */
    public function buildPart(array $part, array $extraMeta = []): array
    {
        $scadFile = $part['scad'];
        // Nøkkelen endres hvis geometrien, SCAD-fila eller OpenSCAD-binæren endres.
        $cacheKey = $part['cache_key'] . '|' . $this->scad->fingerprint($scadFile);

        if ($token = $this->files->cacheLookup($cacheKey)) {
            $meta = $this->files->meta($token);
            if ($meta) {
                $meta['cached'] = true;
                return $meta;
            }
        }

        [$token, $dir] = $this->files->createWorkspace();
        $stl = $dir . '/model.stl';
        try {
            $run = $this->scad->render($scadFile, $part['variables'], $stl);
            $report = $this->inspector->inspect($stl, $part['bounds'], $this->tolerance, (int) ($part['shells'] ?? 1));
            if (!$report['ok']) {
                throw new GenerationException(
                    'Den genererte modellen besto ikke kvalitetskontrollen og kan ikke lastes ned.',
                    implode("\n", $report['problems']),
                    500,
                    'invalid_mesh'
                );
            }
        } catch (Throwable $e) {
            $this->files->remove($token);
            throw $e;
        }

        $meta = [
            'token'     => $token,
            'part'      => $part['key'],
            'filename'  => $part['filename'],
            'count'     => $part['count'],
        ] + $extraMeta + [
            'check'     => $report,
            'seconds'   => round($run['seconds'], 3),
            'created'   => date('c'),
        ];
        $this->files->saveMeta($token, $meta);
        $this->files->cacheStore($cacheKey, $token);
        $meta['cached'] = false;
        return $meta;
    }
}
