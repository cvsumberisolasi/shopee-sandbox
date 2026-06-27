<?php
declare(strict_types=1);

namespace App;

final class Signature
{
    /**
     * Generate Shopee API v2 signature.
     * base_string = partner_id + api_path + timestamp + sorted_json_body
     */
    public static function sign(
        int $partnerId,
        string $partnerKey,
        string $apiPath,
        array $body
    ): string {
        $timestamp = (string) ($body['timestamp'] ?? time());
        $bodyForSig = $body;
        unset($bodyForSig['sign']);

        ksort($bodyForSig);

        $base = $partnerId . $apiPath . $timestamp;
        if (!empty($bodyForSig)) {
            $base .= json_encode(
                $bodyForSig,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );
        }

        return hash_hmac('sha256', $base, $partnerKey);
    }
}