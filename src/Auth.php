<?php

declare(strict_types=1);

namespace Wibiesana\Padi\Core;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Exception;

/**
 * Authentication Helper - JWT Token Management
 * 
 * Worker-mode safe: secret is cached in static after first init.
 * Security: validates JWT secret strength, checks for weak defaults.
 */
class Auth
{
    private static ?string $secret = null;
    private static ?string $algorithm = null;
    /** @var Key|null Cached key object to avoid re-creation per verification */
    private static ?Key $key = null;

    private static function init(): void
    {
        if (self::$secret !== null) {
            return;
        }

        $root = defined('PADI_ROOT') ? PADI_ROOT : dirname(__DIR__, 4);
        $configPath = $root . '/config/auth.php';

        if (!file_exists($configPath)) {
            throw new Exception('Auth configuration file not found');
        }

        $config = require $configPath;
        self::$secret = $config['jwt_secret'];
        self::$algorithm = $config['jwt_algorithm'] ?? 'HS256';

        // Validate JWT secret strength
        if (strlen(self::$secret) < 32) {
            throw new Exception(
                "JWT secret must be at least 32 characters long. Current length: " . strlen(self::$secret)
            );
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

        $lowerSecret = strtolower(self::$secret);
        foreach ($weakSecrets as $weakSecret) {
            if (str_contains($lowerSecret, $weakSecret)) {
                throw new Exception(
                    "JWT secret appears to be using a default or weak value. Please use a cryptographically secure random string."
                );
            }
        }

        // Pre-create Key object (reused across all verify calls)
        self::$key = new Key(self::$secret, self::$algorithm);
    }

    /**
     * Generate JWT token
     * 
     * @param array $payload Token payload data
     * @param int $expiry Expiry time in seconds (default: 3600 = 1 hour)
     * @return string Encoded JWT token
     */
    public static function generateToken(array $payload, int $expiry = 3600): string
    {
        self::init();

        $now = time();
        $payload['iat'] = $now;
        $payload['exp'] = $now + $expiry;
        $payload['nbf'] = $now; // Not before: prevent use before issuance

        return JWT::encode($payload, self::$secret, self::$algorithm);
    }

    /**
     * Decode and verify JWT token
     * 
     * @return object|null Decoded payload or null on failure
     */
    public static function verifyToken(string $token): ?object
    {
        self::init();

        // Quick sanity check before expensive decode
        if ($token === '' || substr_count($token, '.') !== 2) {
            return null;
        }

        try {
            // Set leeway to 60 seconds to account for clock skew
            JWT::$leeway = 60;
            return JWT::decode($token, self::$key);
        } catch (Exception $e) {
            if (Env::get('APP_DEBUG') === 'true') {
                error_log("[Auth] JWT verification failed: " . $e->getMessage());
            }
            return null;
        }
    }

    /**
     * Get current authenticated user ID from request bearer token
     * 
     * @param Request|null $request Existing request instance (avoids re-parsing)
     */
    public static function userId(?Request $request = null): ?int
    {
        $token = self::extractToken($request);
        if ($token === null) return null;

        $decoded = self::verifyToken($token);
        return $decoded?->user_id ?? null;
    }

    /**
     * Get current authenticated user data from token
     * 
     * @param Request|null $request Existing request instance
     */
    public static function user(?Request $request = null): ?object
    {
        $token = self::extractToken($request);
        if ($token === null) return null;

        return self::verifyToken($token);
    }

    /**
     * Extract bearer token from request
     * 
     * Uses provided Request instance or creates a new one.
     * Avoids creating a new Request() which re-reads php://input.
     */
    private static function extractToken(?Request $request = null): ?string
    {
        // Prefer using existing request to avoid re-reading php://input
        if ($request !== null) {
            return $request->bearerToken();
        }

        // Fallback: read directly from $_SERVER to avoid creating a full Request
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (str_starts_with($header, 'Bearer ')) {
            return substr($header, 7);
        }

        return null;
    }
}
