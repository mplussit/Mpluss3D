<?php
declare(strict_types=1);

/**
 * Slår opp modellgeneratorer fra app/config/models.php.
 * Bare modeller som står der, kan brukes.
 */
final class ModelRegistry
{
    /** @var array<string,class-string<ParametricModel>> */
    private array $models;

    public function __construct(private string $configDir)
    {
        $this->models = require $configDir . '/models.php';
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->models);
    }

    public function has(string $key): bool
    {
        return isset($this->models[$key]);
    }

    public function get(string $key): ParametricModel
    {
        if (!$this->has($key)) {
            throw new GenerationException('Ukjent modell.', "Ukjent modell: $key", 404, 'not_found');
        }
        $class = $this->models[$key];
        return $class::load($this->configDir);
    }
}
