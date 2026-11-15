<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/api/Api.php';

ini_set('display_errors', '0');

(new Api())->handle($_SERVER['REQUEST_METHOD'] ?? 'GET', (string) ($_GET['action'] ?? ''));
