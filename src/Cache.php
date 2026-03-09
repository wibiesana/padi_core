<?php

declare(strict_types=1);

namespace Wibiesana\Padi\Core;

use Predis\Client as RedisClient;

/**
 * Cache Helper - Supports File and Redis drivers with in-memory L1 layer
 * 
 * Architecture:
 * - L1: In-memory array (zero-cost, survives across worker requests)
 * - L2: Redis (network) or File (disk)
 * 
 * Worker-mode safe:
 * - Static state intentionally persists across worker iterations for performance
 * - Redis connection is health-checked via ping on reconnect
 * - In-memory L1 cache avoids repeated L2 lookups within the same worker
 * - Memory bounded: L1 evicts oldest entries when exceeding configurable limit
 * 
 * Shared hosting safe:
 * - File-based cache with no external dependencies
 * - Subdirectory bucketing (2-char prefix) prevents filesystem slowdown
 * - Atomic writes prevent partial reads under concurrent access
 * - JSON encoding (no unserialize) prevents PHP object injection attacks
 * 
 * Security:
 * - File cache uses JSON encoding instead of unserialize()
 * - Atomic file writes (write-to-temp + rename) prevent partial reads
 * - Cache directory permissions restricted to 0750
 * - Key names are hashed to prevent directory traversal
 */
class Cache
{
    private static string $cacheDir;
    private static int $defaultTtl = 300; // 5 minutes
    private static ?string $driver = null;
    private static ?RedisClient $redis = null;

    /** @var array<string, array{value: mixed, expires: int}> In-memory L1 cache */
    private static array $memory = [];

    /** Maximum L1 entries before eviction (prevents unbounded memory growth in worker mode) */
    private static int $maxMemory = 1000;

    // ─── Initialization ─────────────────────────────────────────────────

    private static function init(): void
    {
        if (self::$driver !== null) {
            return;
        }

        self::$driver = Env::get('CACHE_DRIVER', 'file');
        self::$maxMemory = (int) Env::get('CACHE_L1_MAX', '1000');

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
        $port = (int) Env::get('REDIS_PORT', '6379');
        $password = Env::get('REDIS_PASSWORD', '');
        $database = (int) Env::get('REDIS_DATABASE', '0');
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
     * Reconnect Redis if the connection was lost (worker mode resilience)
     * 
     * In long-running worker processes, the Redis server may restart or
     * the TCP connection may be killed by a firewall/timeout. This method
     * detects and recovers from that situation transparently.
     */
    private static function ensureRedisConnection(): bool
    {
        if (self::$redis === null) {
            return false;
        }

        try {
            self::$redis->ping();
            return true;
        } catch (\Exception) {
            // Connection lost — attempt reconnect
            try {
                self::$redis->disconnect();
                self::$redis->connect();
                self::$redis->ping();
                return true;
            } catch (\Exception $e) {
                error_log('[padi] Redis reconnect failed: ' . $e->getMessage() . '. Falling back to file cache.');
                self::$driver = 'file';
                self::$redis = null;
                self::initFile();
                return false;
            }
        }
    }

    // ─── Public API ─────────────────────────────────────────────────────

    /**
     * Get value from cache
     * 
     * Lookup order: L1 memory → L2 (Redis or File)
     * On L2 hit, the value is promoted to L1 for subsequent lookups.
     * 
     * @return mixed Cached value or $default if not found/expired
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        self::init();

        // L1: in-memory check (fastest path)
        if (isset(self::$memory[$key])) {
            $entry = self::$memory[$key];
            if ($entry['expires'] === 0 || $entry['expires'] >= time()) {
                return $entry['value'];
            }
            // Expired in L1
            unset(self::$memory[$key]);
        }

        // L2: Redis
        if (self::$driver === 'redis' && self::ensureRedisConnection()) {
            try {
                $raw = self::$redis->get($key);
                if ($raw === null) {
                    return $default;
                }
                $value = json_decode($raw, true);
                self::setMemory($key, $value, 0); // TTL managed by Redis
                return $value;
            } catch (\Exception $e) {
                error_log('[padi] Redis get error: ' . $e->getMessage());
                return $default;
            }
        }

        // L2: File cache
        $file = self::getCacheFilePath($key);

        if (!file_exists($file)) {
            return $default;
        }

        $raw = file_get_contents($file);
        if ($raw === false) {
            return $default;
        }

        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['expires'], $data['value'])) {
            @unlink($file);
            return $default;
        }

        if ($data['expires'] > 0 && $data['expires'] < time()) {
            @unlink($file);
            return $default;
        }

        // Promote to L1
        self::setMemory($key, $data['value'], $data['expires']);

        return $data['value'];
    }

    /**
     * Set value in cache
     * 
     * Writes to both L1 (memory) and L2 (Redis/File) simultaneously.
     * 
     * @param int|null $ttl Time-to-live in seconds. null = default (300s). 0 = forever.
     */
    public static function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        self::init();

        $ttl = $ttl ?? self::$defaultTtl;
        $expires = $ttl > 0 ? time() + $ttl : 0;

        // L1: always cache in memory
        self::setMemory($key, $value, $expires);

        // L2: Redis
        if (self::$driver === 'redis' && self::ensureRedisConnection()) {
            try {
                $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if ($ttl > 0) {
                    return (bool) self::$redis->setex($key, $ttl, $encoded);
                }
                return (bool) self::$redis->set($key, $encoded);
            } catch (\Exception $e) {
                error_log('[padi] Redis set error: ' . $e->getMessage());
                return false;
            }
        }

        // L2: File cache with atomic write
        $file = self::getCacheFilePath($key);
        $dir = dirname($file);

        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }

