<?php

declare(strict_types=1);

namespace Wibiesana\Padi\Core;

use Monolog\Logger as Monolog;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Formatter\LineFormatter;

/**
 * Logger - Application logging via Monolog
 * 
 * Worker-mode safe: logger instance persists across worker iterations.
 * Shared hosting safe: file-based logging only, no external services required.
 */
class Logger
{
    private static ?Monolog $logger = null;

    public static function init(): void
    {
        if (self::$logger !== null) return;

        $root = defined('PADI_ROOT') ? PADI_ROOT : dirname(__DIR__, 4);
        $configPath = $root . '/config/app.php';

        $config = file_exists($configPath)
            ? require $configPath
            : ['app_name' => 'app'];

        $logDir = $root . '/storage/logs';

        if (!is_dir($logDir)) {
            mkdir($logDir, 0750, true);
        }

        self::$logger = new Monolog($config['app_name'] ?? 'app');

        // Custom formatter
        $dateFormat = "Y-m-d H:i:s";
        $output = "[%datetime%] %level_name%: %message% %context% %extra%\n";
        $formatter = new LineFormatter($output, $dateFormat);

        // Rotating File Handler (keep logs for 14 days)
        $fileHandler = new RotatingFileHandler($logDir . '/app.log', 14, Monolog::DEBUG);
        $fileHandler->setFormatter($formatter);
        self::$logger->pushHandler($fileHandler);

        // Error log handler for critical issues
        $errorHandler = new StreamHandler($logDir . '/error.log', Monolog::ERROR);
        $errorHandler->setFormatter($formatter);
        self::$logger->pushHandler($errorHandler);
    }

    public static function info(string $message, array $context = []): void
    {
        self::init();
        self::$logger->info($message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::init();
        self::$logger->error($message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::init();
        self::$logger->warning($message, $context);
    }

    public static function debug(string $message, array $context = []): void
    {
        self::init();
        self::$logger->debug($message, $context);
    }

    public static function critical(string $message, array $context = []): void
    {
        self::init();
        self::$logger->critical($message, $context);
    }
}
