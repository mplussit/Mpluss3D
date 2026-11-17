<?php
declare(strict_types=1);

/**
 * Kjører OpenSCAD som en separat prosess og eksporterer STL.
 *
 * Sikkerhet:
 *  - Bare .scad-filer som ligger i models/ kan brukes. Brukeren kan aldri
 *    sende egen OpenSCAD-kode.
 *  - Prosessen startes med proc_open() og en argumentliste (ingen shell),
 *    så ingen verdier tolkes av et kommandoskall.
 *  - -D-variabler har validerte navn og er alltid tall eller flate lister
 *    med tall (tekst sendes som tegnkoder og gjøres om med chr() i SCAD).
 *  - Kjøretiden er begrenset, og antall samtidige prosesser styres av
 *    ProcessSlots.
 */
final class ScadGenerator
{
    /**
     * @param list<string> $extraArgs  f.eks. ['--backend=manifold']
     * @param list<string> $wrapper    valgfritt prefiks, f.eks. ['nice', '-n', '10']
     */
    public function __construct(
        private string $binary,
        private string $modelsDir,
        private array $extraArgs = [],
        private int $timeoutSeconds = 60,
        private ?ProcessSlots $slots = null,
        private array $wrapper = [],
    ) {
    }

    /**
     * Lager en ScadGenerator fra config. Finner binæren automatisk og slår på
     * Manifold-motoren hvis OpenSCAD-versjonen støtter den.
     */
    public static function fromConfig(array $config): self
    {
        $os = $config['openscad'];
        $binary = $os['binary'] ?: self::detectBinary();
        if ($binary === null) {
            throw new GenerationException(
                'OpenSCAD er ikke installert på serveren.',
                'Fant ingen openscad-binær. Sett miljøvariabelen OPENSCAD_BIN.',
                503,
                'not_found'
            );
        }
        $args = $os['args'] ?? (self::supportsManifold($binary, $config['storage_dir']) ? ['--backend=manifold'] : []);
        $slots = new ProcessSlots($config['storage_dir'] . '/locks', (int) $os['max_concurrent'], (int) $os['queue_wait']);
        return new self($binary, $config['models_dir'], $args, (int) $os['timeout'], $slots, $os['wrapper'] ?? []);
    }

    public function binary(): string
    {
        return $this->binary;
    }

    /** Identifiserer modellfil + binær + argumenter (brukes i hurtigbufferen). */
    public function fingerprint(string $scadFile): string
    {
        return md5((string) md5_file($this->resolveModel($scadFile)) . '|' . $this->binary . '|' . implode(' ', $this->extraArgs));
    }

    /**
     * Rendrer en modell til STL.
     *
     * @param string $scadFile relativ sti under models/, f.eks. "dice/cube.scad"
     * @param array<string,int|float|list<int|float>> $variables
     * @return array{seconds:float,log:string}
     * @throws GenerationException
     */
    public function render(string $scadFile, array $variables, string $outFile): array
    {
        $scadPath = $this->resolveModel($scadFile);

        if (strtolower(pathinfo($outFile, PATHINFO_EXTENSION)) !== 'stl' || !is_dir(dirname($outFile))) {
            throw new GenerationException('Intern feil ved filgenerering.', "Ugyldig utfil: $outFile");
        }

        $cmd = [...$this->wrapper, $this->binary, ...$this->extraArgs, '--export-format=binstl', '-o', $outFile];
        foreach ($variables as $name => $value) {
            $cmd[] = '-D';
            $cmd[] = $name . '=' . self::formatNumber($name, $value);
        }
        $cmd[] = $scadPath;

        $slot = $this->slots?->acquire();
        try {
            return $this->run($cmd, $outFile, dirname($scadPath));
        } finally {
            $slot?->release();
        }
    }

    /** @param list<string> $cmd */
    private function run(array $cmd, string $outFile, string $cwd): array
    {
        $logFile = $outFile . '.log';
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', $logFile, 'w'],
            2 => ['file', $logFile . '.err', 'w'],
        ];
        $start = microtime(true);
        $proc = @proc_open($cmd, $descriptors, $pipes, $cwd, null, ['bypass_shell' => true]);
        if (!is_resource($proc)) {
            throw new GenerationException('Kunne ikke starte OpenSCAD.', 'proc_open feilet for ' . $this->binary);
        }
        fclose($pipes[0]);

        $exitCode = null;
        while (true) {
            $status = proc_get_status($proc);
            if (!$status['running']) {
                $exitCode = $status['exitcode'];
                break;
            }
            if (microtime(true) - $start > $this->timeoutSeconds) {
                self::killTree($status['pid'], $proc);
                proc_close($proc);
                $log = $this->readLog($logFile);
                @unlink($outFile);
                throw new GenerationException(
                    'Genereringen tok for lang tid og ble stoppet. Prøv igjen, eventuelt med andre verdier.',
                    "Tidsavbrudd etter {$this->timeoutSeconds} s.\n" . $log,
                    504,
                    'timeout'
                );
            }
            usleep(15000);
        }
        $closeCode = proc_close($proc);
        if ($exitCode === -1 || $exitCode === null) {
            $exitCode = $closeCode;
        }
        $seconds = microtime(true) - $start;
        $log = $this->readLog($logFile);

