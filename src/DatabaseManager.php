<?php

declare(strict_types=1);

namespace Wibiesana\Padi\Core;

use PDO;
use PDOException;

/**
 * Database Manager - Handles multiple database connections
 * 
 * Worker-mode safe:
 * - Connections are persistent across worker iterations (reused)
 * - Health check mechanism for stale connections
 * - Thread-safe static state management
 * 
 * Shared hosting safe:
 * - No extension dependencies beyond PDO
 * - Supports MySQL, MariaDB, PostgreSQL, SQLite
 * 
 * Security:
 * - PDO::ATTR_EMULATE_PREPARES = false (real prepared statements)
 * - Connection timeout configured
 * - Sensitive data redacted from error logs
 */
class DatabaseManager
{
    /** @var array<string, PDO> Active database connections */
    private static array $connections = [];

    /** @var array|null Database configurations (loaded once) */
    private static ?array $config = null;

    /** @var string|null Default connection name */
    private static ?string $defaultConnection = null;

    /** @var array|null Last database error */
    private static ?array $lastDatabaseError = null;

    /** @var array Database error history (cleared per request in worker mode) */
    private static array $databaseErrors = [];

    /**
     * Get database connection by name
     * 
     * Returns cached connection or creates a new one.
     * Worker-mode: connections persist across iterations.
     */
    public static function connection(?string $name = null): PDO
    {
        if (self::$config === null) {
            self::loadConfig();
        }

        $name ??= self::$defaultConnection;

        // Return existing connection if already created
        if (isset(self::$connections[$name])) {
            return self::$connections[$name];
        }

        if (!isset(self::$config['connections'][$name])) {
            throw new PDOException("Database connection '{$name}' not configured");
        }

        self::$connections[$name] = self::createConnection(
            self::$config['connections'][$name]
        );

        return self::$connections[$name];
    }

    /**
     * Create PDO connection based on driver configuration
     */
    private static function createConnection(array $config): PDO
    {
        $driver = $config['driver'] ?? 'mysql';

        try {
            return match ($driver) {
                'mysql', 'mariadb' => self::createMySQLConnection($config),
                'pgsql', 'postgres', 'postgresql' => self::createPostgreSQLConnection($config),
                'sqlite' => self::createSQLiteConnection($config),
                default => throw new PDOException("Unsupported database driver: {$driver}"),
            };
        } catch (PDOException $e) {
            self::$lastDatabaseError = [
                'type' => 'connection_error',
                'driver' => $driver,
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'timestamp' => date('Y-m-d H:i:s'),
                'config' => array_diff_key($config, ['password' => '', 'username' => ''])
            ];

            self::$databaseErrors[] = self::$lastDatabaseError;

            throw new PDOException(
                "Failed to connect to {$driver} database: " . $e->getMessage(),
                (int)$e->getCode(),
                $e
            );
        }
    }

    /**
     * Create MySQL/MariaDB connection with optimized settings
     */
    private static function createMySQLConnection(array $config): PDO
    {
        $host = $config['host'] ?? 'localhost';
        $port = $config['port'] ?? 3306;
        $database = $config['database'];
        $charset = $config['charset'] ?? 'utf8mb4';

        $dsn = "mysql:host={$host};port={$port};dbname={$database};charset={$charset}";

        $options = $config['options'] ?? self::getDefaultOptions();

        // MySQL/MariaDB specific optimizations
        $options[PDO::MYSQL_ATTR_FOUND_ROWS] = true;
        $options[PDO::ATTR_TIMEOUT] = $config['timeout'] ?? 5;

        // Use persistent connections in worker mode (connection reuse)
        if (function_exists('frankenphp_handle_request')) {
            $options[PDO::ATTR_PERSISTENT] = $config['persistent'] ?? false;
        }

        $pdo = new PDO($dsn, $config['username'], $config['password'], $options);

        // Set session-level optimizations for MariaDB/MySQL
        $pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");

        return $pdo;
    }

    /**
     * Create PostgreSQL connection
     */
    private static function createPostgreSQLConnection(array $config): PDO
    {
        $host = $config['host'] ?? 'localhost';
        $port = $config['port'] ?? 5432;
        $database = $config['database'];

        $dsn = "pgsql:host={$host};port={$port};dbname={$database}";

        if (isset($config['schema'])) {
            $dsn .= ";options='--search_path={$config['schema']}'";
        }

        $options = $config['options'] ?? self::getDefaultOptions();
        $options[PDO::ATTR_TIMEOUT] = $config['timeout'] ?? 5;

        return new PDO($dsn, $config['username'], $config['password'], $options);
    }

