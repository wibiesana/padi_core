<?php

declare(strict_types=1);

namespace Wibiesana\Padi\Core;

use ErrorException;
use PDOException;

/**
 * Application Core Class
 * 
 * Handles the application lifecycle, bootstrap configuration, 
 * routing dispatch, and FrankenPHP worker loops.
 * 
 * Optimized for:
 * - FrankenPHP Worker Mode (long-lived process safety)
 * - Shared Hosting (URI normalization, no worker-specific deps)
 * - High Performance (pre-compiled CORS, minimal per-request overhead)
 * - Security (strict headers, error sanitization)
 */
class Application
{
    private string $basePath;
    private $router;
    private array $allowedOrigins;
    private bool $isDevelopment;
    private array $config;

    /** @var bool Whether running in FrankenPHP worker mode */
    private bool $isWorkerMode;

    public function __construct(string $basePath)
    {
        $this->basePath = $basePath;
        $this->isWorkerMode = function_exists('frankenphp_handle_request');
        $this->bootstrap();
    }

    private function bootstrap(): void
    {
        // Define root if not defined
        if (!defined('PADI_ROOT')) {
            define('PADI_ROOT', $this->basePath);
        }

        // Load environment variables
        Env::load($this->basePath . '/.env');

        // Load configuration
        $configPath = $this->basePath . '/config/app.php';
        $this->config = file_exists($configPath) ? require $configPath : ['app_debug' => false, 'timezone' => 'UTC'];

        $this->isDevelopment = Env::get('APP_ENV') === 'development';

        // Prepare routing (loaded once, reused across worker requests)
        $routesPath = $this->basePath . '/routes/api.php';
        if (file_exists($routesPath)) {
            $this->router = require $routesPath;
        }

        // Pre-calculate CORS allowed origins (loaded once)
        $corsOrigins = Env::get('CORS_ALLOWED_ORIGINS', '');
        if ($corsOrigins !== '') {
            $this->allowedOrigins = array_map(
                static fn(string $url): string => rtrim(trim($url), '/'),
                explode(',', $corsOrigins)
            );
        } else {
            $this->allowedOrigins = [];
        }

        // Set Timezone
        date_default_timezone_set($this->config['timezone'] ?? 'UTC');

        // Error Handling Configuration
        if ($this->config['app_debug'] ?? false) {
            error_reporting(E_ALL);
            ini_set('display_errors', '1');
        } else {
            error_reporting(0);
            ini_set('display_errors', '0');
        }

        $this->registerErrorHandlers();
    }

    private function registerErrorHandlers(): void
    {
        // Global Error Handler
        set_error_handler(static function (int $errno, string $errstr, string $errfile, int $errline): bool {
            if ($errno === E_DEPRECATED || $errno === E_USER_DEPRECATED) return true;
            if (!(error_reporting() & $errno)) return false;
            throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
        });

        // Global Exception Handler
        set_exception_handler([$this, 'handleException']);
    }

    /**
     * Handle global uncaught exceptions
     */
    public function handleException(\Throwable $exception): void
    {
        $response = new Response();

        $code = $exception->getCode();
        $statusCode = (is_int($code) && $code >= 400 && $code < 600) ? $code : 500;

        $error = [
            'success' => false,
            'message' => 'Internal Server Error',
            'message_code' => 'INTERNAL_SERVER_ERROR'
        ];

        if ($exception instanceof PDOException) {
            // Never expose database error details to the client
            $error['message'] = 'Database error occurred';
            $error['message_code'] = 'DATABASE_ERROR';
            DatabaseManager::logError($exception);
        } else {
            $error['message'] = $exception->getMessage();
            $error['message_code'] = 'EXCEPTION';
        }

        if ($this->config['app_debug'] ?? false) {
            $error['debug'] = [
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'type' => get_class($exception)
            ];
        }

        try {
            $response->json($error, $statusCode);
        } catch (TerminateException $e) {
            // Safe termination in worker mode
        }
    }

    /**
     * Process an individual request
     */
    private function handleRequest(): void
    {
        // 1. Handle CORS
        $this->handleCors();

        // 2. Handle Preflight Requests (fast return)
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
            header('Access-Control-Max-Age: 86400'); // Cache preflight for 24h
            http_response_code(204); // 204 is more correct for preflight
            return;
        }

