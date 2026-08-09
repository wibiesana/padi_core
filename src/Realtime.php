<?php

namespace Wibiesana\Padi\Core;

/**
 * Realtime Service using FrankenPHP Mercure Hub.
 *
 * Provides lightweight capabilities to publish real-time messages to clients
 * using native PHP Curl and Server-Sent Events (SSE).
 *
 * Worker-mode safe:
 * - JWT tokens are cached per-process lifecycle (no regeneration per request).
 * - cURL handles use persistent keep-alive connections for lower latency.
 * - curl_close() is always called to prevent memory leaks.
 * - JSON encoding uses JSON_THROW_ON_ERROR for fast, explicit failure.
 */
class Realtime
{
    /**
     * In-memory JWT cache [secret_hash => [token, generated_at]].
     * Avoids regenerating the publisher JWT on every publish() call in worker mode.
     */
    private static array $jwtCache = [];

    /**
     * Publisher JWT lifetime in seconds. Regenerate before it expires.
     * Default: 55 minutes (3300s), keeping buffer before typical 1h expiry.
     */
    private const PUBLISHER_JWT_TTL = 3300;

    /**
     * Publish a message to a topic.
     *
     * @param string $topic   The topic URI or identifier (e.g. "https://example.com/books/1" or "room-1")
     * @param array  $data    The payload to send
     * @param bool   $private Whether this is a private channel requiring subscriber authorization
     * @param array  $targets List of specific subscriber targets (Mercure authorization targets)
     * @return bool True if successfully sent to the hub, false otherwise
     */
    public static function publish(string $topic, array $data, bool $private = false, array $targets = []): bool
    {
        // 1. Check if realtime is globally enabled in .env
        if (Env::get('MERCURE_ENABLED', 'false') !== 'true') {
            return false;
        }

        $hubUrl = Env::get('MERCURE_HUB_URL', '');
        $secret = Env::get('MERCURE_PUBLISHER_JWT_KEY', 'padi_mercure_publisher_secret_key_change_me_in_prod');

        if (empty($hubUrl)) {
            Logger::error("Mercure Hub URL is not configured in .env (MERCURE_HUB_URL)");
            return false;
        }

        // 2. Get cached (or freshly generated) Publisher JWT
        $token = self::getCachedPublisherJwt($secret);

        // 3. Encode payload — throw immediately on encode failure
        try {
            $dataJson = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            Logger::error("Mercure publish: failed to JSON-encode data: " . $e->getMessage());
            return false;
        }

        // 4. Prepare POST query string (multiple targets require repeated keys)
        $postFields = ['topic' => $topic, 'data' => $dataJson];
        if ($private) {
            $postFields['private'] = 'on';
        }

        $queryParts = [http_build_query($postFields)];
        foreach ($targets as $target) {
            $queryParts[] = 'target=' . urlencode((string)$target);
        }
        $queryString = implode('&', $queryParts);

        // 5. Execute HTTP POST via cURL with keep-alive persistent connection
        return self::curlPost($hubUrl, $queryString, $token);
    }

    /**
     * Publish multiple events in a single loop (batch), sharing one JWT and cURL init.
     *
     * @param array $events  Array of events: [['topic'=>'...', 'data'=>[...], 'private'=>false, 'targets'=>[]], ...]
     * @return int Number of successfully published events
     */
    public static function publishBatch(array $events): int
    {
        if (Env::get('MERCURE_ENABLED', 'false') !== 'true') {
            return 0;
        }

        $hubUrl = Env::get('MERCURE_HUB_URL', '');
        $secret = Env::get('MERCURE_PUBLISHER_JWT_KEY', 'padi_mercure_publisher_secret_key_change_me_in_prod');

        if (empty($hubUrl)) {
            Logger::error("Mercure Hub URL is not configured in .env (MERCURE_HUB_URL)");
            return 0;
        }

        $token = self::getCachedPublisherJwt($secret);
        $successCount = 0;

        foreach ($events as $event) {
            $topic   = (string)($event['topic'] ?? '');
            $data    = (array)($event['data']   ?? []);
            $private = (bool)($event['private'] ?? false);
            $targets = (array)($event['targets'] ?? []);

            if (empty($topic)) {
                continue;
            }

            try {
                $dataJson = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                Logger::error("Mercure publishBatch: JSON encode failed for topic '{$topic}': " . $e->getMessage());
                continue;
            }

            $postFields = ['topic' => $topic, 'data' => $dataJson];
            if ($private) {
                $postFields['private'] = 'on';
            }

            $queryParts = [http_build_query($postFields)];
            foreach ($targets as $target) {
                $queryParts[] = 'target=' . urlencode((string)$target);
            }

            if (self::curlPost($hubUrl, implode('&', $queryParts), $token)) {
                $successCount++;
            }
        }

        return $successCount;
    }

