<?php

declare(strict_types=1);

namespace Wibiesana\Padi\Core;

/**
 * Router - HTTP Request Routing Engine
 * 
 * Performance:
 * - Routes are pre-compiled with regex during registration (not at dispatch time)
 * - Method-first filtering skips irrelevant routes immediately
 * - Worker-mode: route table is built once and reused across all requests
 * 
 * Security:
 * - Controller/method names validated against class_exists/method_exists
 * - Exception traces only exposed in debug mode
 */
class Router
{
    /** @var array<int, array> Registered routes */
    private array $routes = [];

    /** @var string Current group prefix */
    private string $prefix = '';

    /** @var array Current group middlewares */
    private array $groupMiddlewares = [];

    /**
     * Add GET route
     */
    public function get(string $path, $handler): self
    {
        return $this->addRoute('GET', $path, $handler);
    }

    /**
     * Add POST route
     */
    public function post(string $path, $handler): self
    {
        return $this->addRoute('POST', $path, $handler);
    }

    /**
     * Add PUT route
     */
    public function put(string $path, $handler): self
    {
        return $this->addRoute('PUT', $path, $handler);
    }

    /**
     * Add PATCH route
     */
    public function patch(string $path, $handler): self
    {
        return $this->addRoute('PATCH', $path, $handler);
    }

    /**
     * Add DELETE route
     */
    public function delete(string $path, $handler): self
    {
        return $this->addRoute('DELETE', $path, $handler);
    }

    /**
     * Add OPTIONS route
     */
    public function options(string $path, $handler): self
    {
        return $this->addRoute('OPTIONS', $path, $handler);
    }

