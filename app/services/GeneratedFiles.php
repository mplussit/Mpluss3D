<?php
declare(strict_types=1);

/**
 * Midlertidig lagring av genererte filer.
 *
 * Hver generering får en egen mappe med et tilfeldig navn (token på 32
 * heksadesimale tegn). Brukeren ser bare tokenet, aldri filstier.
 * Mapper eldre enn TTL slettes av cleanup().
 *
 * En liten hurtigbuffer (cache/) peker fra en hash av parameterne til
 * tokenet, slik at samme terning ikke genereres på nytt for hver visning.
 */
final class GeneratedFiles
{
    private const TOKEN_PATTERN = '/^[a-f0-9]{32}$/';

    public function __construct(private string $storageDir, private int $ttlSeconds = 3600)
    {
        foreach (['generated', 'cache'] as $d) {
            if (!is_dir("$storageDir/$d")) {
                @mkdir("$storageDir/$d", 0775, true);
            }
        }
    }

    /** @return array{0:string,1:string} [token, mappe] */
    public function createWorkspace(): array
    {
        $token = bin2hex(random_bytes(16));
        $dir = $this->storageDir . '/generated/' . $token;
        if (!mkdir($dir, 0775)) {
            throw new GenerationException('Kunne ikke lagre filen.', "mkdir feilet: $dir");
        }
        return [$token, $dir];
    }

    public static function isValidToken(string $token): bool
    {
        return (bool) preg_match(self::TOKEN_PATTERN, $token);
    }

    /** Full sti til en fil i et arbeidsområde, eller null hvis den ikke finnes. */
    public function file(string $token, string $name): ?string
    {
        if (!self::isValidToken($token) || !preg_match('/^[a-z0-9_-]+\.[a-z]+$/', $name)) {
            return null;
        }
        $path = $this->storageDir . '/generated/' . $token . '/' . $name;
        return is_file($path) ? $path : null;
    }

    public function saveMeta(string $token, array $meta): void
    {
        file_put_contents(
            $this->storageDir . '/generated/' . $token . '/meta.json',
            json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    public function meta(string $token): ?array
    {
        $f = $this->file($token, 'meta.json');
        return $f ? json_decode((string) file_get_contents($f), true) : null;
    }

    public function cacheLookup(string $key): ?string
    {
        $f = $this->cacheFile($key);
        if (!is_file($f)) {
            return null;
        }
        $token = trim((string) file_get_contents($f));
        if (self::isValidToken($token) && $this->file($token, 'meta.json') && $this->file($token, 'model.stl')) {
            $age = time() - filemtime($this->storageDir . '/generated/' . $token . '/meta.json');
            if ($age < $this->ttlSeconds - 300) {   // ikke gi ut filer som snart slettes
                return $token;
            }
        }
        @unlink($f);
        return null;
    }

    public function cacheStore(string $key, string $token): void
    {
        file_put_contents($this->cacheFile($key), $token);
    }

    public function remove(string $token): void
    {
        if (!self::isValidToken($token)) {
            return;
        }
        $dir = $this->storageDir . '/generated/' . $token;
        foreach (glob($dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($dir);
    }

    /** Sletter utløpte filer. Returnerer antall slettede arbeidsområder. */
    public function cleanup(): int
    {
        $removed = 0;
        $limit = time() - $this->ttlSeconds;
        foreach (glob($this->storageDir . '/generated/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $token = basename($dir);
            if (self::isValidToken($token) && filemtime($dir) < $limit) {
                $this->remove($token);
                $removed++;
            }
        }
        foreach (glob($this->storageDir . '/cache/*.txt') ?: [] as $f) {
            if (filemtime($f) < $limit) {
                @unlink($f);
            }
        }
        return $removed;
    }

    private function cacheFile(string $key): string
    {
        return $this->storageDir . '/cache/' . hash('sha256', $key) . '.txt';
    }
}
