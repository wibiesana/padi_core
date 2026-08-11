<?php

declare(strict_types=1);

namespace Wibiesana\Padi\Core;


/**
 * Model-aware Query Builder
 * 
 * Extends the base Query builder with ActiveRecord model integration,
 * providing eager loading (with), SQL JOIN via relations (joinWith),
 * lifecycle hooks (afterLoad), and field hiding automatically when
 * fetching results.
 * 
 * Usage:
 *   MyModel::find()->with('relation')->orderBy('id DESC')->limit(100)->all();
 *   MyModel::find()->where(['status' => 'active'])->one();
 *   MyModel::find(5); // Find by ID
 * 
 * joinWith (Yii2-style SQL JOIN):
 *   Order::find()
 *       ->select(['order.*', 'customer.name as customer_name'])
 *       ->joinWith(['customer c', 'orderItems.product p'])
 *       ->where(['order.status' => 'completed'])
 *       ->andWhere(['p.category_id' => [1, 2, 3]])
 *       ->groupBy(['order.id'])
 *       ->having(['>', 'COUNT(order_item.id)', 5])
 *       ->orderBy(['order.total_amount' => SORT_DESC])
 *       ->limit(10)
 *       ->all();
 */
class ModelQuery extends Query
{
    protected ActiveRecord $model;
    protected array $withRelations = [];

    public function __construct(ActiveRecord $model)
    {
        parent::__construct($model->getConnectionName());
        $this->model = $model;
        $this->from($model->getTable());
    }

    /**
     * Set eager loading relations
     * 
     * Accepts multiple calling styles:
     * @param array|string ...$relations Relation names
     * @return $this
     * 
     * @example
     *   ->with('author')
     *   ->with('author', 'comments')        // variadic
     *   ->with(['author', 'comments'])       // array
     *   ->with('author,comments')            // comma-separated
     *   ->with('author.profile')             // nested
     *   ->with('author:id,name')             // select specific columns
     */
    public function with(array|string ...$relations): self
    {
        $flat = [];
        foreach ($relations as $relation) {
            if (is_array($relation)) {
                $flat = array_merge($flat, $relation);
            } else {
                // Only split on comma if the string doesn't contain a colon
                // (colon syntax like 'relation:col1,col2' should NOT be split)
                if (strpos($relation, ':') === false && strpos($relation, ',') !== false) {
                    $flat = array_merge($flat, array_map('trim', explode(',', $relation)));
                } else {
                    $flat[] = trim($relation);
                }
            }
        }
        $this->withRelations = array_merge($this->withRelations, $flat);
        return $this;
    }

    /**
     * Return results as plain arrays (no-op for compatibility).
     * 
     * Since Padi always returns results as associative arrays,
     * this method is provided purely for compatibility with other ORMs.
     * 
     * @return $this
     */
    public function asArray(): self
    {
        return $this;
    }

    /**
     * Join with related models via SQL JOIN (Yii2-style).
     * 
     * Unlike with() which uses separate queries (eager loading),
     * joinWith() adds SQL JOIN clauses to the main query, allowing
     * you to filter, sort, and aggregate across related tables.
     * 
     * Supports:
     * - Simple:    ->joinWith(['customer'])
     * - Alias:     ->joinWith(['customer c'])
     * - Nested:    ->joinWith(['orderItems.product'])
     * - Combined:  ->joinWith(['customer c', 'orderItems.product p'])
     * - Array:     ->joinWith('customer')  (single string)
     * 
     * @param array|string $relations Relation names with optional aliases
     * @param string $joinType JOIN type: 'LEFT JOIN', 'INNER JOIN', 'RIGHT JOIN'
     * @return $this
     * 
     * @example
     *   Order::find()
     *       ->select(['order.*', 'customer.name as customer_name'])
     *       ->joinWith(['customer c', 'orderItems.product p'])
     *       ->where(['order.status' => 'completed'])
     *       ->andWhere(['p.category_id' => [1, 2, 3]])
     *       ->groupBy(['order.id'])
     *       ->having('COUNT(order_item.id) > 5')
     *       ->orderBy(['order.total_amount' => SORT_DESC])
     *       ->limit(10)
     *       ->all();
     */
    public function joinWith(array|string $relations, string $joinType = 'LEFT JOIN'): self
    {
        if (is_string($relations)) {
            $relations = [$relations];
        }

        foreach ($relations as $relation) {
            $this->resolveJoin($relation, $this->model, $joinType);
        }

        return $this;
    }

    /**
     * Resolve a single relation (possibly nested) into SQL JOIN clause(s).
     */
    private function resolveJoin(string $relation, ActiveRecord $contextModel, string $joinType): void
    {
        // Parse alias: 'customer c' → relationPath='customer', alias='c'
        $parts = preg_split('/\s+/', trim($relation), 2);
        $relationPath = $parts[0];
        $finalAlias = $parts[1] ?? null;

        // Handle nested: 'orderItems.product' → segments=['orderItems', 'product']
        $segments = explode('.', $relationPath);
        $currentModel = $contextModel;

        foreach ($segments as $i => $segment) {
            $isLast = ($i === count($segments) - 1);
            $alias = $isLast ? $finalAlias : null;

            $config = $currentModel->getRelationConfig($segment);
            if ($config === null) {
                throw new \InvalidArgumentException(
                    "Relation '{$segment}' not found on model " . get_class($currentModel)
                );
            }

            $relatedModel = new $config['model']();
            $relatedTable = $relatedModel->getTable();
            $currentTable = $currentModel->getTable();

            // Auto-alias joined table to relation name (segment) if no manual alias is provided
            $tableRef = $alias ?? $segment;
            $joinTable = $relatedTable;
            if ($alias !== null || $relatedTable !== $segment) {
                $joinTable = "{$relatedTable} {$tableRef}";
            }

            match ($config['type']) {
                'belongsTo' => $this->join(
                    $joinType,
                    $joinTable,
                    "{$tableRef}.{$config['foreign_key']} = {$currentTable}.{$config['local_key']}"
                ),
                'hasMany', 'hasOne' => $this->join(
                    $joinType,
                    $joinTable,
                    "{$tableRef}.{$config['foreign_key']} = {$currentTable}.{$config['local_key']}"
                ),
                'belongsToMany' => $this->resolveBelongsToManyJoin(
                    $config, $currentModel, $relatedModel, $alias ?? $segment, $joinType
                ),
            };

            $currentModel = $relatedModel;
        }
    }