        if ($exitCode !== 0 || !is_file($outFile) || filesize($outFile) === 0) {
            @unlink($outFile);
            throw new GenerationException(
                'OpenSCAD klarte ikke å lage modellen.',
                "Exit-kode $exitCode.\n" . $log
            );
        }
        // OpenSCAD avslutter med 0 også ved enkelte skriptfeil (f.eks. udefinerte
        // variabler). Behandle «ERROR:» i loggen som feil.
        if (preg_match('/^ERROR:/m', $log)) {
            @unlink($outFile);
            throw new GenerationException('OpenSCAD rapporterte en feil i modellen.', $log);
        }
        return ['seconds' => $seconds, 'log' => $log];
    }

    private function readLog(string $logFile): string
    {
        $log = '';
        foreach ([$logFile, $logFile . '.err'] as $f) {
            if (is_file($f)) {
                $log .= (string) file_get_contents($f, false, null, 0, 64 * 1024);
                @unlink($f);
            }
        }
        return trim($log);
    }

    private function resolveModel(string $scadFile): string
    {
        $base = realpath($this->modelsDir);
        $path = realpath($this->modelsDir . '/' . $scadFile);
        if ($base === false || $path === false
            || !str_starts_with($path, $base . DIRECTORY_SEPARATOR)
            || strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'scad') {
            throw new GenerationException('Ukjent modell.', "Ugyldig modellfil: $scadFile", 400, 'not_found');
        }
        return $path;
    }

    /**
     * Et tall, eller en flat liste med tall (f.eks. rutene i en form eller
     * tegnkodene til en tekst). Bare tall slipper gjennom: ingen strenger og
     * ingen OpenSCAD-kode fra brukeren.
     */
    private static function formatNumber(string $name, mixed $value): string
    {
        if (!preg_match('/^[a-z_][a-z0-9_]{0,40}$/', $name)) {
            throw new GenerationException('Intern feil ved filgenerering.', "Ugyldig variabelnavn: $name");
        }
        if (is_array($value)) {
            if (!array_is_list($value) || count($value) > 4000) {
                throw new GenerationException('Intern feil ved filgenerering.', "Variabelen $name er ikke en gyldig liste.");
            }
            return '[' . implode(',', array_map(fn($v) => self::scalar($name, $v), $value)) . ']';
        }
        return self::scalar($name, $value);
    }

    private static function scalar(string $name, mixed $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value) && is_finite($value)) {
            $s = rtrim(rtrim(sprintf('%.6F', $value), '0'), '.');
            return $s === '-0' ? '0' : $s;
        }
        throw new GenerationException('Intern feil ved filgenerering.', "Variabelen $name er ikke et tall.");
    }

    /** Stopper prosessen og eventuelle underprosesser. */
    private static function killTree(int $pid, $proc): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            // openscad.com starter openscad.exe som underprosess.
            $k = @proc_open(['taskkill', '/F', '/T', '/PID', (string) $pid], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $p, null, null, ['bypass_shell' => true]);
            if (is_resource($k)) {
                stream_get_contents($p[1]);
                proc_close($k);
            }
        }
        @proc_terminate($proc, 9);
    }

    /**
     * Leter etter OpenSCAD. Nyere versjoner (med Manifold) foretrekkes.
     */
    public static function detectBinary(): ?string
    {
        $candidates = [];
        if (PHP_OS_FAMILY === 'Windows') {
            $local = getenv('LOCALAPPDATA');
            if ($local) {
                $snapshots = glob($local . '/Programs/OpenSCAD-*/openscad.com') ?: [];
                rsort($snapshots, SORT_NATURAL);
                array_push($candidates, ...$snapshots);
            }
            foreach ([getenv('ProgramFiles'), getenv('ProgramW6432')] as $pf) {
                if ($pf) {
                    $candidates[] = $pf . '/OpenSCAD (Nightly)/openscad.com';
                    $candidates[] = $pf . '/OpenSCAD/openscad.com';
                }
            }
        } elseif (PHP_OS_FAMILY === 'Darwin') {
            $candidates[] = '/Applications/OpenSCAD.app/Contents/MacOS/OpenSCAD';
        } else {
            $candidates = ['/usr/local/bin/openscad', '/usr/bin/openscad', '/snap/bin/openscad'];
        }
        foreach ($candidates as $c) {
            if (is_file($c)) {
                return $c;
            }
        }
        // Søk i PATH
        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $dir) {
            foreach (['openscad', 'openscad.com', 'openscad.exe'] as $name) {
                $f = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $name;
                if (is_file($f) && (PHP_OS_FAMILY === 'Windows' || is_executable($f))) {
                    return $f;
                }
            }
        }
        return null;
    }

    /**
     * Sjekker (og hurtigbufrer) om binæren støtter --backend=manifold.
     */
    public static function supportsManifold(string $binary, string $storageDir): bool
    {
        $cacheFile = $storageDir . '/openscad-capabilities.json';
        $id = $binary . '|' . (@filemtime($binary) ?: 0);
        $cache = is_file($cacheFile) ? json_decode((string) file_get_contents($cacheFile), true) : null;
        if (is_array($cache) && ($cache['id'] ?? null) === $id) {
            return (bool) $cache['manifold'];
        }
        $help = '';
        $p = @proc_open([$binary, '--help'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
        if (is_resource($p)) {
            $help = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            proc_close($p);
        }
        $manifold = str_contains($help, '--backend');
        @file_put_contents($cacheFile, json_encode(['id' => $id, 'manifold' => $manifold]));
        return $manifold;
    }
}
