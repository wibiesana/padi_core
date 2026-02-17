<?php

namespace Core;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Exception;

class Auth
{
    private static ?string $secret = null;
    private static ?string $algorithm = null;

    private static function init(): void
    {
        if (self::$secret === null) {
            $root = defined('PADI_ROOT') ? PADI_ROOT : dirname(__DIR__, 4);
            $config = require $root . '/app/Config/auth.php';
            self::$secret = $config['jwt_secret'];
            self::$algorithm = $config['jwt_algorithm'] ?? 'HS256';

            // Validate JWT secret strength
            if (strlen(self::$secret) < 32) {
                throw new \Exception("JWT secret must be at least 32 characters long. Current length: " . strlen(self::$secret));
            }

            // Check for common default/weak secrets
            $weakSecrets = [
                'your-secret-key',
                'change-this',
                'secret',
                'your-secret-key-change-this',
                'jwt-secret',
                'supersecret',
                '12345678901234567890123456789012'
            ];

            foreach ($weakSecrets as $weakSecret) {
                if (stripos(self::$secret, $weakSecret) !== false) {
                    throw new \Exception("JWT secret appears to be using a default or weak value. Please use a cryptographically secure random string.");
                }
            }
        }
    }

    /**
     * Generate JWT token
     */
    public static function generateToken(array $payload, int $expiry = 3600): string
    {
        self::init();

        $payload['iat'] = time();
        $payload['exp'] = time() + $expiry;

        return JWT::encode($payload, self::$secret, self::$algorithm);
    }

    /**
     * Decode and verify JWT token
     */
    public static function verifyToken(string $token): ?object
    {
        self::init();

        try {
            // Set leeway to 60 seconds to account for clock skew
            JWT::$leeway = 60;
            return JWT::decode($token, new Key(self::$secret, self::$algorithm));
        } catch (Exception $e) {
            // Log JWT verification failures if in debug mode
            if (Env::get('APP_DEBUG') === 'true') {
                error_log("[Auth] JWT Verification failed: " . $e->getMessage() . " - Token: " . substr($token, 0, 10) . "...");
            }
            return null;
        }
    }

    /**
     * Get current authenticated user ID from token
     */
    public static function userId(): ?int
    {
        $token = (new Request())->bearerToken();
        if (!$token) return null;

        $decoded = self::verifyToken($token);
        return $decoded ? ($decoded->user_id ?? null) : null;
    }

    /**
     * Get current authenticated user data from token
     */
    public static function user(): ?object
    {
        $token = (new Request())->bearerToken();
        if (!$token) return null;

        return self::verifyToken($token);
    }
}
