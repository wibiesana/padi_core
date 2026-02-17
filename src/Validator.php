<?php

declare(strict_types=1);

namespace Core;

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
     * Validate data
     */
    public function validate(): bool
    {
        foreach ($this->rules as $field => $rules) {
            $rulesList = is_string($rules) ? explode('|', $rules) : $rules;

            foreach ($rulesList as $rule) {
                $this->validateField($field, $rule);
            }

            // Add to validated data if no errors
            if (!isset($this->errors[$field]) && isset($this->data[$field])) {
                $this->validated[$field] = $this->data[$field];
            }
        }

        return empty($this->errors);
    }

    /**
     * Validate single field
     */
    private function validateField(string $field, string $rule): void
    {
        [$ruleName, $ruleValue] = $this->parseRule($rule);
        $value = $this->data[$field] ?? null;

        switch ($ruleName) {
            case 'required':
                if (empty($value) && $value !== '0') {
                    $this->addError($field, $ruleName, 'The {field} field is required');
                } elseif (is_string($value) && trim($value) === '') {
                    $this->addError($field, $ruleName, 'The {field} field is required');
                }
                break;

            case 'email':
                if (!empty($value) && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $this->addError($field, $ruleName, 'The {field} must be a valid email address');
                }
                break;

            case 'min':
                if (!empty($value) && strlen($value) < $ruleValue) {
                    $this->addError($field, $ruleName, "The {field} must be at least {$ruleValue} characters");
                }
                break;

            case 'max':
                if (!empty($value) && strlen($value) > $ruleValue) {
                    $this->addError($field, $ruleName, "The {field} must not exceed {$ruleValue} characters");
                }
                break;

            case 'numeric':
                if (!empty($value) && !is_numeric($value)) {
                    $this->addError($field, $ruleName, 'The {field} must be a number');
                }
                break;

            case 'integer':
                if (!empty($value) && !filter_var($value, FILTER_VALIDATE_INT)) {
                    $this->addError($field, $ruleName, 'The {field} must be an integer');
                }
                break;

            case 'alpha':
                if (!empty($value) && !ctype_alpha($value)) {
                    $this->addError($field, $ruleName, 'The {field} must contain only letters');
                }
                break;

            case 'alphanumeric':
                if (!empty($value) && !ctype_alnum($value)) {
                    $this->addError($field, $ruleName, 'The {field} must contain only letters and numbers');
                }
                break;

            case 'url':
                if (!empty($value) && !filter_var($value, FILTER_VALIDATE_URL)) {
                    $this->addError($field, $ruleName, 'The {field} must be a valid URL');
                }
                break;

            case 'in':
                $allowed = explode(',', $ruleValue);
                if (!empty($value) && !in_array($value, $allowed)) {
                    $this->addError($field, $ruleName, "The {field} must be one of: {$ruleValue}");
                }
                break;

            case 'unique':
                // Format: unique:table,column,ignoreId,idColumn
                $params = explode(',', $ruleValue);
                $table = $params[0] ?? '';
                $column = $params[1] ?? '';
                $ignoreId = $params[2] ?? null;
                $idColumn = $params[3] ?? 'id';

                if (!empty($value) && $this->checkUnique($table, $column, $value, $ignoreId, $idColumn)) {
                    $this->addError($field, $ruleName, "The {field} has already been taken");
                }
                break;

            case 'confirmed':
                $confirmField = $field . '_confirmation';
                if ($value !== ($this->data[$confirmField] ?? null)) {
                    $this->addError($field, $ruleName, 'The {field} confirmation does not match');
                }
                break;

            case 'date':
                if (!empty($value) && !strtotime($value)) {
                    $this->addError($field, $ruleName, 'The {field} must be a valid date');
                }
                break;

            case 'boolean':
                if (!empty($value) && !in_array($value, [true, false, 0, 1, '0', '1'], true)) {
                    $this->addError($field, $ruleName, 'The {field} must be true or false');
                }
                break;
        }
    }

    /**
     * Parse rule string
     */
    private function parseRule(string $rule): array
    {
        if (strpos($rule, ':') !== false) {
            [$name, $value] = explode(':', $rule, 2);
            return [$name, $value];
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

        // Replace {field} placeholder
        $message = str_replace('{field}', $field, $message);

        if (!isset($this->errors[$field])) {
            $this->errors[$field] = [];
        }

        $this->errors[$field][] = $message;
    }

    /**
     * Check if value is unique in database
     */
    private function checkUnique(string $table, string $column, $value, $ignoreId = null, string $idColumn = 'id'): bool
    {
        try {
            // Validate table and column names to prevent SQL injection
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

            $db = Database::getInstance()->getConnection();
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $result = $stmt->fetch(\PDO::FETCH_ASSOC);

            return $result['count'] > 0;
        } catch (\Exception $e) {
            // Log error in debug mode
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
     * Get validated data
     */
    public function validated(): array
    {
        return $this->validated;
    }
}
