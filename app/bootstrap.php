<?php
/**
 * Felles oppstart for API, CLI-skript og tester.
 */
declare(strict_types=1);

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (!is_file($autoload)) {
    http_response_code(500);
    exit("Avhengigheter mangler. Kjør «composer install» i prosjektmappen.\n");
}
require $autoload;

mb_internal_encoding('UTF-8');
date_default_timezone_set('Europe/Oslo');

/** @return array<string,mixed> */
function app_config(): array
{
    static $config = null;
    return $config ??= require __DIR__ . '/config/config.php';
}

/** Skriver tekniske feildetaljer til storage/logs (aldri til brukeren). */
function app_log(string $message): void
{
    $dir = app_config()['storage_dir'] . '/logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    @file_put_contents($dir . '/app-' . date('Y-m-d') . '.log', '[' . date('c') . '] ' . $message . "\n", FILE_APPEND);
}
