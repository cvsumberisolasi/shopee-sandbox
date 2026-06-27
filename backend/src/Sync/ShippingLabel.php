<?php
declare(strict_types=1);

namespace App\Sync;

use App\Database;
use App\ShopeeClient;
use App\TokenStore;

/**
 * Generate a shipping label (Air Waybill / Resi) PDF for an order.
 *
 * Flow (Shopee Open Platform v2):
 *   1) v2.logistics.create_shipping_document  → returns shipping_document_id
 *   2) v2.logistics.get_shipping_document_result  → check status
 *   3) v2.logistics.download_shipping_document  → fetch binary PDF
 *
 * Some logistics channels are async; in that case get_shipping_document_result
 * returns status=PENDING and we retry a few times.
 */
final class ShippingLabel
{
    private const POLL_ATTEMPTS = 5;
    private const POLL_INTERVAL = 2; // seconds

    public static function fetch(string $orderSn, int $shopId, ShopeeClient $client, TokenStore $store): array
    {
        $token = $store->getFresh($shopId, $client);
        if (!$token) {
            return ['ok' => false, 'error' => 'no_valid_token', 'message' => 'Token tidak valid / tidak ada'];
        }

        // 1) create_shipping_document — requires package_number(s) and a shipping_document_type
        $packages = self::getPackages($orderSn);
        if (empty($packages)) {
            return ['ok' => false, 'error' => 'no_packages', 'message' => 'Order belum punya paket'];
        }
        $firstPkg = $packages[0];

        $resp = $client->call('/api/v2/logistics/create_shipping_document', [
            'order_sn'                => $orderSn,
            'package_number_list'     => [$firstPkg['package_number']],
            'shipping_document_type'  => 'NORMAL_AIR_WAYBILL',
        ], $token['access_token'], $shopId);

        if (!self::isOk($resp)) {
            // Special-case: sandbox doesn't expose shipping_document endpoints
            // (returns raw 404 "page not found" instead of JSON error)
            $code = $resp['http_code'] ?? null;
            $msg  = $resp['message'] ?? '';
            if ($code === 404 || str_contains($msg, 'page not found')) {
                return [
                    'ok' => false,
                    'error' => 'endpoint_unavailable',
                    'message' => 'Shipping document API tidak tersedia di Shopee sandbox ini. Coba di production Shopee, atau gunakan Seller Centre.',
                ];
            }
            if ($resp['error'] === 'api_suspended') {
                return [
                    'ok' => false,
                    'error' => 'api_suspended',
                    'message' => 'Shipping document API di-suspend untuk app ini. Aktifkan di Shopee Console → App → API Permissions.',
                ];
            }
            return ['ok' => false, 'error' => $resp['error'] ?? 'create_failed', 'message' => $resp['message'] ?? 'create_shipping_document gagal'];
        }

        $shippingDocumentId = $resp['response']['shipping_document_id'] ?? $resp['shipping_document_id'] ?? null;
        if (!$shippingDocumentId) {
            return ['ok' => false, 'error' => 'no_doc_id', 'message' => 'Shopee tidak mengembalikan shipping_document_id'];
        }

        // 2) poll result
        $status = 'PENDING';
        for ($i = 0; $i < self::POLL_ATTEMPTS; $i++) {
            sleep(self::POLL_INTERVAL);
            $r = $client->call('/api/v2/logistics/get_shipping_document_result', [
                'order_sn_list'       => [$orderSn],
                'shipping_document_id'=> $shippingDocumentId,
            ], $token['access_token'], $shopId);

            if (!self::isOk($r)) {
                return ['ok' => false, 'error' => $r['error'] ?? 'poll_failed', 'message' => $r['message'] ?? 'get_shipping_document_result gagal'];
            }
            $status = $r['response']['status'] ?? $r['status'] ?? 'PENDING';
            if ($status === 'READY' || $status === 'SUCCESS' || $status === 'COMPLETED') break;
        }

        if ($status !== 'READY' && $status !== 'SUCCESS' && $status !== 'COMPLETED') {
            return ['ok' => false, 'error' => 'still_pending', 'status' => $status, 'message' => "Label belum siap (status=$status), coba lagi nanti"];
        }

        // 3) download binary
        $dl = $client->downloadBinary('/api/v2/logistics/download_shipping_document', [
            'shipping_document_id' => $shippingDocumentId,
            'package_number_list'  => [$firstPkg['package_number']],
        ], $token['access_token'], $shopId);

        if (!empty($dl['error'])) {
            return ['ok' => false, 'error' => 'download_failed', 'message' => $dl['error']];
        }
        return ['ok' => true, 'mime' => $dl['mime'], 'bytes' => $dl['bytes']];
    }

    private static function isOk(array $resp): bool
    {
        return ($resp['error'] ?? '__no_error_field__') === '' || ($resp['error'] ?? null) === null;
    }

    private static function getPackages(string $orderSn): array
    {
        $stmt = Database::pdo()->prepare('SELECT raw_json FROM orders WHERE order_sn = ?');
        $stmt->execute([$orderSn]);
        $row = $stmt->fetch();
        if (!$row || !$row['raw_json']) return [];
        $j = json_decode($row['raw_json'], true);
        return $j['package_list'] ?? [];
    }
}