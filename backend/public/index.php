<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use App\Database;
use App\Env;
use App\ShopeeClient;
use App\Sync\SyncShop;
use App\Sync\SyncProducts;
use App\Sync\SyncOrders;
use App\Sync\ShippingLabel;
use App\TokenStore;

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

Database::migrate();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri    = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$query  = [];
parse_str(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_QUERY) ?? '', $query);

function out(int $code, array $data): never {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function shopId(array $q): int {
    $id = (int)($q['shop_id'] ?? Env::get('SHOPEE_SANDBOX_SHOP_ID'));
    if ($id <= 0) out(400, ['error' => 'shop_id required']);
    return $id;
}

try {
    if ($method === 'OPTIONS') out(204, []);

    if ($uri === '/api/auth/url' && $method === 'GET') {
        $client = new ShopeeClient();
        $redirectUri = Env::get('SHOPEE_AUTH_REDIRECT_URI', 'http://127.0.0.1:8080/callback.php');
        out(200, ['url' => $client->buildAuthUrl($redirectUri)]);
    }

    if ($uri === '/api/dashboard' && $method === 'GET') {
        $shopId = shopId($query);
        $shop = Database::pdo()->prepare('SELECT * FROM shops WHERE shop_id = ?');
        $shop->execute([$shopId]);
        $shopRow = $shop->fetch();

        $counts = Database::pdo()->prepare(<<<'SQL'
            SELECT
                (SELECT COUNT(*) FROM products WHERE shop_id = ?) AS products,
                (SELECT COUNT(*) FROM orders WHERE shop_id = ?) AS orders
        SQL);
        $counts->execute([$shopId, $shopId]);
        $cnt = $counts->fetch();

        $log = Database::pdo()->query(
            'SELECT resource, status, message, ran_at FROM sync_log ORDER BY id DESC LIMIT 10'
        )->fetchAll();

        out(200, [
            'shop'    => $shopRow,
            'counts'  => $cnt,
            'sync_log'=> $log,
        ]);
    }

    if ($uri === '/api/products' && $method === 'GET') {
        $shopId = shopId($query);
        $page   = max(1, (int)($query['page'] ?? 1));
        $size   = min(100, max(1, (int)($query['page_size'] ?? 20)));
        $offset = ($page - 1) * $size;

        $total = Database::pdo()->prepare('SELECT COUNT(*) AS c FROM products WHERE shop_id = ?');
        $total->execute([$shopId]);
        $totalCount = (int)$total->fetch()['c'];

        $stmt = Database::pdo()->prepare(<<<'SQL'
            SELECT item_id, shop_id, name, sku, price, stock, status, synced_at
            FROM products WHERE shop_id = ?
            ORDER BY synced_at DESC LIMIT ? OFFSET ?
        SQL);
        $stmt->bindValue(1, $shopId, PDO::PARAM_INT);
        $stmt->bindValue(2, $size, PDO::PARAM_INT);
        $stmt->bindValue(3, $offset, PDO::PARAM_INT);
        $stmt->execute();

        out(200, [
            'page'        => $page,
            'page_size'   => $size,
            'total'       => $totalCount,
            'total_pages' => (int)ceil($totalCount / $size),
            'items'       => $stmt->fetchAll(),
        ]);
    }

    if ($uri === '/api/orders' && $method === 'GET') {
        $shopId = shopId($query);
        $page   = max(1, (int)($query['page'] ?? 1));
        $size   = min(100, max(1, (int)($query['page_size'] ?? 20)));
        $offset = ($page - 1) * $size;
        $status = $query['status'] ?? null; // optional filter

        // counts by status (for tabs)
        $counts = Database::pdo()->prepare(<<<'SQL'
            SELECT status, COUNT(*) AS c FROM orders WHERE shop_id = ? GROUP BY status
        SQL);
        $counts->execute([$shopId]);
        $byStatus = [];
        foreach ($counts->fetchAll() as $r) $byStatus[$r['status'] ?? 'UNKNOWN'] = (int)$r['c'];

        $totalSql = 'SELECT COUNT(*) AS c FROM orders WHERE shop_id = ?';
        $listSql  = 'SELECT order_sn, shop_id, status, total_amount, currency, create_time, raw_json, synced_at FROM orders WHERE shop_id = ?';
        $params   = [$shopId];
        if ($status) {
            $totalSql .= ' AND status = ?';
            $listSql  .= ' AND status = ?';
            $params[] = $status;
        }
        $total = Database::pdo()->prepare($totalSql);
        $total->execute($params);
        $totalCount = (int)$total->fetch()['c'];

        $listSql .= ' ORDER BY create_time DESC LIMIT ? OFFSET ?';
        $stmt = Database::pdo()->prepare($listSql);
        $i = 1;
        foreach ($params as $p) {
            $stmt->bindValue($i++, $p, is_int($p) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue($i++, $size, PDO::PARAM_INT);
        $stmt->bindValue($i++, $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        // Decode raw_json into a flat detail map for each row (avoids N+1 fetches)
        $items = [];
        foreach ($rows as $r) {
            $detail = $r['raw_json'] ? json_decode($r['raw_json'], true) : [];
            $firstItem = $detail['item_list'][0] ?? [];
            $firstPkg  = $detail['package_list'][0] ?? [];
            $addr      = $detail['recipient_address'] ?? [];
            $items[] = [
                'order_sn'       => $r['order_sn'],
                'shop_id'        => (int)$r['shop_id'],
                'status'         => $r['status'],
                'total_amount'   => $r['total_amount'] !== null ? (float)$r['total_amount'] : null,
                'currency'       => $r['currency'],
                'create_time'    => $r['create_time'] !== null ? (int)$r['create_time'] : null,
                'synced_at'      => (int)$r['synced_at'],
                'product_name'   => $firstItem['item_name'] ?? null,
                'product_image'  => $firstItem['image_info']['image_url'] ?? null,
                'product_model'  => $firstItem['model_name'] ?? null,
                'item_count'     => count($detail['item_list'] ?? []),
                'buyer_username' => $detail['buyer_username'] ?? null,
                'payment_method' => $detail['payment_method'] ?? null,
                'shipping_carrier'=> $firstPkg['shipping_carrier'] ?? $detail['shipping_carrier'] ?? null,
                'package_number' => $firstPkg['package_number'] ?? null,
                'tracking_number'=> $firstPkg['tracking_number'] ?? null,
                'logistics_status'=> $firstPkg['logistics_status'] ?? null,
                'recipient_name' => $addr['name'] ?? null,
                'recipient_city' => $addr['city'] ?? null,
                'recipient_state'=> $addr['state'] ?? null,
            ];
        }

        out(200, [
            'page'        => $page,
            'page_size'   => $size,
            'total'       => $totalCount,
            'total_pages' => (int)ceil($totalCount / $size),
            'counts'      => $byStatus,
            'items'       => $items,
        ]);
    }

    if ($uri === '/api/sync' && $method === 'POST') {
        $shopId = shopId($query);
        $type   = $_GET['type'] ?? 'all';
        $results = [];
        if ($type === 'all' || $type === 'shop')     $results['shop']     = SyncShop::run($shopId);
        if ($type === 'all' || $type === 'products') $results['products'] = SyncProducts::run($shopId);
        if ($type === 'all' || $type === 'orders')   $results['orders']   = SyncOrders::run($shopId);
        out(200, ['results' => $results]);
    }

    // GET /api/orders/{order_sn}/detail
    if (preg_match('#^/api/orders/([^/]+)/detail$#', $uri, $m) && $method === 'GET') {
        $orderSn = $m[1];
        $stmt = Database::pdo()->prepare('SELECT order_sn, shop_id, status, total_amount, currency, create_time, raw_json, synced_at FROM orders WHERE order_sn = ?');
        $stmt->execute([$orderSn]);
        $row = $stmt->fetch();
        if (!$row) out(404, ['error' => 'order not found', 'order_sn' => $orderSn]);
        $detail = $row['raw_json'] ? json_decode($row['raw_json'], true) : [];
        out(200, [
            'order_sn'  => $row['order_sn'],
            'shop_id'   => (int)$row['shop_id'],
            'summary'   => [
                'status'       => $row['status'],
                'total_amount' => $row['total_amount'] !== null ? (float)$row['total_amount'] : null,
                'currency'     => $row['currency'],
                'create_time'  => $row['create_time'] !== null ? (int)$row['create_time'] : null,
                'synced_at'    => (int)$row['synced_at'],
            ],
            'detail' => $detail,
        ]);
    }

    // POST /api/orders/{order_sn}/label — fetch shipping label PDF for the order
    if (preg_match('#^/api/orders/([^/]+)/label$#', $uri, $m) && $method === 'POST') {
        $orderSn = $m[1];
        $shopId  = shopId($query);
        $result = ShippingLabel::fetch($orderSn, $shopId, new ShopeeClient(), new TokenStore());
        if (!$result['ok']) {
            out(502, $result);
        }
        header('Content-Type: ' . $result['mime']);
        header('Content-Disposition: inline; filename="resi_' . $orderSn . '.pdf"');
        echo $result['bytes'];
        exit;
    }

    out(404, ['error' => 'not found', 'uri' => $uri]);
} catch (\Throwable $e) {
    out(500, ['error' => 'internal', 'message' => $e->getMessage()]);
}