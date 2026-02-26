<?php

declare(strict_types=1);

namespace Wibiesana\Padi\Core;

use PDO;
use Exception;

/**
 * Migrator - Database migration runner
 * 
 * Supports MySQL/MariaDB, PostgreSQL, and SQLite.
 * Worker-mode safe: stateless, creates fresh instance per invocation.
 */
class Migrator
{
    private PDO $db;
    private string $migrationPath;
    private string $driver;

    public function __construct()
    {
        $this->db = DatabaseManager::connection();
        $this->driver = DatabaseManager::getDriver();
        $root = defined('PADI_ROOT') ? PADI_ROOT : dirname(__DIR__, 4);
        $this->migrationPath = $root . '/database/migrations';
        $this->createMigrationsTable();
    }

    private function createMigrationsTable(): void
    {
        $sql = match ($this->driver) {
            'sqlite' => "CREATE TABLE IF NOT EXISTS migrations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                migration VARCHAR(255) NOT NULL,
                batch INTEGER NOT NULL,
                executed_at INTEGER DEFAULT (strftime('%s', 'now'))
            )",
            'pgsql', 'postgres', 'postgresql' => "CREATE TABLE IF NOT EXISTS migrations (
                id SERIAL PRIMARY KEY,
                migration VARCHAR(255) NOT NULL,
                batch INTEGER NOT NULL,
                executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )",
            default => "CREATE TABLE IF NOT EXISTS migrations (
                id INT AUTO_INCREMENT PRIMARY KEY,
                migration VARCHAR(255) NOT NULL,
                batch INT NOT NULL,
                executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )",
        };

        $this->db->exec($sql);
    }

    public function migrate(?array $tableFilter = null): void
    {
        $executed = $this->getExecutedMigrations();
        $files = glob($this->migrationPath . '/*.php');

        if (!$files) {
            echo "No migration files found.\n";
            return;
        }

        sort($files);
        $toExecute = [];

        foreach ($files as $file) {
            $name = basename($file, '.php');

            if (in_array($name, $executed, true)) {
                continue;
            }

            if ($tableFilter) {
                $matchesFilter = false;
                foreach ($tableFilter as $table) {
                    if (stripos($name, $table) !== false) {
                        $matchesFilter = true;
                        break;
                    }
                }
                if (!$matchesFilter) continue;
            }

            $toExecute[] = $file;
        }

        if (empty($toExecute)) {
            echo "Nothing to migrate.\n";
            return;
        }

        $batch = $this->getNextBatch();
        $successCount = 0;

        foreach ($toExecute as $file) {
            $name = basename($file, '.php');
            echo "Migrating: {$name}... ";

            try {
                $migration = require $file;

                if (is_object($migration) && method_exists($migration, 'up')) {
                    $migration->up();
                } elseif (is_array($migration) && isset($migration['up'])) {
                    $migration['up']($this->db);
                } else {
                    throw new Exception("Invalid migration format");
                }

                $stmt = $this->db->prepare("INSERT INTO migrations (migration, batch) VALUES (:migration, :batch)");
                $stmt->execute(['migration' => $name, 'batch' => $batch]);

                echo "✓ DONE\n";
                $successCount++;
            } catch (Exception $e) {
                echo "✗ FAILED: " . $e->getMessage() . "\n";
                break;
            }
        }

        echo "\nMigrated {$successCount} files successfully.\n";
    }

    public function rollback(int $steps = 1): void
    {
        for ($i = 0; $i < $steps; $i++) {
            $lastBatch = $this->db->query("SELECT MAX(batch) FROM migrations")->fetchColumn();
            if (!$lastBatch) {
                echo "Nothing to rollback.\n";
                return;
            }

            $stmt = $this->db->prepare("SELECT migration FROM migrations WHERE batch = :batch ORDER BY id DESC");
            $stmt->execute(['batch' => $lastBatch]);
            $migrations = $stmt->fetchAll(PDO::FETCH_COLUMN);

            if (empty($migrations)) {
                echo "No migrations found for batch {$lastBatch}.\n";
                break;
            }

            echo "Rolling back batch {$lastBatch}...\n";

            foreach ($migrations as $name) {
                echo "  Rolling back: {$name}... ";
                $file = $this->migrationPath . '/' . $name . '.php';

                if (file_exists($file)) {
                    $migration = require $file;
                    try {
                        if (is_object($migration) && method_exists($migration, 'down')) {
                            $migration->down();
                        } elseif (is_array($migration) && isset($migration['down'])) {
                            $migration['down']($this->db);
                        }

                        $stmtDel = $this->db->prepare("DELETE FROM migrations WHERE migration = :migration");
                        $stmtDel->execute(['migration' => $name]);
                        echo "✓ DONE\n";
                    } catch (Exception $e) {
                        echo "✗ FAILED: " . $e->getMessage() . "\n";
                    }
                } else {
                    echo "✗ FILE NOT FOUND\n";
                }
            }
        }
    }

    public function status(): void
    {
        $executed = $this->getExecutedMigrations();
        $files = glob($this->migrationPath . '/*.php');

        if (!$files) {
            echo "No migration files found.\n";
            return;
        }

        sort($files);

        echo "\nMigration Status:\n";
        echo str_repeat('-', 70) . "\n";
        printf("%-50s %s\n", "Migration", "Status");
        echo str_repeat('-', 70) . "\n";

        foreach ($files as $file) {
            $name = basename($file, '.php');
            $status = in_array($name, $executed, true) ? "✓ Migrated" : "✗ Pending";
            printf("%-50s %s\n", $name, $status);
        }

        echo str_repeat('-', 70) . "\n";
        echo "Total: " . count($files) . " migrations\n";
        echo "Executed: " . count($executed) . " migrations\n";
        echo "Pending: " . (count($files) - count($executed)) . " migrations\n";
    }

    private function getExecutedMigrations(): array
    {
        try {
            return $this->db->query("SELECT migration FROM migrations")->fetchAll(PDO::FETCH_COLUMN);
        } catch (Exception $e) {
            return [];
        }
    }

    private function getNextBatch(): int
    {
        $batch = $this->db->query("SELECT MAX(batch) FROM migrations")->fetchColumn();
        return (int)$batch + 1;
    }
}
