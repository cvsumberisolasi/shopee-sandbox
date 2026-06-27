<?php
declare(strict_types=1);

namespace App;

final class Env
{
    private static array $cache = [];
    private static bool $loaded = false;

    public static function load(string $rootDir): void
    {
        if (self::$loaded) return;
        $envFile = $rootDir . '/.env';
        if (!is_readable($envFile)) {
            // No .env file — rely on OS environment variables (Render, Railway, Docker, etc.)
            self::$loaded = true;
            return;
        }
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) continue;
            [$k, $v] = array_map('trim', explode('=', $line, 2));
            self::$cache[$k] = $v;
            $_ENV[$k] = $v;
            putenv("$k=$v");
        }
        self::$loaded = true;
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        return self::$cache[$key] ?? getenv($key) ?: $default;
    }

    public static function require(string $key): string
    {
        $v = self::get($key);
        if ($v === null || $v === '') {
            throw new \RuntimeException("Missing required env: $key");
        }
        return $v;
    }

    public static function int(string $key): int
    {
        return (int) self::require($key);
    }
}