<?php

declare(strict_types=1);

namespace Core;

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
    public function json($data, int $statusCode = 200): void
    {
        // Log if headers were already sent, but continue to try and send the body
        if (headers_sent($file, $line)) {
            error_log("Response::json - headers already sent at $file:$line");
        }

        $this->status($statusCode);
        $this->header('Content-Type', 'application/json');

        // Add debug information if enabled (only in development)
        if (Env::get('APP_DEBUG', false) === 'true' && Env::get('APP_ENV') === 'development') {
            // Sanitize queries - remove sensitive parameters
            $queries = Database::getQueries();
            $sanitizedQueries = array_map(function ($query) {
                $sensitiveKeys = ['password', 'token', 'secret', 'api_key', 'auth'];

                if (isset($query['params']) && is_array($query['params'])) {
                    foreach ($query['params'] as $key => $value) {
                        foreach ($sensitiveKeys as $sensitiveKey) {
                            if (stripos((string)$key, $sensitiveKey) !== false) {
                                $query['params'][$key] = '***REDACTED***';
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

                // Only include all errors if specifically enabled
                if (Env::get('DEBUG_SHOW_ALL_DB_ERRORS', 'false') === 'true') {
                    $debugInfo['database_errors'] = $allDbErrors;
                }
            }

            // Only include full query details if explicitly enabled
            if (Env::get('DEBUG_SHOW_QUERIES', 'false') === 'true') {
                $debugInfo['queries'] = $sanitizedQueries;
            }

            // Add debug info directly to the response instead of wrapping
            if (is_array($data)) {
                if (isset($data['debug']) && is_array($data['debug'])) {
                    $data['debug'] = array_merge($data['debug'], $debugInfo);
                } else {
                    $data['debug'] = $debugInfo;
                }
            } else {
                // If data is not an array, wrap it but flatten the structure
                $data = [
                    'data' => $data,
                    'debug' => $debugInfo
                ];
            }
        }

        // Enable gzip compression for JSON responses
        if (!headers_sent() && extension_loaded('zlib') && Env::get('ENABLE_COMPRESSION', 'true') === 'true') {
            if (isset($_SERVER['HTTP_ACCEPT_ENCODING']) && strpos($_SERVER['HTTP_ACCEPT_ENCODING'], 'gzip') !== false) {
                ob_start('ob_gzhandler');
            }
        }

        $this->sendHeaders();

        // 204 No Content should not have a body
        if ($statusCode !== 204) {
            echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        }

        $this->terminate();
    }

    /**
     * Send plain text response
     */
    public function text(string $data, int $code = 200): void
    {
        if (headers_sent($file, $line)) {
            error_log("Response::text - headers already sent at $file:$line");
        }

        $this->statusCode = $code;
        $this->header('Content-Type', 'text/plain');
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
            error_log("Response::html - headers already sent at $file:$line");
        }

        $this->statusCode = $code;
        $this->header('Content-Type', 'text/html');
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
        }

        $filename = $filename ?? basename($filePath);

        $this->header('Content-Type', 'application/octet-stream');
        $this->header('Content-Disposition', 'attachment; filename="' . $filename . '"');
        $this->header('Content-Length', (string)filesize($filePath));
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
            error_log("Response::redirect - headers already sent at $file:$line");
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
        // Check if headers were already sent
        if (headers_sent()) {
            return;
        }

        http_response_code($this->statusCode);

        // All CORS and Security headers are now handled in index.php
        // but we keep some basic security headers here as secondary protection
        header('X-Frame-Options: SAMEORIGIN');
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
        $statusTexts = [
            200 => 'OK',
            201 => 'Created',
            204 => 'No Content',
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            422 => 'Unprocessable Entity',
            500 => 'Internal Server Error',
        ];

        return $statusTexts[$code] ?? 'Unknown Status';
    }

    /**
     * Terminate response execution
     * Worker-mode compatible: doesn't call exit() in FrankenPHP worker mode
     */
    private function terminate(): void
    {
        // Check if running in FrankenPHP worker mode
        if (function_exists('frankenphp_handle_request')) {
            // In worker mode, just return to let the worker loop continue
            return;
        }

        // In traditional mode, exit normally
        exit;
    }
}
