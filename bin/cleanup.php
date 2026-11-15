<?php
/**
 * Sletter genererte filer som er eldre enn generated_ttl (standard 1 time).
 * API-et rydder også automatisk av og til, men på en server bør dette
 * kjøres fra cron, f.eks. hvert kvarter:
 *
 *   *\/15 * * * *  php /sti/til/mplus-3d/bin/cleanup.php
 */
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

$config = app_config();
$removed = (new GeneratedFiles($config['storage_dir'], (int) $config['generated_ttl']))->cleanup();
echo "Slettet $removed utløpte modeller.\n";
