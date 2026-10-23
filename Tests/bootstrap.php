<?php

// Module test bootstrap: FreeScout's autoloader plus this module's own autoloader.
$root = dirname(__DIR__, 3);

if (file_exists($root . '/bootstrap/cache/config.php')) {
    fwrite(STDERR, "FreeScout's config is cached, so tests would run against the dev database. Run: php artisan config:clear\n");
    exit(1);
}

require $root . '/vendor/autoload.php';
require dirname(__DIR__) . '/vendor/autoload.php';
