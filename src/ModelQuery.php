<?php

declare(strict_types=1);

namespace Wibiesana\Padi\Core;


/**
 * Model-aware Query Builder
 * 
 * Extends the base Query builder with ActiveRecord model integration,
 * providing eager loading (with), lifecycle hooks (afterLoad), and
 * field hiding automatically when fetching results.
 * 
 * Usage:
 *   MyModel::find()->with('relation')->orderBy('id DESC')->limit(100)->all();
 *   MyModel::find()->where(['status' => 'active'])->one();
 *   MyModel::find(5); // Find by ID
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
     * @param array|string $relations Relation names (string or array)
     * @return $this
     * 
     * @example
     *   ->with('author')
     *   ->with(['author', 'comments'])
     *   ->with('author,comments')
     *   ->with('author.profile')       // nested
     *   ->with('author:id,name')       // select specific columns
     */
    public function with(array|string $relations): self
    {
        if (is_string($relations)) {
            $relations = array_map('trim', explode(',', $relations));
        }
        $this->withRelations = array_merge($this->withRelations, $relations);
        return $this;
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
     * 
     * @throws \Exception When record is not found
     */
    public function findOrFailByPk(int|string|array $id): array
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
}
