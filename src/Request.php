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

    /**
     * Parsed trusted proxy list — cached once per process lifecycle.
     * Worker-mode safe: resolved on first ip() call, reused for all subsequent requests.
     *
     * @var list<string>|null
     */
    private static ?array $trustedProxies = null;
    private string $uri;
    private ?string $rawInput = null;
    public ?object $user = null;
    private ?int $responseStatusCode = null;

    public function __construct()
    {
        $this->method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $requestUri = $_SERVER['REQUEST_URI'] ?? '/';
        if (str_starts_with($requestUri, '//')) {
            $requestUri = '/' . ltrim($requestUri, '/');
        }
        $path = parse_url($requestUri, PHP_URL_PATH) ?: '/';
        $this->uri = preg_replace('#/{2,}#', '/', $path) ?: '/';
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
                // Convert HTTP_ACCEPT_LANGUAGE → Accept-Language
                // Using strtr() + ucwords is faster than the str_replace chain
                // as it creates fewer intermediate strings
                $header = ucwords(strtolower(strtr(substr($key, 5), '_', ' ')));
                $this->headers[strtr($header, ' ', '-')] = $value;
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
     * Only trusts proxy headers (CF-Connecting-IP, X-Real-IP, X-Forwarded-For)
     * when the direct connection (REMOTE_ADDR) is a known trusted proxy.
     * This prevents clients from spoofing their IP via forged forwarded headers.
     *
     * Configure trusted proxies via TRUSTED_PROXIES env var (comma-separated):
     *   TRUSTED_PROXIES=127.0.0.1,10.0.0.0/8,::1
     *
     * Cloudflare IP ranges are trusted automatically when CF-Connecting-IP is present
     * only if REMOTE_ADDR is already a trusted proxy (your edge server).
     */
    public function ip(): string
    {
        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        // Only read proxy headers if the direct connection is from a trusted proxy
        if ($this->isTrustedProxy($remoteAddr)) {
            $proxyHeaders = [
                'HTTP_CF_CONNECTING_IP', // Cloudflare (most specific)
                'HTTP_X_REAL_IP',        // Nginx proxy
                'HTTP_X_FORWARDED_FOR',  // Standard (may contain chain)
            ];

            foreach ($proxyHeaders as $header) {
                $value = $_SERVER[$header] ?? '';
                if ($value === '') continue;

                // X-Forwarded-For: "client, proxy1, proxy2" — use leftmost (original client)
                if (str_contains($value, ',')) {
                    $value = trim(explode(',', $value, 2)[0]);
                }

                if (filter_var($value, FILTER_VALIDATE_IP)) {
                    return $value;
                }
            }
        } else {
            // Development warning: proxy headers are present but REMOTE_ADDR is not trusted.
            // If this happens in production, rate limiting will use the proxy IP instead of
            // the real client IP — causing ALL users behind this proxy to share one rate limit.
            // Fix: add REMOTE_ADDR to TRUSTED_PROXIES in your .env.
            // e.g. TRUSTED_PROXIES=127.0.0.1,::1,{$remoteAddr}
            if (Env::get('APP_ENV', 'production') === 'development') {
                $hasProxyHeader = isset($_SERVER['HTTP_X_FORWARDED_FOR'])
                    || isset($_SERVER['HTTP_X_REAL_IP'])
                    || isset($_SERVER['HTTP_CF_CONNECTING_IP']);

                if ($hasProxyHeader) {
                    error_log(
                        "[padi] WARNING: Proxy headers detected but REMOTE_ADDR ({$remoteAddr}) " .
                        "is not in TRUSTED_PROXIES. ip() is returning the proxy IP, not the real client IP. " .
                        "Rate limiting will be inaccurate. " .
                        "Add to .env: TRUSTED_PROXIES=127.0.0.1,::1,{$remoteAddr}"
                    );
                }
            }
        }


        // Fallback: use direct connection IP (safe, cannot be spoofed)
        return filter_var($remoteAddr, FILTER_VALIDATE_IP) ? $remoteAddr : '0.0.0.0';
    }

    /**
     * Check if an IP address is in the trusted proxies list.
     *
     * Configured via TRUSTED_PROXIES env var (comma-separated IPs or CIDR ranges).
     * Defaults to localhost addresses for safety.
     *
     * The parsed list is cached in a static property so the env string is only
     * parsed once per process lifecycle — optimal for FrankenPHP worker mode.
     *
     * Examples:
     *   TRUSTED_PROXIES=127.0.0.1,::1
     *   TRUSTED_PROXIES=127.0.0.1,10.0.0.1,172.16.0.0/12
     */
    private function isTrustedProxy(string $ip): bool
    {
        // Parse once per process; ??= skips re-parsing on subsequent requests
        self::$trustedProxies ??= array_values(array_filter(
            array_map('trim', explode(',', Env::get('TRUSTED_PROXIES', '127.0.0.1,::1'))),
            static fn(string $p): bool => $p !== ''
        ));

        foreach (self::$trustedProxies as $proxy) {
            // Exact match
            if ($proxy === $ip) {
                return true;
            }

            // CIDR range match (e.g. 10.0.0.0/8)
            if (str_contains($proxy, '/') && $this->ipInCidr($ip, $proxy)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if an IP is within a CIDR range.
     * Supports both IPv4 (10.0.0.0/8) and IPv6 (::1/128).
     */
    private function ipInCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr, 2);
        $bits = (int) $bits;

        // IPv6
        if (str_contains($ip, ':')) {
            $ipBin     = inet_pton($ip);
            $subnetBin = inet_pton($subnet);
            if ($ipBin === false || $subnetBin === false) return false;

            $fullBytes = intdiv($bits, 8);
            $remBits   = $bits % 8;

            if (substr($ipBin, 0, $fullBytes) !== substr($subnetBin, 0, $fullBytes)) return false;
            if ($remBits === 0) return true;

            $mask = 0xFF & (0xFF << (8 - $remBits));
            return (ord($ipBin[$fullBytes]) & $mask) === (ord($subnetBin[$fullBytes]) & $mask);
        }

        // IPv4
        $ipLong     = ip2long($ip);
        $subnetLong = ip2long($subnet);
        if ($ipLong === false || $subnetLong === false) return false;

        $mask = $bits > 0 ? ~((1 << (32 - $bits)) - 1) : 0;
        return ($ipLong & $mask) === ($subnetLong & $mask);
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
