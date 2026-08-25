<?php

declare(strict_types=1);

namespace Wibiesana\Padi\Core;

/**
 * Cache — L1 (memory) + L2 (Redis | File) with bounded eviction
 *
 * Worker-mode safe:
 * - Static state persists across worker iterations for performance
 * - Redis connection is health-checked and auto-reconnected
 * - L1 avoids repeated L2 lookups; TTL-checked on every read
 * - Memory bounded: oldest 25% evicted when limit exceeded
 *
 * Shared hosting safe:
 * - File driver has zero external dependencies
 * - Subdirectory bucketing (256 buckets) prevents FS slowdown
 * - Atomic writes (tmp + rename) prevent partial reads
 * - JSON-only encoding prevents PHP object injection
 * - Cache directory restricted to 0750
 * - Key names hashed to prevent directory traversal
 *
 * Redis drivers (optional, install one if using CACHE_DRIVER=redis):
 * - predis/predis (pure PHP, no extension needed)
 * - ext-redis (C extension, faster)
 */
class Cache
{
    private const int JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    private static string $cacheDir;
    private static int $defaultTtl = 300;
    private static ?string $driver = null;
    /** @var \Predis\Client|\Redis|null */
    private static object|null $redis = null;

    /** @var array<string, array{value: mixed, expires: int}> */
    private static array $memory = [];
    private static int $maxMemory = 1000;

    /** @var object Reusable sentinel for cache-miss detection (avoids new stdClass per call) */
    private static object $miss;

    /** Get the reusable miss sentinel (lazy-initialized once) */
    private static function miss(): object
    {
        return self::$miss ??= new \stdClass();
    }

    /**
     * Maximum PHP memory usage (bytes) before L1 cache is force-cleared.
     * Configurable via CACHE_L1_MAX_MEMORY_MB env (default: 64 MB).
     * Set to 0 to disable memory-usage-based eviction.
     */
    private static int $maxMemoryBytes = 67108864; // 64 MB default

    // ─── Initialization ─────────────────────────────────────────────────

    private static function init(): void
    {
        if (self::$driver !== null) {
            return;
        }

        self::$driver    = Env::get('CACHE_DRIVER', 'file');
        self::$maxMemory = (int) Env::get('CACHE_L1_MAX', '1000');

        // Load memory guard limit (convert MB -> bytes; 0 = disabled)
        $maxMb = (int) Env::get('CACHE_L1_MAX_MEMORY_MB', '64');
        self::$maxMemoryBytes = $maxMb > 0 ? $maxMb * 1048576 : 0;

        match (self::$driver) {
            'redis' => self::initRedis(),
            default => self::initFile(),
        };
    }

    private static function initFile(): void
    {
        if (isset(self::$cacheDir)) {
            return;
        }

        $root = defined('PADI_ROOT') ? PADI_ROOT : dirname(__DIR__, 4);
        self::$cacheDir = $root . '/storage/cache/';

        if (!is_dir(self::$cacheDir)) {
            mkdir(self::$cacheDir, 0750, true);
        }
    }

    private static function initRedis(): void
    {
        if (self::$redis !== null) {
            return;
        }

        $host     = Env::get('REDIS_HOST', '127.0.0.1');
        $port     = (int) Env::get('REDIS_PORT', '6379');
        $username = Env::get('REDIS_USERNAME', '');
        $password = Env::get('REDIS_PASSWORD', '');
        $database = (int) Env::get('REDIS_DATABASE', '0');
        $prefix   = Env::get('REDIS_PREFIX', 'padi:');

        // Try ext-redis first (faster, C extension), then Predis (pure PHP)
        if (extension_loaded('redis')) {
            self::initExtRedis($host, $port, $username, $password, $database, $prefix);
        } elseif (class_exists('\Predis\Client')) {
            self::initPredis($host, $port, $username, $password, $database, $prefix);
        } else {
            error_log('[padi] Redis driver requested but neither ext-redis nor predis/predis is installed. Falling back to file cache.');
            self::$driver = 'file';
            self::initFile();
        }
    }

    private static function initExtRedis(string $host, int $port, string $username, string $password, int $database, string $prefix): void
    {
        if (!self::tryRedis(function () use ($host, $port, $username, $password, $database, $prefix) {
            $redis = new \Redis();
            $redis->connect($host, $port, 2.0); // 2s timeout

            if ($username !== '' && $password !== '') {
                $redis->auth([$username, $password]);
            } elseif ($password !== '') {
                $redis->auth($password);
            }

            if ($database > 0) {
                $redis->select($database);
            }

            if ($prefix !== '') {
                $redis->setOption(\Redis::OPT_PREFIX, $prefix);
            }

            self::$redis = $redis;
        }, 'connection')) {
            return;
        }

        if (self::$redis !== null) {
            self::tryRedis(fn () => self::$redis->ping(), 'ping');
        }
    }

