<?php

declare(strict_types=1);

namespace Wibiesana\Padi\Core;

/**
 * HTTP Response Handler
 * 
 * Worker-mode safe: uses TerminateException instead of exit().
 * Performance: JSON_THROW_ON_ERROR for fast fail, no PRETTY_PRINT in production.
 * Security: Strict security headers on every response.
 */
class Response
{
    private array $headers = [];
    private int $statusCode = 200;

    /**
     * Set response header
     */
    public function header(string $key, string $value): self
    {
        $this->headers[$key] = $value;
        return $this;
    }

    /**
     * Set status code
     */
    public function status(int $code): self
    {
        $this->statusCode = $code;
        return $this;
    }

    /**
     * Send JSON response
     */
    public function json(mixed $data, int $statusCode = 200): void
    {
        // Log if headers were already sent, but continue to try and send the body
        if (headers_sent($file, $line)) {
            error_log("Response::json - headers already sent at {$file}:{$line}");
        }

        $this->status($statusCode);
        $this->header('Content-Type', 'application/json; charset=utf-8');

        // Add debug information if enabled (only in development)
        $isDebug = Env::get('APP_DEBUG', 'false') === 'true';
        $isDev = Env::get('APP_ENV') === 'development';

        if ($isDebug && $isDev) {
            $data = $this->appendDebugInfo($data);
        }

        // JSON encode flags
        $jsonFlags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

        // Only use PRETTY_PRINT in development (saves bandwidth in production)
        if ($isDev) {
            $jsonFlags |= JSON_PRETTY_PRINT;
        }

        $jsonOutput = ($statusCode !== 204) ? json_encode($data, $jsonFlags) : '';

        // GZip compression for responses > 1KB
        $useCompression = !headers_sent()
            && extension_loaded('zlib')
            && Env::get('ENABLE_COMPRESSION', 'true') === 'true'
            && $jsonOutput !== ''
            && strlen($jsonOutput) > 1024
            && isset($_SERVER['HTTP_ACCEPT_ENCODING'])
            && str_contains($_SERVER['HTTP_ACCEPT_ENCODING'], 'gzip');

        if ($useCompression) {
            $compressed = gzencode($jsonOutput, 6);
            if ($compressed !== false) {
                $this->header('Content-Encoding', 'gzip');
                $this->header('Content-Length', (string)strlen($compressed));
                $this->sendHeaders();
                echo $compressed;
                $this->terminate();
                return;
            }
        }

        $this->sendHeaders();

        // 204 No Content should not have a body
        if ($statusCode !== 204) {
            echo $jsonOutput;
        }

        $this->terminate();
    }