    /**
     * Get the client-facing (public) Mercure Hub URL.
     *
     * @return string
     */
    public static function getHubUrl(): string
    {
        return Env::get('MERCURE_PUBLIC_HUB_URL', '');
    }

    /**
     * Generate a Subscriber JWT token for clients listening to secure/private topics.
     *
     * @param array $topics Array of topics the subscriber is allowed to read. Default ['*'] (all).
     * @return string Signed JWT
     */
    public static function generateSubscriberJwt(array $topics = ['*']): string
    {
        $secret = Env::get('MERCURE_SUBSCRIBER_JWT_KEY', 'padi_mercure_subscriber_secret_key_change_me_in_prod');

        $now = time();
        $payload = [
            'mercure' => ['subscribe' => $topics],
            'iat'     => $now,
            'exp'     => $now + (int)Env::get('JWT_EXPIRY', 3600),
        ];

        return self::generateJwt($secret, $payload);
    }

    // ──────────────────────────────────────────────────────────
    //  Private Helpers
    // ──────────────────────────────────────────────────────────

    /**
     * Return a valid publisher JWT, reusing a cached token if still fresh.
     * Avoids the CPU cost of HMAC-SHA256 on every publish() call in worker mode.
     */
    private static function getCachedPublisherJwt(string $secret): string
    {
        $cacheKey = md5($secret);
        $now      = time();

        if (
            isset(self::$jwtCache[$cacheKey]) &&
            ($now - self::$jwtCache[$cacheKey]['generated_at']) < self::PUBLISHER_JWT_TTL
        ) {
            return self::$jwtCache[$cacheKey]['token'];
        }

        // Generate fresh publisher JWT with exp claim for security
        $payload = [
            'mercure' => ['publish' => ['*']],
            'iat'     => $now,
            'exp'     => $now + self::PUBLISHER_JWT_TTL + 300, // 300s buffer
        ];

        $token = self::generateJwt($secret, $payload);

        self::$jwtCache[$cacheKey] = [
            'token'        => $token,
            'generated_at' => $now,
        ];

        return $token;
    }

    /**
     * Execute a single HTTP POST to the Mercure Hub via cURL.
     * Uses keep-alive and fast timeouts; always closes the handle cleanly.
     *
     * @param string $hubUrl      Mercure Hub endpoint URL
     * @param string $queryString URL-encoded POST body
     * @param string $token       Publisher Bearer JWT
     * @return bool
     */
    private static function curlPost(string $hubUrl, string $queryString, string $token): bool
    {
        $ch = curl_init();
        if ($ch === false) {
            Logger::error("Mercure: failed to initialize cURL handle");
            return false;
        }

        curl_setopt_array($ch, [
            CURLOPT_URL            => $hubUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $queryString,
            CURLOPT_HTTPHEADER     => [
                "Authorization: Bearer {$token}",
                "Content-Type: application/x-www-form-urlencoded",
                "Connection: keep-alive",
            ],
            // Worker-mode friendly timeouts: fast fail, non-blocking feel
            CURLOPT_CONNECTTIMEOUT_MS => 500,   // 0.5s connection timeout
            CURLOPT_TIMEOUT_MS        => 1000,  // 1.0s total timeout
            // Security: verify TLS cert in production; skip only for local dev
            CURLOPT_SSL_VERIFYPEER => Env::get('APP_ENV', 'production') !== 'development',
            CURLOPT_SSL_VERIFYHOST => Env::get('APP_ENV', 'production') !== 'development' ? 2 : 0,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);

        curl_close($ch); // Always close — critical in worker mode to prevent handle accumulation

        if ($error !== '') {
            Logger::error("Mercure cURL error: {$error}");
            return false;
        }

        if ($httpCode >= 400) {
            Logger::error("Mercure Hub returned HTTP {$httpCode}: " . ($response ?: '(empty body)'));
            return false;
        }

        return true;
    }

    /**
     * Generate a compact HS256 JWT using native PHP (no external library required).
     *
     * @param string $secret  HMAC signing key
     * @param array  $payload JWT claims
     * @return string Signed JWT string
     */
    private static function generateJwt(string $secret, array $payload): string
    {
        // JSON_THROW_ON_ERROR: fail loudly rather than silently emit null
        try {
            $headerJson  = json_encode(['typ' => 'JWT', 'alg' => 'HS256'], JSON_THROW_ON_ERROR);
            $payloadJson = json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            Logger::error("Mercure JWT encode failed: " . $e->getMessage());
            return '';
        }

        $headerB64  = self::base64UrlEncode($headerJson);
        $payloadB64 = self::base64UrlEncode($payloadJson);

        $signature  = hash_hmac('sha256', "{$headerB64}.{$payloadB64}", $secret, true);

        return "{$headerB64}.{$payloadB64}." . self::base64UrlEncode($signature);
    }

    /**
     * Base64URL encode (RFC 4648 §5) — URL-safe, no padding.
     *
     * @param string $data Raw bytes or string to encode
     * @return string
     */
    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