    private static function initPredis(string $host, int $port, string $username, string $password, int $database, string $prefix): void
    {
        $config = [
            'scheme'             => 'tcp',
            'host'               => $host,
            'port'               => $port,
            'database'           => $database,
            'read_write_timeout' => 2,
        ];

        if ($username !== '' && $password !== '') {
            $config['username'] = $username;
            $config['password'] = $password;
        } elseif ($password !== '') {
            $config['password'] = $password;
        }

        if ($prefix !== '') {
            $config['prefix'] = $prefix;
        }

        if (!self::tryRedis(fn () => self::$redis = new \Predis\Client($config), 'connection')) {
            return;
        }

        if (self::$redis !== null) {
            self::tryRedis(fn () => self::$redis->ping(), 'ping');
        }
    }

    // ─── Redis Helpers ──────────────────────────────────────────────────

    /**
     * Execute a Redis operation with automatic reconnect and file-fallback.
     *
     * Centralizes every try/catch + error_log + fallback pattern.
     * Returns the callback result on success, or $fallback on failure.
     */
    private static function redisOp(callable $fn, string $op, mixed $fallback = null): mixed
    {
        if (!self::ensureRedis()) {
            return $fallback;
        }

        try {
            return $fn();
        } catch (\Exception $e) {
            error_log("[padi] Redis {$op} error: " . $e->getMessage());
            return $fallback;
        }
    }

    /**
     * Ensure Redis connection is alive; reconnect once on failure.
     * Falls back to file driver if reconnect also fails.
     */
    private static function ensureRedis(): bool
    {
        if (self::$redis === null) {
            return false;
        }

        try {
            self::$redis->ping();
            return true;
        } catch (\Exception) {
            return self::tryRedis(function () {
                if (self::$redis instanceof \Redis) {
                    self::$redis->close();
                    self::$redis->connect(
                        Env::get('REDIS_HOST', '127.0.0.1'),
                        (int) Env::get('REDIS_PORT', '6379'),
                        2.0,
                    );
                } else {
                    self::$redis->disconnect();
                    self::$redis->connect();
                }
                self::$redis->ping();
            }, 'reconnect');
        }
    }

    /**
     * Try a Redis bootstrap operation; on failure fall back to file driver.
     */
    private static function tryRedis(callable $fn, string $op): bool
    {
        try {
            $fn();
            return true;
        } catch (\Exception $e) {
            error_log("[padi] Redis {$op} failed: " . $e->getMessage() . '. Falling back to file cache.');
            self::$driver = 'file';
            self::$redis = null;
            self::initFile();
            return false;
        }
    }

    // ─── L1 Memory Layer ────────────────────────────────────────────────

    /**
     * Read from L1. Returns sentinel on miss so callers can distinguish cached-null from miss.
     */
    private static function getFromMemory(string $key, mixed $miss): mixed
    {
        if (!isset(self::$memory[$key])) {
            return $miss;
        }

        $entry = self::$memory[$key];

        if ($entry['expires'] === 0 || $entry['expires'] >= time()) {
            return $entry['value'];
        }

        unset(self::$memory[$key]);
        return $miss;
    }

    /**
     * Write to L1 with bounded eviction (oldest 25% bulk-evicted).
     * 
     * Two eviction triggers:
     * 1. Entry count reaches $maxMemory → evict oldest 25%.
     * 2. PHP memory_get_usage() exceeds $maxMemoryBytes → flush all L1.
     *    This prevents large cached values from exhausting process memory
     *    in FrankenPHP worker mode where the process is long-lived.
     */
    private static function setMemory(string $key, mixed $value, int $expires): void
    {
        // Memory-usage-based guard (worker mode safety net)
        if (self::$maxMemoryBytes > 0 && memory_get_usage() > self::$maxMemoryBytes) {
            self::$memory = [];
        }

        if (count(self::$memory) >= self::$maxMemory && !isset(self::$memory[$key])) {
            $evict = (int) (self::$maxMemory * 0.25);
            self::$memory = array_slice(self::$memory, $evict, null, true);
        }

        self::$memory[$key] = ['value' => $value, 'expires' => $expires];
    }

    // ─── File Layer Helpers ─────────────────────────────────────────────

