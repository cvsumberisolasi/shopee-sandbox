<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use App\Database;
use App\Env;
use App\Sync\SyncShop;
use App\Sync\SyncProducts;
use App\Sync\SyncOrders;

Database::migrate();

$type = $argv[1] ?? 'all';
$shopId = Env::int('SHOPEE_SANDBOX_SHOP_ID');

echo "Sync started (type=$type, shop=$shopId)\n";

$results = [];

if ($type === 'all' || $type === 'shop') {
    $r = SyncShop::run($shopId);
    echo "shop: " . json_encode($r) . "\n";
    $results['shop'] = $r;
}
if ($type === 'all' || $type === 'products') {
    $r = SyncProducts::run($shopId);
    echo "products: " . json_encode($r) . "\n";
    $results['products'] = $r;
}
if ($type === 'all' || $type === 'orders') {
    $r = SyncOrders::run($shopId);
    echo "orders: " . json_encode($r) . "\n";
    $results['orders'] = $r;
}

echo "Done.\n";