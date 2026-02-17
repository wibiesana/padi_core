<?php

declare(strict_types=1);

namespace Core;

class Request
{
    private array $params = [];
    private array $query = [];
    private array $body = [];
    private array $files = [];
    private array $headers = [];
    private string $method;
    private string $uri;
    public ?object $user = null;
    private ?int $responseStatusCode = null;

    public function __construct()
    {
        $this->method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $this->uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $this->query = $_GET;
        $this->files = $_FILES;
        $this->parseHeaders();
        $this->parseBody();
    }

    /**
     * Parse request headers
     */
    private function parseHeaders(): void
    {
        foreach ($_SERVER as $key => $value) {
            if (strpos($key, 'HTTP_') === 0) {
                $header = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
                $this->headers[$header] = $value;
            }
        }

        // Add content type if available
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $this->headers['Content-Type'] = $_SERVER['CONTENT_TYPE'];
        }
    }

    /**
     * Parse request body
     */
    private function parseBody(): void
    {
        $contentType = $this->header('Content-Type', '');

        if (strpos($contentType, 'application/json') !== false) {
            $input = file_get_contents('php://input');
            $this->body = json_decode($input, true) ?? [];
        } elseif ($this->method === 'POST' || $this->method === 'PUT' || $this->method === 'PATCH') {
            $this->body = $_POST;
        }

        // Note: Input sanitization removed - use output encoding instead
        // Passwords, JSON data, and HTML content should not be stripped at input
        // Use htmlspecialchars() when outputting to HTML, not on input
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
     * Get all input data (sanitized)
     */
    public function all(): array
    {
        return array_merge($this->query, $this->body, $this->params);
    }

    /**
     * Get raw input data (unsanitized)
     */
    public function raw(): array
    {
        $contentType = $this->header('Content-Type', '');
        $rawQuery = $_GET;
        $rawBody = [];

        if (strpos($contentType, 'application/json') !== false) {
            $input = file_get_contents('php://input');
            $rawBody = json_decode($input, true) ?? [];
        } else {
            $rawBody = $_POST;
        }

        return array_merge($rawQuery, $rawBody, $this->params);
    }

    /**
     * Get specific input value
     */
    public function input(string $key, mixed $default = null)
    {
        return $this->all()[$key] ?? $default;
    }

    /**
     * Get only specified keys
     */
    public function only(array $keys): array
    {
        return array_intersect_key($this->all(), array_flip($keys));
    }

    /**
     * Get all except specified keys
     */
    public function except(array $keys): array
    {
        return array_diff_key($this->all(), array_flip($keys));
    }

    /**
     * Check if input has key
     */
    public function has(string $key): bool
    {
        return isset($this->all()[$key]);
    }

    /**
     * Get query parameter
     */
    public function query(?string $key = null, mixed $default = null)
    {
        if ($key === null) {
            return $this->query;
        }

        return $this->query[$key] ?? $default;
    }

    /**
     * Get header value
     */
    public function header(string $key, mixed $default = null)
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
     * Set route parameters
     */
    public function setParams(array $params): void
    {
        $this->params = $params;
    }

    /**
     * Get route parameter
     */
    public function param(string $key, mixed $default = null)
    {
        return $this->params[$key] ?? $default;
    }

    /**
     * Get bearer token
     */
    public function bearerToken(): ?string
    {
        $header = $this->header('Authorization', '');

        if (preg_match('/Bearer\s+(.*)$/i', $header, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Check if request is JSON
     */
    public function isJson(): bool
    {
        return strpos($this->header('Content-Type', ''), 'application/json') !== false;
    }

    /**
     * Get client IP address
     * Supports X-Forwarded-For for proxy/CDN (e.g., Cloudflare, nginx)
     */
    public function ip(): string
    {
        // Check for proxy headers (trust these only if behind a known proxy)
        $headers = [
            'HTTP_CF_CONNECTING_IP', // Cloudflare
            'HTTP_X_FORWARDED_FOR',  // Standard proxy header
            'HTTP_X_REAL_IP',        // Nginx proxy
            'REMOTE_ADDR'            // Direct connection
        ];

        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = $_SERVER[$header];

                // X-Forwarded-For can contain multiple IPs (client, proxy1, proxy2)
                // Take the first one (original client)
                if (strpos($ip, ',') !== false) {
                    $ips = explode(',', $ip);
                    $ip = trim($ips[0]);
                }

                // Validate IP format
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }

                // If validation fails but IP exists, return it anyway (might be private network)
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return '0.0.0.0';
    }

    /**
     * Set response status code
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
