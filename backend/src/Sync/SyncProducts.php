<?php
declare(strict_types=1);

namespace App\Sync;

use App\Database;
use App\ShopeeClient;
use App\TokenStore;

final class SyncProducts
{
    public static function run(int $shopId, int $pageSize = 50): array
    {
        $client = new ShopeeClient();
        $token = TokenStore::getFresh($shopId, $client);
        if (!$token) {
            Database::log('products', 'error', 'no token');
            return ['ok' => false, 'error' => 'no token'];
        }

        $offset = 0;
        $totalSynced = 0;
        $hasNext = true;

        while ($hasNext) {
            $body = [
                'offset'    => $offset,
                'page_size' => $pageSize,
            ];
            $resp = $client->call('/api/v2/product/get_item_list', $body, $token['access_token'], $shopId);

            if (!empty($resp['error']) && $resp['error'] !== '') {
                // Sandbox often returns error_unknown when shop has no products
                // or when shop has never created any items. Treat as empty list.
                $errCode = $resp['error'] ?? '';
                if ($errCode === 'product.error_unknown') {
                    Database::log('products', 'ok', 'sandbox has no products yet (empty result)');
                    return ['ok' => true, 'count' => 0, 'note' => 'no products in sandbox'];
                }
                Database::log('products', 'error', json_encode($resp));
                return ['ok' => false, 'error' => $resp['error']];
            }

            $data = $resp['response'] ?? [];
            $items = $data['item'] ?? [];
            foreach ($items as $it) {
                $itemId = (int)($it['item_id'] ?? 0);
                if ($itemId <= 0) continue;
                $detail = self::fetchItem($client, $token['access_token'], $shopId, $itemId);
                $stmt = Database::pdo()->prepare(<<<'SQL'
                    INSERT INTO products (item_id, shop_id, name, sku, price, stock, status, raw_json, synced_at)
                    VALUES (?,?,?,?,?,?,?,?,?)
                    ON CONFLICT(item_id) DO UPDATE SET
                        name=excluded.name, sku=excluded.sku, price=excluded.price,
                        stock=excluded.stock, status=excluded.status,
                        raw_json=excluded.raw_json, synced_at=excluded.synced_at
                SQL);
                $stmt->execute([
                    $itemId,
                    $shopId,
                    $detail['name'] ?? $it['item_name'] ?? null,
                    $detail['sku'] ?? null,
                    $detail['price'] ?? null,
                    $detail['stock'] ?? null,
                    $detail['status'] ?? $it['item_status'] ?? null,
                    json_encode($detail ?: $it),
                    time(),
                ]);
                $totalSynced++;
            }
            $hasNext = (bool)($data['has_next_page'] ?? false);
            if ($hasNext) $offset = (int)($data['next_offset'] ?? ($offset + $pageSize));
            if (!$hasNext || count($items) === 0) break;
        }

        Database::log('products', 'ok', "synced $totalSynced products for shop $shopId");
        return ['ok' => true, 'count' => $totalSynced];
    }

    private static function fetchItem(ShopeeClient $client, string $token, int $shopId, int $itemId): array
    {
        $resp = $client->call(
            '/api/v2/product/get_item_base_info',
            ['item_id_list' => [$itemId]],
            $token,
            $shopId
        );
        $list = $resp['response']['item_list'] ?? [];
        return $list[0] ?? [];
    }
}