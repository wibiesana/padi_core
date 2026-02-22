<?php

declare(strict_types=1);

namespace Wibiesana\Padi\Core;

use Predis\Client as RedisClient;

/**
 * Cache Helper - Supports File and Redis drivers
 * Driver is configured via CACHE_DRIVER environment variable
 */
class Cache
{
    private static string $cacheDir;
    private static int $defaultTtl = 300; // 5 minutes
    private static ?string $driver = null;
    private static ?RedisClient $redis = null;

    private static function init(): void
    {
        if (self::$driver === null) {
            self::$driver = Env::get('CACHE_DRIVER', 'file');
        }

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
                mkdir(self::$cacheDir, 0755, true);
            }
        }
    }

    private static function initRedis(): void
    {
        if (self::$redis === null) {
            $host = Env::get('REDIS_HOST', '127.0.0.1');
            $port = (int) Env::get('REDIS_PORT', 6379);
            $password = Env::get('REDIS_PASSWORD', '');
            $database = (int) Env::get('REDIS_DATABASE', 0);

            $config = [
                'scheme' => 'tcp',
                'host' => $host,
                'port' => $port,
                'database' => $database,
            ];

            if (!empty($password)) {
                $config['password'] = $password;
            }

            try {
                self::$redis = new RedisClient($config);
                // Test connection
                self::$redis->ping();
            } catch (\Exception $e) {
                // Fallback to file cache if Redis fails
                error_log('Redis connection failed: ' . $e->getMessage() . '. Falling back to file cache.');
                self::$driver = 'file';
                self::initFile();
            }
        }
    }

    /**
     * Get value from cache
     */
    public static function get(string $key): mixed
    {
        self::init();

        if (self::$driver === 'redis' && self::$redis) {
            try {
                $value = self::$redis->get($key);
                return $value !== null ? unserialize($value) : null;
            } catch (\Exception $e) {
                error_log('Redis get error: ' . $e->getMessage());
                return null;
            }
        }

        // File cache
        $file = self::$cacheDir . md5($key) . '.cache';

        if (!file_exists($file)) {
            return null;
        }

        $data = unserialize(file_get_contents($file));

        // Check if expired
        if ($data['expires'] < time()) {
            unlink($file);
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

        if (self::$driver === 'redis' && self::$redis) {
            try {
                $serialized = serialize($value);
                return (bool) self::$redis->setex($key, $ttl, $serialized);
            } catch (\Exception $e) {
                error_log('Redis set error: ' . $e->getMessage());
                return false;
            }
        }

        // File cache
        $file = self::$cacheDir . md5($key) . '.cache';

        $data = [
            'key' => $key,
            'value' => $value,
            'expires' => time() + $ttl
        ];

        return file_put_contents($file, serialize($data)) !== false;
    }

    /**
     * Check if key exists in cache
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

        if (self::$driver === 'redis' && self::$redis) {
            try {
                return (bool) self::$redis->del($key);
            } catch (\Exception $e) {
                error_log('Redis delete error: ' . $e->getMessage());
                return false;
            }
        }

        // File cache
        $file = self::$cacheDir . md5($key) . '.cache';

        if (file_exists($file)) {
            return unlink($file);
        }

        return false;
    }

    /**
     * Clear all cache
     */
    public static function clear(): bool
    {
        self::init();

        if (self::$driver === 'redis' && self::$redis) {
            try {
                return (bool) self::$redis->flushdb();
            } catch (\Exception $e) {
                error_log('Redis clear error: ' . $e->getMessage());
                return false;
            }
        }

        // File cache
        $files = glob(self::$cacheDir . '*.cache');

        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
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
     * Clean up expired cache files
     */
    public static function cleanup(): int
    {
        self::init();

        $files = glob(self::$cacheDir . '*.cache');
        $deleted = 0;

        foreach ($files as $file) {
            if (is_file($file)) {
                $data = unserialize(file_get_contents($file));

                if ($data['expires'] < time()) {
                    unlink($file);
                    $deleted++;
                }
            }
        }

        return $deleted;
    }
}
