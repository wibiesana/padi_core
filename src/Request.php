<?php

declare(strict_types=1);

namespace Wibiesana\Padi\Core;

/**
 * HTTP Request Handler
 * 
 * Parses and encapsulates all request data (headers, body, query, files).
 * Worker-mode safe: each instance is created fresh per request.
 * 
 * Security:
 * - Input is NOT sanitized at input (output encoding strategy)
 * - php://input is read exactly once and cached
 * - IP validation with proxy header support
 */
class Request
{
    private array $params = [];
    private array $query = [];
    private array $body = [];
    private array $files = [];
    private array $headers = [];
    private string $method;
    private string $uri;
    private ?string $rawInput = null;
    public ?object $user = null;
    private ?int $responseStatusCode = null;

    public function __construct()
    {
        $this->method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $this->uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $this->query = $_GET;
        $this->files = $_FILES;
        $this->parseHeaders();
        $this->parseBody();
    }

    /**
     * Parse request headers from $_SERVER
     * Converts HTTP_X_HEADER keys to X-Header format
     */
    private function parseHeaders(): void
    {
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $header = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
                $this->headers[$header] = $value;
            }
        }

        // Add content type and length if available
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $this->headers['Content-Type'] = $_SERVER['CONTENT_TYPE'];
        }
        if (isset($_SERVER['CONTENT_LENGTH'])) {
            $this->headers['Content-Length'] = $_SERVER['CONTENT_LENGTH'];
        }
    }

    /**
     * Parse request body (reads php://input exactly once)
     */
    private function parseBody(): void
    {
        $contentType = $this->header('Content-Type', '');

        if (str_contains($contentType, 'application/json')) {
            $this->rawInput = file_get_contents('php://input');
            if ($this->rawInput !== '') {
                $decoded = json_decode($this->rawInput, true);
                $this->body = is_array($decoded) ? $decoded : [];
            }
        } elseif (in_array($this->method, ['POST', 'PUT', 'PATCH'], true)) {
            $this->body = $_POST;
        }
    }

    /**
     * Get request method
     */
    public function method(): string
    {
        return $this->method;
    }

    /**
     * Get request URI
     */
    public function uri(): string
    {
        return $this->uri;
    }

    /**
     * Get all input data (query + body + route params merged)
     */
    public function all(): array
    {
        return array_merge($this->query, $this->body, $this->params);
    }

    /**
     * Get raw (uncached) input data
     * Note: body is already parsed from the cached raw input
     */
    public function raw(): array
    {
        return array_merge($this->query, $this->body, $this->params);
    }

    /**
     * Get the raw request body string
     */
    public function rawBody(): string
    {
        if ($this->rawInput === null) {
            $this->rawInput = file_get_contents('php://input');
        }
        return $this->rawInput ?? '';
    }

    /**
     * Get specific input value
     */
    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $this->query[$key] ?? $this->params[$key] ?? $default;
    }

    /**
     * Get only specified keys from input
     */
    public function only(array $keys): array
    {
        $all = $this->all();
        return array_intersect_key($all, array_flip($keys));
    }

    /**
     * Get all inputs except specified keys
     */
    public function except(array $keys): array
    {
        $all = $this->all();
        return array_diff_key($all, array_flip($keys));
    }

    /**
     * Check if input has a key with non-null value
     */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->body)
            || array_key_exists($key, $this->query)
            || array_key_exists($key, $this->params);
    }

    /**
     * Get query parameter(s)
     */
    public function query(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->query;
        }

        return $this->query[$key] ?? $default;
    }

    /**
     * Get header value (case-sensitive formatted key: "Authorization", "Content-Type")
     */
    public function header(string $key, mixed $default = null): mixed
    {
        return $this->headers[$key] ?? $default;
    }

    /**
     * Get all headers
     */
    public function headers(): array
    {
        return $this->headers;
    }

    /**
     * Get uploaded file
     */
    public function file(string $key): ?array
    {
        return $this->files[$key] ?? null;
    }

    /**
     * Get all files
     */
    public function files(): array
    {
        return $this->files;
    }

    /**
     * Set route parameters (called by Router)
     */
    public function setParams(array $params): void
    {
        $this->params = $params;
    }

    /**
     * Get route parameter
     */
    public function param(string $key, mixed $default = null): mixed
    {
        return $this->params[$key] ?? $default;
    }

    /**
     * Get bearer token from Authorization header
     */
    public function bearerToken(): ?string
    {
        $header = $this->header('Authorization', '');

        if (str_starts_with($header, 'Bearer ')) {
            return substr($header, 7);
        }

        // Fallback for case-insensitive match
        if (preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Check if request has JSON content type
     */
    public function isJson(): bool
    {
        return str_contains($this->header('Content-Type', ''), 'application/json');
    }

    /**
     * Get client IP address
     * 
     * Supports proxy headers (Cloudflare, nginx, load balancers).
     * Note: X-Forwarded-For trust should be configured at the reverse proxy level.
     */
    public function ip(): string
    {
        // Priority order: most specific proxy headers first
        $headers = [
            'HTTP_CF_CONNECTING_IP', // Cloudflare
            'HTTP_X_REAL_IP',        // Nginx proxy
            'HTTP_X_FORWARDED_FOR',  // Standard proxy header (may contain chain)
            'REMOTE_ADDR'            // Direct connection
        ];

        foreach ($headers as $header) {
            $value = $_SERVER[$header] ?? '';
            if ($value === '') continue;

            // X-Forwarded-For can contain "client, proxy1, proxy2"
            // Use the FIRST (leftmost) IP = original client
            if (str_contains($value, ',')) {
                $value = trim(explode(',', $value, 2)[0]);
            }

            // Validate IP format
            if (filter_var($value, FILTER_VALIDATE_IP)) {
                return $value;
            }
        }

        return '0.0.0.0';
    }

    /**
     * Set response status code (used by Controller for auto-formatting)
     */
    public function setResponseStatusCode(int $code): void
    {
        $this->responseStatusCode = $code;
    }

    /**
     * Get response status code
     */
    public function getResponseStatusCode(): ?int
    {
        return $this->responseStatusCode;
    }
}
