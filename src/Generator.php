<?php

declare(strict_types=1);

namespace Wibiesana\Padi\Core;

/**
 * Code Generator - Similar to Yii's Gii
 * Generate ActiveRecord, Controller, and Routes automatically
 */
class Generator
{
    private string $baseDir;
    private $db;

    /**
     * Protected tables that should not be auto-generated
     * These are core tables with custom logic
     */
    private array $protectedTables = [
        'users',
        'password_resets',
        'migrations'
    ];

    private array $tablesCache = [];
    private array $schemaCache = [];
    private array $foreignKeysCache = [];
    private array $columnUniqueCache = [];

    public function __construct()
    {
        $this->baseDir = defined('PADI_ROOT') ? PADI_ROOT : dirname(__DIR__, 4);
        $this->db = Database::connection();
    }

    /**
     * Check if table is protected
     */
    private function isProtectedTable(string $tableName): bool
    {
        return in_array(strtolower($tableName), $this->protectedTables);
    }

    /**
     * Generate ActiveRecord from database table
     */
    public function generateModel(string $tableName, array $options = []): bool
    {
        // Skip protected tables unless force flag is set
        if ($this->isProtectedTable($tableName) && !($options['force'] ?? false)) {
            echo "⚠️  Table '{$tableName}' is protected. Skipping model generation.\n";
            echo "   Use --force flag to regenerate (not recommended).\n";
            return false;
        }

        $modelName = $this->tableNameToModelName($tableName);
        $namespace = $options['namespace'] ?? 'App\\Models';
        $fillable = $options['fillable'] ?? [];
        $hidden = $options['hidden'] ?? [];

        // Get table columns from database
        if (empty($fillable)) {
            $fillable = $this->getTableColumns($tableName);
        }

        // Auto-hide sensitive fields
        $sensitiveFields = ['password', 'token', 'secret', 'key'];
        foreach ($fillable as $column) {
            if (in_array(strtolower($column), $sensitiveFields)) {
                $hidden[] = $column;
            }
        }

        // Detect Primary Key
        $primaryKey = $this->detectPrimaryKey($tableName);

        // 1. Generate Base Model (Always overwrite)
        $baseModelTemplate = $this->getBaseModelTemplate($modelName, $tableName, $fillable, $hidden, $namespace . '\\Base', $primaryKey, $options);
        $baseDir = $this->baseDir . '/app/Models/Base';
        if (!is_dir($baseDir)) mkdir($baseDir, 0755, true);

        $baseFilePath = $baseDir . '/' . $modelName . '.php';
        file_put_contents($baseFilePath, $baseModelTemplate);
        echo "✓ Base Model {$modelName} created at {$baseFilePath}\n";

        // 2. Generate Concrete Model (Only if not exists)
        $concreteFilePath = $this->baseDir . '/app/Models/' . $modelName . '.php';
        if (!file_exists($concreteFilePath) || ($options['force'] ?? false)) {
            $concreteModelTemplate = $this->getConcreteModelTemplate($modelName, $namespace, $options);
            file_put_contents($concreteFilePath, $concreteModelTemplate);
            echo "✓ Concrete Model {$modelName} created at {$concreteFilePath}\n";
        } else {
            echo "ℹ️  Concrete Model {$modelName} already exists. Skipping.\n";
        }

        return true;
    }

    /**
     * Generate Controller with CRUD operations
     */
    public function generateController(string $modelName, array $options = []): bool
    {
        // Check if this is a protected model
        $tableName = $this->modelNameToTableName($modelName);
        if ($this->isProtectedTable($tableName) && !($options['force'] ?? false)) {
            echo "⚠️  Model '{$modelName}' is for protected table. Skipping controller generation.\n";
            echo "   Use --force flag to regenerate (not recommended).\n";
            return false;
        }

        $controllerName = $modelName . 'Controller';
        $namespace = $options['namespace'] ?? 'App\\Controllers';
        $modelNamespace = $options['model_namespace'] ?? 'App\\Models';

        // 1. Get schema and validation rules
        $schema = $this->getTableSchema($tableName);
        $validationRules = $this->generateValidationRules($schema, $tableName);

        // 2. Generate Base Controller (Always overwrite)
        $baseControllerTemplate = $this->getBaseControllerTemplate($modelName, $controllerName, $namespace . '\\Base', $modelNamespace, $validationRules);
        $baseDir = $this->baseDir . '/app/Controllers/Base';
        if (!is_dir($baseDir)) mkdir($baseDir, 0755, true);

        $baseFilePath = $baseDir . '/' . $controllerName . '.php';
        file_put_contents($baseFilePath, $baseControllerTemplate);
        echo "✓ Base Controller {$controllerName} created at {$baseFilePath}\n";

        // 3. Generate Concrete Controller (Only if not exists)
        $concreteFilePath = $this->baseDir . '/app/Controllers/' . $controllerName . '.php';
        if (!file_exists($concreteFilePath) || ($options['force'] ?? false)) {
            $concreteControllerTemplate = $this->getConcreteControllerTemplate($modelName, $controllerName, $namespace, $modelNamespace);
            file_put_contents($concreteFilePath, $concreteControllerTemplate);
            echo "✓ Concrete Controller {$controllerName} created at {$concreteFilePath}\n";
        } else {
            echo "ℹ️  Concrete Controller {$controllerName} already exists. Skipping.\n";
        }

        return true;
    }

    /**
     * Generate API Resource
     */
    public function generateResource(string $modelName, array $options = []): bool
    {
        $tableName = $this->modelNameToTableName($modelName);
        $resourceName = $modelName . 'Resource';
        $namespace = $options['resource_namespace'] ?? 'App\\Resources';

        // 1. Get columns
        $columns = $this->getTableColumns($tableName);

        // Ensure Primary Key(s) are included in Resource
        $primaryKey = $this->detectPrimaryKey($tableName);
        $pkList = is_array($primaryKey) ? $primaryKey : [$primaryKey];
        foreach (array_reverse($pkList) as $pk) {
            if (!in_array($pk, $columns)) {
                array_unshift($columns, $pk);
            }
        }

        // 2. Get relations
        $foreignKeys = $this->getTableForeignKeys($tableName);
        $relations = [];

        foreach ($foreignKeys as $fk) {
            $column = $fk['COLUMN_NAME'];
            $methodName = $this->getRelationName($column);
            $relations[$methodName] = [
                'column' => $column,
                'table' => $fk['REFERENCED_TABLE_NAME']
            ];
        }

        // 3. Generate Template
        $template = $this->getResourceTemplate($resourceName, $namespace, $columns, $relations);

        $resourceDir = $this->baseDir . '/app/Resources';
        if (!is_dir($resourceDir)) mkdir($resourceDir, 0755, true);

        $filePath = $resourceDir . '/' . $resourceName . '.php';

        if (file_exists($filePath) && !($options['force'] ?? false)) {
            echo "ℹ️  Resource {$resourceName} already exists. Skipping.\n";
        } else {
            file_put_contents($filePath, $template);
            echo "✓ Resource {$resourceName} created successfully at {$filePath}\n";
        }

        return true;
    }

