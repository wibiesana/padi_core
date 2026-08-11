<?php

declare(strict_types=1);

namespace Wibiesana\Padi\Core;

use PDO;

abstract class ActiveRecord
{
    protected PDO $db;
    protected string $table;
    protected string|array $primaryKey = 'id';
    protected array $fillable = [];
    protected array $hidden = [];
    protected array $with = [];
    protected ?string $defaultOrder = null;

    /**
     * Get table name
     */
    public function getTable(): string
    {
        return $this->table;
    }

    /**
     * Get database connection
     */
    public function getDb(): PDO
    {
        return $this->db;
    }

    /**
     * Enable automatic audit fields (created_at, updated_at, created_by, updated_by)
     * Set to false to disable, or override `$auditFields` to map custom names per model
     */
    protected bool $useAudit = true;

    /**
     * Audit field names. Models can override this to use different column names.
     * Example: ['created_at' => 'created_at', 'updated_at' => 'updated_at', 'created_by' => 'created_by', 'updated_by' => 'updated_by']
     */
    protected array $auditFields = [];

    /**
     * Timestamp format for audit fields
     * 'datetime' - MySQL DATETIME format (Y-m-d H:i:s)
     * 'unix' - Unix timestamp (integer)
     */
    protected string $timestampFormat = 'datetime';

    /**
     * Cache of table columns to avoid repeated introspection
     * @var array<string,array>
     */
    private static array $columnsCache = [];

    /**
     * Timestamps for columns cache entries (used for TTL-based invalidation)
     * @var array<string,int>
     */
    private static array $columnsCacheTtl = [];

    /**
     * TTL for columns cache in seconds. Default: 3600s (1 hour).
     * Set COLUMNS_CACHE_TTL=0 in .env to cache forever (original behavior).
     * Setting a TTL allows cache to refresh after ALTER TABLE without worker restart.
     */
    private static int $columnsCacheTtlSeconds = 3600;

    /**
     * Clear columns cache.
     * 
     * In worker mode, call this during lifecycle management to free memory.
     * Column metadata is stable so the cache will rebuild as needed.
     */
    public static function clearColumnsCache(): void
    {
        self::$columnsCache    = [];
        self::$columnsCacheTtl = [];
    }

    /**
     * Get the LIKE operator based on the database driver
     * 
     * @return string 'LIKE' or 'ILIKE'
     */
    protected function getLikeOperator(): string
    {
        try {
            $driver = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
            return $driver === 'pgsql' ? 'ILIKE' : 'LIKE';
        } catch (\Exception $e) {
            return 'LIKE';
        }
    }

    /**
     * Database connection name to use
     * Set this in your model to use a specific database connection
     * 
     * @example protected ?string $connection = 'pgsql';
     */
    protected ?string $connection = null;

    public function __construct()
    {
        // Use specified connection or default
        $this->db = Database::connection($this->connection);

        // Load columns cache TTL from env once (static — only set on first instantiation)
        if (self::$columnsCacheTtlSeconds === 3600) {
            $envTtl = Env::get('COLUMNS_CACHE_TTL', '3600');
            self::$columnsCacheTtlSeconds = max(0, (int)$envTtl);
        }
    }

    /**
     * Get the database connection name
     */
    public function getConnectionName(): ?string
    {
        return $this->connection;
    }

    /**
     * Eager load relationships
     * 
     * @param array|string ...$relations Relation names
     */
    public function with(array|string ...$relations): self
    {
        $flat = [];
        foreach ($relations as $relation) {
            if (is_array($relation)) {
                $flat = array_merge($flat, $relation);
            } else {
                if (strpos($relation, ':') === false && strpos($relation, ',') !== false) {
                    $flat = array_merge($flat, array_map('trim', explode(',', $relation)));
                } else {
                    $flat[] = trim($relation);
                }
            }
        }
        $this->with = array_merge($this->with, $flat);
        return $this;
    }

    /**
     * Clear eager-loaded relation definitions on this instance.
     * 
     * Call this when reusing a model instance across multiple queries
     * to prevent relations from accumulating unintentionally.
     */
    public function clearWith(): self
    {
        $this->with = [];
        return $this;
    }

    /**
     * Start a new model-aware query builder
     * 
     * @deprecated Use static::find() instead.
     */
    public static function findBuilder(): ModelQuery
    {
        $instance = new static();
        return new ModelQuery($instance);
    }

    /**
     * Alias for findBuilder()
     * 
     * @deprecated Use static::find() instead.
     */
    public static function findQuery(): ModelQuery
    {
        return static::findBuilder();
    }

    /**
     * Find all records with eager loading
     */
    public function get(array $columns = ['*']): array
    {
        // For simplicity, we'll reuse all() logical here but add relationship loading
        // A real implementation would use a query builder pattern
        $results = $this->all($columns);

        if (!empty($this->with) && !empty($results)) {
            $this->loadRelations($results);
        }

        return $results;
    }

