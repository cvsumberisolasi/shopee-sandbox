<?php
declare(strict_types=1);

namespace App\Sync;

use App\Database;
use App\ShopeeClient;
use App\TokenStore;

final class SyncOrders
{
    public static function run(int $shopId, int $pageSize = 50, int $daysBack = 15): array
    {
        $client = new ShopeeClient();
        $token = TokenStore::getFresh($shopId, $client);
        if (!$token) {
            Database::log('orders', 'error', 'no token');
            return ['ok' => false, 'error' => 'no token'];
        }

        $timeFrom = time() - ($daysBack * 86400);
        $timeTo = time();
        $cursor = '';
        $hasMore = true;
        $allSn = [];

        // Stage 1: collect order_sn from get_order_list
        while ($hasMore) {
            $body = [
                'time_range_field' => 'create_time',
                'time_from'         => $timeFrom,
                'time_to'           => $timeTo,
                'page_size'         => $pageSize,
                'cursor'            => $cursor,
            ];
            $resp = $client->call('/api/v2/order/get_order_list', $body, $token['access_token'], $shopId);

            if (!empty($resp['error']) && $resp['error'] !== '') {
                Database::log('orders', 'error', json_encode($resp));
                return ['ok' => false, 'error' => $resp['error']];
            }

            $data = $resp['response'] ?? [];
            foreach (($data['order_list'] ?? []) as $o) {
                if (!empty($o['order_sn'])) $allSn[] = $o['order_sn'];
            }
            $hasMore = (bool)($data['more'] ?? false);
            $cursor  = (string)($data['next_cursor'] ?? '');
            if (!$hasMore || $cursor === '') break;
        }

        if (empty($allSn)) {
            Database::log('orders', 'ok', "0 orders to sync for shop $shopId");
            return ['ok' => true, 'count' => 0];
        }

        // Stage 2: batch fetch details via get_order_detail (max 50 ssn per call)
        // response_optional_fields forces Shopee to include buyer, items, packages, payment info
        // that are otherwise omitted when null/empty.
        $detailsBySn = [];
        $optionalFields = 'buyer_username,item_list,recipient_address,payment_method,shipping_carrier,package_list,total_amount,pay_time,note,order_status';
        foreach (array_chunk($allSn, 50) as $chunk) {
            $body = [
                'order_sn_list' => implode(',', $chunk),
                'response_optional_fields' => $optionalFields,
            ];
            $resp = $client->call('/api/v2/order/get_order_detail', $body, $token['access_token'], $shopId);
            if (!empty($resp['error']) && $resp['error'] !== '') {
                Database::log('orders', 'error', "detail fetch failed: " . json_encode($resp));
                continue;
            }
            foreach (($resp['response']['order_list'] ?? []) as $d) {
                if (!empty($d['order_sn'])) $detailsBySn[$d['order_sn']] = $d;
            }
        }

        // Stage 3: persist
        $stmt = Database::pdo()->prepare(<<<'SQL'
            INSERT INTO orders (order_sn, shop_id, status, total_amount, currency, create_time, raw_json, synced_at)
            VALUES (?,?,?,?,?,?,?,?)
            ON CONFLICT(order_sn) DO UPDATE SET
                status=excluded.status, total_amount=excluded.total_amount,
                currency=excluded.currency, create_time=excluded.create_time,
                raw_json=excluded.raw_json, synced_at=excluded.synced_at
        SQL);
        $totalSynced = 0;
        foreach ($allSn as $sn) {
            $d = $detailsBySn[$sn] ?? ['order_sn' => $sn];
            $stmt->execute([
                $sn,
                $shopId,
                $d['order_status'] ?? null,
                isset($d['total_amount']) ? (float)$d['total_amount'] : null,
                $d['currency'] ?? null,
                isset($d['create_time']) ? (int)$d['create_time'] : null,
                json_encode($d),
                time(),
            ]);
            $totalSynced++;
        }

        Database::log('orders', 'ok', "synced $totalSynced orders for shop $shopId");
        return ['ok' => true, 'count' => $totalSynced];
    }
}