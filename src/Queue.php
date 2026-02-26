<?php

declare(strict_types=1);

namespace Wibiesana\Padi\Core;

use PDO;
use Exception;

/**
 * Queue - Simple database-backed job queue
 * 
 * Worker-mode safe: initTable() is called once, not per job.
 * Shared hosting safe: uses database (no Redis/RabbitMQ required).
 */
class Queue
{
    private static bool $tableInitialized = false;

    private static function initTable(): void
    {
        if (self::$tableInitialized) {
            return;
        }

        $db = Database::connection();
        $driver = DatabaseManager::getDriver();

        if ($driver === 'pgsql') {
            $sql = "CREATE TABLE IF NOT EXISTS jobs (
                id SERIAL PRIMARY KEY,
                queue VARCHAR(255) NOT NULL,
                payload TEXT NOT NULL,
                attempts SMALLINT DEFAULT 0,
                reserved_at INTEGER NULL,
                available_at INTEGER NOT NULL,
                created_at INTEGER NOT NULL
            )";
        } elseif ($driver === 'sqlite') {
            $sql = "CREATE TABLE IF NOT EXISTS jobs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                queue VARCHAR(255) NOT NULL,
                payload TEXT NOT NULL,
                attempts INTEGER DEFAULT 0,
                reserved_at INTEGER NULL,
                available_at INTEGER NOT NULL,
                created_at INTEGER NOT NULL
            )";
        } else {
            $sql = "CREATE TABLE IF NOT EXISTS jobs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                queue VARCHAR(255) NOT NULL,
                payload LONGTEXT NOT NULL,
                attempts TINYINT DEFAULT 0,
                reserved_at INT NULL,
                available_at INT NOT NULL,
                created_at INT NOT NULL,
                INDEX idx_queue_available (queue, available_at, reserved_at)
            )";
        }

        $db->exec($sql);
        self::$tableInitialized = true;
    }

    /**
     * Push a new job onto the queue
     */
    public static function push(string $jobClass, array $data = [], string $queue = 'default', int $delay = 0): void
    {
        self::initTable();
        $db = Database::connection();

        $payload = json_encode([
            'job' => $jobClass,
            'data' => $data
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $now = time();
        $stmt = $db->prepare("INSERT INTO jobs (queue, payload, available_at, created_at) VALUES (:queue, :payload, :available, :created)");
        $stmt->execute([
            'queue' => $queue,
            'payload' => $payload,
            'available' => $now + $delay,
            'created' => $now
        ]);
    }

    /**
     * Run the queue worker (blocking loop)
     */
    public static function work(string $queue = 'default'): void
    {
        self::initTable();
        $db = Database::connection();
        $maxAttempts = (int)Env::get('QUEUE_MAX_ATTEMPTS', '3');
        $sleepSeconds = (int)Env::get('QUEUE_SLEEP', '3');

        echo "Worker started on queue: {$queue} [" . date('Y-m-d H:i:s') . "]\n";

        while (true) {
            $db->beginTransaction();

            try {
                // Get next available job (FOR UPDATE locks the row)
                $stmt = $db->prepare(
                    "SELECT * FROM jobs WHERE queue = :queue AND reserved_at IS NULL AND available_at <= :now ORDER BY id ASC LIMIT 1 FOR UPDATE"
                );
                $stmt->execute(['queue' => $queue, 'now' => time()]);
                $job = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($job) {
                    // Reserve the job
                    $reserve = $db->prepare("UPDATE jobs SET reserved_at = :now WHERE id = :id");
                    $reserve->execute(['now' => time(), 'id' => $job['id']]);
                    $db->commit();

                    // Process job outside of transaction
                    echo "Processing job #{$job['id']}... ";
                    if (self::process($job)) {
                        $db->prepare("DELETE FROM jobs WHERE id = :id")->execute(['id' => $job['id']]);
                        echo "DONE\n";
                    } else {
                        if ($job['attempts'] >= $maxAttempts) {
                            $db->prepare("DELETE FROM jobs WHERE id = :id")->execute(['id' => $job['id']]);
                            echo "REMOVED (Max attempts reached)\n";
                            Logger::error("Job deleted after max attempts", [
                                'id' => $job['id'],
                                'payload' => $job['payload']
                            ]);
                        } else {
                            $db->prepare("UPDATE jobs SET reserved_at = NULL, attempts = attempts + 1 WHERE id = :id")
                                ->execute(['id' => $job['id']]);
                            echo "FAILED (Will retry)\n";
                        }
                    }
                } else {
                    $db->rollBack();
                    sleep($sleepSeconds);
                }
            } catch (\Throwable $e) {
                // Ensure transaction is rolled back on any error
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                Logger::error("Queue worker error: " . $e->getMessage());
                sleep($sleepSeconds);
            }
        }
    }

    private static function process(array $job): bool
    {
        $payload = json_decode($job['payload'], true);
        if (!is_array($payload)) {
            Logger::error("Invalid job payload", ['id' => $job['id']]);
            return false;
        }

        $jobClass = $payload['job'] ?? '';
        $data = $payload['data'] ?? [];

        if (!class_exists($jobClass)) {
            Logger::error("Job class not found: {$jobClass}");
            return false;
        }

        try {
            $instance = new $jobClass();
            if (method_exists($instance, 'handle')) {
                $instance->handle($data);
                return true;
            }
            Logger::error("Job class has no handle() method: {$jobClass}");
        } catch (Exception $e) {
            Logger::error("Job failed: {$jobClass}", [
                'error' => $e->getMessage(),
                'data' => $data
            ]);
        }

        return false;
    }
}
