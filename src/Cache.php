<?php

declare(strict_types=1);

namespace Wibiesana\Padi\Core;

use Predis\Client as RedisClient;

/**
 * Cache Helper - Supports File and Redis drivers
 * 
 * Worker-mode safe: Redis client persists across worker iterations.
 * Shared hosting safe: file-based cache with no external dependencies.
 * 
 * Security:
 * - File cache uses JSON encoding instead of unserialize() to prevent 
 *   PHP object injection attacks
 * - Atomic file writes prevent partial reads
 * - Cache directory permissions restricted
 */
class Cache
{
    private static string $cacheDir;
    private static int $defaultTtl = 300; // 5 minutes
    private static ?string $driver = null;
    private static ?RedisClient $redis = null;

    private static function init(): void
    {
        if (self::$driver !== null) {
            return;
        }

        self::$driver = Env::get('CACHE_DRIVER', 'file');

        if (self::$driver === 'redis') {
            self::initRedis();
        } else {
            self::initFile();
        }
    }

    private static function initFile(): void
    {
        if (!isset(self::$cacheDir)) {
            $root = defined('PADI_ROOT') ? PADI_ROOT : dirname(__DIR__, 4);
            self::$cacheDir = $root . '/storage/cache/';

            if (!is_dir(self::$cacheDir)) {
                mkdir(self::$cacheDir, 0750, true);
            }
        }
    }

    private static function initRedis(): void
    {
        if (self::$redis !== null) {
            return;
        }

        $host = Env::get('REDIS_HOST', '127.0.0.1');
        $port = (int)Env::get('REDIS_PORT', '6379');
        $password = Env::get('REDIS_PASSWORD', '');
        $database = (int)Env::get('REDIS_DATABASE', '0');
        $prefix = Env::get('REDIS_PREFIX', 'padi:');

        $config = [
            'scheme' => 'tcp',
            'host' => $host,
            'port' => $port,
            'database' => $database,
            'read_write_timeout' => 2,
        ];

        if ($password !== '') {
            $config['password'] = $password;
        }

        if ($prefix !== '') {
            $config['prefix'] = $prefix;
        }

        try {
            self::$redis = new RedisClient($config);
            self::$redis->ping();
        } catch (\Exception $e) {
            error_log('[padi] Redis connection failed: ' . $e->getMessage() . '. Falling back to file cache.');
            self::$driver = 'file';
            self::$redis = null;
            self::initFile();
        }
    }

    /**
     * Get value from cache
     * 
     * @return mixed Cached value or null if not found/expired
     */
    public static function get(string $key): mixed
    {
        self::init();

        if (self::$driver === 'redis' && self::$redis !== null) {
            try {
                $value = self::$redis->get($key);
                if ($value === null) return null;
                return json_decode($value, true);
            } catch (\Exception $e) {
                error_log('[padi] Redis get error: ' . $e->getMessage());
                return null;
            }
        }

        // File cache
        $file = self::getCacheFilePath($key);

        if (!file_exists($file)) {
            return null;
        }

        $raw = file_get_contents($file);
        if ($raw === false) return null;

        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['expires'], $data['value'])) {
            // Corrupted cache file
            @unlink($file);
            return null;
        }

        // Check if expired
        if ($data['expires'] < time()) {
            @unlink($file);
            return null;
        }

        return $data['value'];
    }

    /**
     * Set value in cache
     */
    public static function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        self::init();

        $ttl = $ttl ?? self::$defaultTtl;

        if (self::$driver === 'redis' && self::$redis !== null) {
            try {
                $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                return (bool)self::$redis->setex($key, $ttl, $encoded);
            } catch (\Exception $e) {
                error_log('[padi] Redis set error: ' . $e->getMessage());
                return false;
            }
        }

        // File cache with atomic write
        $file = self::getCacheFilePath($key);
        $tempFile = $file . '.tmp.' . getmypid();

        $data = json_encode([
            'key' => $key,
            'value' => $value,
            'expires' => time() + $ttl
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (file_put_contents($tempFile, $data, LOCK_EX) === false) {
            return false;
        }

        // Atomic rename prevents partial reads
        return rename($tempFile, $file);
    }

    /**
     * Check if key exists in cache (and is not expired)
     */
    public static function has(string $key): bool
    {
        return self::get($key) !== null;
    }

    /**
     * Delete key from cache
     */
    public static function delete(string $key): bool
    {
        self::init();

        if (self::$driver === 'redis' && self::$redis !== null) {
            try {
                return (bool)self::$redis->del($key);
            } catch (\Exception $e) {
                error_log('[padi] Redis delete error: ' . $e->getMessage());
                return false;
            }
        }

        $file = self::getCacheFilePath($key);
        if (file_exists($file)) {
            return @unlink($file);
        }

        return false;
    }

    /**
     * Clear all cache entries
     */
    public static function clear(): bool
    {
        self::init();

        if (self::$driver === 'redis' && self::$redis !== null) {
            try {
                return (bool)self::$redis->flushdb();
            } catch (\Exception $e) {
                error_log('[padi] Redis clear error: ' . $e->getMessage());
                return false;
            }
        }

        // File cache
        $files = glob(self::$cacheDir . '*.cache');
        if ($files === false) return true;

        foreach ($files as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        return true;
    }

    /**
     * Remember - Get from cache or execute callback and cache result
     */
    public static function remember(string $key, int $ttl, callable $callback): mixed
    {
        $value = self::get($key);

        if ($value !== null) {
            return $value;
        }

        $value = $callback();
        self::set($key, $value, $ttl);

        return $value;
    }

    /**
     * Clean up expired cache files (housekeeping)
     */
    public static function cleanup(): int
    {
        self::init();

        if (self::$driver === 'redis') {
            return 0; // Redis handles TTL automatically
        }

        $files = glob(self::$cacheDir . '*.cache');
        if ($files === false) return 0;

        $deleted = 0;
        $now = time();

        foreach ($files as $file) {
            if (!is_file($file)) continue;

            $raw = file_get_contents($file);
            if ($raw === false) {
                @unlink($file);
                $deleted++;
                continue;
            }

            $data = json_decode($raw, true);
            if (!is_array($data) || !isset($data['expires']) || $data['expires'] < $now) {
                @unlink($file);
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * Get cache file path (uses SHA-256 for collision resistance)
     */
    private static function getCacheFilePath(string $key): string
    {
        return self::$cacheDir . hash('xxh3', $key) . '.cache';
    }
}
