<?php

declare(strict_types=1);

namespace Wibiesana\Padi\Core;

use PDO;

/**
 * Database Class - Backward Compatible Wrapper
 * 
 * This class maintains backward compatibility with existing code
 * while internally using the new DatabaseManager for multi-database support
 */
class Database
{
    private static ?Database $instance = null;
    private PDO $connection;

    private function __construct()
    {
        // Use DatabaseManager to get default connection
        $this->connection = DatabaseManager::connection();
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private static int $queryCount = 0;
    private static array $queries = [];

    public function getConnection(): PDO
    {
        return $this->connection;
    }

    /**
     * Get connection by name (NEW METHOD)
     * 
     * @param string|null $name Connection name
     * @return PDO
     */
    public static function connection(?string $name = null): PDO
    {
        return DatabaseManager::connection($name);
    }

    /**
     * Log query for debugging
     */
    public static function logQuery(string $query, array $params = []): void
    {
        self::$queryCount++;

        if (Env::get('APP_DEBUG', false) === 'true') {
            self::$queries[] = [
                'query' => $query,
                'params' => $params,
                'time' => microtime(true)
            ];
        }
    }

    /**
     * Log query error
     */
    public static function logQueryError(\Exception $e, string $query = '', array $params = []): void
    {
        DatabaseManager::logError($e, $query, $params);
    }

    /**
     * Get total query count
     */
    public static function getQueryCount(): int
    {
        return self::$queryCount;
    }

    /**
     * Get all executed queries
     */
    public static function getQueries(): array
    {
        return self::$queries;
    }

    /**
     * Reset query counter
     */
    public static function resetQueryCount(): void
    {
        self::$queryCount = 0;
        self::$queries = [];
    }

    /**
     * Reset query log (alias for resetQueryCount)
     * Used in FrankenPHP worker mode to clear state between requests
     */
    public static function resetQueryLog(): void
    {
        self::resetQueryCount();
    }

    public static function beginTransaction(?string $connection = null): bool
    {
        return DatabaseManager::beginTransaction($connection);
    }

    public static function commit(?string $connection = null): bool
    {
        return DatabaseManager::commit($connection);
    }

    public static function rollback(?string $connection = null): bool
    {
        return DatabaseManager::rollback($connection);
    }

    /**
     * Execute a callback within a transaction
     * 
     * @param callable $callback
     * @param string|null $connection
     * @return mixed
     * @throws \Exception
     */
    public static function transaction(callable $callback, ?string $connection = null): mixed
    {
        self::beginTransaction($connection);

        try {
            $result = $callback();
            self::commit($connection);
            return $result;
        } catch (\Throwable $e) {
            self::rollback($connection);
            throw $e;
        }
    }

    // Prevent cloning
    private function __clone() {}

    // Prevent unserialization
    public function __wakeup()
    {
        throw new \Exception("Cannot unserialize singleton");
    }
}