    /**
     * Load relations for result set
     */
    public function loadRelations(array &$results): void
    {
        if (empty($results)) return;

        // Group relations by base name to avoid redundant loads and overwriting
        $groupedRelations = [];
        foreach ($this->with as $relationItem) {
            $baseRelation = $relationItem;
            $nestedPart = null;
            $columnsPart = null;

            // Handle dot notation for nested: "relation.child"
            if (strpos($baseRelation, '.') !== false) {
                [$baseRelation, $nestedPart] = explode('.', $baseRelation, 2);
            }

            // Handle column specification: "relation:id,name"
            if (strpos($baseRelation, ':') !== false) {
                [$baseRelation, $columnsPart] = explode(':', $baseRelation, 2);
            }

            if (!isset($groupedRelations[$baseRelation])) {
                $groupedRelations[$baseRelation] = [
                    'columns' => $columnsPart ? array_map('trim', explode(',', $columnsPart)) : null,
                    'nested' => []
                ];
            }
            if ($nestedPart) {
                $groupedRelations[$baseRelation]['nested'][] = $nestedPart;
            }
        }

        foreach ($groupedRelations as $relation => $config) {
            if (method_exists($this, $relation)) {
                $relationConfig = $this->$relation();
                $columnsOverride = $config['columns'];
                $nestedRelations = $config['nested'];

                // Collect IDs
                $ids = array_column($results, $relationConfig['local_key']);
                $ids = array_filter(array_unique($ids)); // Optimization: Unique IDs only

                if (empty($ids)) continue;

                $placeholders = implode(',', array_fill(0, count($ids), '?'));

                // Determine columns to select
                $selectColumns = $columnsOverride ?? $relationConfig['columns'] ?? ['*'];

                // Ensure the foreign key is selected to allow mapping back to parent
                if ($selectColumns !== ['*'] && !in_array('*', $selectColumns) && $relationConfig['type'] !== 'belongsToMany') {
                    $fk = $relationConfig['foreign_key'];
                    if (!in_array($fk, $selectColumns)) {
                        $selectColumns[] = $fk;
                    }
                }

                $selectStr = implode(',', $selectColumns);

                // Fetch Related Data
                if ($relationConfig['type'] === 'belongsToMany') {
                    $pivotTable = $relationConfig['pivot_table'];
                    $foreignKey = $relationConfig['foreign_key'];
                    $relatedKey = $relationConfig['related_key'];

                    $relatedModel = new $relationConfig['model']();
                    $relatedTable = $relatedModel->getTable();
                    $relatedPk = $relatedModel->primaryKey;

                    $qualifiedCols = implode(',', array_map(fn($c) => "rt.$c", $selectColumns));

                    $sql = "SELECT pt.{$foreignKey} as _pivot_key, {$qualifiedCols} 
                            FROM {$pivotTable} pt 
                            JOIN {$relatedTable} rt ON pt.{$relatedKey} = rt.{$relatedPk}
                            WHERE pt.{$foreignKey} IN ({$placeholders})";

                    $stmt = $this->db->prepare($sql);
                    $stmt->execute(array_values($ids));
                    $relatedData = $stmt->fetchAll(PDO::FETCH_ASSOC);

                    if (method_exists($relatedModel, 'afterLoad')) {
                        $relatedModel->afterLoad($relatedData);
                    }

                    // Handle nested eager loading
                    if (!empty($nestedRelations)) {
                        $relatedModel->with($nestedRelations);
                        $relatedModel->loadRelations($relatedData);
                    }

                    // Group by pivot_key
                    $relatedMap = [];
                    foreach ($relatedData as $item) {
                        $pivotKey = $item['_pivot_key'];
                        unset($item['_pivot_key']);
                        $relatedMap[$pivotKey][] = $item;
                    }

                    // Attach to results
                    foreach ($results as &$result) {
                        $key = $result[$this->primaryKey] ?? null;
                        if ($key === null) continue;
                        $result[$relation] = $relatedMap[$key] ?? [];
                    }
                } else {
                    // Fetch related data for belongsTo and hasMany
                    $relatedModel = new $relationConfig['model']();
                    $sql = "SELECT {$selectStr} FROM {$relatedModel->table} WHERE {$relationConfig['foreign_key']} IN ($placeholders)";

                    $stmt = $this->db->prepare($sql);
                    $stmt->execute(array_values($ids));
                    $relatedData = $stmt->fetchAll(PDO::FETCH_ASSOC);

                    if (method_exists($relatedModel, 'afterLoad')) {
                        $relatedModel->afterLoad($relatedData);
                    }

                    // Handle nested eager loading
                    if (!empty($nestedRelations)) {
                        $relatedModel->with($nestedRelations);
                        $relatedModel->loadRelations($relatedData);
                    } elseif (method_exists($relatedModel, 'getWith') && !empty($relatedModel->getWith())) {
                        $relatedModel->loadRelations($relatedData);
                    }

                    // Group related data by foreign key
                    $relatedMap = [];
                    foreach ($relatedData as $item) {
                        $relatedMap[$item[$relationConfig['foreign_key']]][] = $item;
                    }

                    // Attach to results
                    foreach ($results as &$result) {
                        $key = $result[$relationConfig['local_key']] ?? null;
                        if ($key === null) continue;

                        $result[$relation] = $relatedMap[$key] ?? [];

                        // If belongsTo or hasOne (single item), unwrap array
                        if ($relationConfig['type'] === 'belongsTo' || $relationConfig['type'] === 'hasOne') {
                            $result[$relation] = $result[$relation][0] ?? null;
                        }
                    }
                }
            }
        }
    }