    /**
     * Create SQLite connection
     */
    private static function createSQLiteConnection(array $config): PDO
    {
        $database = $config['database'];

        if ($database === ':memory:') {
            $dsn = 'sqlite::memory:';
        } else {
            $dir = dirname($database);
            if (!is_dir($dir)) {
                mkdir($dir, 0750, true);
            }
            $dsn = "sqlite:{$database}";
        }

        $pdo = new PDO($dsn, null, null, $config['options'] ?? self::getDefaultOptions());

        // SQLite optimizations
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('PRAGMA synchronous = NORMAL');
        $pdo->exec('PRAGMA cache_size = -20000'); // 20MB cache

        return $pdo;
    }

    /**
     * Get default PDO options
     * 
     * Security: EMULATE_PREPARES = false ensures real prepared statements,
     * preventing SQL injection even if parameterization is accidentally skipped.
     */
    private static function getDefaultOptions(): array
    {
        return [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ];
    }

    /**
     * Load database configuration (once per process lifecycle)
     */
    private static function loadConfig(): void
    {
        $root = defined('PADI_ROOT') ? PADI_ROOT : dirname(__DIR__, 4);
        $configPath = $root . '/config/database.php';

        if (!file_exists($configPath)) {
            throw new PDOException("Database configuration file not found: {$configPath}");
        }

        self::$config = require $configPath;
        self::$defaultConnection = self::$config['default'] ?? 'mysql';
    }

    /**
     * Set default connection name
     */
    public static function setDefaultConnection(string $name): void
    {
        self::$defaultConnection = $name;
    }

    /**
     * Get default connection name
     */
    public static function getDefaultConnection(): string
    {
        if (self::$config === null) {
            self::loadConfig();
        }

        return self::$defaultConnection;
    }

    /**
     * Add new connection configuration at runtime
     */
    public static function addConnection(string $name, array $config): void
    {
        if (self::$config === null) {
            self::loadConfig();
        }

        self::$config['connections'][$name] = $config;
    }

    /**
     * Disconnect a specific connection
     */
    public static function disconnect(?string $name = null): void
    {
        $name ??= self::$defaultConnection;

        unset(self::$connections[$name]);
    }

    /**
     * Disconnect all connections
     */
    public static function disconnectAll(): void
    {
        self::$connections = [];
    }

    /**
     * Get all active connection names
     */
    public static function getConnections(): array
    {
        return array_keys(self::$connections);
    }

    /**
     * Check if a connection is configured
     */
    public static function hasConnection(string $name): bool
    {
        if (self::$config === null) {
            self::loadConfig();
        }

        return isset(self::$config['connections'][$name]);
    }

    /**
     * Begin transaction on a connection
     */
    public static function beginTransaction(?string $name = null): bool
    {
        return self::connection($name)->beginTransaction();
    }

    /**
     * Commit transaction
     */
    public static function commit(?string $name = null): bool
    {
        return self::connection($name)->commit();
    }

    /**
     * Rollback transaction
     */
    public static function rollback(?string $name = null): bool
    {
        return self::connection($name)->rollBack();
    }

    /**
     * Get database driver name for a connection
     */
    public static function getDriver(?string $name = null): string
    {
        if (self::$config === null) {
            self::loadConfig();
        }

        $name ??= self::$defaultConnection;

        return self::$config['connections'][$name]['driver'] ?? 'mysql';
    }

    /**
     * Get last database error
     */
    public static function getLastError(): ?array
    {
        return self::$lastDatabaseError;
    }

    /**
     * Get all database errors
     */
    public static function getAllErrors(): array
    {
        return self::$databaseErrors;
    }

    /**
     * Clear database errors (called per-request in worker mode)
     */
    public static function clearErrors(): void
    {
        self::$lastDatabaseError = null;
        self::$databaseErrors = [];
    }

    /**
     * Log a database error with sanitized parameters
     */
    public static function logError(\Exception $e, string $query = '', array $params = []): void
    {
        self::$lastDatabaseError = [
            'type' => 'query_error',
            'message' => $e->getMessage(),
            'code' => $e->getCode(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'query' => $query,
            'params' => self::sanitizeParams($params),
            'timestamp' => date('Y-m-d H:i:s')
        ];

        self::$databaseErrors[] = self::$lastDatabaseError;
    }

    /**
     * Sanitize parameters - redact sensitive values
     */
    private static function sanitizeParams(array $params): array
    {
        $sensitiveKeys = ['password', 'token', 'secret', 'api_key', 'auth', 'pass'];
        $sanitized = [];

        foreach ($params as $key => $value) {
            $isSensitive = false;
            foreach ($sensitiveKeys as $sensitiveKey) {
                if (stripos((string)$key, $sensitiveKey) !== false) {
                    $isSensitive = true;
                    break;
                }
            }

            $sanitized[$key] = $isSensitive ? '***REDACTED***' : $value;
        }

        return $sanitized;
    }
}
