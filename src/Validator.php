<?php

declare(strict_types=1);

namespace Wibiesana\Padi\Core;

/**
 * Validator - Input Validation Engine
 *
 * Supported rules:
 *   required, sometimes, required_if, required_with, required_without,
 *   string, numeric, integer, boolean, array, json, uuid,
 *   email, url, date, date_format, before, after,
 *   alpha, alpha_dash, alphanumeric, regex,
 *   min, max, between, size, in, not_in,
 *   exists, unique, confirmed, nullable
 *
 * Security:
 * - Table/column names validated against injection
 * - No dynamic SQL without parameterization
 */
class Validator
{
    private array $data;
    private array $rules;
    private array $messages;
    private array $errors = [];
    private array $validated = [];

    public function __construct(array $data, array $rules, array $messages = [])
    {
        $this->data = $data;
        $this->rules = $rules;
        $this->messages = $messages;
    }

    /**
     * Validate all fields against their rules
     */
    public function validate(): bool
    {
        foreach ($this->rules as $field => $rules) {
            $rulesList = is_string($rules) ? explode('|', $rules) : $rules;

            // Handle 'sometimes' — skip entire field if not present in input
            if (in_array('sometimes', $rulesList, true) && !array_key_exists($field, $this->data)) {
                continue;
            }

            foreach ($rulesList as $rule) {
                $this->validateField($field, $rule);
            }

            // Add to validated data if no errors for this field
            if (!isset($this->errors[$field]) && array_key_exists($field, $this->data)) {
                $this->validated[$field] = $this->data[$field];
            }
        }

        return empty($this->errors);
    }

    /**
     * Validate a single field against a single rule
     */
    private function validateField(string $field, string $rule): void
    {
        [$ruleName, $ruleValue] = $this->parseRule($rule);
        $value = $this->data[$field] ?? null;

        match ($ruleName) {
            // Presence & conditional
            'required'         => $this->validateRequired($field, $value),
            'required_if'      => $this->validateRequiredIf($field, $value, $ruleValue),
            'required_with'    => $this->validateRequiredWith($field, $value, $ruleValue),
            'required_without' => $this->validateRequiredWithout($field, $value, $ruleValue),
            'sometimes'        => null, // Handled in validate() loop
            'nullable'         => null, // Skip — allows null values

            // Type
            'string'       => $this->validateString($field, $value),
            'numeric'      => $this->validateNumeric($field, $value),
            'integer'      => $this->validateInteger($field, $value),
            'boolean'      => $this->validateBoolean($field, $value),
            'array'        => $this->validateArray($field, $value),
            'json'         => $this->validateJson($field, $value),

            // Format
            'email'        => $this->validateEmail($field, $value),
            'url'          => $this->validateUrl($field, $value),
            'uuid'         => $this->validateUuid($field, $value),
            'date'         => $this->validateDate($field, $value),
            'date_format'  => $this->validateDateFormat($field, $value, $ruleValue),
            'before'       => $this->validateBefore($field, $value, $ruleValue),
            'after'        => $this->validateAfter($field, $value, $ruleValue),

            // Character
            'alpha'        => $this->validateAlpha($field, $value),
            'alpha_dash'   => $this->validateAlphaDash($field, $value),
            'alphanumeric' => $this->validateAlphanumeric($field, $value),
            'regex'        => $this->validateRegex($field, $value, $ruleValue),

            // Size
            'min'     => $this->validateMin($field, $value, (int)$ruleValue),
            'max'     => $this->validateMax($field, $value, (int)$ruleValue),
            'between' => $this->validateBetween($field, $value, $ruleValue),
            'size'    => $this->validateSize($field, $value, (int)$ruleValue),

            // Set
            'in'     => $this->validateIn($field, $value, $ruleValue),
            'not_in' => $this->validateNotIn($field, $value, $ruleValue),

            // Database
            'exists'    => $this->validateExists($field, $value, $ruleValue),
            'unique'    => $this->validateUnique($field, $value, $ruleValue),
            'confirmed' => $this->validateConfirmed($field, $value),

            default => null, // Unknown rules are silently ignored
        };
    }

    private function validateRequired(string $field, mixed $value): void
    {
        if ($value === null || $value === '') {
            $this->addError($field, 'required', 'The {field} field is required');
        } elseif (is_string($value) && trim($value) === '') {
            $this->addError($field, 'required', 'The {field} field is required');
        }
    }

