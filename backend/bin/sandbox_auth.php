<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use App\Env;
use App\ShopeeClient;

$client = new ShopeeClient();
$redirectUri = 'http://127.0.0.1:8080/callback.php';
$url = $client->buildAuthUrl($redirectUri);

echo "Visit this URL in your browser:\n\n";
echo $url . "\n\n";
echo "Login with sandbox seller account:\n";
echo "  Account:  " . Env::get('SHOPEE_SANDBOX_ACCOUNT') . "\n";
echo "  Password: " . Env::get('SHOPEE_SANDBOX_PASSWORD') . "\n";
echo "  OTP code (if asked): 123456\n\n";
echo "After approve, Shopee redirects to callback.php which saves the token.\n";