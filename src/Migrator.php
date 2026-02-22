<?php

namespace Wibiesana\Padi\Core;

use PDO;
use Exception;

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
        if ($this->driver === 'sqlite') {
            $sql = "CREATE TABLE IF NOT EXISTS migrations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                migration VARCHAR(255) NOT NULL,
                batch INTEGER NOT NULL,
                executed_at INTEGER DEFAULT (strftime('%s', 'now'))
            )";
        } elseif ($this->driver === 'pgsql') {
            $sql = "CREATE TABLE IF NOT EXISTS migrations (
                id SERIAL PRIMARY KEY,
                migration VARCHAR(255) NOT NULL,
                batch INTEGER NOT NULL,
                executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )";
        } else {
            $sql = "CREATE TABLE IF NOT EXISTS migrations (
                id INT AUTO_INCREMENT PRIMARY KEY,
                migration VARCHAR(255) NOT NULL,
                batch INT NOT NULL,
                executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )";
        }

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

        sort($files); // Execute in order
        $toExecute = [];

        foreach ($files as $file) {
            $name = basename($file, '.php');

            // Skip if already executed
            if (in_array($name, $executed)) {
                continue;
            }

            // Filter by table names if specified
            if ($tableFilter) {
                $matchesFilter = false;
                foreach ($tableFilter as $table) {
                    if (stripos($name, $table) !== false) {
                        $matchesFilter = true;
                        break;
                    }
                }
                if (!$matchesFilter) {
                    continue;
                }
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
            echo "Migrating: $name... ";

            try {
                $migration = require $file;

                // Support new class-based migrations
                if (is_object($migration) && method_exists($migration, 'up')) {
                    $migration->up();
                } elseif (is_array($migration) && isset($migration['up'])) {
                    // Support old array-based migrations
                    $migration['up']($this->db);
                } else {
                    throw new Exception("Invalid migration format");
                }

                $stmt = $this->db->prepare("INSERT INTO migrations (migration, batch) VALUES (?, ?)");
                $stmt->execute([$name, $batch]);

                echo "✓ DONE\n";
                $successCount++;
            } catch (Exception $e) {
                echo "✗ FAILED: " . $e->getMessage() . "\n";
                break;
            }
        }

        echo "\nMigrated $successCount files successfully.\n";
    }

    public function rollback(int $steps = 1): void
    {
        for ($i = 0; $i < $steps; $i++) {
            $lastBatch = $this->db->query("SELECT MAX(batch) FROM migrations")->fetchColumn();
            if (!$lastBatch) {
                echo "Nothing to rollback.\n";
                return;
            }

            $stmt = $this->db->prepare("SELECT migration FROM migrations WHERE batch = ? ORDER BY id DESC");
            $stmt->execute([$lastBatch]);
            $migrations = $stmt->fetchAll(PDO::FETCH_COLUMN);

            if (empty($migrations)) {
                echo "No migrations found for batch $lastBatch.\n";
                break;
            }

            echo "Rolling back batch $lastBatch...\n";

            foreach ($migrations as $name) {
                echo "  Rolling back: $name... ";
                $file = $this->migrationPath . '/' . $name . '.php';

                if (file_exists($file)) {
                    $migration = require $file;
                    try {
                        // Support new class-based migrations
                        if (is_object($migration) && method_exists($migration, 'down')) {
                            $migration->down();
                        } elseif (is_array($migration) && isset($migration['down'])) {
                            $migration['down']($this->db);
                        }

                        $stmtDel = $this->db->prepare("DELETE FROM migrations WHERE migration = ?");
                        $stmtDel->execute([$name]);
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
            $status = in_array($name, $executed) ? "✓ Migrated" : "✗ Pending";
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
