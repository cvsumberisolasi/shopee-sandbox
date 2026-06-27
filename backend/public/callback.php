<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use App\Database;
use App\Env;
use App\ShopeeClient;
use App\TokenStore;

Database::migrate();

$code   = $_GET['code']   ?? null;
$shopId = isset($_GET['shop_id']) ? (int)$_GET['shop_id'] : null;
$error  = $_GET['error']  ?? null;

if ($error) {
    http_response_code(400);
    echo "<h1>Auth Error</h1><p>$error</p><p>" . htmlspecialchars($_GET['error_description'] ?? '') . "</p>";
    exit;
}

if (!$code || !$shopId) {
    http_response_code(400);
    echo "<h1>Missing code or shop_id</h1>";
    exit;
}

$client = new ShopeeClient();
$resp = $client->exchangeCode($code, $shopId);

if (!empty($resp['error']) || empty($resp['access_token'])) {
    http_response_code(500);
    echo "<h1>Token Exchange Failed</h1><pre>" . htmlspecialchars(json_encode($resp, JSON_PRETTY_PRINT)) . "</pre>";
    exit;
}

$expireAt = time() + (int)($resp['expire_in'] ?? 14400);
TokenStore::save(
    $shopId,
    $resp['access_token'],
    $resp['refresh_token'] ?? '',
    $expireAt
);

$redirect = Env::get('FRONTEND_URL', 'http://127.0.0.1:5173') . "/?shop_id=$shopId&auth=ok";
header("Location: $redirect");
exit;