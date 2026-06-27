<?php
/**
 * Router for PHP built-in server (used by Render free tier).
 * - Serves existing .php files directly
 * - Routes everything else through index.php
 *
 * Used by: php -S 0.0.0.0:$PORT router.php
 */
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = __DIR__ . $uri;

// Serve real files (e.g. /favicon.ico, /static/*) directly
if ($uri !== '/' && file_exists($path) && !is_dir($path)) {
    return false;
}

// /callback.php → real callback handler
if (preg_match('#^/callback(\.php)?$#', $uri)) {
    require __DIR__ . '/callback.php';
    return true;
}

// Everything else → API router
require __DIR__ . '/index.php';
return true;