    private function getResourceTemplate(string $resourceName, string $namespace, array $columns, array $relations): string
    {
        $fieldsStr = "";
        foreach ($columns as $col) {
            $fieldsStr .= "            '{$col}' => \$this->{$col},\n";
        }

        $relationsStr = "\n            // Relations\n";
        $flattenedStr = "\n            // Flattened Fields\n";

        foreach ($relations as $method => $relationData) {
            $relationsStr .= "            '{$method}' => \$this->whenLoaded('{$method}'),\n";

            $displayCol = $this->getDisplayColumn($relationData['table']);
            $flattenedStr .= "            '{$method}_name' => \$this->{$method}['{$displayCol}'] ?? null,\n";
        }

        return <<<PHP
<?php

namespace {$namespace};

use Wibiesana\Padi\Core\Resource;

class {$resourceName} extends Resource
{
    public function toArray(\$request): array
    {
        return [
{$fieldsStr}{$relationsStr}{$flattenedStr}
        ];
    }
}
PHP;
    }

    /**
     * Generate Routes for a resource
     */
    public function generateRoutes(string $resourceName, array $options = []): string
    {
        $controllerName = $this->tableNameToModelName($resourceName) . 'Controller';
        $prefix = $options['prefix'] ?? $this->tableNameToRoutePrefix($resourceName);
        $middleware = $options['middleware'] ?? [];
        $protected = $options['protected'] ?? ['index', 'all', 'show', 'store', 'update', 'destroy'];

        $routes = $this->getRoutesTemplate($prefix, $controllerName, $middleware, $protected);

        if ($options['write'] ?? false) {
            $this->appendRoutesToFile($routes, $prefix);
        } else {
            echo "💡 Routes generated. Add this to app/Routes/api.php manually or use --write flag:\n\n";
            echo $routes . "\n";
        }

        return $routes;
    }

    /**
     * Generate complete CRUD (Model + Controller + Routes)
     */
    public function generateCrud(string $tableName, array $options = []): bool
    {
        // Skip protected tables unless force flag is set
        if ($this->isProtectedTable($tableName) && !($options['force'] ?? false)) {
            echo "⚠️  Table '{$tableName}' is a protected core table. Skipping CRUD generation.\n";
            echo "   Protected tables: " . implode(', ', $this->protectedTables) . "\n";
            echo "   Use --force flag to regenerate (not recommended).\n\n";
            return false;
        }

        echo "Generating CRUD for table: {$tableName}\n";
        echo str_repeat('=', 60) . "\n\n";

        // Generate Model
        echo "1. Generating Model...\n";
        $this->generateModel($tableName, $options);


        // Generate Controller
        echo "\n2. Generating Controller...\n";
        $modelName = $this->tableNameToModelName($tableName);
        $this->generateController($modelName, $options);

        // Generate Resource
        echo "\n3. Generating Resource...\n";
        $this->generateResource($modelName, $options);

        // Generate Routes
        echo "\n4. Generating Routes...\n";
        $this->generateRoutes($tableName, $options);

        // Generate API Collection
        echo "\n5. Generating API Collection...\n";
        $this->generatePostmanCollection($tableName, $options);

        echo "\n" . str_repeat('=', 60) . "\n";
        echo "✓ CRUD generation completed!\n";

        return true;
    }

    /**
     * Generate CRUD for all tables in the database
     */
    public function generateCrudAll(array $options = []): void
    {
        $tables = $this->getAllTables();
        foreach ($tables as $table) {
            // Options are passed down, e.g. for write flag or forcing overwrite
            $this->generateCrud($table, $options);
        }
    }

    /**
     * Get table columns from database
     */
    private function getTableColumns(string $tableName): array
    {
        // Detect Primary Key to exclude it if it's auto-increment
        $schema = $this->getTableSchema($tableName);
        $columns = array_keys($schema);
        $primaryKey = $this->detectPrimaryKey($tableName);
        $pkList = is_array($primaryKey) ? $primaryKey : [$primaryKey];

        // Exclude common auto-generated columns (including audit fields)
        $exclude = ['created_at', 'updated_at', 'created_by', 'updated_by'];

        foreach ($pkList as $pk) {
            if (strpos($schema[$pk]['Extra'] ?? '', 'auto_increment') !== false) {
                $exclude[] = $pk;
            }
        }

        // Also exclude legacy 'id' if explicitly present and we want to keep it consistent
        if (isset($schema['id']) && !in_array('id', $exclude)) {
            // If 'id' exists but isn't the PK, it might still be auto-increment in some designs?
            // But usually it WOULD be the PK. Let's stick to the explicit excludes.
        }

        return array_diff($columns, $exclude);
    }

    /**
     * Detect which audit columns exist in table
     */
    private function detectAuditColumns(string $tableName): array
    {
        $schema = $this->getTableSchema($tableName);
        $columns = array_keys($schema);

        $auditColumns = [];
        $possibleAudit = ['created_at', 'updated_at', 'created_by', 'updated_by'];

        foreach ($possibleAudit as $col) {
            if (in_array($col, $columns)) {
                $auditColumns[] = $col;
            }
        }

        return $auditColumns;
    }

    /**
     * Detect timestamp format based on column type (INT = unix, DATETIME/TIMESTAMP = datetime)
     */
    private function detectTimestampFormat(string $tableName): string
    {
        $schema = $this->getTableSchema($tableName);

        // Check created_at or updated_at column type
        foreach (['created_at', 'updated_at'] as $col) {
            if (isset($schema[$col])) {
                $type = strtolower($schema[$col]['Type'] ?? '');

                // Check if it's an integer type
                if (strpos($type, 'int') !== false || strpos($type, 'bigint') !== false) {
                    return 'unix';
                }

                // Default to datetime for DATETIME, TIMESTAMP, etc.
                return 'datetime';
            }
        }

        return 'datetime'; // Default fallback
    }