    /**
     * Get defined eager loads
     */
    public function getWith(): array
    {
        return $this->with;
    }

    /**
     * Get relation configuration by name.
     * 
     * Used by ModelQuery::joinWith() to resolve relation definitions
     * into SQL JOIN clauses automatically.
     * 
     * @return array|null Relation config or null if relation method doesn't exist
     */
    public function getRelationConfig(string $name): ?array
    {
        if (method_exists($this, $name)) {
            return $this->$name();
        }
        return null;
    }

    /**
     * Get primary key column name(s).
     */
    public function getPrimaryKeyName(): string|array
    {
        return $this->primaryKey;
    }

    // Relationship helpers
    protected function hasMany(string $model, string $foreignKey, string $localKey = 'id', array $columns = ['*']): array
    {
        return [
            'type' => 'hasMany',
            'model' => $model,
            'foreign_key' => $foreignKey,
            'local_key' => $localKey,
            'columns' => $columns
        ];
    }

    protected function hasOne(string $model, string $foreignKey, string $localKey = 'id', array $columns = ['*']): array
    {
        return [
            'type' => 'hasOne',
            'model' => $model,
            'foreign_key' => $foreignKey,
            'local_key' => $localKey,
            'columns' => $columns
        ];
    }

    protected function belongsTo(string $model, string $foreignKey, string $ownerKey = 'id', array $columns = ['*']): array
    {
        return [
            'type' => 'belongsTo',
            'model' => $model,
            'foreign_key' => $ownerKey, // In related table
            'local_key' => $foreignKey,  // In this table
            'columns' => $columns
        ];
    }

    protected function belongsToMany(string $model, string $pivotTable, string $foreignKey, string $relatedKey, array $columns = ['*']): array
    {
        return [
            'type' => 'belongsToMany',
            'model' => $model,
            'pivot_table' => $pivotTable,
            'foreign_key' => $foreignKey, // key for THIS model in pivot
            'related_key' => $relatedKey, // key for RELATED model in pivot
            'local_key' => $this->primaryKey, // key in THIS model
            'columns' => $columns
        ];
    }

    /**
     * Find all records
     */
    public function all(array $columns = ['*'], ?string $orderBy = null): array
    {
        // Validate column names to prevent SQL injection
        $sanitizedCols = array_map(function ($col) {
            if ($col === '*') return $col;
            // Only allow valid column names (alphanumeric, underscore, and dots for table prefixes)
            if (!preg_match('/^[a-zA-Z0-9_.]+$/', $col)) {
                throw new \InvalidArgumentException("Invalid column name: {$col}");
            }
            return $col;
        }, $columns);

        $cols = implode(', ', $sanitizedCols);

        $orderClause = '';
        $orderBy = $orderBy ?: $this->defaultOrder;
        if ($orderBy) {
            $orderClause = " ORDER BY " . $this->sanitizeOrderBy($orderBy);
        } elseif (is_string($this->primaryKey)) {
            $orderClause = " ORDER BY {$this->primaryKey} DESC";
        }

        $sql = "SELECT {$cols} FROM {$this->table}{$orderClause}";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        Database::logQuery($sql);

        $results = $this->hideFields($stmt->fetchAll(PDO::FETCH_ASSOC));

        if (method_exists($this, 'afterLoad')) {
            $this->afterLoad($results);
        }

        // If 'with' was called on an instance before calling all()
        if (!empty($this->with) && !empty($results)) {
            $this->loadRelations($results);
        }

        return $results;
    }

    /**
     * Start a model-aware query builder for fluent chaining.
     * 
     * Returns a ModelQuery that can be chained with with(), where(),
     * orderBy(), limit(), paginate(), all(), one(), etc.
     * 
     * Usage:
     *   MyModel::find()->with('relation')->orderBy('id DESC')->limit(100)->all();
     *   MyModel::find()->where(['status' => 'active'])->one();
     * 
     * To find by primary key, use findByPk() or findOne():
     *   MyModel::findByPk(5);
     *   MyModel::findOne(5);
     */
    public static function find(): ModelQuery
    {
        $instance = new static();
        return new ModelQuery($instance);
    }

