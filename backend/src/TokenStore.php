<?php
declare(strict_types=1);

namespace App;

final class TokenStore
{
    public static function save(int $shopId, string $access, string $refresh, int $expireAt): void
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare(<<<'SQL'
            INSERT INTO tokens (shop_id, access_token, refresh_token, expire_at)
            VALUES (?,?,?,?)
            ON CONFLICT(shop_id) DO UPDATE SET
                access_token=excluded.access_token,
                refresh_token=excluded.refresh_token,
                expire_at=excluded.expire_at
        SQL);
        $stmt->execute([$shopId, $access, $refresh, $expireAt]);
    }

    public static function get(int $shopId): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM tokens WHERE shop_id = ?');
        $stmt->execute([$shopId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function isExpired(int $shopId, int $leeway = 60): bool
    {
        $t = self::get($shopId);
        if (!$t) return true;
        return $t['expire_at'] - $leeway <= time();
    }

    /**
     * Return valid token, auto-refreshing if expired (or missing).
     * Returns ['access_token' => ..., 'refresh_token' => ..., 'expire_at' => ...]
     * or null if no token and refresh fails.
     */
    public static function getFresh(int $shopId, ShopeeClient $client, int $leeway = 60): ?array
    {
        $t = self::get($shopId);
        if ($t && $t['expire_at'] - $leeway > time()) {
            return $t;
        }
        if (!$t || empty($t['refresh_token'])) {
            return null;
        }
        // Refresh via Shopee API
        $resp = $client->refresh($t['refresh_token'], $shopId);
        if (!empty($resp['error']) || empty($resp['access_token'])) {
            return null;
        }
        $exp = time() + (int)($resp['expire_in'] ?? 14400);
        self::save($shopId, $resp['access_token'], $resp['refresh_token'], $exp);
        return self::get($shopId);
    }
}