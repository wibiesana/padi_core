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
     * Paginate results with model processing
     */
    public function paginate(int $perPage = 25, int $page = 1): array
    {
        $result = parent::paginate($perPage, $page);

        if (!empty($result['data'])) {
            $result['data'] = $this->processResults($result['data']);
        }

        return $result;
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