    /**
     * Find record by primary key.
     * 
     *   MyModel::findByPk(5);
     *   MyModel::findByPk(5, ['id', 'name']);
     *   MyModel::findByPk([1, 2]); // composite PK
     */
    public static function findByPk(int|string|array $id, array $columns = ['*']): ?array
    {
        $instance = new static();

        $sanitizedCols = array_map(function ($col) {
            if ($col === '*') return $col;
            if (!preg_match('/^[a-zA-Z0-9_-]+$/', $col)) {
                throw new \InvalidArgumentException("Invalid column name: {$col}");
            }
            return $col;
        }, $columns);

        $cols = implode(', ', $sanitizedCols);

        $conditions = $instance->getPkConditions($id);
        $where = [];
        $params = [];
        foreach ($conditions as $col => $val) {
            $where[] = "{$col} = :pk_{$col}";
            $params["pk_{$col}"] = $val;
        }

        $whereClause = implode(' AND ', $where);
        $sql = "SELECT {$cols} FROM {$instance->table} WHERE {$whereClause} LIMIT 1";

        $stmt = $instance->db->prepare($sql);
        $stmt->execute($params);
        Database::logQuery($sql, $params);

        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($result) {
            $results = [$result];

            if (method_exists($instance, 'afterLoad')) {
                $instance->afterLoad($results);
            }

            if (!empty($instance->with)) {
                $instance->loadRelations($results);
            }
            return $instance->hideFields($results)[0];
        }

        return null;
    }

    /**
     * Pass dynamic static method calls to an instance of the model.
     * Allows static calls like Product::create($data), Product::update($id, $data), Product::delete($id), etc.
     */
    public static function __callStatic(string $method, array $arguments)
    {
        $instance = new static();
        if (method_exists($instance, $method)) {
            return $instance->$method(...$arguments);
        }
        throw new \BadMethodCallException("Method {$method} does not exist on " . static::class);
    }


    /**
     * Find a single record by primary key
     * 
     * Convenience alias for findByPk($id). Supports Yii2-style usage:
     *   MyModel::findOne(5);
     *   MyModel::findOne(5, ['id', 'name']);
     *   $this->modelClass::findOne($id);
     */
    public static function findOne(int|string|array $id, array $columns = ['*']): ?array
    {
        return static::findByPk($id, $columns);
    }

    /**
     * Find multiple records by primary key(s) or a set of conditions
     * 
     * Supports:
     *   MyModel::findAll([1, 2, 3]);                  // By primary keys
     *   MyModel::findAll(['status' => 'active']);     // By conditions
     */
    public static function findAll(array $condition, array $columns = ['*']): array
    {
        $instance = new static();
        
        // Check if the array is a simple list of primary keys (e.g. [1, 2, 3])
        if (isset($condition[0]) && !is_array($condition[0])) {
            $pk = $instance->primaryKey;
            if (is_array($pk)) {
                throw new \InvalidArgumentException("findAll() with list of IDs is not supported for composite primary keys.");
            }
            return static::find()->where([$pk => $condition])->select($columns)->all();
        }

        return static::find()->where($condition)->select($columns)->all();
    }



    /**
     * Find record by ID or throw 404 exception
     * 
     * @throws \Exception When record is not found (HTTP 404)
     */
    public static function findOrFail(int|string|array $id, array $columns = ['*']): array
    {
        $result = static::findOne($id, $columns);
        if ($result === null) {
            $instance = new static();
            throw new \Exception("Record not found in {$instance->table}", 404);
        }
        return $result;
    }

    /**
     * Helper to get primary key conditions
     */
    public function getPkConditions(int|string|array $id): array
    {
        $conditions = [];
        if (is_array($this->primaryKey)) {
            if (is_array($id)) {
                foreach ($this->primaryKey as $key) {
                    $conditions[$key] = $id[$key] ?? null;
                }
            } elseif (is_string($id) && strpos($id, '_') !== false) {
                // Support virtual ID string "val1_val2_val3"
                $values = explode('_', $id);
                foreach ($this->primaryKey as $index => $key) {
                    $conditions[$key] = $values[$index] ?? null;
                }
            } else {
                // Fallback for single value passed to composite key (might not be ideal but for simplicity)
                $firstKey = $this->primaryKey[0];
                $conditions[$firstKey] = $id;
            }
        } else {
            /** @var string $pkName */
            $pkName = $this->primaryKey;
            if (is_array($id)) {
                $conditions[$pkName] = $id[$pkName] ?? null;
            } else {
                $conditions[$pkName] = $id; // int|string — safe scalar PK value
            }
        }
        return $conditions;
    }

