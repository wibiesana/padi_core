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
 */
class Application
{
    private string $basePath;
    private $router;
    private array $allowedOrigins;
    private bool $isDevelopment;
    private array $config;

    public function __construct(string $basePath)
    {
        $this->basePath = $basePath;
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
        $isDebug = Env::get('APP_DEBUG') === 'true';

        // Debug REQUEST (Development ONLY)
        if ($this->isDevelopment && $isDebug) {
            file_put_contents($this->basePath . '/server_dump.txt', print_r($_SERVER, true));
        }

        // Prepare routing
        $routesPath = $this->basePath . '/routes/api.php';
        if (file_exists($routesPath)) {
            $this->router = require $routesPath;
        }

        // Pre-calculate CORS allowed origins
        $this->allowedOrigins = array_map(function ($url) {
            return rtrim(trim($url), '/');
        }, explode(',', Env::get('CORS_ALLOWED_ORIGINS', '')));

        // Set Timezone
        date_default_timezone_set($this->config['timezone'] ?? 'UTC');

        // Error Handling Configuration
        if ($this->config['app_debug']) {
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
        set_error_handler(function ($errno, $errstr, $errfile, $errline) {
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
        $statusCode = ($code >= 400 && $code < 600) ? (int)$code : 500;

        $error = [
            'success' => false,
            'message' => 'Internal Server Error',
            'message_code' => 'INTERNAL_SERVER_ERROR'
        ];

        if ($exception instanceof PDOException) {
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
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';

        if (!empty($origin)) {
            if ($this->isDevelopment || in_array($origin, $this->allowedOrigins, true)) {
                header("Access-Control-Allow-Origin: {$origin}");
                header('Access-Control-Allow-Credentials: true');
            }
        } elseif ($this->isDevelopment) {
            header('Access-Control-Allow-Origin: *');
        }

        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS, PATCH');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-Response-Format, Accept, Origin');

        // 2. Handle Preflight Requests
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(200);
            return; // Return immediately to release worker
        }

        // 3. Health Check Active DB Connections
        foreach (DatabaseManager::getConnections() as $connName) {
            try {
                DatabaseManager::connection($connName)->query('SELECT 1');
            } catch (PDOException $e) {
                if ($this->isDevelopment) {
                    error_log("[error] DB reconnect for $connName");
                }
                DatabaseManager::disconnect($connName);
            }
        }

        // 4. URI Normalization (Shared Hosting Fix)
        if (isset($_SERVER['REQUEST_URI'], $_SERVER['SCRIPT_NAME'])) {
            $uri = $_SERVER['REQUEST_URI'];
            $scriptName = $_SERVER['SCRIPT_NAME'];
            $scriptDir = dirname($scriptName);

            if (str_starts_with($uri, $scriptName)) {
                $uri = substr($uri, strlen($scriptName));
            } elseif ($scriptDir !== '/' && str_starts_with($uri, $scriptDir)) {
                $uri = substr($uri, strlen($scriptDir));
            }

            if ($uri === '' || $uri[0] !== '/') {
                $uri = '/' . $uri;
            }

            $_SERVER['REQUEST_URI'] = $uri;
        }

        // 5. Dispatch the router
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
     * Run the application
     */
    public function run(): void
    {
        if (function_exists('frankenphp_handle_request')) {
            // Terminate worker if it processes too many requests to prevent arbitrary state buildup
            $maxRequests = (int)Env::get('MAX_REQUESTS', 500);

            for ($count = 0; frankenphp_handle_request(); ++$count) {
                $this->handleRequest();

                if ($count >= $maxRequests) {
                    frankenphp_finish_request();
                    exit(0);
                }
            }
        } else {
            $this->handleRequest();
        }
    }
}