    /**
     * Get full table schema from database
     */
    private function getTableSchema(string $tableName): array
    {
        if (isset($this->schemaCache[$tableName])) {
            return $this->schemaCache[$tableName];
        }
        try {
            $stmt = $this->db->query("DESCRIBE {$tableName}");
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            $schema = [];
            foreach ($rows as $row) {
                $schema[$row['Field']] = $row;
            }
            $this->schemaCache[$tableName] = $schema;
            return $schema;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Generate validation rules based on table schema.
     * Returns ['store' => [...], 'update' => [...]] where:
     *   - store  rules use 'required' for non-nullable columns without defaults
     *   - update rules replace 'required' with 'sometimes' (safe for partial PUT/PATCH)
     */
    private function generateValidationRules(array $schema, string $tableName): array
    {
        $storeRules  = [];
        $updateRules = [];

        $pks    = $this->detectPrimaryKey($tableName);
        $pkList = is_array($pks) ? $pks : [$pks];
        $exclude = array_merge(['created_at', 'updated_at', 'deleted_at', 'created_by', 'updated_by'], $pkList);

        foreach ($schema as $column => $info) {
            if (in_array($column, $exclude)) continue;
            if (strpos($info['Extra'] ?? '', 'auto_increment') !== false) continue;

            $storeColumnRules  = [];
            $updateColumnRules = [];

            $isNullable = ($info['Null'] ?? 'YES') === 'YES';
            $hasDefault = ($info['Default'] ?? null) !== null;

            // ── Presence ──────────────────────────────────────────────────
            if (!$isNullable && !$hasDefault) {
                $storeColumnRules[]  = 'required';
                $updateColumnRules[] = 'sometimes'; // partial update safe
            } else {
                // nullable column — still validate when present on update
                $updateColumnRules[] = 'sometimes';
                if ($isNullable) {
                    $storeColumnRules[]  = 'nullable';
                    $updateColumnRules[] = 'nullable';
                }
            }

            // ── Type ──────────────────────────────────────────────────────
            $type = strtolower($info['Type'] ?? '');

            if ($type === 'tinyint(1)') {
                // Boolean flag columns
                $storeColumnRules[]  = 'boolean';
                $updateColumnRules[] = 'boolean';
            } elseif (strpos($type, 'int') !== false) {
                $storeColumnRules[]  = 'integer';
                $updateColumnRules[] = 'integer';
            } elseif (strpos($type, 'decimal') !== false || strpos($type, 'float') !== false || strpos($type, 'double') !== false) {
                $storeColumnRules[]  = 'numeric';
                $updateColumnRules[] = 'numeric';
            } elseif (strpos($type, 'varchar') !== false || strpos($type, 'char') !== false) {
                $storeColumnRules[]  = 'string';
                $updateColumnRules[] = 'string';
            } elseif (strpos($type, 'text') !== false) {
                // text/longtext — no max length constraint
                $storeColumnRules[]  = 'string';
                $updateColumnRules[] = 'string';
            } elseif (strpos($type, 'datetime') !== false || strpos($type, 'timestamp') !== false) {
                $storeColumnRules[]  = 'date_format:Y-m-d H:i:s';
                $updateColumnRules[] = 'date_format:Y-m-d H:i:s';
            } elseif (strpos($type, 'date') !== false) {
                $storeColumnRules[]  = 'date_format:Y-m-d';
                $updateColumnRules[] = 'date_format:Y-m-d';
            } elseif (strpos($type, 'json') !== false) {
                $storeColumnRules[]  = 'json';
                $updateColumnRules[] = 'json';
            }

            // ── Max length for varchar/char ────────────────────────────────
            if (preg_match('/(varchar|char)\((\d+)\)/', $type, $matches)) {
                $storeColumnRules[]  = 'max:' . $matches[2];
                $updateColumnRules[] = 'max:' . $matches[2];
            }

            // ── Semantic checks ───────────────────────────────────────────
            if (str_contains(strtolower($column), 'email')) {
                $storeColumnRules[]  = 'email';
                $updateColumnRules[] = 'email';
            }

            if (str_contains(strtolower($column), 'url') || str_contains(strtolower($column), 'website')) {
                $storeColumnRules[]  = 'url';
                $updateColumnRules[] = 'url';
            }

            if (str_contains(strtolower($column), 'uuid')) {
                $storeColumnRules[]  = 'uuid';
                $updateColumnRules[] = 'uuid';
            }

            // ── Unique key ────────────────────────────────────────────────
            if (($info['Key'] ?? '') === 'UNI') {
                $storeColumnRules[]  = "unique:{$tableName},{$column}";
                // Update: unique ignores the current record's id (appended at runtime)
                $updateColumnRules[] = "unique:{$tableName},{$column}";
            }

            if (!empty($storeColumnRules)) {
                $storeRules[$column]  = implode('|', $storeColumnRules);
            }
            if (!empty($updateColumnRules)) {
                $updateRules[$column] = implode('|', $updateColumnRules);
            }
        }

        return ['store' => $storeRules, 'update' => $updateRules];
    }

    /**
     * Derive a camelCase relation method name from a FK column name
     * e.g. 'fleet_id' -> 'fleet', 'captain_id' -> 'captain'
     */
    private function getRelationName(string $column): string
    {
        $base = $column;
        if (substr($base, -3) === '_id') {
            $base = substr($base, 0, -3);
        }
        return $this->snakeToCamel($base);
    }

    /**
     * Convert Model name to table name
     */
    private function modelNameToTableName(string $modelName): string
    {
        $table = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $modelName));

        // Check if singular table exists first, then try plural
        try {
            $stmt = $this->db->query("SHOW TABLES LIKE '{$table}'");
            $result = $stmt->fetch();
            if ($result) {
                return $table; // Singular table exists
            }
        } catch (\Exception $e) {
            // Continue to try plural
        }

        // Try plural version
        if (substr($table, -1) !== 's') {
            $table .= 's';
        }
        return $table;
    }

    /**
     * Convert table name to Model name
     */
    private function tableNameToModelName(string $tableName): string
    {
        // Handle plural table names more carefully
        // Only remove 's' if it's actually a plural form, not part of a word like 'class'
        $singular = $tableName;

        // Common plural patterns to handle
        if (preg_match('/(.+)ies$/', $tableName, $matches)) {
            // countries -> country
            $singular = $matches[1] . 'y';
        } elseif (preg_match('/(.+)ses$/', $tableName, $matches)) {
            // classes -> class, addresses -> address
            $singular = $matches[1] . 's';
        } elseif (preg_match('/(.+[^s])s$/', $tableName, $matches)) {
            // users -> user, posts -> post (but not class -> clas)
            $singular = $matches[1];
        }

        // Convert snake_case to PascalCase
        return str_replace('_', '', ucwords($singular, '_'));
    }

    /**
     * Convert table name to kebab-case route prefix
     * Follows REST API best practices: post_tags -> post-tags
     */
    private function tableNameToRoutePrefix(string $tableName): string
    {
        // Convert snake_case to kebab-case for routes
        return str_replace('_', '-', strtolower($tableName));
    }

