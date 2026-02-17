<?php

declare(strict_types=1);

namespace Core;

/**
 * Base Controller
 */
abstract class Controller
{
    protected Request $request;
    protected Response $response;

    public function __construct(?Request $request = null)
    {
        $this->request = $request ?? new Request();
        $this->response = new Response();
    }

    /**
     * Validate request data
     */
    protected function validate(array $rules, array $messages = []): array
    {
        // Prevent empty validation rules
        if (empty($rules)) {
            throw new \Exception('Validation rules not configured. Please contact administrator.', 500);
        }

        $validator = new Validator($this->request->all(), $rules, $messages);

        if (!$validator->validate()) {
            throw new ValidationException($validator->errors());
        }

        return $validator->validated();
    }

    /**
     * Return JSON response
     */
    protected function json(array $data, int $code = 200): void
    {
        $this->response->json($data, $code);
    }

    /**
     * Return database error response with detailed debug info
     */
    protected function databaseError(string $message = 'Database error occurred', ?\Exception $exception = null): void
    {
        // Log the database error if exception is provided
        if ($exception) {
            Database::logQueryError($exception);
        }

        $response = [
            'success' => false,
            'message' => $message,
            'message_code' => 'DATABASE_ERROR'
        ];

        // Add database error details in debug mode
        if (Env::get('APP_DEBUG', false) === 'true') {
            $lastError = DatabaseManager::getLastError();
            if ($lastError) {
                $response['database_error'] = $lastError;
            }

            if ($exception) {
                $response['exception'] = [
                    'message' => $exception->getMessage(),
                    'code' => $exception->getCode(),
                    'file' => $exception->getFile(),
                    'line' => $exception->getLine()
                ];
            }
        }

        $this->response->json($response, 500);
    }

    /**
     * Check if current user has specific role
     */
    protected function hasRole(string $role): bool
    {
        return $this->request->user && $this->request->user->role === $role;
    }

    /**
     * Check if current user has any of the specified roles
     */
    protected function hasAnyRole(array $roles): bool
    {
        return $this->request->user && in_array($this->request->user->role, $roles, true);
    }

    /**
     * Require specific role or throw forbidden error
     */
    protected function requireRole(string $role, ?string $message = null): void
    {
        if (!$this->hasRole($role)) {
            throw new \Exception($message ?? "Only {$role}s can access this resource", 403);
        }
    }

    /**
     * Require any of the specified roles or throw forbidden error
     */
    protected function requireAnyRole(array $roles, ?string $message = null): void
    {
        if (!$this->hasAnyRole($roles)) {
            $roleList = implode(', ', $roles);
            throw new \Exception($message ?? "Only {$roleList} can access this resource", 403);
        }
    }

    /**
     * Check if current user is the owner of the resource
     */
    protected function isOwner(int $resourceUserId): bool
    {
        return $this->request->user && $this->request->user->user_id == $resourceUserId;
    }

    /**
     * Check if current user is admin
     */
    protected function isAdmin(): bool
    {
        return $this->hasRole('admin');
    }

    /**
     * Require admin role or resource owner
     */
    protected function requireAdminOrOwner(int $resourceUserId, ?string $message = null): void
    {
        if (!$this->isAdmin() && !$this->isOwner($resourceUserId)) {
            throw new \Exception($message ?? 'You can only access your own resources', 403);
        }
    }

    /**
     * Set response status code for auto-formatting
     */
    protected function setStatusCode(int $code): void
    {
        $this->request->setResponseStatusCode($code);
    }

    /**
     * Return raw response (alias for direct return)
     */
    protected function raw($data, int $code = 200)
    {
        $this->setStatusCode($code);
        return $data;
    }

    /**
     * Return simple response format
     */
    protected function simple($data, string $status = 'success', ?string $code = null, int $statusCode = 200)
    {
        $this->setStatusCode($statusCode);
        return [
            'status' => $status,
            'code' => $code ?? $this->getStatusCodeName($statusCode),
            'item' => $data
        ];
    }

    /**
     * Return created response for auto-formatting
     */
    protected function created($data = null)
    {
        $this->setStatusCode(201);
        return $data;
    }

    /**
     * Return no content response
     */
    protected function noContent()
    {
        $this->setStatusCode(204);
        return null;
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
