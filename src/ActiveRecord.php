<?php

declare(strict_types=1);

namespace Core;

use PDO;
use Core\Query;
use Core\Auth;

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
    }

    /**
     * Eager load relationships
     */
    public function with(array|string $relations): self
    {
        if (is_string($relations)) {
            $relations = explode(',', $relations);
        }

        $this->with = array_merge($this->with, $relations);
        return $this;
    }

    /**
     * Start a new query builder for this model
     */
    public static function findBuilder(): Query
    {
        $instance = new static();
        return (new Query($instance->connection))->from($instance->table);
    }

    /**
     * Alias for findBuilder()
     */
    public static function findQuery(): Query
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

                        // If belongsTo (single item), unwrap array
                        if ($relationConfig['type'] === 'belongsTo') {
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
            // Only allow valid column names (alphanumeric and underscore)
            if (!preg_match('/^[a-zA-Z0-9_-]+$/', $col)) {
                throw new \InvalidArgumentException("Invalid column name: {$col}");
            }
            return $col;
        }, $columns);

        $cols = implode(', ', $sanitizedCols);

        $orderClause = '';
        $orderBy = $orderBy ?: $this->defaultOrder;
        if ($orderBy) {
            $orderClause = " ORDER BY {$orderBy}";
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
     * Find record by ID (supports composite keys via array or underscore-separated string)
     */
    public function find(int|string|array $id, array $columns = ['*']): ?array
    {
        // Validate column names
        $sanitizedCols = array_map(function ($col) {
            if ($col === '*') return $col;
            if (!preg_match('/^[a-zA-Z0-9_-]+$/', $col)) {
                throw new \InvalidArgumentException("Invalid column name: {$col}");
            }
            return $col;
        }, $columns);

        $cols = implode(', ', $sanitizedCols);

        $conditions = $this->getPkConditions($id);
        $where = [];
        $params = [];
        foreach ($conditions as $col => $val) {
            $where[] = "{$col} = :pk_{$col}";
            $params["pk_{$col}"] = $val;
        }

        $whereClause = implode(' AND ', $where);
        $sql = "SELECT {$cols} FROM {$this->table} WHERE {$whereClause} LIMIT 1";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        Database::logQuery($sql, $params);

        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($result) {
            $results = [$result];

            if (method_exists($this, 'afterLoad')) {
                $this->afterLoad($results);
            }

            if (!empty($this->with)) {
                $this->loadRelations($results);
            }
            return $this->hideFields($results)[0];
        }

        return null;
    }

    /**
     * Helper to get primary key conditions
     */
    protected function getPkConditions(int|string|array $id): array
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
            $conditions[$this->primaryKey] = is_array($id) ? ($id[$this->primaryKey] ?? null) : $id;
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
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $col)) {
                throw new \InvalidArgumentException("Invalid column name: {$col}");
            }
            return $col;
        }, $columns);

        $cols = implode(', ', $sanitizedCols);
        $where = [];
        $params = [];

        foreach ($conditions as $key => $value) {
            // Validate condition keys as well
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $key)) {
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

            // Add ID to data for afterSave
            $data[$this->primaryKey] = $id;
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
     */
    public function batchInsert(array $rows): bool
    {
        if (empty($rows)) {
            return false;
        }

        $preparedRows = [];
        foreach ($rows as $row) {
            $row = $this->filterFillable($row);
            if ($this->beforeSave($row, true)) {
                $preparedRows[] = $row;
            }
        }

        if (empty($preparedRows)) {
            return false;
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
            // Logging massive query might be bad, truncating params in log
            Database::logQuery($sql, array_slice($params, 0, 10)); // Log only first 10 params

            if ($result) {
                Cache::delete("table_count:{$this->table}");
            }

            return $result;
        } catch (\PDOException $e) {
            Database::logQueryError($e, $sql, array_slice($params, 0, 10));
            throw $e;
        }
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
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $key)) {
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
            $orderClause = " ORDER BY {$orderBy}";
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
     * Get table columns (cached). Uses PDO column metadata when available.
     */
    protected function getTableColumns(): array
    {
        if (isset(self::$columnsCache[$this->table])) {
            return self::$columnsCache[$this->table];
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

        self::$columnsCache[$this->table] = $columns;
        return $columns;
    }

    /**
     * Hide sensitive fields
     */
    protected function hideFields(array $data): array
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
}