    /**
     * Add route for any HTTP method
     */
    public function any(string $path, $handler): void
    {
        foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'] as $method) {
            $this->addRoute($method, $path, $handler);
        }
    }

    /**
     * Create versioned route group (e.g., /v1, /v2)
     */
    public function version(string $v, callable $callback): void
    {
        $this->group(['prefix' => 'v' . ltrim($v, 'v')], $callback);
    }

    /**
     * Create route group with prefix and/or middleware
     */
    public function group(array $attributes, callable $callback): void
    {
        $previousPrefix = $this->prefix;
        $previousMiddlewares = $this->groupMiddlewares;

        if (isset($attributes['prefix'])) {
            $this->prefix .= '/' . trim($attributes['prefix'], '/');
        }

        if (isset($attributes['middleware'])) {
            $middlewares = is_array($attributes['middleware'])
                ? $attributes['middleware']
                : [$attributes['middleware']];
            $this->groupMiddlewares = array_merge($this->groupMiddlewares, $middlewares);
        }

        $callback($this);

        $this->prefix = $previousPrefix;
        $this->groupMiddlewares = $previousMiddlewares;
    }

    /**
     * Add middleware to last registered route
     */
    public function middleware($middleware): self
    {
        $middlewares = is_array($middleware) ? $middleware : [$middleware];
        $lastRouteKey = array_key_last($this->routes);

        if ($lastRouteKey !== null) {
            $this->routes[$lastRouteKey]['middlewares'] = array_merge(
                $this->routes[$lastRouteKey]['middlewares'],
                $middlewares
            );
        }

        return $this;
    }

    /**
     * Register a route
     * 
     * Regex is pre-compiled here so worker mode doesn't recompile per-request.
     */
    private function addRoute(string $method, string $path, $handler): self
    {
        $path = '/' . trim($this->prefix . '/' . trim($path, '/'), '/');
        $path = $path === '/' ? '/' : rtrim($path, '/');

        // Pre-compile regex for performance (compiled once, matched many times)
        $regex = preg_replace('/\{([a-zA-Z0-9_]+)\*\}/', '(?P<$1>.*)', $path);
        $regex = preg_replace('/\{([a-zA-Z0-9_]+)\}/', '(?P<$1>[^/]+)', $regex);
        $regex = '#^' . $regex . '$#';

        $this->routes[] = [
            'method' => $method,
            'path' => $path,
            'regex' => $regex,
            'handler' => $handler,
            'middlewares' => $this->groupMiddlewares
        ];

        return $this;
    }

    /**
     * Dispatch incoming request to matching route
     */
    public function dispatch(Request $request): void
    {
        // Reset per-request state for worker loop consistency
        Database::resetQueryCount();
        DatabaseManager::clearErrors();

        $method = $request->method();
        $uri = rtrim($request->uri(), '/') ?: '/';

        foreach ($this->routes as $route) {
            // Fast path: skip routes with a different HTTP method
            if ($route['method'] !== $method) {
                continue;
            }

            if (preg_match($route['regex'], $uri, $matches)) {
                // Extract named parameters only
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
                $request->setParams($params);

                try {
                    // Execute middleware pipeline
                    foreach ($route['middlewares'] as $middleware) {
                        $this->executeMiddleware($middleware, $request);
                    }

                    // Execute handler
                    $this->executeHandler($route['handler'], $request);
                } catch (TerminateException $e) {
                    // Response was intentionally terminated (e.g., json() called)
                    return;
                } catch (\Exception $e) {
                    $this->handleException($e, $request);
                }
                return;
            }
        }

        // No matching route found
        $response = new Response();
        $response->json([
            'success' => false,
            'message' => 'Route not found',
            'message_code' => 'ROUTE_NOT_FOUND'
        ], 404);
    }

    /**
     * Execute a middleware (class-based or callable)
     */
    private function executeMiddleware($middleware, Request $request): void
    {
        if (is_string($middleware)) {
            // Support parameters like 'RoleMiddleware:admin,manager'
            $parts = explode(':', $middleware, 2);
            $name = $parts[0];
            $params = $parts[1] ?? '';

            $middlewareClass = "App\\Middleware\\{$name}";
            if (!class_exists($middlewareClass)) {
                throw new \Exception("Middleware {$middlewareClass} not found", 500);
            }

            $instance = new $middlewareClass();
            $instance->handle($request, $params);
        } elseif (is_callable($middleware)) {
            $middleware($request);
        }
    }

    /**
     * Execute route handler (closure or Controller@method string)
     */
    private function executeHandler($handler, Request $request): void
    {
        $result = null;

        if (is_callable($handler)) {
            $result = $handler($request);
        } elseif (is_string($handler)) {
            [$controller, $method] = explode('@', $handler);
            $controllerClass = "App\\Controllers\\{$controller}";

            if (!class_exists($controllerClass)) {
                throw new \Exception("Controller {$controllerClass} not found", 404);
            }

            $instance = new $controllerClass($request);

            if (!method_exists($instance, $method)) {
                throw new \Exception("Method {$method} not found in {$controllerClass}", 404);
            }

            $result = $instance->$method();
        }

        // Auto-format response if result is returned or status code is set
        if ($result !== null || $request->getResponseStatusCode() !== null) {
            $this->formatResponse($result, $request);
        }
    }

    /**
     * Handle controller/middleware exceptions
     */
    private function handleException(\Exception $e, Request $request): void
    {
        $response = new Response();
        $statusCode = $e->getCode();

        // Ensure valid HTTP status code (PDOExceptions return SQLSTATE strings)
        if (!is_int($statusCode) || $statusCode < 100 || $statusCode > 599) {
            $statusCode = 500;
        }

        $error = [
            'success' => false,
            'message' => $e->getMessage() ?: 'An error occurred',
            'message_code' => self::getStatusCodeName($statusCode)
        ];

        // Include validation errors
        if ($e instanceof ValidationException) {
            $error['errors'] = $e->getErrors();
        }

        // Debug info only in debug mode
        if (Env::get('APP_DEBUG', 'false') === 'true') {
            $error['debug'] = [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ];
        }

        $response->json($error, $statusCode);
    }

    /**
     * Auto-format response based on return type and headers
     */
    private function formatResponse($data, Request $request): void
    {
        $response = new Response();
        $statusCode = $request->getResponseStatusCode() ?? 200;
        $format = $this->getResponseFormat($request);

        match ($format) {
            'raw' => $response->json($data, $statusCode),
            'simple' => $response->json(
                (is_array($data) && isset($data['status']))
                    ? $data
                    : [
                        'status' => 'success',
                        'code' => self::getStatusCodeName($statusCode),
                        'item' => $data
                    ],
                $statusCode
            ),
            default => $this->formatFullResponse($data, $response, $statusCode),
        };
    }

    /**
     * Full framework response format (with auto-detect collection/single)
     */
    private function formatFullResponse($data, Response $response, int $statusCode): void
    {
        if (is_array($data) && isset($data['success'])) {
            $response->json($data, $statusCode);
            return;
        }

        $this->autoFormatResponse($data, $response, $statusCode);
    }

    /**
     * Auto-format response as collection or single item
     */
    private function autoFormatResponse($data, Response $response, int $statusCode): void
    {
        $messageCode = match ($statusCode) {
            200 => 'SUCCESS',
            201 => 'CREATED',
            204 => 'NO_CONTENT',
            default => 'SUCCESS'
        };

        if (is_array($data) && $this->isCollection($data)) {
            $response->json([
                'success' => true,
                'message' => 'Success',
                'message_code' => $messageCode,
                'item' => $data
            ], $statusCode);
        } else {
            $responseData = [
                'success' => true,
                'message' => 'Success',
                'message_code' => $messageCode
            ];

            if ($data !== null) {
                $responseData['item'] = $data;
            }

            $response->json($responseData, $statusCode);
        }
    }

    /**
     * Check if data is a collection (sequential array or paginated result)
     */
    private function isCollection($data): bool
    {
        if (!is_array($data)) return false;
        if (empty($data)) return true;
        if (isset($data['meta'], $data['data'])) return true;

        return array_is_list($data);
    }

    /**
     * Get response format preference from header or env
     */
    private function getResponseFormat(Request $request): string
    {
        $formatHeader = $request->header('X-Response-Format');
        if ($formatHeader !== null) {
            return strtolower($formatHeader);
        }

        return strtolower(Env::get('RESPONSE_FORMAT', 'full'));
    }

    /**
     * Get status code name
     */
    public static function getStatusCodeName(int $code): string
    {
        return match ($code) {
            200 => 'SUCCESS',
            201 => 'CREATED',
            204 => 'NO_CONTENT',
            400 => 'BAD_REQUEST',
            401 => 'UNAUTHORIZED',
            403 => 'FORBIDDEN',
            404 => 'NOT_FOUND',
            405 => 'METHOD_NOT_ALLOWED',
            409 => 'CONFLICT',
            422 => 'VALIDATION_FAILED',
            429 => 'TOO_MANY_REQUESTS',
            500 => 'INTERNAL_SERVER_ERROR',
            502 => 'BAD_GATEWAY',
            503 => 'SERVICE_UNAVAILABLE',
            default => 'SUCCESS'
        };
    }
}