        $tempFile = $file . '.tmp.' . getmypid();

        $data = json_encode([
            'key' => $key,
            'value' => $value,
            'expires' => $expires,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (file_put_contents($tempFile, $data, LOCK_EX) === false) {
            return false;
        }

        return rename($tempFile, $file);
    }

    /**
     * Check if key exists in cache (and is not expired)
     * 
     * Uses L1 memory first, then checks L2 existence without full deserialization
     * for file cache (uses filemtime as fast expiry heuristic).
     */
    public static function has(string $key): bool
    {
        self::init();

        // L1 check
        if (isset(self::$memory[$key])) {
            $entry = self::$memory[$key];
            if ($entry['expires'] === 0 || $entry['expires'] >= time()) {
                return true;
            }
            unset(self::$memory[$key]);
        }

        // L2: Redis
        if (self::$driver === 'redis' && self::ensureRedisConnection()) {
            try {
                return (bool) self::$redis->exists($key);
            } catch (\Exception $e) {
                error_log('[padi] Redis has error: ' . $e->getMessage());
                return false;
            }
        }

        // L2: File — use get() to check expiry properly
        return self::get($key) !== null;
    }

    /**
     * Delete key from cache
     */
    public static function delete(string $key): bool
    {
        self::init();

        // Remove from L1
        unset(self::$memory[$key]);

        // L2: Redis
        if (self::$driver === 'redis' && self::ensureRedisConnection()) {
            try {
                return (bool) self::$redis->del($key);
            } catch (\Exception $e) {
                error_log('[padi] Redis delete error: ' . $e->getMessage());
                return false;
            }
        }

        // L2: File
        $file = self::getCacheFilePath($key);
        if (file_exists($file)) {
            return @unlink($file);
        }

        return true;
    }

    /**
     * Delete multiple keys from cache at once
     */
    public static function deleteMany(array $keys): int
    {
        self::init();

        $deleted = 0;

        // Remove from L1
        foreach ($keys as $key) {
            unset(self::$memory[$key]);
        }

        // L2: Redis — single DEL command for all keys (bulk operation)
        if (self::$driver === 'redis' && self::ensureRedisConnection()) {
            try {
                return (int) self::$redis->del($keys);
            } catch (\Exception $e) {
                error_log('[padi] Redis deleteMany error: ' . $e->getMessage());
                return 0;
            }
        }

        // L2: File
        foreach ($keys as $key) {
            $file = self::getCacheFilePath($key);
            if (file_exists($file) && @unlink($file)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * Clear all cache entries
     */
    public static function clear(): bool
    {
        self::init();

        // Clear L1
        self::$memory = [];

        // L2: Redis
        if (self::$driver === 'redis' && self::ensureRedisConnection()) {
            try {
                return (bool) self::$redis->flushdb();
            } catch (\Exception $e) {
                error_log('[padi] Redis clear error: ' . $e->getMessage());
                return false;
            }
        }

        // L2: File cache — iterate subdirectory buckets
        self::clearDirectory(self::$cacheDir);

        return true;
    }

    /**
     * Remember - Get from cache or execute callback and cache result
     * 
     * Note: If the callback returns null, the null value IS cached.
     * Use delete() to invalidate if needed. This prevents cache stampede
     * on callbacks that legitimately return null.
     */
    public static function remember(string $key, int $ttl, callable $callback): mixed
    {
        // Use a sentinel to distinguish "not in cache" from "cached null"
        $sentinel = "\x00__CACHE_MISS__\x00";
        $value = self::get($key, $sentinel);

        if ($value !== $sentinel) {
            return $value;
        }

        $value = $callback();
        self::set($key, $value, $ttl);

        return $value;
    }

    /**
     * Increment a numeric value in cache
     * 
     * @param int $step Amount to increment by
     * @return int|false New value, or false on failure
     */
    public static function increment(string $key, int $step = 1): int|false
    {
        self::init();

        // Redis native INCRBY is atomic
        if (self::$driver === 'redis' && self::ensureRedisConnection()) {
            try {
                return (int) self::$redis->incrby($key, $step);
            } catch (\Exception $e) {
                error_log('[padi] Redis increment error: ' . $e->getMessage());
                return false;
            }
        }

        // File: read-modify-write (not perfectly atomic, but acceptable for file cache)
        $current = self::get($key, 0);
        if (!is_numeric($current)) {
            return false;
        }
        $new = (int) $current + $step;
        self::set($key, $new);
        // Update L1 immediately
        if (isset(self::$memory[$key])) {
            self::$memory[$key]['value'] = $new;
        }
        return $new;
    }

    /**
     * Decrement a numeric value in cache
     * 
     * @param int $step Amount to decrement by
     * @return int|false New value, or false on failure
     */
    public static function decrement(string $key, int $step = 1): int|false
    {
        return self::increment($key, -$step);
    }

    /**
     * Clean up expired cache files (housekeeping)
     * 
     * Uses filemtime() as a fast pre-filter: files modified more recently than
     * the default TTL are skipped without reading, reducing I/O significantly
     * on large cache directories.
     */
    public static function cleanup(): int
    {
        self::init();

        if (self::$driver === 'redis') {
            return 0; // Redis handles TTL natively
        }

        return self::cleanupDirectory(self::$cacheDir);
    }

    // ─── Worker Mode Integration ────────────────────────────────────────

    /**
     * Reset per-request state (call from Application::cleanupRequest)
     * 
     * Note: L1 memory cache and Redis/file connections intentionally persist
     * across worker requests for performance. Only call clearMemory() if you
     * need to force-refresh cached data for the next request.
     */
    public static function reset(): void
    {
        // Intentionally empty — Cache state is designed to persist across
        // worker iterations. L1 entries have TTL checks on every get().
        // This method exists for API consistency with other reset() methods.
    }

    /**
     * Clear only the L1 in-memory cache
     * 
     * Useful when you know underlying data has changed and you want to force 
     * re-reading from L2 on next access without invalidating L2 itself.
     */
    public static function clearMemory(): void
    {
        self::$memory = [];
    }

    /**
     * Get current L1 cache size (for monitoring/debugging)
     */
    public static function getMemorySize(): int
    {
        return count(self::$memory);
    }

    // ─── Private Helpers ────────────────────────────────────────────────

    /**
     * Store an entry in the L1 in-memory cache with bounded eviction
     * 
     * When the memory cache exceeds the configured limit, the oldest 25% of
     * entries are evicted in bulk. Bulk eviction is more efficient than
     * per-insert eviction and amortizes the overhead.
     */
    private static function setMemory(string $key, mixed $value, int $expires): void
    {
        // Evict oldest 25% when limit exceeded (amortized O(1) per insert)
        if (count(self::$memory) >= self::$maxMemory && !isset(self::$memory[$key])) {
            $evictCount = (int) (self::$maxMemory * 0.25);
            self::$memory = array_slice(self::$memory, $evictCount, null, true);
        }

        self::$memory[$key] = [
            'value' => $value,
            'expires' => $expires,
        ];
    }

    /**
     * Get cache file path with subdirectory bucketing
     * 
     * Uses the first 2 characters of the hash as a subdirectory to distribute
     * files across 256 buckets, preventing filesystem performance degradation
     * when thousands of cache files exist (ext4/NTFS both suffer with 10k+ 
     * files in a single directory).
     */
    private static function getCacheFilePath(string $key): string
    {
        $hash = hash('xxh3', $key);
        $bucket = substr($hash, 0, 2);
        return self::$cacheDir . $bucket . '/' . $hash . '.cache';
    }

    /**
     * Recursively clear all .cache files in a directory
     */
    private static function clearDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . $item;

            if (is_dir($path)) {
                self::clearDirectory($path . '/');
                // Remove empty bucket directories
                @rmdir($path);
            } elseif (str_ends_with($item, '.cache')) {
                @unlink($path);
            }
        }
    }

    /**
     * Recursively clean expired files in a directory, with fast filemtime pre-filter
     */
    private static function cleanupDirectory(string $dir): int
    {
        if (!is_dir($dir)) {
            return 0;
        }

        $items = scandir($dir);
        if ($items === false) {
            return 0;
        }

        $deleted = 0;
        $now = time();

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . $item;

            if (is_dir($path)) {
                $deleted += self::cleanupDirectory($path . '/');
                continue;
            }

            if (!str_ends_with($item, '.cache')) {
                continue;
            }

            // Fast pre-filter: if file was modified very recently,
            // it's likely not expired yet — skip the expensive read
            $mtime = @filemtime($path);
            if ($mtime !== false && ($now - $mtime) < self::$defaultTtl) {
                continue;
            }

            // Read and check actual expiry
            $raw = @file_get_contents($path);
            if ($raw === false) {
                @unlink($path);
                $deleted++;
                continue;
            }

            $data = json_decode($raw, true);
            if (!is_array($data) || !isset($data['expires'])) {
                @unlink($path);
                $deleted++;
                continue;
            }

            // expires=0 means forever — don't delete
            if ($data['expires'] > 0 && $data['expires'] < $now) {
                @unlink($path);
                $deleted++;
            }
        }

        return $deleted;
    }
}
