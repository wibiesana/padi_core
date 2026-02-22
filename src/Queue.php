<?php

declare(strict_types=1);

namespace Wibiesana\Padi\Core;

use PDO;
use Exception;

class Queue
{
    private static function initTable(): void
    {
        $db = Database::getInstance()->getConnection();
        $sql = "CREATE TABLE IF NOT EXISTS jobs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            queue VARCHAR(255) NOT NULL,
            payload LONGTEXT NOT NULL,
            attempts TINYINT DEFAULT 0,
            reserved_at INT NULL,
            available_at INT NOT NULL,
            created_at INT NOT NULL
        )";
        $db->exec($sql);
    }

    /**
     * Push a new job onto the queue
     */
    public static function push(string $jobClass, array $data = [], string $queue = 'default', int $delay = 0): void
    {
        self::initTable();
        $db = Database::getInstance()->getConnection();

        $payload = json_encode([
            'job' => $jobClass,
            'data' => $data
        ]);

        $stmt = $db->prepare("INSERT INTO jobs (queue, payload, available_at, created_at) VALUES (?, ?, ?, ?)");
        $stmt->execute([
            $queue,
            $payload,
            time() + $delay,
            time()
        ]);
    }

    /**
     * Run the queue worker
     */
    public static function work(string $queue = 'default'): void
    {
        self::initTable();
        $db = Database::getInstance()->getConnection();

        echo "Worker started on queue: $queue [" . date('Y-m-d H:i:s') . "]\n";

        while (true) {
            $db->beginTransaction();

            // Get next job
            $stmt = $db->prepare("SELECT * FROM jobs WHERE queue = ? AND reserved_at IS NULL AND available_at <= ? ORDER BY id ASC LIMIT 1 FOR UPDATE");
            $stmt->execute([$queue, time()]);
            $job = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($job) {
                // Reserve job
                $stmtReserve = $db->prepare("UPDATE jobs SET reserved_at = ? WHERE id = ?");
                $stmtReserve->execute([time(), $job['id']]);
                $db->commit();

                // Process job
                echo "Processing job #{$job['id']}... ";
                if (self::process($job)) {
                    // Delete job on success
                    $db->prepare("DELETE FROM jobs WHERE id = ?")->execute([$job['id']]);
                    echo "DONE\n";
                } else {
                    // Release job or fail it (with retry limit)
                    $maxAttempts = (int)Env::get('QUEUE_MAX_ATTEMPTS', 3);

                    if ($job['attempts'] >= $maxAttempts) {
                        $db->prepare("DELETE FROM jobs WHERE id = ?")->execute([$job['id']]);
                        echo "REMOVED (Max attempts reached)\n";
                        Logger::error("Job deleted after reaching max attempts", ['id' => $job['id'], 'payload' => $job['payload']]);
                    } else {
                        $db->prepare("UPDATE jobs SET reserved_at = NULL, attempts = attempts + 1 WHERE id = ?")->execute([$job['id']]);
                        echo "FAILED (Will retry)\n";
                    }
                }
            } else {
                $db->rollBack();
                sleep(3); // Wait for new jobs
            }
        }
    }

    private static function process(array $job): bool
    {
        $payload = json_decode($job['payload'], true);
        $jobClass = $payload['job'];
        $data = $payload['data'];

        if (class_exists($jobClass)) {
            try {
                $instance = new $jobClass();
                if (method_exists($instance, 'handle')) {
                    $instance->handle($data);
                    return true;
                }
            } catch (Exception $e) {
                Logger::error("Job failed: " . $jobClass, ['error' => $e->getMessage(), 'data' => $data]);
            }
        } else {
            Logger::error("Job class not found: " . $jobClass);
        }

        return false;
    }
}