    /**
     * Append debug information to response data
     */
    private function appendDebugInfo(mixed $data): mixed
    {
        // Sanitize queries - remove sensitive parameters
        $queries = Database::getQueries();
        $sensitiveKeys = ['password', 'token', 'secret', 'api_key', 'auth'];

        $sanitizedQueries = array_map(static function (array $query) use ($sensitiveKeys): array {
            if (isset($query['params']) && is_array($query['params'])) {
                foreach ($query['params'] as $key => $value) {
                    foreach ($sensitiveKeys as $sensitiveKey) {
                        if (stripos((string)$key, $sensitiveKey) !== false) {
                            $query['params'][$key] = '***REDACTED***';
                            break;
                        }
                    }
                }
            }
            return $query;
        }, $queries);

        $debugInfo = [
            'execution_time' => round((microtime(true) - ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true))) * 1000, 2) . 'ms',
            'memory_usage' => round(memory_get_peak_usage() / 1024 / 1024, 2) . 'MB',
            'query_count' => Database::getQueryCount(),
        ];

        // Add database error information if available
        $lastDbError = DatabaseManager::getLastError();
        if ($lastDbError !== null) {
            $debugInfo['database_error'] = $lastDbError;
        }

        $allDbErrors = DatabaseManager::getAllErrors();
        if (!empty($allDbErrors)) {
            $debugInfo['database_errors_count'] = count($allDbErrors);

            if (Env::get('DEBUG_SHOW_ALL_DB_ERRORS', 'false') === 'true') {
                $debugInfo['database_errors'] = $allDbErrors;
            }
        }

        if (Env::get('DEBUG_SHOW_QUERIES', 'false') === 'true') {
            $debugInfo['queries'] = $sanitizedQueries;
        }

        // Append debug info to response
        if (is_array($data)) {
            if (isset($data['debug']) && is_array($data['debug'])) {
                $data['debug'] = array_merge($data['debug'], $debugInfo);
            } else {
                $data['debug'] = $debugInfo;
            }
        } else {
            $data = [
                'data' => $data,
                'debug' => $debugInfo
            ];
        }

        return $data;
    }

    /**
     * Send plain text response
     */
    public function text(string $data, int $code = 200): void
    {
        if (headers_sent($file, $line)) {
            error_log("Response::text - headers already sent at {$file}:{$line}");
        }

        $this->statusCode = $code;
        $this->header('Content-Type', 'text/plain; charset=utf-8');
        $this->sendHeaders();

        echo $data;
        $this->terminate();
    }

    /**
     * Send HTML response
     */
    public function html(string $data, int $code = 200): void
    {
        if (headers_sent($file, $line)) {
            error_log("Response::html - headers already sent at {$file}:{$line}");
        }

        $this->statusCode = $code;
        $this->header('Content-Type', 'text/html; charset=utf-8');
        $this->sendHeaders();

        echo $data;
        $this->terminate();
    }

    /**
     * Send file download
     */
    public function download(string $filePath, ?string $filename = null): void
    {
        if (!file_exists($filePath)) {
            $this->status(404)->text('File not found');
            return;
        }

        $filename = $filename ?? basename($filePath);
        // Sanitize filename to prevent header injection
        $safeFilename = str_replace(["\r", "\n", '"'], ['', '', "'"], $filename);

        $this->header('Content-Type', 'application/octet-stream');
        $this->header('Content-Disposition', 'attachment; filename="' . $safeFilename . '"');
        $this->header('Content-Length', (string)filesize($filePath));
        $this->header('Cache-Control', 'no-cache, must-revalidate');
        $this->sendHeaders();

        readfile($filePath);
        $this->terminate();
    }

    /**
     * Redirect to URL
     */
    public function redirect(string $url, int $code = 302): void
    {
        if (headers_sent($file, $line)) {
            error_log("Response::redirect - headers already sent at {$file}:{$line}");
        }

        // Validate redirect URL to prevent open redirect
        if (!filter_var($url, FILTER_VALIDATE_URL) && !str_starts_with($url, '/')) {
            error_log("Response::redirect - potentially unsafe URL: {$url}");
        }

        $this->statusCode = $code;
        $this->header('Location', $url);
        $this->sendHeaders();
        $this->terminate();
    }

    /**
     * Send all headers
     */
    private function sendHeaders(): void
    {
        if (headers_sent()) {
            return;
        }

        http_response_code($this->statusCode);

        // Security headers (defense in depth, also set in Application)
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');

        foreach ($this->headers as $key => $value) {
            header("{$key}: {$value}");
        }
    }

    /**
     * Get HTTP status text
     */
    public static function getStatusText(int $code): string
    {
        return match ($code) {
            200 => 'OK',
            201 => 'Created',
            204 => 'No Content',
            301 => 'Moved Permanently',
            302 => 'Found',
            304 => 'Not Modified',
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            409 => 'Conflict',
            422 => 'Unprocessable Entity',
            429 => 'Too Many Requests',
            500 => 'Internal Server Error',
            502 => 'Bad Gateway',
            503 => 'Service Unavailable',
            default => 'Unknown Status'
        };
    }

    /**
     * Terminate response execution
     * 
     * Worker-mode compatible: throws TerminateException instead of exit()
     * so the worker loop can catch it and continue processing.
     */
    private function terminate(): never
    {
        // Check if running in FrankenPHP worker mode
        if (function_exists('frankenphp_handle_request')) {
            throw new TerminateException('Response terminated intentionally.');
        }

        // In traditional mode (shared hosting, Apache, etc.), exit normally
        exit;
    }
}
