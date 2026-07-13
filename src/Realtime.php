<?php

namespace Wibiesana\Padi\Core;

/**
 * Realtime Service using FrankenPHP Mercure Hub.
 *
 * Provides lightweight capabilities to publish real-time messages to clients
 * using native PHP Curl and Server-Sent Events (SSE).
 */
class Realtime
{
    /**
     * Publish a message to a topic.
     *
     * @param string $topic The topic URI or identifier (e.g. "https://example.com/books/1" or "room-1")
     * @param array $data The payload to send
     * @param bool $private Whether this is a private channel requiring subscriber authorization
     * @param array $targets List of specific subscriber targets (Mercure authorization targets)
     * @return bool True if successfully sent to the hub, false otherwise
     */
    public static function publish(string $topic, array $data, bool $private = false, array $targets = []): bool
    {
        // 1. Check if realtime is globally enabled in .env
        if (!Env::get('MERCURE_ENABLED', false)) {
            return false;
        }

        $hubUrl = Env::get('MERCURE_HUB_URL');
        $secret = Env::get('MERCURE_PUBLISHER_JWT_KEY', 'padi_mercure_publisher_secret_key_change_me_in_prod');

        if (empty($hubUrl)) {
            Logger::error("Mercure Hub URL is not configured in .env");
            return false;
        }

        // 2. Generate Publisher JWT
        $jwtPayload = [
            'mercure' => [
                'publish' => ['*'] // Allow publishing to any topic
            ]
        ];
        $token = self::generateJwt($secret, $jwtPayload);

        // 3. Prepare POST payload
        $postFields = [
            'topic' => $topic,
            'data'  => json_encode($data)
        ];

        if ($private) {
            $postFields['private'] = 'on';
        }

        // Build query string manually so multiple targets serialize correctly
        // e.g. target=a&target=b instead of overwriting a single key
        $queryParts = [http_build_query($postFields)];
        foreach ($targets as $target) {
            $queryParts[] = 'target=' . urlencode($target);
        }
        $queryString = implode('&', $queryParts);

        // 4. Send HTTP POST to Mercure Hub via cURL
        $ch = curl_init($hubUrl);
        if ($ch === false) {
            Logger::error("Failed to initialize cURL for Mercure publish");
            return false;
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $queryString);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer {$token}",
            "Content-Type: application/x-www-form-urlencoded"
        ]);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1); // Fail fast if host is unreachable
        curl_setopt($ch, CURLOPT_TIMEOUT, 1);        // Max 1 second execution time

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            Logger::error("Mercure publish cURL error: " . $error);
            return false;
        }

        if ($httpCode >= 400) {
            Logger::error("Mercure Hub returned error status {$httpCode}: " . $response);
            return false;
        }

        return true;
    }

    /**
     * Get client-facing Hub URL.
     *
     * @return string
     */
    public static function getHubUrl(): string
    {
        return Env::get('MERCURE_PUBLIC_HUB_URL', '');
    }

    /**
     * Generate Subscriber JWT token for client listening to secure/private topics.
     *
     * @param array $topics Array of topics the subscriber is allowed to read. Default ['*'] (all).
     * @return string
     */
    public static function generateSubscriberJwt(array $topics = ['*']): string
    {
        $secret = Env::get('MERCURE_SUBSCRIBER_JWT_KEY', 'padi_mercure_subscriber_secret_key_change_me_in_prod');
        
        $jwtPayload = [
            'mercure' => [
                'subscribe' => $topics
            ],
            'exp' => time() + (int)Env::get('JWT_EXPIRY', 3600)
        ];

        return self::generateJwt($secret, $jwtPayload);
    }

    /**
     * Generate simple HS256 JWT token using native PHP.
     *
     * @param string $secret
     * @param array $payload
     * @return string
     */
    private static function generateJwt(string $secret, array $payload): string
    {
        $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
        $payloadJson = json_encode($payload);

        $base64UrlHeader = self::base64UrlEncode($header);
        $base64UrlPayload = self::base64UrlEncode($payloadJson);

        $signature = hash_hmac('sha256', $base64UrlHeader . "." . $base64UrlPayload, $secret, true);
        $base64UrlSignature = self::base64UrlEncode($signature);

        return $base64UrlHeader . "." . $base64UrlPayload . "." . $base64UrlSignature;
    }

    /**
     * Helper to base64url encode.
     *
     * @param string $data
     * @return string
     */
    private static function base64UrlEncode(string $data): string
    {
        return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($data));
    }
}