    /**
     * Find records with conditions
     */
    public function where(array $conditions, array $columns = ['*']): array
    {
        // Validate column names to prevent SQL injection
        $sanitizedCols = array_map(function ($col) {
            if ($col === '*') return $col;
            if (!preg_match('/^[a-zA-Z0-9_.]+$/', $col)) {
                throw new \InvalidArgumentException("Invalid column name: {$col}");
            }
            return $col;
        }, $columns);

        $cols = implode(', ', $sanitizedCols);
        $where = [];
        $params = [];

        foreach ($conditions as $key => $value) {
            // Validate condition keys (allow dots for table prefixes)
            if (!preg_match('/^[a-zA-Z0-9_.]+$/', $key)) {
                throw new \InvalidArgumentException("Invalid condition key: {$key}");
            }
            $where[] = "{$key} = :{$key}";
            $params[$key] = $value;
        }

        $whereClause = implode(' AND ', $where);
        $sql = "SELECT {$cols} FROM {$this->table} WHERE {$whereClause}";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        Database::logQuery($sql, $params);

        $results = $this->hideFields($stmt->fetchAll(PDO::FETCH_ASSOC));

        if (method_exists($this, 'afterLoad')) {
            $this->afterLoad($results);
        }

        if (!empty($this->with) && !empty($results)) {
            $this->loadRelations($results);
        }

        return $results;
    }

