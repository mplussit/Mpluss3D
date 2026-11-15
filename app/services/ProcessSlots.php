<?php
declare(strict_types=1);

/**
 * Enkel semafor basert på fillåser. Begrenser hvor mange OpenSCAD-prosesser
 * som kan kjøre samtidig, uten behov for database eller ekstra tjenester.
 */
final class ProcessSlots
{
    public function __construct(
        private string $lockDir,
        private int $maxConcurrent = 2,
        private int $waitSeconds = 20,
    ) {
        if (!is_dir($lockDir)) {
            @mkdir($lockDir, 0775, true);
        }
    }

    public function acquire(): ProcessSlot
    {
        $deadline = microtime(true) + $this->waitSeconds;
        do {
            for ($i = 0; $i < $this->maxConcurrent; $i++) {
                $h = @fopen($this->lockDir . "/slot-$i.lock", 'c');
                if ($h && flock($h, LOCK_EX | LOCK_NB)) {
                    return new ProcessSlot($h);
                }
                if ($h) {
                    fclose($h);
                }
            }
            usleep(100000);
        } while (microtime(true) < $deadline);

        throw new GenerationException(
            'Serveren er opptatt med andre modeller. Prøv igjen om litt.',
            'Ingen ledig OpenSCAD-plass.',
            503,
            'busy'
        );
    }
}

final class ProcessSlot
{
    /** @param resource $handle */
    public function __construct(private $handle)
    {
    }

    public function release(): void
    {
        if (is_resource($this->handle)) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
        }
    }

    public function __destruct()
    {
        $this->release();
    }
}