        // 3. Security Headers (applied to every response)
        $this->sendSecurityHeaders();

        // 4. Health Check Active DB Connections (worker mode only, prevents stale connections)
        if ($this->isWorkerMode) {
            $this->healthCheckConnections();
        }

        // 5. URI Normalization (Shared Hosting Fix)
        $this->normalizeUri();

        // 6. Dispatch the router
        if ($this->router) {
            try {
                $request = new Request();
                $this->router->dispatch($request);
            } catch (TerminateException $e) {
                // Intentionally stopped execution (Response generated)
            }
        }
    }

    /**
     * Handle CORS headers
     */
    private function handleCors(): void
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';

        if ($origin !== '') {
            if ($this->isDevelopment || in_array($origin, $this->allowedOrigins, true)) {
                header("Access-Control-Allow-Origin: {$origin}");
                header('Access-Control-Allow-Credentials: true');
                header('Vary: Origin');
            }
        } elseif ($this->isDevelopment) {
            header('Access-Control-Allow-Origin: *');
        }

        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS, PATCH');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-Response-Format, Accept, Origin');
    }

    /**
     * Send security-hardening response headers
     */
    private function sendSecurityHeaders(): void
    {
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        header('X-XSS-Protection: 0'); // Modern browsers: CSP is preferred
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header("Permissions-Policy: camera=(), microphone=(), geolocation=()");

        // HSTS only on production HTTPS (skipped on shared hosting HTTP)
        if (!$this->isDevelopment && (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }

    /**
     * Health check active database connections (worker mode)
     * 
     * Reconnects stale DB connections between worker loop iterations
     * to prevent "MySQL server has gone away" errors.
     */
    private function healthCheckConnections(): void
    {
        $connections = DatabaseManager::getConnections();
        if (empty($connections)) return;

        foreach ($connections as $connName) {
            try {
                $pdo = DatabaseManager::connection($connName);
                // Lightweight query, server-side parsed without result set
                $pdo->query('SELECT 1');
            } catch (PDOException $e) {
                if ($this->isDevelopment) {
                    error_log("[padi] DB reconnect for {$connName}: {$e->getMessage()}");
                }
                DatabaseManager::disconnect($connName);
            }
        }
    }

    /**
     * Normalize URI for shared hosting environments
     * 
     * Strips the script directory prefix from REQUEST_URI so routing
     * works correctly in subdirectory deployments (e.g., /myapp/public/index.php).
     */
    private function normalizeUri(): void
    {
        if (!isset($_SERVER['REQUEST_URI'], $_SERVER['SCRIPT_NAME'])) {
            return;
        }

        $uri = $_SERVER['REQUEST_URI'];
        $scriptName = $_SERVER['SCRIPT_NAME'];
        $scriptDir = dirname($scriptName);

        if (str_starts_with($uri, $scriptName)) {
            $uri = substr($uri, strlen($scriptName));
        } elseif ($scriptDir !== '/' && str_starts_with($uri, $scriptDir)) {
            $uri = substr($uri, strlen($scriptDir));
        }

        if ($uri === '' || $uri === false || $uri[0] !== '/') {
            $uri = '/' . ($uri ?: '');
        }

        $_SERVER['REQUEST_URI'] = $uri;
    }

    /**
     * Clean up per-request state for worker mode
     * Prevents memory leaks and state bleed between requests
     */
    private function cleanupRequest(): void
    {
        // Flush and clean any lingering output buffers
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        // Clear superglobal state that may leak between worker requests
        $_GET = [];
        $_POST = [];
        $_FILES = [];
        $_COOKIE = [];
    }

    /**
     * Run the application
     */
    public function run(): void
    {
        if ($this->isWorkerMode) {
            $maxRequests = (int)Env::get('MAX_REQUESTS', '500');
            $count = 0;

            for (; frankenphp_handle_request(); ++$count) {
                try {
                    $this->handleRequest();
                } finally {
                    // Always clean up, even if an exception occurred
                    $this->cleanupRequest();
                }

                if ($count >= $maxRequests) {
                    // Graceful worker restart to prevent memory buildup
                    gc_collect_cycles();
                    exit(0);
                }
            }
        } else {
            // Traditional PHP (Apache/nginx/shared hosting)
            $this->handleRequest();
        }
    }
}