    /**
     * Resolve a belongsToMany relation into two JOINs (pivot + related table).
     */
    private function resolveBelongsToManyJoin(
        array $config,
        ActiveRecord $currentModel,
        ActiveRecord $relatedModel,
        ?string $alias,
        string $joinType
    ): void {
        $pivotTable = $config['pivot_table'];
        $currentTable = $currentModel->getTable();
        $relatedTable = $relatedModel->getTable();
        $pk = $currentModel->getPrimaryKeyName();
        $relatedPk = $relatedModel->getPrimaryKeyName();

        // Auto-alias joined table to relation name if no manual alias is provided
        $tableRef = $alias ?? $relatedTable;
        $joinTable = $relatedTable;
        if ($alias !== null || $relatedTable !== $alias) {
            $joinTable = "{$relatedTable} {$tableRef}";
        }

        // JOIN 1: pivot table
        $this->join(
            $joinType,
            $pivotTable,
            "{$pivotTable}.{$config['foreign_key']} = {$currentTable}.{$pk}"
        );

        // JOIN 2: related table
        $this->join(
            $joinType,
            $joinTable,
            "{$tableRef}.{$relatedPk} = {$pivotTable}.{$config['related_key']}"
        );
    }

    /**
     * Execute query and return all results with model processing
     */
    public function all(): array
    {
        $results = parent::all();
        return $this->processResults($results);
    }

    /**
     * Execute query and return a single row with model processing
     */
    public function one(): ?array
    {
        $result = parent::one();

        if ($result === null) {
            return null;
        }

        $results = [$result];
        $results = $this->processResults($results);

        return $results[0] ?? null;
    }

    /**
     * Find a single record by Primary Key
     */
    public function findByPk(int|string|array $id): ?array
    {
        $conditions = $this->model->getPkConditions($id);
        return $this->where($conditions)->one();
    }

    /**
     * Find a single record by Primary Key or throw 404 Exception
     * Allows builder chaining: Product::find()->with(...)->findOrFail($id)
     * 
     * @throws \Exception When record is not found
     */
    public function findOrFail(int|string|array $id): array
    {
        $result = $this->findByPk($id);
        if ($result === null) {
            throw new \Exception("Record not found in " . $this->model->getTable(), 404);
        }
        return $result;
    }

    /**
     * Paginate results with model processing and standard API meta envelope
     */
    public function paginate(int $perPage = 25, int $page = 1): array
    {
        $result = parent::paginate($perPage, $page);

        $data = $result['data'] ?? [];
        if (!empty($data)) {
            $data = $this->processResults($data);
        }

        $total = (int)($result['total'] ?? 0);
        $offset = ($page - 1) * $perPage;

        return [
            'data' => $data,
            'meta' => [
                'total' => $total,
                'per_page' => $perPage,
                'current_page' => $page,
                'last_page' => $perPage > 0 ? (int)ceil($total / $perPage) : 1,
                'from' => $total > 0 ? $offset + 1 : 0,
                'to' => min($offset + $perPage, $total)
            ]
        ];
    }

    /**
     * Process results through model lifecycle:
     * - afterLoad hook
     * - Hide sensitive fields
     * - Eager load relations
     */
    protected function processResults(array $results): array
    {
        if (empty($results)) {
            return $results;
        }

        // Apply afterLoad lifecycle hook
        if (method_exists($this->model, 'afterLoad')) {
            $this->model->afterLoad($results);
        }

        // Hide sensitive fields
        $results = $this->model->hideFields($results);

        // Eager load relations
        if (!empty($this->withRelations)) {
            $this->model->with($this->withRelations);
            $this->model->loadRelations($results);
        }

        return $results;
    }

    /**
     * Batch query results using a PHP Generator.
     * 
     * This allows you to process large datasets without exhausting server memory.
     * 
     * @param int $size Chunk size
     * @return \Generator Array of records per chunk
     * 
     * @example
     *   foreach (Order::find()->where(['status' => 'completed'])->batch(100) as $orders) {
     *       // Process 100 orders
     *   }
     */
    public function batch(int $size = 100): \Generator
    {
        $page = 1;
        while (true) {
            $oldLimit = $this->limit;
            $oldOffset = $this->offset;

            $this->limit($size);
            $this->offset(($page - 1) * $size);

            $rows = $this->all();

            $this->limit = $oldLimit;
            $this->offset = $oldOffset;

            if (empty($rows)) {
                break;
            }

            yield $rows;

            if (count($rows) < $size) {
                break;
            }

            $page++;
        }
    }

    /**
     * Iterate over query results one by one using a PHP Generator.
     * 
     * This allows you to process large datasets record by record with low memory usage.
     * 
     * @param int $size Chunk size to fetch in background (default 100)
     * @return \Generator Individual records
     * 
     * @example
     *   foreach (Order::find()->each(100) as $order) {
     *       // Process single order
     *   }
     */
    public function each(int $size = 100): \Generator
    {
        foreach ($this->batch($size) as $rows) {
            foreach ($rows as $row) {
                yield $row;
            }
        }
    }
}
