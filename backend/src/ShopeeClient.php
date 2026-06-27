<?php
declare(strict_types=1);

namespace App;

final class ShopeeClient
{
    private string $partnerKey;
    private int $partnerId;
    private string $apiBase;

    public function __construct()
    {
        Env::load(dirname(__DIR__, 2));
        $this->partnerId = Env::int('SHOPEE_PARTNER_ID');
        $this->partnerKey = Env::require('SHOPEE_PARTNER_KEY');
        $this->apiBase = Env::require('SHOPEE_API_BASE');
    }

    public function buildAuthUrl(string $redirectUri, string $state = 'state'): string
    {
        $authUrl = Env::require('SHOPEE_AUTH_URL');
        $qs = http_build_query([
            'partner_id'   => $this->partnerId,
            'auth_type'    => 'seller',
            'redirect_uri' => $redirectUri,
            'response_type'=> 'code',
            'state'        => $state,
        ]);
        return $authUrl . '?' . $qs;
    }

    public function exchangeCode(string $code, int $shopId): array
    {
        return $this->request('/api/v2/auth/token/get', [
            'shop_id' => $shopId,
            'code'    => $code,
        ], post: true);
    }

    public function refresh(string $refreshToken, int $shopId): array
    {
        return $this->request('/api/v2/auth/access_token/get', [
            'partner_id'    => $this->partnerId,
            'shop_id'       => $shopId,
            'refresh_token' => $refreshToken,
        ], post: true);
    }

    public function call(string $apiPath, array $body, string $accessToken, int $shopId): array
    {
        return $this->request($apiPath, $body, $accessToken, $shopId);
    }

    /**
     * Shopee v2 signature convention (from official docs /api/v2/signature):
     *
     *   Public API:
     *     base = partner_id + api_path + timestamp
     *
     *   Shop API (authenticated):
     *     base = partner_id + api_path + timestamp + access_token + shop_id
     *
     *   Merchant API: (not used in this app)
     *     base = partner_id + api_path + timestamp + access_token + merchant_id
     *
     * All common params (partner_id, timestamp, access_token, shop_id, sign)
     * live in the QUERY STRING. Endpoint-specific params also in query for GET;
     * body is empty.
     */
    private function request(string $path, array $params, ?string $accessToken = null, ?int $shopId = null, bool $post = false): array
    {
        $timestamp = time();

        // Signature base:
        //   Public/Auth endpoints: partner_id + api_path + timestamp
        //   Shop endpoints:        partner_id + api_path + timestamp + access_token + shop_id
        // (Body never participates in sign — verified by API Test Tool.)
        if ($accessToken !== null && $shopId !== null) {
            $base = $this->partnerId . $path . $timestamp . $accessToken . $shopId;
        } else {
            $base = $this->partnerId . $path . $timestamp;
        }
        $sign = hash_hmac('sha256', $base, $this->partnerKey);

        // Query string = common params only. Caller-passed params go to body (POST) or query (GET).
        $query = ['partner_id' => $this->partnerId, 'timestamp' => $timestamp, 'sign' => $sign];
        if ($accessToken) $query['access_token'] = $accessToken;
        if ($shopId !== null) $query['shop_id'] = $shopId;

        $url = $this->apiBase . $path . '?' . http_build_query($query);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 30,
        ]);

        if ($post) {
            // POST: caller-passed params → JSON body
            if (!empty($params)) {
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
            }
        } else {
            // GET: caller-passed params → query string (skip common params to avoid dup)
            $common = ['partner_id', 'timestamp', 'sign', 'access_token', 'shop_id'];
            $extra = array_diff_key($params, array_flip($common));
            if (!empty($extra)) {
                $url .= '&' . http_build_query($extra);
                curl_setopt($ch, CURLOPT_URL, $url);
            }
        }
        $resp = curl_exec($ch);
        $err  = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($resp === false) {
            return ['error' => 'curl_error', 'message' => $err, 'http_code' => $code];
        }
        $decoded = json_decode($resp, true);
        if (!is_array($decoded)) {
            return ['error' => 'invalid_json', 'message' => $resp, 'http_code' => $code];
        }
        $decoded['http_code'] = $code;
        return $decoded;
    }

    /**
     * Fetch a binary endpoint (e.g. shipping document PDF).
     * Returns ['bytes' => string, 'mime' => string, 'http_code' => int]
     * or ['error' => string, 'message' => string] on failure.
     */
    public function downloadBinary(string $path, array $params, string $accessToken, int $shopId): array
    {
        $timestamp = time();
        $base = $this->partnerId . $path . $timestamp . $accessToken . $shopId;
        $sign = hash_hmac('sha256', $base, $this->partnerKey);

        $query = [
            'partner_id'   => $this->partnerId,
            'timestamp'    => $timestamp,
            'sign'         => $sign,
            'access_token' => $accessToken,
            'shop_id'      => $shopId,
        ];
        $common = ['partner_id', 'timestamp', 'sign', 'access_token', 'shop_id'];
        $extra  = array_diff_key($params, array_flip($common));
        if (!empty($extra)) {
            $query = array_merge($query, $extra);
        }
        $url = $this->apiBase . $path . '?' . http_build_query($query);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_TIMEOUT        => 60,
        ]);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $hdrSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        if ($raw === false) {
            return ['error' => 'curl_error', 'message' => $err];
        }
        $headers = substr($raw, 0, $hdrSize);
        $body = substr($raw, $hdrSize);

        // Parse Content-Type from response headers
        $mime = 'application/octet-stream';
        if (preg_match('/^content-type:\s*(.+)$/im', $headers, $m)) {
            $mime = trim(explode(';', $m[1])[0]);
        }

        if ($code >= 400) {
            $j = json_decode($body, true);
            return ['error' => 'http_error', 'message' => $j['message'] ?? $j['error'] ?? "HTTP $code", 'http_code' => $code];
        }
        return ['bytes' => $body, 'mime' => $mime, 'http_code' => $code];
    }
}