    private function validateRequiredIf(string $field, mixed $value, ?string $ruleValue): void
    {
        if ($ruleValue === null) return;
        $params = explode(',', $ruleValue, 2);
        $otherField = $params[0] ?? '';
        $otherValue = $params[1] ?? '';

        if (array_key_exists($otherField, $this->data) && (string)($this->data[$otherField] ?? '') === $otherValue) {
            $this->validateRequired($field, $value);
        }
    }

    private function validateRequiredWith(string $field, mixed $value, ?string $ruleValue): void
    {
        if ($ruleValue === null) return;
        foreach (explode(',', $ruleValue) as $otherField) {
            $otherField = trim($otherField);
            if (array_key_exists($otherField, $this->data) && !$this->isEmpty($this->data[$otherField])) {
                $this->validateRequired($field, $value);
                return;
            }
        }
    }

    private function validateRequiredWithout(string $field, mixed $value, ?string $ruleValue): void
    {
        if ($ruleValue === null) return;
        foreach (explode(',', $ruleValue) as $otherField) {
            $otherField = trim($otherField);
            if (!array_key_exists($otherField, $this->data) || $this->isEmpty($this->data[$otherField])) {
                $this->validateRequired($field, $value);
                return;
            }
        }
    }

    private function validateString(string $field, mixed $value): void
    {
        if ($this->isEmpty($value)) return;
        if (!is_string($value)) {
            $this->addError($field, 'string', 'The {field} must be a string');
        }
    }