    /**
     * Create new record
     */
    public function create(array $data): int|string
    {
        $data = $this->filterFillable($data);

        if (!$this->beforeSave($data, true)) {
            return 0;
        }

        $columns = implode(', ', array_keys($data));
        $placeholders = ':' . implode(', :', array_keys($data));

        $sql = "INSERT INTO {$this->table} ({$columns}) VALUES ({$placeholders})";

        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($data);
            Database::logQuery($sql, $data);

            $id = $this->db->lastInsertId();

            // Add ID to data for afterSave (only for non-composite primary keys)
            if (is_string($this->primaryKey)) {
                $data[$this->primaryKey] = $id;
            }
            $this->afterSave(true, $data);

            // Invalidate cache
            Cache::delete("table_count:{$this->table}");

            return $id;
        } catch (\PDOException $e) {
            Database::logQueryError($e, $sql, $data);
            throw $e;
        }
    }

    /**
     * Update record by ID
     */
    public function update(int|string|array $id, array $data): bool
    {
        $data = $this->filterFillable($data);

        if (!$this->beforeSave($data, false)) {
            return false;
        }

        $set = [];
        $params = [];
        foreach ($data as $key => $value) {
            $set[] = "{$key} = :val_{$key}";
            $params["val_{$key}"] = $value;
        }

        $setClause = implode(', ', $set);

        $conditions = $this->getPkConditions($id);
        $where = [];
        foreach ($conditions as $col => $val) {
            $where[] = "{$col} = :pk_{$col}";
            $params["pk_{$col}"] = $val;
        }
        $whereClause = implode(' AND ', $where);

        $sql = "UPDATE {$this->table} SET {$setClause} WHERE {$whereClause}";

        try {
            $stmt = $this->db->prepare($sql);
            $result = $stmt->execute($params);
            Database::logQuery($sql, $params);

            if ($result) {
                // For afterSave, we merge ID into data if possible
                $saveData = $data;
                if (!is_array($id)) {
                    $saveData['id'] = $id;
                } else {
                    $saveData = array_merge($saveData, $id);
                }
                $this->afterSave(false, $saveData);
            }

            return $result;
        } catch (\PDOException $e) {
            Database::logQueryError($e, $sql, $params);
            throw $e;
        }
    }


    /**
     * Batch insert multiple records
     * 
     * Automatically chunks large datasets to respect max_allowed_packet
     * limits on shared hosting (typically 1MB-16MB).
     * 
     * @param array $rows Array of records to insert
     * @param int $chunkSize Max rows per INSERT statement (default 500)
     */
    public function batchInsert(array $rows, int $chunkSize = 500): bool
    {
        if (empty($rows)) {
            return false;
        }

        $chunks = array_chunk($rows, $chunkSize);
        $success = true;

        foreach ($chunks as $chunk) {
            $preparedRows = [];
            foreach ($chunk as $row) {
                $row = $this->filterFillable($row);
                if ($this->beforeSave($row, true)) {
                    $preparedRows[] = $row;
                }
            }

            if (empty($preparedRows)) {
                continue;
            }

            // Use keys from the first row to determine columns
            $firstRow = reset($preparedRows);
            $columns = array_keys($firstRow);
            $columnNames = implode(', ', $columns);

            $values = [];
            $params = [];

            foreach ($preparedRows as $index => $row) {
                $rowPlaceholders = [];
                foreach ($columns as $col) {
                    // Use index to make unique param names
                    $paramName = ":{$col}_{$index}";
                    $rowPlaceholders[] = $paramName;
                    $params[$paramName] = $row[$col] ?? null;
                }
                $values[] = '(' . implode(', ', $rowPlaceholders) . ')';
            }

            $valuesClause = implode(', ', $values);
            $sql = "INSERT INTO {$this->table} ({$columnNames}) VALUES {$valuesClause}";

            try {
                $stmt = $this->db->prepare($sql);
                $result = $stmt->execute($params);
                Database::logQuery($sql, array_slice($params, 0, 10));

                if (!$result) {
                    $success = false;
                }
            } catch (\PDOException $e) {
                Database::logQueryError($e, $sql, array_slice($params, 0, 10));
                throw $e;
            }
        }

        if ($success) {
            Cache::delete("table_count:{$this->table}");
        }

        return $success;
    }

    /**
     * Update multiple records matching conditions
     * 
     * @param array $data Data to update
     * @param array $conditions Key-value pairs for WHERE clause
     */
    public function updateAll(array $data, array $conditions = []): int
    {
        $data = $this->filterFillable($data);

        if (!$this->beforeSave($data, false)) {
            return 0;
        }

        $set = [];
        $params = [];

        // Prepare SET clause with prefixed params to avoid collision
        foreach ($data as $key => $value) {
            $set[] = "{$key} = :update_{$key}";
            $params["update_{$key}"] = $value;
        }

        $setClause = implode(', ', $set);

        // Prepare WHERE clause
        $where = [];
        foreach ($conditions as $key => $value) {
            if (!preg_match('/^[a-zA-Z0-9_.]+$/', $key)) {
                throw new \InvalidArgumentException("Invalid condition key: {$key}");
            }
            $where[] = "{$key} = :where_{$key}";
            $params["where_{$key}"] = $value;
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $sql = "UPDATE {$this->table} SET {$setClause} {$whereClause}";

        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            Database::logQuery($sql, $params);

            return $stmt->rowCount();
        } catch (\PDOException $e) {
            Database::logQueryError($e, $sql, $params);
            throw $e;
        }
    }

    /**
     * Delete record by ID
     */
    public function delete(int|string|array $id): bool
    {
        if (!$this->beforeDelete($id)) {
            return false;
        }

        $conditions = $this->getPkConditions($id);
        $where = [];
        $params = [];
        foreach ($conditions as $col => $val) {
            $where[] = "{$col} = :pk_{$col}";
            $params["pk_{$col}"] = $val;
        }
        $whereClause = implode(' AND ', $where);

        $sql = "DELETE FROM {$this->table} WHERE {$whereClause}";

        try {
            $stmt = $this->db->prepare($sql);
            $result = $stmt->execute($params);
            Database::logQuery($sql, $params);

            if ($result) {
                $this->afterDelete($id);
            }

            // Invalidate cache
            Cache::delete("table_count:{$this->table}");

            return $result;
        } catch (\PDOException $e) {
            Database::logQueryError($e, $sql, $params);
            throw $e;
        }
    }

    /**
     * Delete multiple records matching the specified conditions
     * 
     * Supports:
     *   MyModel::deleteAll([1, 2, 3]);                  // By primary keys
     *   MyModel::deleteAll(['status' => 'inactive']);   // By conditions
     * 
     * @param array $conditions Conditions for the WHERE clause or list of primary keys
     * @return int The number of rows deleted
     */
    public static function deleteAll(array $conditions = []): int
    {
        $instance = new static();
        $query = Query::find($instance->connection)->from($instance->table);
        
        if (!empty($conditions)) {
            // Check if the array is a simple list of primary keys (e.g. [1, 2, 3])
            if (isset($conditions[0]) && !is_array($conditions[0])) {
                $pk = $instance->primaryKey;
                if (is_array($pk)) {
                    throw new \InvalidArgumentException("deleteAll() with list of IDs is not supported for composite primary keys.");
                }
                $query->where([$pk => $conditions]);
            } else {
                $query->where($conditions);
            }
        }
        
        $deleted = $query->delete();
        if ($deleted > 0) {
            Cache::delete("table_count:{$instance->table}");
        }
        return $deleted;
    }

    /**
     * Paginate results with optional conditions
     */
    public function paginate(int $page = 1, int $perPage = 10, array $conditions = [], ?string $orderBy = null): array
    {
        $offset = ($page - 1) * $perPage;

        $where = [];
        $params = [];

        foreach ($conditions as $key => $value) {
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $key)) {
                throw new \InvalidArgumentException("Invalid condition key: {$key}");
            }
            $where[] = "{$key} = :{$key}";
            $params[$key] = $value;
        }

        $whereClause = !empty($where) ? ' WHERE ' . implode(' AND ', $where) : '';

        // Cache total count for 5 minutes
        $cacheKey = "table_count:{$this->table}" . ($whereClause ? md5($whereClause . serialize($params)) : '');
        $total = Cache::remember($cacheKey, 300, function () use ($whereClause, $params) {
            $countSql = "SELECT COUNT(*) as total FROM {$this->table}{$whereClause}";
            $countStmt = $this->db->prepare($countSql);
            $countStmt->execute($params);
            Database::logQuery($countSql, $params);
            return $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
        });

        // Get paginated data
        $orderClause = '';
        $orderBy = $orderBy ?: $this->defaultOrder;
        if ($orderBy) {
            $orderClause = " ORDER BY " . $this->sanitizeOrderBy($orderBy);
        } elseif (is_string($this->primaryKey)) {
            $orderClause = " ORDER BY {$this->primaryKey} DESC";
        }

        $sql = "SELECT * FROM {$this->table}{$whereClause}{$orderClause} LIMIT :limit OFFSET :offset";
        $stmt = $this->db->prepare($sql);

        foreach ($params as $key => $value) {
            $stmt->bindValue(":{$key}", $value);
        }

        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $logParams = array_merge($params, ['limit' => $perPage, 'offset' => $offset]);
        Database::logQuery($sql, $logParams);

        $results = $this->hideFields($stmt->fetchAll(PDO::FETCH_ASSOC));

        if (method_exists($this, 'afterLoad')) {
            $this->afterLoad($results);
        }

        if (!empty($this->with) && !empty($results)) {
            $this->loadRelations($results);
        }

        return [
            'data' => $results,
            'meta' => [
                'total' => (int)$total,
                'per_page' => $perPage,
                'current_page' => $page,
                'last_page' => (int)ceil($total / $perPage),
                'from' => $offset + 1,
                'to' => min($offset + $perPage, $total)
            ]
        ];
    }

    /**
     * Execute raw query
     */
    public function query(string $sql, array $params = []): array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        Database::logQuery($sql, $params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Sanitize an ORDER BY clause to prevent SQL injection.
     *
     * Accepts comma-separated segments in the form:
     *   column | table.column | column ASC | table.column DESC
     *
     * Rejects anything containing SQL functions, subqueries, or special characters.
     *
     * @throws \InvalidArgumentException on invalid ORDER BY input
     */
    private function sanitizeOrderBy(string $orderBy): string
    {
        $segments = array_map('trim', explode(',', $orderBy));

        foreach ($segments as $segment) {
            // Allow: column, table.column, alias.column with optional ASC/DESC
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_.]*(?:\s+(?:ASC|DESC))?$/i', $segment)) {
                throw new \InvalidArgumentException(
                    "Invalid ORDER BY segment: '{$segment}'. " .
                        "Only column names and ASC/DESC direction are allowed."
                );
            }
        }

        return implode(', ', $segments);
    }

    /**
     * Filter only fillable fields
     */
    protected function filterFillable(array $data): array
    {
        if (empty($this->fillable)) {
            return $data;
        }

        return array_intersect_key($data, array_flip($this->fillable));
    }

    /**
     * Get table columns (cached with TTL). Uses PDO column metadata when available.
     * 
     * Cache TTL defaults to 3600s so that ALTER TABLE changes are picked up
     * in worker mode without requiring a full process restart.
     * Set COLUMNS_CACHE_TTL=0 in .env to disable TTL (cache forever).
     */
    protected function getTableColumns(): array
    {
        $now = time();
        $ttl = self::$columnsCacheTtlSeconds;

        // Serve from cache if present and not expired
        if (isset(self::$columnsCache[$this->table])) {
            $cachedAt = self::$columnsCacheTtl[$this->table] ?? 0;
            if ($ttl === 0 || ($now - $cachedAt) < $ttl) {
                return self::$columnsCache[$this->table];
            }
            // Expired — fall through to re-fetch
        }

        $columns = [];
        try {
            $sql = "SELECT * FROM {$this->table} LIMIT 1";
            $stmt = $this->db->prepare($sql);
            $stmt->execute();

            $count = $stmt->columnCount();
            for ($i = 0; $i < $count; $i++) {
                $meta = $stmt->getColumnMeta($i);
                if (!empty($meta['name'])) {
                    $columns[] = $meta['name'];
                }
            }
        } catch (\Throwable $e) {
            // Fallback: try information_schema (best-effort, may not work on all DBs)
            try {
                $schemaSql = "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = :table";
                $s = $this->db->prepare($schemaSql);
                $s->execute(['table' => $this->table]);
                $rows = $s->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $r) {
                    if (isset($r['COLUMN_NAME'])) $columns[] = $r['COLUMN_NAME'];
                }
            } catch (\Throwable $_) {
                // give up silently and leave columns empty
                $columns = [];
            }
        }

        self::$columnsCache[$this->table]    = $columns;
        self::$columnsCacheTtl[$this->table] = $now;
        return $columns;
    }

    /**
     * Hide sensitive fields
     */
    public function hideFields(array $data): array
    {
        if (empty($this->hidden)) {
            return $data;
        }

        return array_map(function ($item) {
            foreach ($this->hidden as $field) {
                unset($item[$field]);
            }
            return $item;
        }, $data);
    }
    /**
     * Lifecycle Hook: Called before save (create/update)
     */
    protected function beforeSave(array &$data, bool $insert): bool
    {
        // Automatic audit handling
        if (!$this->useAudit) return true;

        $columns = $this->getTableColumns();

        $defaults = [
            'created_at' => 'created_at',
            'updated_at' => 'updated_at',
            'created_by' => 'created_by',
            'updated_by' => 'updated_by',
        ];

        $fields = array_merge($defaults, $this->auditFields ?: []);

        // Get timestamp value based on format
        $now = $this->timestampFormat === 'unix' ? time() : date('Y-m-d H:i:s');
        $userId = Auth::userId();

        if ($insert) {
            if (in_array($fields['created_at'], $columns) && !isset($data[$fields['created_at']])) {
                $data[$fields['created_at']] = $now;
            }
            if (in_array($fields['updated_at'], $columns) && !isset($data[$fields['updated_at']])) {
                $data[$fields['updated_at']] = $now;
            }
            if (in_array($fields['created_by'], $columns) && !isset($data[$fields['created_by']]) && $userId !== null) {
                $data[$fields['created_by']] = $userId;
            }
            if (in_array($fields['updated_by'], $columns) && !isset($data[$fields['updated_by']]) && $userId !== null) {
                $data[$fields['updated_by']] = $userId;
            }
        } else {
            if (in_array($fields['updated_at'], $columns)) {
                $data[$fields['updated_at']] = $now;
            }
            if (in_array($fields['updated_by'], $columns) && $userId !== null) {
                $data[$fields['updated_by']] = $userId;
            }
        }

        return true;
    }

    /**
     * Lifecycle Hook: Called after records are loaded from database
     */
    public function afterLoad(array &$items): void
    {
        // Override in model
    }

    /**
     * Lifecycle Hook: Called after save (create/update)
     */
    protected function afterSave(bool $insert, array $data): void
    {
        // Override in model
    }

    /**
     * Lifecycle Hook: Called before delete
     */
    protected function beforeDelete(int|string|array $id): bool
    {
        return true;
    }

    /**
     * Lifecycle Hook: Called after delete
     */
    protected function afterDelete(int|string|array $id): void
    {
        // Override in model
    }

    /**
     * Count records with optional conditions
     * 
     * @param array $conditions Key-value pairs for WHERE clause
     */
    public function count(array $conditions = []): int
    {
        $where = [];
        $params = [];

        foreach ($conditions as $key => $value) {
            if (!preg_match('/^[a-zA-Z0-9_.]+$/', $key)) {
                throw new \InvalidArgumentException("Invalid condition key: {$key}");
            }
            $where[] = "{$key} = :{$key}";
            $params[$key] = $value;
        }

        $whereClause = !empty($where) ? ' WHERE ' . implode(' AND ', $where) : '';
        $sql = "SELECT COUNT(*) as total FROM {$this->table}{$whereClause}";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        Database::logQuery($sql, $params);

        return (int)$stmt->fetch(PDO::FETCH_ASSOC)['total'];
    }

    /**
     * Insert or update on duplicate key (MariaDB/MySQL)
     * 
     * Uses INSERT ... ON DUPLICATE KEY UPDATE for atomic upsert operations.
     * 
     * @param array $data Data to insert
     * @param array $updateColumns Columns to update on duplicate (defaults to all data columns)
     * @return int|string Last insert ID
     */
    public function upsert(array $data, array $updateColumns = []): int|string
    {
        $data = $this->filterFillable($data);

        if (!$this->beforeSave($data, true)) {
            return 0;
        }

        $columns = implode(', ', array_keys($data));
        $placeholders = ':' . implode(', :', array_keys($data));

        $updateParts = [];
        $updateCols = !empty($updateColumns) ? $updateColumns : array_keys($data);
        foreach ($updateCols as $col) {
            $updateParts[] = "{$col} = VALUES({$col})";
        }
        $updateClause = implode(', ', $updateParts);

        $sql = "INSERT INTO {$this->table} ({$columns}) VALUES ({$placeholders}) ON DUPLICATE KEY UPDATE {$updateClause}";

        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($data);
            Database::logQuery($sql, $data);

            Cache::delete("table_count:{$this->table}");
            return $this->db->lastInsertId();
        } catch (\PDOException $e) {
            Database::logQueryError($e, $sql, $data);
            throw $e;
        }
    }
}
