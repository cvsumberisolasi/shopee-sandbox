<?php
declare(strict_types=1);

spl_autoload_register(function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) return;
    $relative = substr($class, strlen($prefix));
    $relativePath = str_replace('\\', '/', $relative) . '.php';
    $file = __DIR__ . '/' . $relativePath;
    if (is_file($file)) require $file;
});

App\Env::load(dirname(__DIR__, 2));