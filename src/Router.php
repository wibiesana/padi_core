<?php

declare(strict_types=1);

namespace Core;

class Router
{
    private array $routes = [];
    private string $prefix = '';
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
     * Add route for any method
     */
    public function any(string $path, $handler): void
    {
        $methods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];
        foreach ($methods as $method) {
            $this->addRoute($method, $path, $handler);
        }
    }

    /**
     * Create versioned route group
     */
    public function version(string $v, callable $callback): void
    {
        $this->group(['prefix' => 'v' . ltrim($v, 'v')], $callback);
    }

    /**
     * Create route group with prefix
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
     * Add middleware to route
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
     * Add route
     */
    private function addRoute(string $method, string $path, $handler): self
    {
        $path = '/' . trim($this->prefix . '/' . trim($path, '/'), '/');
        $path = $path === '/' ? '/' : rtrim($path, '/');

        // Pre-compile regex for performance
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
     * Dispatch request
     */
    public function dispatch(Request $request): void
    {
        $method = $request->method();
        $uri = rtrim($request->uri(), '/') ?: '/';


        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }

            if (preg_match($route['regex'], $uri, $matches)) {
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
                $request->setParams($params);

                try {
                    // Execute middlewares
                    foreach ($route['middlewares'] as $middleware) {
                        $this->executeMiddleware($middleware, $request);
                    }

                    // Execute handler
                    $this->executeHandler($route['handler'], $request);
                } catch (\Exception $e) {
                    // Handle middleware or controller exceptions
                    $this->handleException($e, $request);
                }
                return;
            }
        }

        // Route not found
        $response = new Response();
        $response->json([
            'success' => false,
            'message' => 'Route not found',
            'message_code' => 'ROUTE_NOT_FOUND'
        ], 404);
    }

    /**
     * Execute middleware
     */
    private function executeMiddleware($middleware, Request $request): void
    {
        if (is_string($middleware)) {
            // Support parameters like 'RoleMiddleware:admin,manager'
            $parts = explode(':', $middleware, 2);
            $name = $parts[0];
            $params = isset($parts[1]) ? $parts[1] : '';

            $middlewareClass = "App\\Middleware\\{$name}";
            if (class_exists($middlewareClass)) {
                $instance = new $middlewareClass();
                $instance->handle($request, $params);
            } else {
                throw new \Exception("Middleware {$middlewareClass} not found");
            }
        } elseif (is_callable($middleware)) {
            $middleware($request);
        }
    }

    /**
     * Execute route handler
     */
    private function executeHandler($handler, Request $request): void
    {
        $result = null;

        if (is_callable($handler)) {
            $result = $handler($request);
        } elseif (is_string($handler)) {
            [$controller, $method] = explode('@', $handler);
            $controllerClass = "App\\Controllers\\{$controller}";

            if (class_exists($controllerClass)) {
                $instance = new $controllerClass($request);
                if (method_exists($instance, $method)) {
                    $result = $instance->$method();
                } else {
                    throw new \Exception("Method {$method} not found in {$controllerClass}");
                }
            } else {
                throw new \Exception("Controller {$controllerClass} not found");
            }
        }

        // Auto-format response if result is returned or status code is set
        if ($result !== null || $request->getResponseStatusCode() !== null) {
            $this->formatResponse($result, $request);
        }
    }

    /**
     * Handle exceptions from controllers
     */
    private function handleException(\Exception $e, Request $request): void
    {
        $response = new Response();
        $statusCode = $e->getCode();

        // Ensure status code is valid HTTP status code (integer between 100 and 599)
        // PDOExceptions often return string error codes (SQLSTATE) which should be treated as 500
        if (!is_int($statusCode) || $statusCode < 100 || $statusCode > 599) {
            $statusCode = 500;
        }

        $error = [
            'success' => false,
            'message' => $e->getMessage() ?: 'An error occurred',
            'message_code' => $this->getStatusCodeName($statusCode)
        ];

        // Include validation errors if it's a ValidationException
        if ($e instanceof ValidationException) {
            $error['errors'] = $e->getErrors();
        }

        // Add debug info if in debug mode
        if (Env::get('APP_DEBUG', false)) {
            $error['debug'] = [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ];
        }

        $response->json($error, $statusCode);
    }

    /**
     * Auto-format response based on return type
     */
    private function formatResponse($data, Request $request): void
    {
        $response = new Response();

        // Get custom status code if set
        $statusCode = $request->getResponseStatusCode() ?? 200;

        // Handle different response formats based on environment variable or request header
        $format = $this->getResponseFormat($request);

        switch ($format) {
            case 'raw':
                // Return data as-is without wrapping
                $response->json($data, $statusCode);
                break;

            case 'simple':
                // Simple success wrapper
                if (is_array($data) && isset($data['status'])) {
                    // Data already has status structure
                    $response->json($data, $statusCode);
                } else {
                    $response->json([
                        'status' => 'success',
                        'code' => $this->getStatusCodeName($statusCode),
                        'item' => $data
                    ], $statusCode);
                }
                break;

            case 'full':
            default:
                // Full framework response format
                if (is_array($data) && isset($data['success'])) {
                    // Data already has framework format
                    $response->json($data, $statusCode);
                } else {
                    // Auto-detect if it's a collection or single item
                    $this->autoFormatResponse($data, $response, $statusCode);
                }
                break;
        }
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
            // Collection response
            $response->json([
                'success' => true,
                'message' => 'Success',
                'message_code' => $messageCode,
                'item' => $data
            ], $statusCode);
        } else {
            // Single item response
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
     * Check if data is a collection
     */
    private function isCollection($data): bool
    {
        if (!is_array($data)) {
            return false;
        }

        // Empty array is considered a collection
        if (empty($data)) {
            return true;
        }

        // If it has pagination meta, it's likely a collection
        if (isset($data['meta']) && isset($data['data'])) {
            return true;
        }

        // If it's a sequential array or has multiple items with similar structure
        return array_keys($data) === range(0, count($data) - 1);
    }

    /**
     * Get response format preference
     */
    private function getResponseFormat(Request $request): string
    {
        // Check request header first
        $formatHeader = $request->header('X-Response-Format');
        if ($formatHeader) {
            return strtolower($formatHeader);
        }

        // Check environment variable
        $envFormat = Env::get('RESPONSE_FORMAT', 'full');
        return strtolower($envFormat);
    }

    /**
     * Get status code name
     */
    private function getStatusCodeName(int $code): string
    {
        return match ($code) {
            200 => 'SUCCESS',
            201 => 'CREATED',
            204 => 'NO_CONTENT',
            400 => 'BAD_REQUEST',
            401 => 'UNAUTHORIZED',
            403 => 'FORBIDDEN',
            404 => 'NOT_FOUND',
            422 => 'VALIDATION_FAILED',
            500 => 'INTERNAL_SERVER_ERROR',
            default => 'SUCCESS'
        };
    }
}
