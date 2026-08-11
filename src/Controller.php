<?php

declare(strict_types=1);

namespace Wibiesana\Padi\Core;

/**
 * Base Controller
 *
 * Worker-mode safe: no static state, fresh instance per request.
 * Shared-hosting safe: no external dependencies.
 *
 * DRY principles:
 * - Centralized authorization via assertUser() guard
 * - Single-path error response via error()
 * - Debug flag cached per-instance to avoid repeated Env lookups
 */
abstract class Controller
{
    protected Request $request;
    protected Response $response;

    /** Cached debug flag — avoids repeated Env::get() per request */
    private readonly bool $isDebug;

    public function __construct(?Request $request = null)
    {
        $this->request = $request ?? new Request();
        $this->response = new Response();
        $this->isDebug = Env::get('APP_ENV', 'production') === 'development' && Env::get('APP_DEBUG', 'false') === 'true';
    }

    // ──────────────────────────────────────────────
    //  INPUT & VALIDATION
    // ──────────────────────────────────────────────

    /**
     * Validate request data against rules
     *
     * @return array Validated data (only keys present in rules)
     * @throws ValidationException if validation fails
     * @throws \InvalidArgumentException if rules are empty
     */
    protected function validate(array $rules, array $messages = []): array
    {
        if (empty($rules)) {
            throw new \InvalidArgumentException('Validation rules cannot be empty.');
        }

        $validator = new Validator($this->request->all(), $rules, $messages);

        if (!$validator->validate()) {
            throw new ValidationException($validator->errors());
        }

        return $validator->validated();
    }

    /**
     * Build standard model query with auto search, sort, and eager loading from Request
     *
     * @param string $modelClass FQCN Model (e.g. Semester::class)
     * @param array $withRelations List of eager loaded relations
     * @return ModelQuery
     */
    protected function query(string $modelClass, array $withRelations = []): ModelQuery
    {
        $search = $this->request->query('search');
        $sortBy = $this->request->query('sort_by');
        $order  = strtoupper((string)$this->request->query('order', 'asc')) === 'DESC' ? 'DESC' : 'ASC';

        /** @var ModelQuery $query */
        if ($search && method_exists($modelClass, 'search')) {
            $query = $modelClass::search(substr((string)$search, 0, 255));
        } else {
            $query = $modelClass::find();
        }

        if ($sortBy) {
            $tableName = (new $modelClass())->getTable();
            $query->orderBy("{$tableName}.{$sortBy} {$order}");
        } else {
            $tableName = (new $modelClass())->getTable();
            $query->orderBy("{$tableName}.id DESC");
        }

        if (!empty($withRelations)) {
            $query->with(...$withRelations);
        }

        return $query;
    }

    // ──────────────────────────────────────────────
    //  RESPONSE HELPERS (DRY)
    // ──────────────────────────────────────────────

    /**
     * Return JSON response directly (terminates request)
     */
    protected function json(array $data, int $code = 200): void
    {
        $this->response->json($data, $code);
    }

    /**
     * Return a standardised error response
     *
     * Single path for ALL error types: database, auth, business logic.
     * Debug info is only appended when APP_DEBUG=true.
     */
    protected function error(
        string $message,
        int $code = 500,
        string $messageCode = 'ERROR',
        ?\Throwable $exception = null
    ): void {
        if ($exception) {
            Database::logQueryError($exception);
        }

        $response = [
            'success'      => false,
            'message'      => $message,
            'message_code' => $messageCode,
        ];

        if ($this->isDebug && $exception) {
            $response['debug'] = [
                'exception' => $exception->getMessage(),
                'code'      => $exception->getCode(),
                'file'      => $exception->getFile(),
                'line'      => $exception->getLine(),
            ];

            $lastDbError = DatabaseManager::getLastError();
            if ($lastDbError !== null) {
                $response['debug']['database_error'] = $lastDbError;
            }
        }

        $this->response->json($response, $code);
    }