    /**
     * Hash-bucketed file path (256 subdirectories).
     */
    private static function filePath(string $key): string
    {
        $hash = hash('xxh3', $key);
        return self::$cacheDir . substr($hash, 0, 2) . '/' . $hash . '.cache';
    }

    /**
     * Atomic write: tmp file → rename (prevents partial reads under concurrency).
     */
    private static function fileWrite(string $key, mixed $value, int $expires): bool
    {
        $file = self::filePath($key);
        $dir  = dirname($file);

        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }

        $tmp = $file . '.tmp.' . getmypid();
        $payload = json_encode(
            ['key' => $key, 'value' => $value, 'expires' => $expires],
            self::JSON_FLAGS,
        );

        if (file_put_contents($tmp, $payload, LOCK_EX) === false) {
            return false;
        }

        return rename($tmp, $file);
    }

    /**
     * Read + validate + expiry-check a cache file. Returns value or $miss sentinel.
     */
    private static function fileRead(string $key, mixed $miss): mixed
    {
        $file = self::filePath($key);

        if (!file_exists($file)) {
            return $miss;
        }

        $raw = file_get_contents($file);
        if ($raw === false) {
            return $miss;
        }

        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['expires'], $data['value'])) {
            @unlink($file);
            return $miss;
        }

        if ($data['expires'] > 0 && $data['expires'] < time()) {
            @unlink($file);
            return $miss;
        }

        // Promote to L1
        self::setMemory($key, $data['value'], $data['expires']);
        return $data['value'];
    }

    /**
     * Walk all .cache files recursively, calling $visitor(string $path) on each.
     * Removes empty bucket directories after visiting.
     */
    private static function walkCacheFiles(string $dir, ?callable $visitor = null): void
    {
        $items = @scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . $item;

            if (is_dir($path)) {
                self::walkCacheFiles($path . '/', $visitor);
                @rmdir($path); // remove if empty
                continue;
            }

            if (str_ends_with($item, '.cache') && $visitor !== null) {
                $visitor($path);
            }
        }
    }

    // ─── Public API ─────────────────────────────────────────────────────

    /**
     * Get value from cache. Lookup: L1 → L2 (Redis|File).
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        self::init();

        $miss = self::miss();

        // L1
        $value = self::getFromMemory($key, $miss);
        if ($value !== $miss) {
            return $value;
        }

        // L2: Redis
        if (self::$driver === 'redis') {
            $result = self::redisOp(function () use ($key, $miss) {
                $raw = self::$redis->get($key);
                if ($raw === null) {
                    return $miss;
                }
                $value = json_decode($raw, true);
                self::setMemory($key, $value, 0); // TTL managed by Redis
                return $value;
            }, 'get', $miss);

            return $result === $miss ? $default : $result;
        }

        // L2: File
        $value = self::fileRead($key, $miss);
        return $value === $miss ? $default : $value;
    }

    /**
     * Set value in cache. Writes to L1 + L2 simultaneously.
     *
     * @param int|null $ttl Seconds. null = default (300s). 0 = forever.
     */
    public static function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        self::init();

        $ttl     = $ttl ?? self::$defaultTtl;
        $expires = $ttl > 0 ? time() + $ttl : 0;

        self::setMemory($key, $value, $expires);

        // L2: Redis
        if (self::$driver === 'redis') {
            return (bool) self::redisOp(function () use ($key, $value, $ttl) {
                $encoded = json_encode($value, self::JSON_FLAGS);
                return $ttl > 0
                    ? self::$redis->setex($key, $ttl, $encoded)
                    : self::$redis->set($key, $encoded);
            }, 'set', false);
        }

        // L2: File
        return self::fileWrite($key, $value, $expires);
    }

    /**
     * Check if key exists and is not expired.
     */
    public static function has(string $key): bool
    {
        self::init();

        $miss = self::miss();
        if (self::getFromMemory($key, $miss) !== $miss) {
            return true;
        }

        if (self::$driver === 'redis') {
            return (bool) self::redisOp(
                fn () => self::$redis->exists($key),
                'has',
                false,
            );
        }

        return self::fileRead($key, $miss) !== $miss;
    }

    /**
     * Delete key from cache (L1 + L2).
     */
    public static function delete(string $key): bool
    {
        self::init();

        unset(self::$memory[$key]);

        if (self::$driver === 'redis') {
            return (bool) self::redisOp(fn () => self::$redis->del($key), 'delete', false);
        }

        $file = self::filePath($key);
        return !file_exists($file) || @unlink($file);
    }

    /**
     * Delete multiple keys at once (single Redis DEL command).
     */
    public static function deleteMany(array $keys): int
    {
        self::init();

        foreach ($keys as $key) {
            unset(self::$memory[$key]);
        }

        if (self::$driver === 'redis') {
            return (int) self::redisOp(fn () => self::$redis->del($keys), 'deleteMany', 0);
        }

        $deleted = 0;
        foreach ($keys as $key) {
            $file = self::filePath($key);
            if (file_exists($file) && @unlink($file)) {
                $deleted++;
            }
        }
        return $deleted;
    }

    /**
     * Clear all cache entries (L1 + L2).
     */
    public static function clear(): bool
    {
        self::init();
        self::$memory = [];

        if (self::$driver === 'redis') {
            return (bool) self::redisOp(fn () => self::$redis->flushdb(), 'clear', false);
        }

        self::walkCacheFiles(self::$cacheDir, @unlink(...));
        return true;
    }

    /**
     * Remember — get or compute + cache.
     *
     * Cached null values ARE stored (prevents stampede on null-returning callbacks).
     */
    public static function remember(string $key, int $ttl, callable $callback): mixed
    {
        $miss  = self::miss();
        $value = self::get($key, $miss);

        if ($value !== $miss) {
            return $value;
        }

        $value = $callback();
        self::set($key, $value, $ttl);
        return $value;
    }

    /**
     * Atomically increment a numeric cache value.
     */
    public static function increment(string $key, int $step = 1): int|false
    {
        self::init();

        // Redis: INCRBY is natively atomic
        if (self::$driver === 'redis') {
            return self::redisOp(fn () => (int) self::$redis->incrby($key, $step), 'increment', false);
        }

        // File driver: use exclusive lock for atomic read-modify-write
        // Prevents race condition between concurrent processes on shared hosting
        $file = self::filePath($key);
        $dir  = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }

        $fp = @fopen($file, 'c+');
        if ($fp === false) {
            return false;
        }

        if (!flock($fp, LOCK_EX)) {
            fclose($fp);
            return false;
        }

        try {
            $raw  = stream_get_contents($fp);
            $data = $raw !== '' ? json_decode($raw, true) : null;

            // Check expiry
            if (is_array($data) && isset($data['expires']) && $data['expires'] > 0 && $data['expires'] < time()) {
                $data = null; // treat as expired
            }

            $current = is_array($data) && isset($data['value']) ? $data['value'] : 0;
            if (!is_numeric($current)) {
                return false;
            }

            $new     = (int) $current + $step;
            $expires = is_array($data) ? ($data['expires'] ?? 0) : 0;
            $payload = json_encode(
                ['key' => $key, 'value' => $new, 'expires' => $expires],
                self::JSON_FLAGS,
            );

            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, $payload);
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }

        // Update L1 memory cache
        self::setMemory($key, $new, $expires);

        return $new;
    }

    /**
     * Atomically decrement a numeric cache value.
     */
    public static function decrement(string $key, int $step = 1): int|false
    {
        return self::increment($key, -$step);
    }

    /**
     * Clean expired file cache entries. Returns count of deleted files.
     *
     * Uses filemtime() as pre-filter: recently-modified files are skipped
     * without reading, reducing I/O on large cache directories.
     */
    public static function cleanup(): int
    {
        self::init();

        if (self::$driver === 'redis') {
            return 0; // Redis handles TTL natively
        }

        $deleted = 0;
        $now     = time();
        $ttl     = self::$defaultTtl;

        self::walkCacheFiles(self::$cacheDir, function (string $path) use (&$deleted, $now, $ttl) {
            // Fast pre-filter: recently modified → likely not expired
            $mtime = @filemtime($path);
            if ($mtime !== false && ($now - $mtime) < $ttl) {
                return;
            }

            $raw  = @file_get_contents($path);
            $data = $raw !== false ? json_decode($raw, true) : null;

            if (!is_array($data) || !isset($data['expires'])) {
                @unlink($path);
                $deleted++;
                return;
            }

            if ($data['expires'] > 0 && $data['expires'] < $now) {
                @unlink($path);
                $deleted++;
            }
        });

        return $deleted;
    }

    // ─── Worker Mode Integration ────────────────────────────────────────

    /**
     * Per-request reset hook (API consistency with other reset() methods).
     *
     * Cache state intentionally persists across worker iterations.
     * L1 entries are TTL-checked on every get().
     */
    public static function reset(): void
    {
        // intentionally empty
    }

    /** Clear only L1 memory (force re-read from L2 on next access). */
    public static function clearMemory(): void
    {
        self::$memory = [];
    }

    /** Current L1 entry count (monitoring/debugging). */
    public static function getMemorySize(): int
    {
        return count(self::$memory);
    }
}
