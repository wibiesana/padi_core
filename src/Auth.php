<?php

declare(strict_types=1);

namespace Wibiesana\Padi\Core;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Exception;

/**
 * Authentication Helper - JWT Token Management
 * 
 * Worker-mode safe: secret & key cached in static after first init.
 *                   Per-request state cleared via reset().
 * Shared hosting safe: multiple Authorization header fallbacks
 *                      (HTTP_AUTHORIZATION, REDIRECT_HTTP_AUTHORIZATION, getallheaders).
 * Performance: per-request decoded token cache avoids double JWT decode.
 */
class Auth
{
    /** @var string|null JWT secret (loaded once, persists across worker requests) */
    private static ?string $secret = null;
    private static ?string $algorithm = null;
    /** @var Key|null Cached Key object (reused across all verify calls) */
    private static ?Key $key = null;

    /** @var object|false|null Per-request decoded token cache (null=not cached, false=invalid, object=valid) */
    private static object|false|null $decodedCache = null;
    /** @var string|null Per-request raw token cache */
    private static ?string $tokenCache = null;
    /** @var bool Whether token was already extracted this request */
    private static bool $tokenExtracted = false;

    /** @var array Weak/default secrets to reject (checked once at init) */
    private const WEAK_SECRETS = [
        'your-secret-key',
        'change-this',
        'secret',
        'your-secret-key-change-this',
        'jwt-secret',
        'supersecret',
        '12345678901234567890123456789012',
    ];

    /**
     * One-time initialization: load config, validate secret, cache Key object.
     * Safe for worker mode — runs once and statics persist.
     */
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
        $secret = $config['jwt_secret'];
        $algorithm = $config['jwt_algorithm'] ?? 'HS256';

        // Validate JWT secret strength
        if (strlen($secret) < 32) {
            throw new Exception(
                "JWT secret must be at least 32 characters long. Current length: " . strlen($secret)
            );
        }

        // Reject common default/weak secrets
        $lowerSecret = strtolower($secret);
        foreach (self::WEAK_SECRETS as $weak) {
            if (str_contains($lowerSecret, $weak)) {
                throw new Exception(
                    "JWT secret appears to be using a default or weak value. Please use a cryptographically secure random string."
                );
            }
        }

        self::$secret = $secret;
        self::$algorithm = $algorithm;
        self::$key = new Key($secret, $algorithm);

        // Set leeway once (JWT library static — no need to set per-call)
        JWT::$leeway = 60;
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
        $payload['nbf'] = $now;

        return JWT::encode($payload, self::$secret, self::$algorithm);
    }

    /**
     * Decode and verify JWT token
     * 
     * Caches result per-request so multiple calls (userId + user) don't re-decode.
     * 
     * @return object|null Decoded payload or null on failure
     */
    public static function verifyToken(string $token): ?object
    {
        self::init();

        // Return cached result if same token was already decoded this request
        if (self::$decodedCache !== null && self::$tokenCache === $token) {
            return self::$decodedCache === false ? null : self::$decodedCache;
        }

        // Quick structural check before expensive decode
        if ($token === '' || substr_count($token, '.') !== 2) {
            self::$tokenCache = $token;
            self::$decodedCache = false;
            return null;
        }

        try {
            $decoded = JWT::decode($token, self::$key);
            self::$tokenCache = $token;
            self::$decodedCache = $decoded;
            return $decoded;
        } catch (Exception $e) {
            if (Env::get('APP_DEBUG') === 'true') {
                error_log("[Auth] JWT verification failed: " . $e->getMessage());
            }
            self::$tokenCache = $token;
            self::$decodedCache = false;
            return null;
        }
    }

    /**
     * Get current authenticated user ID from request bearer token
     * 
     * Delegates to user() — zero-cost thanks to per-request cache.
     * 
     * @param Request|null $request Existing request instance (avoids re-parsing)
     */
    public static function userId(?Request $request = null): ?int
    {
        return self::user($request)?->user_id ?? null;
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
     * Supports multiple header sources for shared hosting compatibility:
     * 1. Request instance bearerToken() (if provided)
     * 2. $_SERVER['HTTP_AUTHORIZATION'] (standard CGI/FastCGI)
     * 3. $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] (Apache mod_rewrite)
     * 4. getallheaders() fallback (Apache mod_php / some shared hosting)
     * 
     * Result is cached per-request to avoid repeated header lookups.
     */
    private static function extractToken(?Request $request = null): ?string
    {
        // Return cached extraction result within same request
        if (self::$tokenExtracted) {
            return self::$tokenCache;
        }

        $token = null;

        // 1. Prefer using existing Request instance (avoids re-reading php://input)
        if ($request !== null) {
            $token = $request->bearerToken();
            if ($token !== null) {
                self::$tokenExtracted = true;
                self::$tokenCache = $token;
                return $token;
            }
        }

        // 2. Try standard server variables
        $header = $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? '';

        // 3. Shared hosting fallback: some Apache configs strip HTTP_AUTHORIZATION
        if ($header === '' && function_exists('getallheaders')) {
            $headers = getallheaders();
            if ($headers !== false) {
                // getallheaders() key casing varies across environments
                $header = $headers['Authorization'] ?? $headers['authorization'] ?? '';
            }
        }

        if ($header !== '' && str_starts_with($header, 'Bearer ')) {
            $token = substr($header, 7);
        }

        self::$tokenExtracted = true;
        self::$tokenCache = $token;
        return $token;
    }

    /**
     * Reset per-request state (called between worker requests)
     * 
     * Prevents decoded token and user data from leaking between requests.
     * Must be called from Application::cleanupRequest() in the worker loop.
     */
    public static function reset(): void
    {
        self::$decodedCache = null;
        self::$tokenCache = null;
        self::$tokenExtracted = false;
    }
}
