<?php

declare(strict_types=1);

namespace Core;

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
        $baseNamespace = $namespace . '\\Base';
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

        // 1. Generate Base ActiveRecord (Always overwrite)
        $baseTemplate = $this->getBaseModelTemplate($modelName, $tableName, $fillable, $hidden, $baseNamespace, $primaryKey);
        $baseDir = $this->baseDir . '/app/Models/Base';
        if (!is_dir($baseDir)) mkdir($baseDir, 0755, true);

        $baseFilePath = $baseDir . '/' . $modelName . '.php';
        file_put_contents($baseFilePath, $baseTemplate);
        echo "✓ Base ActiveRecord {$modelName} created/updated at {$baseFilePath}\n";

        // 2. Generate Concrete ActiveRecord (Only if not exists)
        $template = $this->getModelTemplate($modelName, $namespace, $baseNamespace);
        $filePath = $this->baseDir . '/app/Models/' . $modelName . '.php';

        if (file_exists($filePath)) {
            echo "ℹ️  ActiveRecord {$modelName} already exists. Skipping to preserve custom logic.\n";
        } else {
            file_put_contents($filePath, $template);
            echo "✓ ActiveRecord {$modelName} created successfully at {$filePath}\n";
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
        $baseNamespace = $namespace . '\\Base';
        $modelNamespace = $options['model_namespace'] ?? 'App\\Models';

        // 1. Get schema and validation rules
        $tableName = $this->modelNameToTableName($modelName);
        $schema = $this->getTableSchema($tableName);
        $validationRules = $this->generateValidationRules($schema, $tableName);

        // 2. Generate Base Controller (Always overwrite)
        $baseTemplate = $this->getBaseControllerTemplate($modelName, $controllerName, $baseNamespace, $modelNamespace, $validationRules);
        $baseDir = $this->baseDir . '/app/Controllers/Base';
        if (!is_dir($baseDir)) mkdir($baseDir, 0755, true);

        $baseFilePath = $baseDir . '/' . $controllerName . '.php';
        file_put_contents($baseFilePath, $baseTemplate);
        echo "✓ Base Controller {$controllerName} created/updated at {$baseFilePath}\n";

        // 3. Generate Concrete Controller (Only if not exists)
        $template = $this->getControllerTemplate($controllerName, $namespace, $baseNamespace);
        $filePath = $this->baseDir . '/app/Controllers/' . $controllerName . '.php';

        if (file_exists($filePath)) {
            echo "ℹ️  Controller {$controllerName} already exists. Skipping to preserve custom logic.\n";
        } else {
            file_put_contents($filePath, $template);
            echo "✓ Controller {$controllerName} created successfully at {$filePath}\n";
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
            $relationNameBase = $column;
            if (substr($relationNameBase, -3) === '_id') {
                $relationNameBase = substr($relationNameBase, 0, -3);
            }
            $methodName = $this->snakeToCamel($relationNameBase);
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

use Core\Resource;

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
        $protected = $options['protected'] ?? ['store', 'update', 'destroy'];

        $routes = $this->getRoutesTemplate($prefix, $controllerName, $middleware, $protected);

        if ($options['write'] ?? false) {
            $this->appendRoutesToFile($routes, $prefix);
        } else {
            echo "💡 Routes generated. Add this to routes/api.php manually or use --write flag:\n\n";
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

        // Generate Postman Collection
        echo "\n5. Generating Postman Collection...\n";
        $this->generatePostmanCollection($tableName, $options);

        echo "\n" . str_repeat('=', 60) . "\n";
        echo "✓ CRUD generation completed!\n";

        return true;
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
        try {
            $db = Database::getInstance()->getConnection();
            $stmt = $db->query("DESCRIBE {$tableName}");
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            $schema = [];
            foreach ($rows as $row) {
                $schema[$row['Field']] = $row;
            }
            return $schema;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Generate validation rules based on table schema
     */
    private function generateValidationRules(array $schema, string $tableName): array
    {
        $rules = [];
        $pks = $this->detectPrimaryKey($tableName);
        $pkList = is_array($pks) ? $pks : [$pks];
        $exclude = array_merge(['created_at', 'updated_at', 'deleted_at'], $pkList);

        foreach ($schema as $column => $info) {
            if (in_array($column, $exclude)) continue;
            if (strpos($info['Extra'] ?? '', 'auto_increment') !== false) continue;

            $columnRules = [];

            // Required? (Not nullable and no default, but allow if it has a default value)
            $isNullable = ($info['Null'] ?? 'YES') === 'YES';
            $hasDefault = ($info['Default'] ?? null) !== null;

            if (!$isNullable && !$hasDefault) {
                $columnRules[] = 'required';
            }

            // Type rules
            $type = strtolower($info['Type'] ?? '');
            if (strpos($type, 'int') !== false) {
                $columnRules[] = 'integer';
            } elseif (strpos($type, 'decimal') !== false || strpos($type, 'float') !== false || strpos($type, 'double') !== false) {
                $columnRules[] = 'numeric';
            } elseif (strpos($type, 'varchar') !== false || strpos($type, 'char') !== false || strpos($type, 'text') !== false) {
                $columnRules[] = 'string';
            }

            // Max length for varchar
            if (preg_match('/varchar\((\d+)\)/', $type, $matches)) {
                $columnRules[] = 'max:' . $matches[1];
            }

            // Email check
            if (strpos(strtolower($column), 'email') !== false) {
                $columnRules[] = 'email';
            }

            // Unique check
            if (($info['Key'] ?? '') === 'UNI') {
                $columnRules[] = "unique:{$tableName},{$column}";
            }

            if (!empty($columnRules)) {
                $rules[$column] = implode('|', $columnRules);
            }
        }

        return $rules;
    }

    private function getTableNameFromSchema(array $schema): string
    {
        // Guess table name if unknown (fallback)
        foreach ($schema as $info) {
            // Usually there's no easy way to get table name from DESCRIBE result directly
            // but we can pass it down if needed. For now, let's keep it placeholder or pass it.
            return 'TABLE_NAME';
        }
        return 'table';
    }

    /**
     * Convert Model name to table name
     */
    private function modelNameToTableName(string $modelName): string
    {
        $table = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $modelName));

        // Check if singular table exists first, then try plural
        try {
            $db = Database::getInstance()->getConnection();
            $stmt = $db->query("SHOW TABLES LIKE '{$table}'");
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
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
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
     * Get Base Model template
     */
    private function getBaseModelTemplate(string $modelName, string $tableName, array $fillable, array $hidden, string $namespace, string|array $primaryKey = 'id'): string
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
        $joins = [];

        // Arrays to hold search fields and join calls for Core\Query
        $searchFields = [];
        $joinCalls = [];

        // Get actual Foreign Keys from Database
        $foreignKeys = $this->getTableForeignKeys($tableName);
        $joinedTables = []; // Track used aliases

        // 1. Analyze Foreign Keys for Relationships & Search Joins
        foreach ($foreignKeys as $fk) {
            $column = $fk['COLUMN_NAME'];
            $relatedTable = $fk['REFERENCED_TABLE_NAME'];

            // Generate relation name
            $relationNameBase = $column;
            if (substr($relationNameBase, -3) === '_id') {
                $relationNameBase = substr($relationNameBase, 0, -3);
            }
            $methodName = $this->snakeToCamel($relationNameBase);
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
            $searchFields[] = "['LIKE', '{$alias}.{$displayCol}', \$keyword]";
        }

        // 2. Analyze Local Text Columns for Search
        $textColumnKeywords = ['name', 'title', 'description', 'content', 'email', 'username', 'category', 'type', 'status', 'code', 'sku', 'nis', 'nisn'];

        foreach ($fillable as $column) {
            foreach ($textColumnKeywords as $keyword) {
                if (stripos($column, $keyword) !== false) {
                    $searchFields[] = "['LIKE', '{$tableName}.{$column}', \$keyword]";
                    break;
                }
            }
        }

        // Fallback if no search fields found
        if (empty($searchFields) && !empty($fillable)) {
            $searchFields[] = "['LIKE', '{$tableName}.id', \$keyword]";
        }

        // Format Join calls and Search fields for template
        $joinCallsStr = !empty($joinCalls) ? implode("\n            ", $joinCalls) : "";
        $searchFieldsStr = implode(",\n                ", $searchFields);

        // searchPaginate method
        $searchPaginateMethod = <<<PHP
    /**
     * Search with pagination and joins
     */
    public function searchPaginate(string \$keyword, int \$page = 1, int \$perPage = 25, ?string \$orderBy = null): array
    {
        \$query = \Core\Query::find()
            ->select("{\$this->table}.*")
            ->from(\$this->table)
            {$joinCallsStr}
            ->where(['OR',
                {$searchFieldsStr}
            ]);

        if (\$orderBy) {
            \$query->orderBy(\$orderBy);
        } else {
            \$query->orderBy("{\$this->table}.{\$this->primaryKey} DESC");
        }

        \$result = \$query->paginate(\$perPage, \$page);

        if (!empty(\$result['data'])) {
            \$this->loadRelations(\$result['data']);
            \$result['data'] = \$this->hideFields(\$result['data']);
        }

        return [
            'data' => \$result['data'],
            'meta' => [
                'total' => (int)\$result['total'],
                'per_page' => \$result['per_page'],
                'current_page' => \$result['current_page'],
                'last_page' => \$result['last_page'],
                'from' => (\$result['current_page'] - 1) * \$result['per_page'] + 1,
                'to' => min(\$result['current_page'] * \$result['per_page'], \$result['total'])
            ]
        ];
    }
PHP;

        $pkStr = is_array($primaryKey) ? "['" . implode("', '", $primaryKey) . "']" : "'$primaryKey'";

        return <<<PHP
<?php

namespace {$namespace};

use Core\ActiveRecord;

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
{$searchPaginateMethod}

    /**
     * Search {$tableName} (simple limit)
     */
    public function search(string \$keyword, ?string \$orderBy = null): array
    {
        \$query = \Core\Query::find()
            ->select("{\$this->table}.*")
            ->from(\$this->table)
            {$joinCallsStr}
            ->where(['OR',
                {$searchFieldsStr}
            ])
            ->limit(100);

        if (\$orderBy) {
            \$query->orderBy(\$orderBy);
        } else {
            \$query->orderBy("{\$this->table}.{\$this->primaryKey} DESC");
        }

        \$results = \$query->all();

        if (!empty(\$results)) {
            \$this->loadRelations(\$results);
            \$results = \$this->hideFields(\$results);
        }

        return \$results;
    }
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
     * Get Concrete Model template
     */
    private function getModelTemplate(string $modelName, string $namespace, string $baseNamespace): string
    {
        return <<<PHP
<?php

namespace {$namespace};

use {$baseNamespace}\\{$modelName} as BaseModel;

class {$modelName} extends BaseModel
{
    /**
     * Automatically eager load these relations on every query.
     */
    // protected array \$with = [];
}

PHP;
    }

    /**
     * Get Base Controller template
     */
    private function getBaseControllerTemplate(string $modelName, string $controllerName, string $namespace, string $modelNamespace, array $validationRules): string
    {
        $modelVar = lcfirst($modelName);
        $tableName = $this->modelNameToTableName($modelName); // Infer table name
        $resourceName = strtolower($modelName);

        $storeRules = "";
        $updateRules = "";

        foreach ($validationRules as $column => $rule) {
            $storeRules .= "            '{$column}' => '{$rule}',\n";
            // For update, unique rules need to ignore current ID
            if (strpos($rule, 'unique:') !== false) {
                $updateRules .= "            '{$column}' => '{$rule},' . \$id,\n";
            } else {
                $updateRules .= "            '{$column}' => '{$rule}',\n";
            }
        }

        $storeRules = rtrim($storeRules, ",\n");
        $updateRules = rtrim($updateRules, ",\n");

        // Autodetect relations for index eager loading via Real Foreign Keys
        $withRelations = [];
        $foreignKeys = $this->getTableForeignKeys($tableName);

        foreach ($foreignKeys as $fk) {
            $column = $fk['COLUMN_NAME'];
            // Relations logic matching Model generation
            $relationNameBase = $column;
            if (substr($relationNameBase, -3) === '_id') {
                $relationNameBase = substr($relationNameBase, 0, -3);
            }
            $relName = $this->snakeToCamel($relationNameBase);
            // Determine display column for the referenced table
            $displayCol = $this->getDisplayColumn($fk['REFERENCED_TABLE_NAME']);

            // Default to id,displayCol for safety and performance
            $withRelations[] = "'{$relName}:id,{$displayCol}'";
        }

        $withRelationsStr = "";
        if (!empty($withRelations)) {
            $arrayContent = implode(", ", $withRelations);
            $withRelationsStr = "\n        // Auto-generated eager loading\n        \$this->model->with([{$arrayContent}]);\n";
        }

        return <<<PHP
<?php

namespace {$namespace};

use Core\Controller;
use {$modelNamespace}\\{$modelName};
use App\Resources\\{$modelName}Resource;

class {$controllerName} extends Controller
{
    protected {$modelName} \$model;
    
    public function __construct(?\Core\Request \$request = null)
    {
        parent::__construct(\$request);
        \$this->model = new {$modelName}();
    }
    
    /**
     * Get all {$resourceName}s with pagination
     * GET /{$resourceName}s
     */
    public function index()
    {{$withRelationsStr}
        \$page = max(1, (int)\$this->request->query('page', 1)); // Min page 1
        \$perPage = min(100, max(1, (int)\$this->request->query('per-page', 25))); // Max 100 per page
        \$search = \$this->request->query('search');
        
        // Handle Sorting
        \$sortBy = \$this->request->query('sort_by');
        \$order = \$this->request->query('order', 'asc');
        \$orderBy = null;

        if (\$sortBy) {
            \$direction = strtolower(\$order) === 'desc' ? 'DESC' : 'ASC';
            \$orderBy = "{\$sortBy} {\$direction}";
        }

        if (\$search) {
            // Limit search query length to prevent abuse
            \$search = substr(\$search, 0, 255);
            \$result = \$this->model->searchPaginate(\$search, \$page, \$perPage, \$orderBy);
        } else {
            \$result = \$this->model->paginate(\$page, \$perPage, [], \$orderBy);
        }

        return {$modelName}Resource::collection(\$result);
    }
    
    /**
     * Get all {$resourceName}s without pagination
     * GET /{$resourceName}s/all
     */
    public function all()
    {{$withRelationsStr}
        \$search = \$this->request->query('search');

        // Handle Sorting
        \$sortBy = \$this->request->query('sort_by');
        \$order = \$this->request->query('order', 'asc');
        \$orderBy = null;

        if (\$sortBy) {
            \$direction = strtolower(\$order) === 'desc' ? 'DESC' : 'ASC';
            \$orderBy = "{\$sortBy} {\$direction}";
        }

        if (\$search) {
             return {$modelName}Resource::collection(\$this->model->search(\$search, \$orderBy));
        }
        return {$modelName}Resource::collection(\$this->model->all(['*'], \$orderBy));
    }
    
    /**
     * Get single {$resourceName}
     * GET /{$resourceName}s/{id}
     */
    public function show()
    {
        \$id = \$this->request->param('id');{$withRelationsStr}
        \${$resourceName} = \$this->model->find(\$id);
        
        if (!\${$resourceName}) {
            throw new \\Exception('{$modelName} not found', 404);
        }
        
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
            \$id = \$this->model->create(\$validated);
            // Auto-generated eager loading
            {$withRelationsStr}
            \${$resourceName} = \$this->model->find(\$id);
            return \$this->created({$modelName}Resource::make(\${$resourceName}));
        } catch (\\PDOException \$e) {
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
        \${$resourceName} = \$this->model->find(\$id);
        
        if (!\${$resourceName}) {
            throw new \\Exception('{$modelName} not found', 404);
        }
        
        \$validated = \$this->validate([
{$updateRules}
        ]);
        
        try {
            \$this->model->update(\$id, \$validated);
             // Auto-generated eager loading
            {$withRelationsStr}
            return {$modelName}Resource::make(\$this->model->find(\$id));
        } catch (\\PDOException \$e) {
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
        \${$resourceName} = \$this->model->find(\$id);
        
        if (!\${$resourceName}) {
            throw new \\Exception('{$modelName} not found', 404);
        }
        
        try {
            \$this->model->delete(\$id);
            return \$this->noContent();
        } catch (\\PDOException \$e) {
            \$this->databaseError('Failed to delete {$resourceName}', \$e);
        }
    }
}
PHP;
    }

    /**
     * Get Concrete Controller template
     */
    private function getControllerTemplate(string $controllerName, string $namespace, string $baseNamespace): string
    {
        return <<<PHP
<?php

namespace {$namespace};

use {$baseNamespace}\\{$controllerName} as BaseController;

class {$controllerName} extends BaseController
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

        $routes = "// ============================================================================\n";
        $routes .= "// {$displayName} ROUTES - READ OPERATIONS (PUBLIC)\n";
        $routes .= "// ============================================================================\n";
        $routes .= "// Public read access for {$prefix} data\n";
        $routes .= "\$router->group(['prefix' => '{$prefix}'{$publicMiddlewareStr}], function (\$router) {\n";
        $routes .= "    // List & view operations\n";
        $routes .= "    \$router->get('/', '{$controllerName}@index');           // List {$prefix} with pagination\n";
        $routes .= "    \$router->get('/all', '{$controllerName}@all');         // Get all {$prefix}\n";
        $routes .= "    \$router->get('/{id}', '{$controllerName}@show');       // Get specific item\n";
        $routes .= "});\n\n";

        $routes .= "// ============================================================================\n";
        $routes .= "// {$displayName} MANAGEMENT ROUTES (PROTECTED)\n";
        $routes .= "// ============================================================================\n";
        $routes .= "// Modification operations for {$prefix} - requires authentication\n";
        $routes .= "\$router->group(['prefix' => '{$prefix}'{$protectedMiddlewareStr}], function (\$router) {\n";
        $routes .= "    // Modification operations\n";
        $routes .= "    \$router->post('/', '{$controllerName}@store');         // Create new item\n";
        $routes .= "    \$router->put('/{id}', '{$controllerName}@update');     // Update item\n";
        $routes .= "    \$router->delete('/{id}', '{$controllerName}@destroy'); // Delete item\n";
        $routes .= "});\n";
        $routes .= "\n";

        return $routes;
    }

    /**
     * Append routes to api.php file
     */
    private function appendRoutesToFile(string $routes, string $prefix): void
    {
        $filePath = $this->baseDir . '/routes/api.php';
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
            echo "✓ Routes for '{$prefix}' automatically appended to routes/api.php\n";
        } else {
            // Just append at the end if no return found
            file_put_contents($filePath, "\n" . $routes, FILE_APPEND);
            echo "✓ Routes for '{$prefix}' appended to end of routes/api.php\n";
        }
    }

    /**
     * Generate Postman Collection for REST API
     */
    public function generatePostmanCollection(string $tableName, array $options = []): bool
    {
        $modelName = $this->tableNameToModelName($tableName);
        $resourceName = strtolower($modelName);
        $prefix = $options['prefix'] ?? str_replace('_', '-', strtolower($tableName));
        $protected = $options['protected'] ?? ['store', 'update', 'destroy'];

        // Get schema for generating sample data
        $schema = $this->getTableSchema($tableName);
        $sampleData = $this->generateSampleData($schema);

        // Get base URL from env or use default
        $baseUrl = \Core\Env::get('APP_URL', 'http://localhost:8000');
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

                // Check if this endpoint should be protected
                $isProtected = false;
                if (in_array('store', $protected) && $method === 'POST') $isProtected = true;
                if (in_array('update', $protected) && $method === 'PUT') $isProtected = true;
                if (in_array('destroy', $protected) && $method === 'DELETE') $isProtected = true;

                if ($isProtected) {
                    $item['request']['header'][] = [
                        'key' => 'Authorization',
                        'value' => 'Bearer {{token}}',
                        'type' => 'text'
                    ];
                }
            }
        }

        // Create postman directory if not exists
        $postmanDir = $this->baseDir . '/postman';
        if (!is_dir($postmanDir)) {
            mkdir($postmanDir, 0755, true);
        }

        // Save collection to file
        $filename = strtolower($modelName) . '_api_collection.json';
        $filePath = $postmanDir . '/' . $filename;

        $jsonContent = json_encode($collection, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        file_put_contents($filePath, $jsonContent);

        echo "✓ Postman Collection created at {$filePath}\n";
        echo "  Import this file to Postman to test the API endpoints\n";

        return true;
    }

    /**
     * Generate sample data for Postman requests
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
