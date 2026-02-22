<?php

declare(strict_types=1);

namespace Wibiesana\Padi\Core;

use Exception;

/**
 * Exception thrown when validation fails.
 * Carries validation errors that can be rendered in the response.
 */
class ValidationException extends Exception
{
    protected array $errors;

    public function __construct(array $errors, string $message = 'Validation failed', int $code = 422)
    {
        parent::__construct($message, $code);
        $this->errors = $errors;
    }

    /**
     * Get validation errors
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