    private function validateEmail(string $field, mixed $value): void
    {
        if ($this->isEmpty($value)) return;
        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $this->addError($field, 'email', 'The {field} must be a valid email address');
        }
    }

    /**
     * Determine if a field should be treated as numeric based on its type or validation rules
     */
    private function isNumericField(string $field, mixed $value): bool
    {
        if (is_int($value) || is_float($value)) {
            return true;
        }

        if (is_string($value) && is_numeric($value)) {
            $rules = $this->rules[$field] ?? [];
            $rulesList = is_string($rules) ? explode('|', $rules) : $rules;
            foreach ($rulesList as $rule) {
                $colonPos = strpos($rule, ':');
                $ruleName = $colonPos !== false ? substr($rule, 0, $colonPos) : $rule;
                if ($ruleName === 'numeric' || $ruleName === 'integer') {
                    return true;
                }
            }
        }

        return false;
    }

    private function validateMin(string $field, mixed $value, int $minLength): void
    {
        if ($this->isEmpty($value)) return;

        if ($this->isNumericField($field, $value)) {
            if ((float)$value < $minLength) {
                $this->addError($field, 'min', "The {field} must be at least {$minLength}");
            }
        } elseif (is_array($value)) {
            if (count($value) < $minLength) {
                $this->addError($field, 'min', "The {field} must have at least {$minLength} items");
            }
        } elseif (is_string($value) && mb_strlen($value) < $minLength) {
            $this->addError($field, 'min', "The {field} must be at least {$minLength} characters");
        }
    }

    private function validateMax(string $field, mixed $value, int $maxLength): void
    {
        if ($this->isEmpty($value)) return;

        if ($this->isNumericField($field, $value)) {
            if ((float)$value > $maxLength) {
                $this->addError($field, 'max', "The {field} must not exceed {$maxLength}");
            }
        } elseif (is_array($value)) {
            if (count($value) > $maxLength) {
                $this->addError($field, 'max', "The {field} must not have more than {$maxLength} items");
            }
        } elseif (is_string($value) && mb_strlen($value) > $maxLength) {
            $this->addError($field, 'max', "The {field} must not exceed {$maxLength} characters");
        }
    }

    private function validateNumeric(string $field, mixed $value): void
    {
        if ($this->isEmpty($value)) return;
        if (!is_numeric($value)) {
            $this->addError($field, 'numeric', 'The {field} must be a number');
        }
    }

    private function validateInteger(string $field, mixed $value): void
    {
        if ($this->isEmpty($value)) return;
        if (!filter_var($value, FILTER_VALIDATE_INT) && $value !== 0 && $value !== '0') {
            $this->addError($field, 'integer', 'The {field} must be an integer');
        }
    }

    private function validateAlpha(string $field, mixed $value): void
    {
        if ($this->isEmpty($value)) return;
        if (!ctype_alpha((string)$value)) {
            $this->addError($field, 'alpha', 'The {field} must contain only letters');
        }
    }

    private function validateAlphanumeric(string $field, mixed $value): void
    {
        if ($this->isEmpty($value)) return;
        if (!ctype_alnum((string)$value)) {
            $this->addError($field, 'alphanumeric', 'The {field} must contain only letters and numbers');
        }
    }

    private function validateUrl(string $field, mixed $value): void
    {
        if ($this->isEmpty($value)) return;
        if (!filter_var($value, FILTER_VALIDATE_URL)) {
            $this->addError($field, 'url', 'The {field} must be a valid URL');
        }
    }

    private function validateIn(string $field, mixed $value, ?string $ruleValue): void
    {
        if ($this->isEmpty($value) || $ruleValue === null) return;
        $allowed = explode(',', $ruleValue);
        if (!in_array((string)$value, $allowed, true)) {
            $this->addError($field, 'in', "The {field} must be one of: {$ruleValue}");
        }
    }

    private function validateExists(string $field, mixed $value, ?string $ruleValue): void
    {
        if ($this->isEmpty($value) || $ruleValue === null) return;

        // Format: exists:table,column
        $params = explode(',', $ruleValue);
        $table = $params[0] ?? '';
        $column = $params[1] ?? 'id';

        if (!$this->checkUnique($table, $column, $value)) {
            $this->addError($field, 'exists', "The selected {field} does not exist");
        }
    }

    private function validateUnique(string $field, mixed $value, ?string $ruleValue): void
    {
        if ($this->isEmpty($value) || $ruleValue === null) return;

        // Format: unique:table,column,ignoreId,idColumn
        $params = explode(',', $ruleValue);
        $table = $params[0] ?? '';
        $column = $params[1] ?? '';
        $ignoreId = $params[2] ?? null;
        $idColumn = $params[3] ?? 'id';

        if ($this->checkUnique($table, $column, $value, $ignoreId, $idColumn)) {
            $this->addError($field, 'unique', "The {field} has already been taken");
        }
    }

    private function validateConfirmed(string $field, mixed $value): void
    {
        $confirmField = $field . '_confirmation';
        if ($value !== ($this->data[$confirmField] ?? null)) {
            $this->addError($field, 'confirmed', 'The {field} confirmation does not match');
        }
    }

    private function validateDate(string $field, mixed $value): void
    {
        if ($this->isEmpty($value)) return;
        if (!strtotime((string)$value)) {
            $this->addError($field, 'date', 'The {field} must be a valid date');
        }
    }

    private function validateBoolean(string $field, mixed $value): void
    {
        if ($this->isEmpty($value)) return;
        if (!in_array($value, [true, false, 0, 1, '0', '1'], true)) {
            $this->addError($field, 'boolean', 'The {field} must be true or false');
        }
    }

    private function validateArray(string $field, mixed $value): void
    {
        if ($this->isEmpty($value)) return;
        if (!is_array($value)) {
            $this->addError($field, 'array', 'The {field} must be an array');
        }
    }

    private function validateRegex(string $field, mixed $value, ?string $pattern): void
    {
        if ($this->isEmpty($value) || $pattern === null) return;
        if (!preg_match($pattern, (string)$value)) {
            $this->addError($field, 'regex', 'The {field} format is invalid');
        }
    }

    private function validateNotIn(string $field, mixed $value, ?string $ruleValue): void
    {
        if ($this->isEmpty($value) || $ruleValue === null) return;
        $disallowed = explode(',', $ruleValue);
        if (in_array((string)$value, $disallowed, true)) {
            $this->addError($field, 'not_in', 'The selected {field} is invalid');
        }
    }

    private function validateBetween(string $field, mixed $value, ?string $ruleValue): void
    {
        if ($this->isEmpty($value) || $ruleValue === null) return;
        [$min, $max] = array_pad(explode(',', $ruleValue, 2), 2, '0');
        $min = (int)$min;
        $max = (int)$max;

        $size = is_array($value) ? count($value)
            : ($this->isNumericField($field, $value) ? (float)$value : mb_strlen((string)$value));

        if ($size < $min || $size > $max) {
            $this->addError($field, 'between', "The {field} must be between {$min} and {$max}");
        }
    }

    private function validateSize(string $field, mixed $value, int $size): void
    {
        if ($this->isEmpty($value)) return;

        $actual = is_array($value) ? count($value)
            : ($this->isNumericField($field, $value) ? (float)$value : mb_strlen((string)$value));

        if ((float)$actual !== (float)$size) {
            $this->addError($field, 'size', "The {field} must be exactly {$size}");
        }
    }

    private function validateDateFormat(string $field, mixed $value, ?string $format): void
    {
        if ($this->isEmpty($value) || $format === null) return;
        $date = \DateTimeImmutable::createFromFormat($format, (string)$value);
        if (!$date || $date->format($format) !== (string)$value) {
            $this->addError($field, 'date_format', "The {field} does not match the format {$format}");
        }
    }

    private function validateBefore(string $field, mixed $value, ?string $date): void
    {
        if ($this->isEmpty($value) || $date === null) return;
        $fieldTime = strtotime((string)$value);
        $compareTime = strtotime($date);
        if ($fieldTime === false || $compareTime === false || $fieldTime >= $compareTime) {
            $this->addError($field, 'before', "The {field} must be a date before {$date}");
        }
    }

    private function validateAfter(string $field, mixed $value, ?string $date): void
    {
        if ($this->isEmpty($value) || $date === null) return;
        $fieldTime = strtotime((string)$value);
        $compareTime = strtotime($date);
        if ($fieldTime === false || $compareTime === false || $fieldTime <= $compareTime) {
            $this->addError($field, 'after', "The {field} must be a date after {$date}");
        }
    }

    private function validateJson(string $field, mixed $value): void
    {
        if ($this->isEmpty($value)) return;
        if (!is_string($value)) {
            $this->addError($field, 'json', 'The {field} must be a valid JSON string');
            return;
        }
        json_decode($value);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->addError($field, 'json', 'The {field} must be a valid JSON string');
        }
    }

    private function validateUuid(string $field, mixed $value): void
    {
        if ($this->isEmpty($value)) return;
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', (string)$value)) {
            $this->addError($field, 'uuid', 'The {field} must be a valid UUID');
        }
    }

    private function validateAlphaDash(string $field, mixed $value): void
    {
        if ($this->isEmpty($value)) return;
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', (string)$value)) {
            $this->addError($field, 'alpha_dash', 'The {field} must only contain letters, numbers, dashes, and underscores');
        }
    }

    /**
     * Check if a value is considered empty (but allows '0')
     */
    private function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || (is_array($value) && empty($value));
    }

    /**
     * Parse rule string into name and value
     */
    private function parseRule(string $rule): array
    {
        $colonPos = strpos($rule, ':');
        if ($colonPos !== false) {
            return [substr($rule, 0, $colonPos), substr($rule, $colonPos + 1)];
        }

        return [$rule, null];
    }

    /**
     * Add validation error
     */
    private function addError(string $field, string $rule, string $message): void
    {
        $key = "{$field}.{$rule}";

        // Use custom message if provided
        if (isset($this->messages[$key])) {
            $message = $this->messages[$key];
        } elseif (isset($this->messages[$field])) {
            $message = $this->messages[$field];
        }

        $message = str_replace('{field}', $field, $message);

        $this->errors[$field] ??= [];
        $this->errors[$field][] = $message;
    }

    /**
     * Check if value is unique in database
     */
    private function checkUnique(string $table, string $column, mixed $value, ?string $ignoreId = null, string $idColumn = 'id'): bool
    {
        try {
            // Validate identifiers to prevent SQL injection
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
                throw new \InvalidArgumentException("Invalid table name: {$table}");
            }
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $column)) {
                throw new \InvalidArgumentException("Invalid column name: {$column}");
            }
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $idColumn)) {
                throw new \InvalidArgumentException("Invalid id column name: {$idColumn}");
            }

            $sql = "SELECT COUNT(*) as count FROM {$table} WHERE {$column} = :value";
            $params = ['value' => $value];

            if ($ignoreId !== null && $ignoreId !== '') {
                $sql .= " AND {$idColumn} != :ignoreId";
                $params['ignoreId'] = $ignoreId;
            }

            $db = Database::connection();
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $result = $stmt->fetch(\PDO::FETCH_ASSOC);

            return ($result['count'] ?? 0) > 0;
        } catch (\Exception $e) {
            if (Env::get('APP_DEBUG') === 'true') {
                error_log("Validator checkUnique error: " . $e->getMessage());
            }
            return false;
        }
    }

    /**
     * Get validation errors
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * Get validated data (only fields that passed validation)
     */
    public function validated(): array
    {
        return $this->validated;
    }
}