    /**
     * Convenience: database-specific error
     */
    protected function databaseError(string $message = 'Database error occurred', ?\Throwable $exception = null): void
    {
        $this->error($message, 500, 'DATABASE_ERROR', $exception);
    }

    // ──────────────────────────────────────────────
    //  AUTHORIZATION (DRY – single user assertion)
    // ──────────────────────────────────────────────

    /**
     * Assert the authenticated user is present
     *
     * Every role / ownership check needs a user object.
     * Centralising this avoids null-check duplication.
     *
     * @return object The authenticated user
     * @throws \Exception 401 if no user is attached to request
     */
    private function assertUser(): object
    {
        if ($this->request->user === null) {
            throw new \Exception('Authentication required', 401);
        }

        return $this->request->user;
    }

    /**
     * Check if current user has a specific role
     */
    protected function hasRole(string $role): bool
    {
        return $this->request->user !== null && $this->request->user->role === $role;
    }

    /**
     * Check if current user has any of the specified roles
     */
    protected function hasAnyRole(array $roles): bool
    {
        return $this->request->user !== null && in_array($this->request->user->role, $roles, true);
    }

    /**
     * Require specific role or throw forbidden
     *
     * @throws \Exception 401 if not authenticated, 403 if wrong role
     */
    protected function requireRole(string $role, ?string $message = null): void
    {
        $user = $this->assertUser();

        if ($user->role !== $role) {
            throw new \Exception($message ?? "Only {$role}s can access this resource", 403);
        }
    }

    /**
     * Require any of the specified roles
     *
     * @throws \Exception 401 if not authenticated, 403 if no role matches
     */
    protected function requireAnyRole(array $roles, ?string $message = null): void
    {
        $user = $this->assertUser();

        if (!in_array($user->role, $roles, true)) {
            $roleList = implode(', ', $roles);
            throw new \Exception($message ?? "Only {$roleList} can access this resource", 403);
        }
    }

    /**
     * Check if current user is the owner of the resource
     *
     * Strict integer comparison prevents type-juggling bypass.
     */
    protected function isOwner(int $resourceUserId): bool
    {
        return $this->request->user !== null
            && (int) $this->request->user->user_id === $resourceUserId;
    }

    /**
     * Check if current user is admin
     */
    protected function isAdmin(): bool
    {
        return $this->hasRole('admin');
    }

    /**
     * Require admin role or resource ownership
     *
     * @throws \Exception 401 if not authenticated, 403 if neither admin nor owner
     */
    protected function requireAdminOrOwner(int $resourceUserId, ?string $message = null): void
    {
        $user = $this->assertUser();

        if ($user->role !== 'admin' && (int) $user->user_id !== $resourceUserId) {
            throw new \Exception($message ?? 'You can only access your own resources', 403);
        }
    }

    // ──────────────────────────────────────────────
    //  RESPONSE SHORTHAND (Auto-formatted by Router)
    // ──────────────────────────────────────────────

    /**
     * Set response status code for auto-formatting
     */
    protected function setStatusCode(int $code): void
    {
        $this->request->setResponseStatusCode($code);
    }

    /**
     * Return raw response (auto-formatted by router)
     */
    protected function raw(mixed $data, int $code = 200): mixed
    {
        $this->setStatusCode($code);
        return $data;
    }

    /**
     * Return simple response format
     */
    protected function simple(mixed $data, string $status = 'success', ?string $code = null, int $statusCode = 200): array
    {
        $this->setStatusCode($statusCode);
        return [
            'status' => $status,
            'code'   => $code ?? Router::getStatusCodeName($statusCode),
            'item'   => $data,
        ];
    }

    /**
     * Return created response (HTTP 201)
     */
    protected function created(mixed $data = null): mixed
    {
        $this->setStatusCode(201);
        return $data;
    }

    /**
     * Return no content response (HTTP 204)
     */
    protected function noContent(): null
    {
        $this->setStatusCode(204);
        return null;
    }
}
