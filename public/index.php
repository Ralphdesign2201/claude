<?php

declare(strict_types=1);

// PHP-Entwicklungsserver: statische Dateien aus assets/ direkt ausliefern (Apache/nginx machen das selbst)
if (PHP_SAPI === 'cli-server') {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    if (preg_match('#^/assets/[A-Za-z0-9._-]+$#', (string) $path) && $path !== '/assets/index.html' && is_file(__DIR__ . $path)) {
        return false;
    }
}

require __DIR__ . '/../src/bootstrap.php';

ini_set('display_errors', '0');

App\App::run();
