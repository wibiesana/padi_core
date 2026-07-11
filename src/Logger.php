<?php

declare(strict_types=1);

namespace Wibiesana\Padi\Core;

/**
 * Logger - Application logging via native PHP
 * 
 * Worker-mode safe: logger instance persists across worker iterations.
 * Shared hosting safe: file-based logging only, no external services required.
 */
class Logger
{
    private static string $logDir = '';
    private static string $appName = 'app';
    private static bool $initialized = false;

    public static function init(): void
    {
        if (self::$initialized) return;

        $root = defined('PADI_ROOT') ? PADI_ROOT : dirname(__DIR__, 4);
        $configPath = $root . '/config/app.php';

        $config = file_exists($configPath)
            ? require $configPath
            : ['app_name' => 'app'];

        self::$appName = $config['app_name'] ?? 'app';
        self::$logDir = $root . '/storage/logs';

        if (!is_dir(self::$logDir)) {
            mkdir(self::$logDir, 0750, true);
        }

        self::$initialized = true;

        // Perform rotation check occasionally (e.g. 1 in 100 requests)
        if (mt_rand(1, 100) === 1) {
            self::rotateLogs();
        }
    }

    private static function rotateLogs(): void
    {
        if (!is_dir(self::$logDir)) return;

        $files = glob(self::$logDir . '/*.log');
        if (!$files) return;

        $cutoff = time() - (14 * 24 * 60 * 60); // 14 days ago

        foreach ($files as $file) {
            if (is_file($file)) {
                $basename = basename($file);
                // Matches format app-YYYY-MM-DD.log or error-YYYY-MM-DD.log
                if (preg_match('/(?:app|error)-(\d{4}-\d{2}-\d{2})\.log$/', $basename, $matches)) {
                    $fileDate = strtotime($matches[1]);
                    if ($fileDate !== false && $fileDate < $cutoff) {
                        @unlink($file);
                    }
                }
            }
        }
    }

    private static function log(string $level, string $message, array $context = []): void
    {
        self::init();

        $datetime = date('Y-m-d H:i:s');
        $contextStr = $context ? ' ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '';
        $formatted = sprintf("[%s] %s: %s%s\n", $datetime, strtoupper($level), $message, $contextStr);

        $dateStr = date('Y-m-d');
        $appLogFile = self::$logDir . '/app-' . $dateStr . '.log';
        
        // Write to daily app log
        @file_put_contents($appLogFile, $formatted, FILE_APPEND | LOCK_EX);

        // Write to daily error log for ERROR/CRITICAL levels
        if (in_array(strtolower($level), ['error', 'critical'], true)) {
            $errorLogFile = self::$logDir . '/error-' . $dateStr . '.log';
            @file_put_contents($errorLogFile, $formatted, FILE_APPEND | LOCK_EX);
        }
    }

    public static function info(string $message, array $context = []): void
    {
        self::log('info', $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::log('error', $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::log('warning', $message, $context);
    }

    public static function debug(string $message, array $context = []): void
    {
        self::log('debug', $message, $context);
    }

    public static function critical(string $message, array $context = []): void
    {
        self::log('critical', $message, $context);
    }
}
