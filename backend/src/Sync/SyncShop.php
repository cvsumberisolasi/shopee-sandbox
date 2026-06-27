<?php
declare(strict_types=1);

namespace App\Sync;

use App\Database;
use App\ShopeeClient;
use App\TokenStore;

final class SyncShop
{
    public static function run(int $shopId): array
    {
        $client = new ShopeeClient();
        $token = TokenStore::getFresh($shopId, $client);
        if (!$token) {
            Database::log('shop', 'error', 'no token');
            return ['ok' => false, 'error' => 'no token'];
        }

        $resp = $client->call('/api/v2/shop/get_shop_info', [], $token['access_token'], $shopId);

        // Shopee returns error="" on success (empty string, but !empty() is true for it).
        if (!empty($resp['error']) && $resp['error'] !== '') {
            Database::log('shop', 'error', json_encode($resp));
            return ['ok' => false, 'error' => $resp['error'], 'detail' => $resp];
        }

        $data = $resp['response'] ?? $resp;
        $stmt = Database::pdo()->prepare(<<<'SQL'
            INSERT INTO shops (shop_id, name, region, status, auth_expire_at, raw_json, synced_at)
            VALUES (?,?,?,?,?,?,?)
            ON CONFLICT(shop_id) DO UPDATE SET
                name=excluded.name, region=excluded.region, status=excluded.status,
                auth_expire_at=excluded.auth_expire_at, raw_json=excluded.raw_json,
                synced_at=excluded.synced_at
        SQL);
        $stmt->execute([
            $shopId,
            $data['shop_name'] ?? null,
            $data['region'] ?? null,
            $data['status'] ?? null,
            $data['auth_time'] ?? null,
            json_encode($data),
            time(),
        ]);
        Database::log('shop', 'ok', 'synced shop ' . $shopId);
        return ['ok' => true, 'data' => $data];
    }
}