    /**
     * Get foreign keys for a table
     */
    private function getTableForeignKeys(string $tableName): array
    {
        if (isset($this->foreignKeysCache[$tableName])) {
            return $this->foreignKeysCache[$tableName];
        }
        $sql = "
            SELECT 
                COLUMN_NAME, 
                REFERENCED_TABLE_NAME, 
                REFERENCED_COLUMN_NAME 
            FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
            WHERE TABLE_SCHEMA = DATABASE() 
              AND TABLE_NAME = :table 
              AND REFERENCED_TABLE_NAME IS NOT NULL
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['table' => $tableName]);
        $this->foreignKeysCache[$tableName] = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        return $this->foreignKeysCache[$tableName];
    }

    /**
     * Detect the primary key(s) for a table
     */
    private function detectPrimaryKey(string $tableName): string|array
    {
        $schema = $this->getTableSchema($tableName);
        if (empty($schema)) return 'id';

        $pks = [];
        foreach ($schema as $column => $info) {
            if (($info['Key'] ?? '') === 'PRI') {
                $pks[] = $column;
            }
        }

        if (count($pks) === 0) {
            // Fallback to 'id' if it exists, otherwise use first column
            return isset($schema['id']) ? 'id' : array_key_first($schema);
        }

        return count($pks) === 1 ? $pks[0] : $pks;
    }



    /**
     * Get the display column for a table (name, title, username, etc.)
     */
    private function getDisplayColumn(string $tableName): string
    {
        $columns = $this->getTableColumns($tableName);

        // Special case for users table - prioritize username because 'name' might not exist or be unused
        if ($tableName === 'users' && in_array('username', $columns)) {
            return 'username';
        }

        // Priority list of display columns
        $candidates = ['username', 'name', 'nama', 'title', 'judul', 'email', 'full_name', 'code', 'id'];

        foreach ($candidates as $candidate) {
            if (in_array($candidate, $columns)) {
                return $candidate;
            }
        }

        return 'id'; // Fallback
    }

    /**
     * Get all tables in the database
     */
    private function getAllTables(): array
    {
        if (!empty($this->tablesCache)) {
            return $this->tablesCache;
        }
        try {
            $stmt = $this->db->query("SHOW TABLES");
            $this->tablesCache = $stmt->fetchAll(\PDO::FETCH_COLUMN);
            return $this->tablesCache;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Check if a column in a table has a unique index
     */
    private function isColumnUnique(string $tableName, string $column): bool
    {
        $cacheKey = "{$tableName}.{$column}";
        if (isset($this->columnUniqueCache[$cacheKey])) {
            return $this->columnUniqueCache[$cacheKey];
        }
        try {
            $stmt = $this->db->prepare("SHOW INDEX FROM {$tableName} WHERE Column_name = :column AND Non_unique = 0");
            $stmt->execute(['column' => $column]);
            $res = (bool)$stmt->fetch();
            $this->columnUniqueCache[$cacheKey] = $res;
            return $res;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Detect hasMany and hasOne relationships pointing to this table
     */
    private function getInverseRelations(string $tableName): array
    {
        $allTables = $this->getAllTables();
        $relations = [];

        foreach ($allTables as $otherTable) {
            if ($otherTable === $tableName) continue;

            $fks = $this->getTableForeignKeys($otherTable);
            foreach ($fks as $fk) {
                if ($fk['REFERENCED_TABLE_NAME'] === $tableName) {
                    $isUnique = $this->isColumnUnique($otherTable, $fk['COLUMN_NAME']);
                    $type = $isUnique ? 'hasOne' : 'hasMany';

                    $relations[] = [
                        'type' => $type,
                        'table' => $otherTable,
                        'column' => $fk['COLUMN_NAME'],
                        'model' => $this->tableNameToModelName($otherTable)
                    ];
                }
            }
        }

        return $relations;
    }

    /**
     * Get Base Model template
     */
    private function getBaseModelTemplate(string $modelName, string $tableName, array $fillable, array $hidden, string $namespace, string|array $primaryKey = 'id', array $options = []): string
    {
        $fillableStr = "'" . implode("', '", $fillable) . "'";
        $hiddenStr = empty($hidden) ? '' : "'" . implode("', '", $hidden) . "'";

        // Detect audit columns
        $auditColumns = $this->detectAuditColumns($tableName);
        $auditConfig = '';

        if (!empty($auditColumns)) {
            $timestampFormat = $this->detectTimestampFormat($tableName);

            $auditConfig = "\n    /**\n";
            $auditConfig .= "     * Audit fields detected: " . implode(', ', $auditColumns) . "\n";
            $auditConfig .= "     * These will be auto-populated by ActiveRecord\n";
            $auditConfig .= "     */\n";
            $auditConfig .= "    protected bool \$useAudit = true;\n";
            $auditConfig .= "    \n";
            $auditConfig .= "    /**\n";
            $auditConfig .= "     * Timestamp format: '{$timestampFormat}'\n";
            $auditConfig .= "     * 'datetime' = Y-m-d H:i:s (DATETIME/TIMESTAMP columns)\n";
            $auditConfig .= "     * 'unix' = integer timestamp (INT/BIGINT columns)\n";
            $auditConfig .= "     */\n";
            $auditConfig .= "    protected string \$timestampFormat = '{$timestampFormat}';\n";
        }

        // Generate relationships and join logic
        $relationsStr = "";

        // Arrays to hold search fields and join calls for Wibiesana\Padi\Core\Query
        $searchFields = [];
        $joinCalls = [];

        // Get actual Foreign Keys from Database
        $foreignKeys = $this->getTableForeignKeys($tableName);
        $joinedTables = []; // Track used aliases

        // 1. Analyze Foreign Keys for Relationships (belongsTo) & Search Joins
        foreach ($foreignKeys as $fk) {
            $column = $fk['COLUMN_NAME'];
            $relatedTable = $fk['REFERENCED_TABLE_NAME'];

            // Generate relation name
            $methodName = $this->getRelationName($column);
            $relatedModel = $this->tableNameToModelName($relatedTable);

            // Determine unique alias
            $alias = $relatedTable;
            if (isset($joinedTables[$alias]) || $alias === $tableName) {
                // If table already joined or matches main table, use unique alias
                $alias = $relatedTable . '_' . $column;
            }
            $joinedTables[$alias] = true;

            // Add belongsTo relation
            $relationsStr .= "\n    public function {$methodName}()\n";
            $relationsStr .= "    {\n";
            $relationsStr .= "        return \$this->belongsTo(\\App\\Models\\{$relatedModel}::class, '{$column}');\n";
            $relationsStr .= "    }\n";

            // Add join call for Query
            $joinCalls[] = "->leftJoin('{$relatedTable} AS {$alias}', '{$tableName}.{$column} = {$alias}.id')";

            // Identify display column for the related table
            $displayCol = $this->getDisplayColumn($relatedTable);

            // Add related name to search fields using Alias
            $searchFields[] = "['{$alias}.{$displayCol}', 'LIKE', \$keyword]";
        }

        // 2. Analyze Inverse Relationships (hasMany / hasOne)
        $inverseRelations = $this->getInverseRelations($tableName);
        foreach ($inverseRelations as $inv) {
            $type = $inv['type'];
            $relatedModel = $inv['model'];
            $foreignKey = $inv['column'];

            // Generate method name
            $suffix = $this->snakeToCamel($foreignKey);
            if (substr($suffix, -2) === 'Id') $suffix = substr($suffix, 0, -2);

            $methodName = strtolower($relatedModel);
            if ($type === 'hasMany') {
                $methodName .= 's'; // simple pluralization
            }
            $methodName .= 'By' . $suffix;

            $relationsStr .= "\n    public function {$methodName}()\n";
            $relationsStr .= "    {\n";
            $relationsStr .= "        return \$this->{$type}(\\App\\Models\\{$relatedModel}::class, '{$foreignKey}');\n";
            $relationsStr .= "    }\n";
        }

        // 2. Global Search - Relation search fields only (fillable fields are dynamic at runtime)
        $relationSearchFieldsStr = "";
        if (!empty($searchFields)) {
            $relationSearchLines = implode("\n            ", array_map(fn($f) => "\$conditions[] = {$f};", $searchFields));
            $relationSearchFieldsStr = "\n            // Search in related tables\n            {$relationSearchLines}";
        }

        // Format Join calls for template
        $joinCallsStr = !empty($joinCalls) ? implode("\n            ", $joinCalls) : "";

        $pkStr = is_array($primaryKey) ? "['" . implode("', '", $primaryKey) . "']" : "'$primaryKey'";

        // Realtime hooks generation
        $realtimeHooks = '';
        $realtimeImport = '';

        if ($options['realtime'] ?? false) {
            $useQueue = !($options['realtime_sync'] ?? false);
            $resourceName = strtolower($modelName);

            if ($useQueue) {
                $realtimeImport = "\nuse Wibiesana\\Padi\\Core\\Queue;\nuse App\\Jobs\\BroadcastRealtimeJob;";
                $realtimeHooks = <<<PHP
\n
    /**
     * Lifecycle Hook: Called after save (create/update)
     * Automatically broadcasts changes via background queue.
     */
    protected function afterSave(bool \$insert, array \$data): void
    {
        \$event = \$insert ? '{$resourceName}_created' : '{$resourceName}_updated';
        Queue::push(BroadcastRealtimeJob::class, [
            'topic' => '{$resourceName}s',
            'data' => [
                'event' => \$event,
                'data'  => \$data
            ]
        ]);
    }

    /**
     * Lifecycle Hook: Called after delete
     * Automatically broadcasts deletion via background queue.
     */
    protected function afterDelete(int|string|array \$id): void
    {
        Queue::push(BroadcastRealtimeJob::class, [
            'topic' => '{$resourceName}s',
            'data' => [
                'event' => '{$resourceName}_deleted',
                'id'    => \$id
            ]
        ]);
    }
PHP;
            } else {
                $realtimeImport = "\nuse Wibiesana\\Padi\\Core\\Realtime;";
                $realtimeHooks = <<<PHP
\n
    /**
     * Lifecycle Hook: Called after save (create/update)
     * Automatically broadcasts changes via Mercure real-time hub.
     */
    protected function afterSave(bool \$insert, array \$data): void
    {
        \$event = \$insert ? '{$resourceName}_created' : '{$resourceName}_updated';
        Realtime::publish('{$resourceName}s', [
            'event' => \$event,
            'data'  => \$data
        ]);
    }

    /**
     * Lifecycle Hook: Called after delete
     * Automatically broadcasts deletion via Mercure real-time hub.
     */
    protected function afterDelete(int|string|array \$id): void
    {
        Realtime::publish('{$resourceName}s', [
            'event' => '{$resourceName}_deleted',
            'id'    => \$id
        ]);
    }
PHP;
            }
        }

        return <<<PHP
<?php

namespace {$namespace};

use Wibiesana\Padi\Core\ActiveRecord;
use Wibiesana\Padi\Core\ModelQuery;{$realtimeImport}

class {$modelName} extends ActiveRecord
{
    protected string \$table = '{$tableName}';
    protected string|array \$primaryKey = {$pkStr};
    
    protected array \$fillable = [
        {$fillableStr}
    ];
    
    protected array \$hidden = [{$hiddenStr}];
{$auditConfig}
{$relationsStr}
    /**
     * Build global search conditions
     * Searches all fillable fields + related table display columns
     */
    protected function buildSearchConditions(string \$keyword): array
    {
        \$conditions = ['OR'];

        // Search all fillable fields from this table
        foreach (\$this->fillable as \$field) {
            \$conditions[] = ["{\$this->table}.{\$field}", 'LIKE', \$keyword];
        }
{$relationSearchFieldsStr}

        return \$conditions;
    }

    /**
     * Start a model-aware search query builder
     */
    public static function search(string \$keyword): ModelQuery
    {
        \$instance = new static();
        \$conditions = \$instance->buildSearchConditions("%{\$keyword}%");

        return static::find()
            ->select("{\$instance->table}.*")
            {$joinCallsStr}
            ->where(\$conditions);
    }
{$realtimeHooks}
}
PHP;
    }

    private function getConcreteModelTemplate(string $modelName, string $namespace, array $options = []): string
    {
        return <<<PHP
<?php

namespace {$namespace};

use {$namespace}\Base\\{$modelName} as Base{$modelName};

class {$modelName} extends Base{$modelName}
{
    /**
     * Override methods here to add custom logic.
     * Use beforeSave(), afterSave(), etc. for lifecycle hooks.
     */
}
PHP;
    }

    /**
     * Helper to convert snake_case to camelCase
     */
    private function snakeToCamel($string)
    {
        return lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $string))));
    }

    /**
     * Get Base Controller template
     */
    private function getBaseControllerTemplate(string $modelName, string $controllerName, string $namespace, string $modelNamespace, array $validationRules): string
    {
        $tableName = $this->modelNameToTableName($modelName); // Infer table name
        $resourceName = strtolower($modelName);

        $storeRulesStr  = "";
        $updateRulesStr = "";

        // $validationRules is now ['store'=>[...], 'update'=>[...]]
        foreach ($validationRules['store'] ?? [] as $column => $rule) {
            $storeRulesStr .= "            '{$column}' => '{$rule}',\n";
        }
        foreach ($validationRules['update'] ?? [] as $column => $rule) {
            // Unique rule on update must ignore the current record's ID
            if (str_contains($rule, 'unique:')) {
                $updateRulesStr .= "            '{$column}' => '{$rule},' . \$id,\n";
            } else {
                $updateRulesStr .= "            '{$column}' => '{$rule}',\n";
            }
        }

        $storeRules  = rtrim($storeRulesStr,  ",\n");
        $updateRules = rtrim($updateRulesStr, ",\n");

        // Autodetect primary key for default ordering
        $pkDetected = $this->detectPrimaryKey($tableName);
        $pkCol = is_array($pkDetected) ? ($pkDetected[0] ?? 'id') : $pkDetected;

        // Autodetect relations for index eager loading via Real Foreign Keys
        $withRelations = [];
        $sortableRelationsEntries = [];
        $foreignKeys = $this->getTableForeignKeys($tableName);

        foreach ($foreignKeys as $fk) {
            $column = $fk['COLUMN_NAME'];
            $relName = $this->getRelationName($column);
            $refTable = $fk['REFERENCED_TABLE_NAME'];
            $refCol = $fk['REFERENCED_COLUMN_NAME'];
            // Determine display column for the referenced table
            $displayCol = $this->getDisplayColumn($refTable);

            // Default to id,displayCol for safety and performance
            $withRelations[] = "'{$relName}:id,{$displayCol}'";
            $sortableRelationsEntries[] = "            '{$relName}' => ['{$refTable}', '{$relName}', '{$tableName}.{$column}', '{$relName}.{$refCol}', '{$relName}.{$displayCol}']";
        }

        $withRelationsProp = "";
        $withRelationsArray = "[]";
        if (!empty($withRelations)) {
            $arrayContent = implode(",\n        ", $withRelations);
            $withRelationsArray = "[\n        {$arrayContent}\n    ]";
        }
        $withRelationsProp = "\n    /** @var array Relations for eager loading */\n    protected array \$withRelations = {$withRelationsArray};\n";

        $sortableRelationsCode = "";
        if (!empty($sortableRelationsEntries)) {
            $sortableRelationsCode = "        \$sortableRelations = [\n" . implode(",\n", $sortableRelationsEntries) . "\n        ];\n";
        } else {
            $sortableRelationsCode = "        \$sortableRelations = [];\n";
        }

        return <<<PHP
<?php

namespace {$namespace};

use Wibiesana\Padi\Core\Controller;
use Wibiesana\Padi\Core\Request;
use {$modelNamespace}\\{$modelName};
use App\Resources\\{$modelName}Resource;

class {$controllerName} extends Controller
{
    {$withRelationsProp}
    
    /**
     * Get all {$resourceName}s with pagination
     * GET /{$resourceName}s
     */
    public function index()
    {
        \$page = max(1, (int)\$this->request->query('page', 1));
        \$perPage = min(100, max(1, (int)\$this->request->query('per-page', 25)));
        \$search = \$this->request->query('search');
        
        \$sortBy = \$this->request->query('sort_by');
        \$order = strtoupper(\$this->request->query('order', 'asc')) === 'DESC' ? 'DESC' : 'ASC';

{$sortableRelationsCode}
        \$query = \$search ? {$modelName}::search(substr(\$search, 0, 255)) : {$modelName}::find();
        
        if (\$sortBy && isset(\$sortableRelations[\$sortBy])) {
            [\$refTable, \$alias, \$fkCol, \$refCol, \$sortColumn] = \$sortableRelations[\$sortBy];
            if (!\$search) {
                \$query->select('{$tableName}.*')->leftJoin("{\$refTable} AS {\$alias}", "{\$fkCol} = {\$refCol}");
            }
            \$query->orderBy("{\$sortColumn} {\$order}");
        } elseif (\$sortBy) {
            \$query->orderBy("{$tableName}.{\$sortBy} {\$order}");
        } else {
            \$query->orderBy('{$tableName}.{$pkCol} DESC');
        }

        \$result = \$query->with(...\$this->withRelations)
            ->paginate(\$perPage, \$page);

        return {$modelName}Resource::collection(\$result);
    }
    
    /**
     * Get all {$resourceName}s without pagination
     * GET /{$resourceName}s/all
     */
    public function all()
    {
        \$search = \$this->request->query('search');
        \$limit = min(5000, max(1, (int)\$this->request->query('limit', 1000)));
        \$sortBy = \$this->request->query('sort_by');
        \$order = strtoupper(\$this->request->query('order', 'asc')) === 'DESC' ? 'DESC' : 'ASC';

{$sortableRelationsCode}
        \$query = \$search ? {$modelName}::search(substr(\$search, 0, 255)) : {$modelName}::find();
        
        if (\$sortBy && isset(\$sortableRelations[\$sortBy])) {
            [\$refTable, \$alias, \$fkCol, \$refCol, \$sortColumn] = \$sortableRelations[\$sortBy];
            if (!\$search) {
                \$query->select('{$tableName}.*')->leftJoin("{\$refTable} AS {\$alias}", "{\$fkCol} = {\$refCol}");
            }
            \$query->orderBy("{\$sortColumn} {\$order}");
        } elseif (\$sortBy) {
            \$query->orderBy("{$tableName}.{\$sortBy} {\$order}");
        } else {
            \$query->orderBy('{$tableName}.{$pkCol} DESC');
        }

        \$results = \$query->with(...\$this->withRelations)
            ->limit(\$limit)
            ->all();

        return {$modelName}Resource::collection(\$results);
    }
    
    /**
     * Get single {$resourceName}
     * GET /{$resourceName}s/{id}
     */
    public function show()
    {
        \$id = \$this->request->param('id');
        \${$resourceName} = {$modelName}::find()->with(...\$this->withRelations)->findOrFail(\$id);
        
        return {$modelName}Resource::make(\${$resourceName});
    }
    
    /**
     * Create new {$resourceName}
     * POST /{$resourceName}s
     */
    public function store()
    {
        \$validated = \$this->validate([
{$storeRules}
        ]);
        
        try {
            \$id = {$modelName}::create(\$validated);
            \${$resourceName} = {$modelName}::find()->with(...\$this->withRelations)->findOrFail(\$id);
            return \$this->created({$modelName}Resource::make(\${$resourceName}));
        } catch (\PDOException \$e) {
            \$this->databaseError('Failed to create {$resourceName}', \$e);
        }
    }
    
    /**
     * Update {$resourceName}
     * PUT /{$resourceName}s/{id}
     */
    public function update()
    {
        \$id = \$this->request->param('id');
        {$modelName}::findOrFail(\$id);
        
        \$validated = \$this->validate([
{$updateRules}
        ]);
        
        try {
            {$modelName}::update(\$id, \$validated);
            \${$resourceName} = {$modelName}::find()->with(...\$this->withRelations)->findOrFail(\$id);
            return {$modelName}Resource::make(\${$resourceName});
        } catch (\PDOException \$e) {
            \$this->databaseError('Failed to update {$resourceName}', \$e);
        }
    }
    
    /**
     * Delete {$resourceName}
     * DELETE /{$resourceName}s/{id}
     */
    public function destroy()
    {
        \$id = \$this->request->param('id');
        {$modelName}::findOrFail(\$id);
        
        try {
            {$modelName}::delete(\$id);
            return \$this->noContent();
        } catch (\PDOException \$e) {
            \$this->databaseError('Failed to delete {$resourceName}', \$e);
        }
    }
}
PHP;
    }

    /**
     * Get Concrete Controller template
     */
    private function getConcreteControllerTemplate(string $modelName, string $controllerName, string $namespace, string $modelNamespace): string
    {
        return <<<PHP
<?php

namespace {$namespace};

use {$namespace}\Base\\{$controllerName} as Base{$controllerName};

class {$controllerName} extends Base{$controllerName}
{
    /**
     * Override methods here to add custom logic.
     */
}
PHP;
    }

    /**
     * Get Routes template
     */
    private function getRoutesTemplate(string $prefix, string $controllerName, array $middleware, array $protected): string
    {
        // Convert prefix to uppercase for display
        $displayName = strtoupper(str_replace('-', ' ', $prefix));

        // Prepare middleware for protected routes
        $protectedMiddleware = $middleware;
        if (!in_array('AuthMiddleware', $protectedMiddleware)) {
            $protectedMiddleware[] = 'AuthMiddleware';
        }
        $protectedMiddlewareStr = empty($protectedMiddleware) ? '' : ", 'middleware' => ['" . implode("', '", $protectedMiddleware) . "']";

        // Prepare middleware for public routes (exclude AuthMiddleware)
        $publicMiddleware = array_diff($middleware, ['AuthMiddleware']);
        $publicMiddlewareStr = empty($publicMiddleware) ? '' : ", 'middleware' => ['" . implode("', '", $publicMiddleware) . "']";

        // Group actions dynamically based on protected array
        $publicRoutes = [];
        $protectedRoutes = [];

        $allRoutesList = [
            'index'   => "    \$router->get('/', '{$controllerName}@index');           // List {$prefix} with pagination\n",
            'all'     => "    \$router->get('/all', '{$controllerName}@all');         // Get all {$prefix}\n",
            'show'    => "    \$router->get('/{id}', '{$controllerName}@show');       // Get specific item\n",
            'store'   => "    \$router->post('/', '{$controllerName}@store');         // Create new item\n",
            'update'  => "    \$router->put('/{id}', '{$controllerName}@update');     // Update item\n",
            'destroy' => "    \$router->delete('/{id}', '{$controllerName}@destroy'); // Delete item\n",
        ];

        foreach ($allRoutesList as $action => $routeLine) {
            if (in_array($action, $protected, true)) {
                $protectedRoutes[] = $routeLine;
            } else {
                $publicRoutes[] = $routeLine;
            }
        }

        $routes = "// ============================================================================\n";
        $routes .= "// {$displayName} ROUTES\n";
        $routes .= "// ============================================================================\n";

        if (!empty($publicRoutes)) {
            $routes .= "// Public operations for {$prefix}\n";
            $routes .= "\$router->group(['prefix' => '{$prefix}'{$publicMiddlewareStr}], function (\$router) {\n";
            $routes .= implode("", $publicRoutes);
            $routes .= "});\n\n";
        }

        if (!empty($protectedRoutes)) {
            $routes .= "// Protected operations for {$prefix} - requires authentication\n";
            $routes .= "\$router->group(['prefix' => '{$prefix}'{$protectedMiddlewareStr}], function (\$router) {\n";
            $routes .= implode("", $protectedRoutes);
            $routes .= "});\n";
        }
        $routes .= "\n";

        return $routes;
    }

    /**
     * Append routes to api.php file
     */
    private function appendRoutesToFile(string $routes, string $prefix): void
    {
        $filePath = $this->baseDir . '/app/Routes/api.php';
        if (!file_exists($filePath)) return;

        $content = file_get_contents($filePath);

        // Check if routes for this prefix already exist (check both single and double quotes)
        if (
            strpos($content, "['prefix' => '{$prefix}'") !== false ||
            strpos($content, "['prefix' => \"{$prefix}\"") !== false ||
            strpos($content, "[\"prefix\" => '{$prefix}'") !== false ||
            strpos($content, "[\"prefix\" => \"{$prefix}\"") !== false
        ) {
            echo "⚠️ Routes for '{$prefix}' already exist in api.php. Skipping auto-append.\n";
            return;
        }

        // Find the last return statement
        $lastReturnPos = strrpos($content, 'return $router;');

        if ($lastReturnPos !== false) {
            $newContent = substr($content, 0, $lastReturnPos) . "\n" . $routes . "\n" . substr($content, $lastReturnPos);
            file_put_contents($filePath, $newContent);
            echo "✓ Routes for '{$prefix}' automatically appended to app/Routes/api.php\n";
        } else {
            // Just append at the end if no return found
            file_put_contents($filePath, "\n" . $routes, FILE_APPEND);
            echo "✓ Routes for '{$prefix}' appended to end of app/Routes/api.php\n";
        }
    }

    /**
     * Generate API Client Collection for REST API
     */
    public function generatePostmanCollection(string $tableName, array $options = []): bool
    {
        $modelName = $this->tableNameToModelName($tableName);
        $prefix = $options['prefix'] ?? str_replace('_', '-', strtolower($tableName));
        $protected = $options['protected'] ?? ['index', 'all', 'show', 'store', 'update', 'destroy'];

        // Get schema for generating sample data
        $schema = $this->getTableSchema($tableName);
        $sampleData = $this->generateSampleData($schema);

        // Get base URL from env or use default
        $baseUrl = Env::get('APP_URL', 'http://localhost:8000');
        $apiPrefix = '';

        $collection = [
            'info' => [
                'name' => "{$modelName} API",
                'description' => "REST API endpoints for {$modelName} resource",
                'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json',
                '_exporter_id' => '0'
            ],
            'item' => [
                [
                    'name' => "Get All {$modelName}s (Paginated)",
                    'request' => [
                        'method' => 'GET',
                        'header' => [],
                        'url' => [
                            'raw' => "{{base_url}}{$apiPrefix}/{$prefix}?page=1&per-page=25",
                            'host' => ['{{base_url}}'],
                            'path' => [ltrim($apiPrefix, '/'), $prefix],
                            'query' => [
                                ['key' => 'page', 'value' => '1'],
                                ['key' => 'per-page', 'value' => '25']
                            ]
                        ]
                    ],
                    'response' => []
                ],
                [
                    'name' => "Search {$modelName}s",
                    'request' => [
                        'method' => 'GET',
                        'header' => [],
                        'url' => [
                            'raw' => "{{base_url}}{$apiPrefix}/{$prefix}?search=sample",
                            'host' => ['{{base_url}}'],
                            'path' => [ltrim($apiPrefix, '/'), $prefix],
                            'query' => [
                                ['key' => 'search', 'value' => 'sample']
                            ]
                        ]
                    ],
                    'response' => []
                ],

                [
                    'name' => "Get All {$modelName}s (No Pagination)",
                    'request' => [
                        'method' => 'GET',
                        'header' => [],
                        'url' => [
                            'raw' => "{{base_url}}{$apiPrefix}/{$prefix}/all",
                            'host' => ['{{base_url}}'],
                            'path' => [ltrim($apiPrefix, '/'), $prefix, 'all']
                        ]
                    ],
                    'response' => []
                ],
                [
                    'name' => "Get Single {$modelName}",
                    'request' => [
                        'method' => 'GET',
                        'header' => [],
                        'url' => [
                            'raw' => "{{base_url}}{$apiPrefix}/{$prefix}/1",
                            'host' => ['{{base_url}}'],
                            'path' => [ltrim($apiPrefix, '/'), $prefix, '1']
                        ]
                    ],
                    'response' => []
                ],
                [
                    'name' => "Create {$modelName}",
                    'request' => [
                        'method' => 'POST',
                        'header' => [
                            ['key' => 'Content-Type', 'value' => 'application/json']
                        ],
                        'body' => [
                            'mode' => 'raw',
                            'raw' => json_encode($sampleData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
                        ],
                        'url' => [
                            'raw' => "{{base_url}}{$apiPrefix}/{$prefix}",
                            'host' => ['{{base_url}}'],
                            'path' => [ltrim($apiPrefix, '/'), $prefix]
                        ]
                    ],
                    'response' => []
                ],
                [
                    'name' => "Update {$modelName}",
                    'request' => [
                        'method' => 'PUT',
                        'header' => [
                            ['key' => 'Content-Type', 'value' => 'application/json']
                        ],
                        'body' => [
                            'mode' => 'raw',
                            'raw' => json_encode($sampleData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
                        ],
                        'url' => [
                            'raw' => "{{base_url}}{$apiPrefix}/{$prefix}/1",
                            'host' => ['{{base_url}}'],
                            'path' => [ltrim($apiPrefix, '/'), $prefix, '1']
                        ]
                    ],
                    'response' => []
                ],
                [
                    'name' => "Delete {$modelName}",
                    'request' => [
                        'method' => 'DELETE',
                        'header' => [],
                        'url' => [
                            'raw' => "{{base_url}}{$apiPrefix}/{$prefix}/1",
                            'host' => ['{{base_url}}'],
                            'path' => [ltrim($apiPrefix, '/'), $prefix, '1']
                        ]
                    ],
                    'response' => []
                ]
            ],
            'variable' => [
                [
                    'key' => 'base_url',
                    'value' => $baseUrl,
                    'type' => 'string'
                ],
                [
                    'key' => 'token',
                    'value' => '',
                    'type' => 'string'
                ]
            ]
        ];

        // Add auth header to protected endpoints
        if (!empty($protected)) {
            foreach ($collection['item'] as &$item) {
                $method = $item['request']['method'] ?? '';
                $name = $item['name'] ?? '';

                // Check if this endpoint should be protected
                $isProtected = false;
                if (in_array('store', $protected, true) && $method === 'POST') $isProtected = true;
                if (in_array('update', $protected, true) && $method === 'PUT') $isProtected = true;
                if (in_array('destroy', $protected, true) && $method === 'DELETE') $isProtected = true;
                if ($method === 'GET') {
                    if (str_contains($name, 'Single')) {
                        if (in_array('show', $protected, true)) $isProtected = true;
                    } elseif (str_contains($name, 'No Pagination')) {
                        if (in_array('all', $protected, true) || in_array('index', $protected, true)) $isProtected = true;
                    } else {
                        if (in_array('index', $protected, true)) $isProtected = true;
                    }
                }

                if ($isProtected) {
                    $item['request']['header'][] = [
                        'key' => 'Authorization',
                        'value' => 'Bearer {{token}}',
                        'type' => 'text'
                    ];
                }
            }
        }

        // Create api_collection directory if not exists
        $postmanDir = $this->baseDir . '/api_collection';
        if (!is_dir($postmanDir)) {
            mkdir($postmanDir, 0755, true);
        }

        // Save collection to file
        $filename = strtolower($modelName) . '_api_collection.json';
        $filePath = $postmanDir . '/' . $filename;

        $jsonContent = json_encode($collection, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        file_put_contents($filePath, $jsonContent);

        echo "✓ API Collection created at {$filePath}\n";
        echo "  Import this file to Postman or Insomnia to test the API endpoints\n";

        return true;
    }

    /**
     * Generate sample data for API client requests
     */
    private function generateSampleData(array $schema): array
    {
        $data = [];
        $exclude = ['id', 'created_at', 'updated_at', 'deleted_at'];

        foreach ($schema as $column => $info) {
            if (in_array($column, $exclude)) continue;
            if (strpos($info['Extra'] ?? '', 'auto_increment') !== false) continue;

            $type = strtolower($info['Type'] ?? '');
            $columnLower = strtolower($column);
            $isRequired = ($info['Null'] ?? 'YES') === 'NO' && ($info['Default'] ?? null) === null;

            // Use actual column names for API consistency (matching ActiveRecord $fillable)
            $fieldName = $column;

            // Generate appropriate sample value based on column name and type
            if (strpos($columnLower, 'email') !== false) {
                $data[$fieldName] = 'user@example.com';
            } elseif (strpos($columnLower, 'password') !== false) {
                $data[$fieldName] = 'Password123!';
            } elseif (strpos($columnLower, 'phone') !== false) {
                $data[$fieldName] = '+1234567890';
            } elseif (strpos($columnLower, 'url') !== false || strpos($columnLower, 'website') !== false) {
                $data[$fieldName] = 'https://example.com';
            } elseif (strpos($columnLower, 'name') !== false) {
                $data[$fieldName] = 'Sample Name';
            } elseif (strpos($columnLower, 'username') !== false) {
                $data[$fieldName] = 'sampleuser';
            } elseif (strpos($columnLower, 'title') !== false) {
                $data[$fieldName] = 'Sample Title';
            } elseif (strpos($columnLower, 'description') !== false) {
                $data[$fieldName] = 'This is a sample description';
            } elseif (strpos($columnLower, 'content') !== false || strpos($columnLower, 'body') !== false) {
                $data[$fieldName] = 'This is sample content';
            } elseif (strpos($columnLower, 'price') !== false || strpos($columnLower, 'amount') !== false) {
                $data[$fieldName] = 99.99;
            } elseif (strpos($columnLower, 'quantity') !== false || strpos($columnLower, 'stock') !== false) {
                $data[$fieldName] = 10;
            } elseif (strpos($columnLower, 'status') !== false) {
                $data[$fieldName] = 'active';
            } elseif (strpos($columnLower, 'role') !== false) {
                $data[$fieldName] = 'user';
            } elseif (strpos($columnLower, 'date') !== false) {
                $data[$fieldName] = date('Y-m-d');
            } elseif (strpos($columnLower, 'time') !== false) {
                $data[$fieldName] = date('H:i:s');
            } elseif (strpos($columnLower, 'is_') !== false || strpos($columnLower, 'has_') !== false) {
                $data[$fieldName] = true;
            } elseif (strpos($type, 'int') !== false) {
                $data[$fieldName] = $isRequired ? 1 : 0;
            } elseif (strpos($type, 'decimal') !== false || strpos($type, 'float') !== false || strpos($type, 'double') !== false) {
                $data[$fieldName] = $isRequired ? 9.99 : 0.0;
            } elseif (strpos($type, 'bool') !== false || strpos($type, 'tinyint(1)') !== false) {
                $data[$fieldName] = true;
            } elseif (strpos($type, 'json') !== false) {
                $data[$fieldName] = [];
            } elseif (strpos($type, 'text') !== false || strpos($type, 'varchar') !== false) {
                $data[$fieldName] = $isRequired ? 'Sample Text' : 'sample text';
            } else {
                $data[$fieldName] = $isRequired ? 'Required Value' : 'value';
            }

            // Don't include optional fields with default values to keep sample clean
            if (!$isRequired && !in_array($columnLower, ['username', 'email', 'password', 'name', 'title'])) {
                unset($data[$fieldName]);
            }
        }

        return $data;
    }
}
