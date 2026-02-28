<?php

declare(strict_types=1);

namespace Wibiesana\Padi\Core;

use PDO;

/**
 * Query Builder Class
 * 
 * Provides a fluent interface for building and executing SQL queries.
 * 
 * Security:
 * - All values are bound via PDO prepared statements
 * - LIMIT/OFFSET are bound as PDO::PARAM_INT (not interpolated)
 * - PostgreSQL ILIKE auto-conversion supported
 * 
 * Performance:
 * - Query state can be reset and reused
 * - Minimal object allocation
 */
class Query
{
    public const VERSION = '2.0.3';

    protected ?PDO $db = null;
    protected ?string $connectionName = null;
    protected string|array $select = ['*'];
    protected ?string $from = null;
    protected array $where = [];
    protected array $params = [];
    protected array $manualParams = [];
    protected array $join = [];
    protected array $orderBy = [];
    protected array $groupBy = [];
    protected ?string $having = null;
    protected ?int $limit = null;
    protected ?int $offset = null;
    protected bool $distinct = false;
    protected bool $autoIlike = true;

    public function __construct(?string $connection = null)
    {
        $this->connectionName = $connection;
        $this->db = Database::connection($connection);
    }

    /**
     * Enable or disable automatic ILIKE conversion for PostgreSQL
     */
    public function autoIlike(bool $value = true): self
    {
        $this->autoIlike = $value;
        return $this;
    }

    /**
     * Reset query builder state for reuse
     * 
     * In worker mode, this prevents stale state from leaking between requests
     * when a Query object is reused.
     */
    public function reset(): self
    {
        $this->select = ['*'];
        $this->from = null;
        $this->where = [];
        $this->params = [];
        $this->manualParams = [];
        $this->join = [];
        $this->orderBy = [];
        $this->groupBy = [];
        $this->having = null;
        $this->limit = null;
        $this->offset = null;
        $this->distinct = false;
        return $this;
    }

    /**
     * Set the columns to select
     */
    public function select(string|array $columns): self
    {
        $this->select = $columns;
        return $this;
    }

    /**
     * Add columns to existing select
     */
    public function addSelect(string|array $columns): self
    {
        if (is_string($this->select) && $this->select === '*') {
            $this->select = $columns;
        } else {
            if (is_string($this->select)) {
                $this->select = [$this->select];
            }
            if (is_string($columns)) {
                $columns = [$columns];
            }
            $this->select = array_merge($this->select, $columns);
        }
        return $this;
    }

    /**
     * Set DISTINCT
     */
    public function distinct(bool $value = true): self
    {
        $this->distinct = $value;
        return $this;
    }

    /**
     * Set the table to query from
     */
    public function from(string $table): self
    {
        $this->from = $table;
        return $this;
    }

    /**
     * Set WHERE condition (replaces existing)
     */
    public function where(string|array $condition, array $params = []): self
    {
        $this->where = [$condition];
        $this->addParams($params);
        return $this;
    }

    /**
     * Add AND WHERE condition
     */
    public function andWhere(string|array $condition, array $params = []): self
    {
        if (empty($this->where)) {
            $this->where = [$condition];
        } else {
            $this->where[] = 'AND';
            $this->where[] = $condition;
        }
        $this->addParams($params);
        return $this;
    }

    /**
     * Add OR WHERE condition
     */
    public function orWhere(string|array $condition, array $params = []): self
    {
        if (empty($this->where)) {
            $this->where = [$condition];
        } else {
            $this->where[] = 'OR';
            $this->where[] = $condition;
        }
        $this->addParams($params);
        return $this;
    }

    /**
     * Add WHERE IN condition
     */
    public function whereIn(string $column, array $values): self
    {
        return $this->andWhere(['IN', $column, $values]);
    }

    /**
     * Add WHERE NOT IN condition
     */
    public function whereNotIn(string $column, array $values): self
    {
        return $this->andWhere(['NOT IN', $column, $values]);
    }

    /**
     * Add raw WHERE condition (use with caution, always bind params)
     * 
     * @param string $expression Raw SQL expression (e.g., "price > :min_price")
     * @param array $params Parameters to bind
     */
    public function whereRaw(string $expression, array $params = []): self
    {
        return $this->andWhere($expression, $params);
    }

    /**
     * Add WHERE BETWEEN condition
     */
    public function whereBetween(string $column, mixed $start, mixed $end): self
    {
        return $this->andWhere(['BETWEEN', $column, [$start, $end]]);
    }

    /**
     * Add WHERE NOT BETWEEN condition
     */
    public function whereNotBetween(string $column, mixed $start, mixed $end): self
    {
        $p1 = ":nbet_" . count($this->params) . "_1";
        $p2 = ":nbet_" . count($this->params) . "_2";
        $this->params[$p1] = $start;
        $this->params[$p2] = $end;
        return $this->andWhere("{$column} NOT BETWEEN {$p1} AND {$p2}");
    }

    /**
     * Add WHERE NULL condition
     */
    public function whereNull(string $column): self
    {
        return $this->andWhere("{$column} IS NULL");
    }

    /**
     * Add WHERE NOT NULL condition
     */
    public function whereNotNull(string $column): self
    {
        return $this->andWhere("{$column} IS NOT NULL");
    }

    /**
     * Add parameters for binding
     */
    public function addParams(array $params): self
    {
        $this->manualParams = array_merge($this->manualParams, $params);
        return $this;
    }

    /**
     * Get current parameters
     */
    public function getParams(): array
    {
        return $this->params;
    }

    /**
     * Add JOIN clause
     */
    public function join(string $type, string $table, string $on = ''): self
    {
        $this->join[] = [$type, $table, $on];
        return $this;
    }

    public function innerJoin(string $table, string $on = ''): self
    {
        return $this->join('INNER JOIN', $table, $on);
    }

    public function leftJoin(string $table, string $on = ''): self
    {
        return $this->join('LEFT JOIN', $table, $on);
    }

    public function rightJoin(string $table, string $on = ''): self
    {
        return $this->join('RIGHT JOIN', $table, $on);
    }

    /**
     * Set ORDER BY (replaces existing)
     */
    public function orderBy(string|array $columns): self
    {
        $this->orderBy = is_string($columns) ? [$columns] : $columns;
        return $this;
    }

    /**
     * Add to existing ORDER BY clause
     */
    public function addOrderBy(string|array $columns): self
    {
        if (is_string($columns)) {
            $columns = [$columns];
        }

        $this->orderBy = empty($this->orderBy)
            ? $columns
            : array_merge($this->orderBy, $columns);

        return $this;
    }

    /**
     * Set GROUP BY
     */
    public function groupBy(string|array $columns): self
    {
        $this->groupBy = is_string($columns) ? [$columns] : $columns;
        return $this;
    }

    /**
     * Set HAVING condition
     */
    public function having(string $condition, array $params = []): self
    {
        $this->having = $condition;
        $this->addParams($params);
        return $this;
    }

    /**
     * Set LIMIT
     */
    public function limit(int $limit): self
    {
        $this->limit = $limit;
        return $this;
    }

    /**
     * Set OFFSET
     */
    public function offset(int $offset): self
    {
        $this->offset = $offset;
        return $this;
    }

    /**
     * Paginate results
     * @return array{data: array, total: int, per_page: int, current_page: int, last_page: int}
     */
    public function paginate(int $perPage = 25, int $page = 1): array
    {
        // Get total count first (before applying limit/offset)
        $total = $this->count();

        // Save state before modifying
        $oldLimit = $this->limit;
        $oldOffset = $this->offset;

        $this->limit($perPage);
        $this->offset(($page - 1) * $perPage);

        $data = $this->all();

        // Restore state so the query builder can be reused
        $this->limit = $oldLimit;
        $this->offset = $oldOffset;

        return [
            'data' => $data,
            'total' => $total,
            'per_page' => $perPage,
            'current_page' => $page,
            'last_page' => $perPage > 0 ? (int)ceil($total / $perPage) : 1
        ];
    }

    /**
     * Execute query and return all results
     */
    public function all(): array
    {
        [$sql, $params] = $this->buildAndPrepare();
        $stmt = $this->db->prepare($sql);
        $this->bindAndExecute($stmt, $params);
        Database::logQuery($sql, $params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Execute query and return a single row
     */
    public function one(): ?array
    {
        $this->limit(1);
        [$sql, $params] = $this->buildAndPrepare();
        $stmt = $this->db->prepare($sql);
        $this->bindAndExecute($stmt, $params);
        Database::logQuery($sql, $params);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Execute query and return a scalar value
     */
    public function scalar(): mixed
    {
        [$sql, $params] = $this->buildAndPrepare();
        $stmt = $this->db->prepare($sql);
        $this->bindAndExecute($stmt, $params);
        Database::logQuery($sql, $params);
        return $stmt->fetchColumn();
    }

    /**
     * Execute query and return a single column
     */
    public function column(): array
    {
        [$sql, $params] = $this->buildAndPrepare();
        $stmt = $this->db->prepare($sql);
        $this->bindAndExecute($stmt, $params);
        Database::logQuery($sql, $params);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Execute COUNT query
     */
    public function count(string $q = '*'): int
    {
        $oldSelect = $this->select;
        $oldOrderBy = $this->orderBy;
        $oldLimit = $this->limit;
        $oldOffset = $this->offset;

        $this->select = ["COUNT({$q})"];
        $this->orderBy = [];
        $this->limit = null;
        $this->offset = null;

        $count = (int)$this->scalar();

        // Restore original state
        $this->select = $oldSelect;
        $this->orderBy = $oldOrderBy;
        $this->limit = $oldLimit;
        $this->offset = $oldOffset;

        return $count;
    }

    /**
     * Check if any records exist
     * 
     * Optimized: uses SELECT 1 LIMIT 1 instead of fetching entire row.
     */
    public function exists(): bool
    {
        $oldSelect = $this->select;
        $oldLimit = $this->limit;

        $this->select = ['1'];
        $this->limit = 1;

        $result = $this->scalar();

        $this->select = $oldSelect;
        $this->limit = $oldLimit;

        return $result !== false;
    }

    /**
     * Calculate the sum of the specified column
     */
    public function sum(string $q): mixed
    {
        $oldSelect = $this->select;
        $this->select = ["SUM({$q})"];
        $result = $this->scalar();
        $this->select = $oldSelect;
        return $result;
    }

    /**
     * Calculate the average of the specified column
     */
    public function average(string $q): mixed
    {
        $oldSelect = $this->select;
        $this->select = ["AVG({$q})"];
        $result = $this->scalar();
        $this->select = $oldSelect;
        return $result;
    }

    /** Alias for average() */
    public function avg(string $q): mixed
    {
        return $this->average($q);
    }

    /**
     * Find the minimum value
     */
    public function min(string $q): mixed
    {
        $oldSelect = $this->select;
        $this->select = ["MIN({$q})"];
        $result = $this->scalar();
        $this->select = $oldSelect;
        return $result;
    }

    /**
     * Find the maximum value
     */
    public function max(string $q): mixed
    {
        $oldSelect = $this->select;
        $this->select = ["MAX({$q})"];
        $result = $this->scalar();
        $this->select = $oldSelect;
        return $result;
    }

    /**
     * Execute DELETE query
     * @return int Number of affected rows
     */
    public function delete(): int
    {
        if (empty($this->from)) {
            throw new \Exception("Table name (from) must be specified for delete operation.");
        }

        // Reset params to manual params
        $this->params = $this->manualParams;

        $sql = 'DELETE FROM ' . $this->from;
        if (!empty($this->where)) {
            $sql .= ' WHERE ' . $this->buildWhere($this->where);
        }

        $stmt = $this->db->prepare($sql);
        $this->bindAndExecute($stmt, $this->params);
        Database::logQuery($sql, $this->params);

        return $stmt->rowCount();
    }

    /**
     * Execute UPDATE query
     * @return int Number of affected rows
     */
    public function update(array $data): int
    {
        if (empty($this->from)) {
            throw new \Exception("Table name (from) must be specified for update operation.");
        }

        if (empty($data)) {
            return 0;
        }

        // Reset params to manual params
        $this->params = $this->manualParams;

        $set = [];
        foreach ($data as $column => $value) {
            $paramName = ":upd_" . str_replace('.', '_', (string)$column);
            $set[] = "{$column} = {$paramName}";
            $this->params[$paramName] = $value;
        }

        $sql = 'UPDATE ' . $this->from . ' SET ' . implode(', ', $set);
        if (!empty($this->where)) {
            $sql .= ' WHERE ' . $this->buildWhere($this->where);
        }

        $stmt = $this->db->prepare($sql);
        $this->bindAndExecute($stmt, $this->params);
        Database::logQuery($sql, $this->params);

        return $stmt->rowCount();
    }

    /**
     * Execute INSERT query
     * @return string|int Last inserted ID
     */
    public function insert(array $data): string|int|false
    {
        if (empty($this->from)) {
            throw new \Exception("Table name (from) must be specified for insert operation.");
        }

        if (empty($data)) {
            return false;
        }

        // Reset params
        $this->params = $this->manualParams;

        $columns = [];
        $placeholders = [];
        foreach ($data as $column => $value) {
            $columns[] = $column;
            $paramName = ":ins_" . str_replace('.', '_', (string)$column);
            $placeholders[] = $paramName;
            $this->params[$paramName] = $value;
        }

        $sql = 'INSERT INTO ' . $this->from . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')';

        $stmt = $this->db->prepare($sql);
        $this->bindAndExecute($stmt, $this->params);
        Database::logQuery($sql, $this->params);

        return $this->db->lastInsertId();
    }

    /**
     * Build SQL and prepare parameters (including LIMIT/OFFSET as bound params)
     * 
     * @return array{0: string, 1: array} [sql, params]
     */
    private function buildAndPrepare(): array
    {
        $sql = $this->buildSql();
        $params = $this->params;

        // Bind LIMIT and OFFSET as named parameters for security
        if ($this->limit !== null) {
            $params[':_limit'] = $this->limit;
        }
        if ($this->offset !== null) {
            $params[':_offset'] = $this->offset;
        }

        return [$sql, $params];
    }

    /**
     * Bind parameters and execute statement
     * 
     * Uses proper PDO types for LIMIT/OFFSET (PARAM_INT)
     */
    private function bindAndExecute(\PDOStatement $stmt, array $params): void
    {
        foreach ($params as $key => $value) {
            if ($key === ':_limit' || $key === ':_offset') {
                $stmt->bindValue($key, (int)$value, PDO::PARAM_INT);
            } elseif (is_int($value)) {
                $stmt->bindValue($key, $value, PDO::PARAM_INT);
            } elseif (is_bool($value)) {
                $stmt->bindValue($key, $value, PDO::PARAM_BOOL);
            } elseif ($value === null) {
                $stmt->bindValue($key, null, PDO::PARAM_NULL);
            } else {
                $stmt->bindValue($key, $value, PDO::PARAM_STR);
            }
        }

        $stmt->execute();
    }

    /**
     * Build the final SQL string
     */
    public function buildSql(): string
    {
        // Reset dynamic params to manual params to prevent duplication
        $this->params = $this->manualParams;

        $sql = 'SELECT ';
        if ($this->distinct) {
            $sql .= 'DISTINCT ';
        }

        $sql .= is_array($this->select) ? implode(', ', $this->select) : $this->select;
        $sql .= ' FROM ' . $this->from;

        // JOINs
        foreach ($this->join as [$type, $table, $on]) {
            $sql .= " {$type} {$table}";
            if ($on !== '') {
                $sql .= " ON {$on}";
            }
        }

        // WHERE
        if (!empty($this->where)) {
            $sql .= ' WHERE ' . $this->buildWhere($this->where);
        }

        // GROUP BY
        if (!empty($this->groupBy)) {
            $sql .= ' GROUP BY ' . implode(', ', $this->groupBy);
        }

        // HAVING
        if ($this->having !== null) {
            $sql .= ' HAVING ' . $this->having;
        }

        // ORDER BY
        if (!empty($this->orderBy)) {
            $orders = [];
            foreach ($this->orderBy as $column => $direction) {
                if (is_int($column)) {
                    $orders[] = $direction;
                } else {
                    if (is_int($direction)) {
                        $direction = $direction === SORT_DESC ? 'DESC' : 'ASC';
                    }
                    $orders[] = "{$column} {$direction}";
                }
            }
            $sql .= ' ORDER BY ' . implode(', ', $orders);
        }

        // LIMIT / OFFSET as parameterized placeholders
        if ($this->limit !== null) {
            $sql .= ' LIMIT :_limit';
        }

        if ($this->offset !== null) {
            $sql .= ' OFFSET :_offset';
        }

        return $sql;
    }

    /**
     * Get raw SQL with parameters interpolated (debugging only)
     */
    public function rawSql(): string
    {
        $sql = $this->buildSql();
        $params = array_merge($this->params, [
            ':_limit' => $this->limit,
            ':_offset' => $this->offset,
        ]);

        foreach ($params as $key => $value) {
            if ($value === null) {
                $replacement = 'NULL';
            } elseif (is_bool($value)) {
                $replacement = $value ? '1' : '0';
            } elseif (is_int($value) || is_float($value)) {
                $replacement = (string)$value;
            } else {
                $replacement = "'" . addslashes((string)$value) . "'";
            }
            $sql = str_replace($key, $replacement, $sql);
        }

        return $sql;
    }

    /**
     * Build WHERE clause from condition array
     */
    protected function buildWhere(array $conditions): string
    {
        if (empty($conditions)) {
            return '';
        }

        $parts = [];
        $keys = array_keys($conditions);
        $count = count($keys);
        $i = 0;

        foreach ($conditions as $key => $value) {
            if (is_int($key)) {
                if (is_array($value)) {
                    $parts[] = $this->parseCondition($value);
                } else {
                    $parts[] = $value;
                }
            } else {
                $parts[] = $this->parseCondition([$key => $value]);

                if ($i < $count - 1) {
                    $nextKey = $keys[$i + 1];
                    if (!(is_int($nextKey) && in_array(strtoupper((string)$conditions[$nextKey]), ['AND', 'OR']))) {
                        $parts[] = 'AND';
                    }
                }
            }
            $i++;
        }

        return implode(' ', array_filter($parts));
    }

    /**
     * Parse a single condition into SQL
     */
    protected function parseCondition(array $condition): string
    {
        if (empty($condition)) return '';

        // Hash format: ['col' => 'val', 'col2' => [1,2,3]]
        if (!isset($condition[0])) {
            $parts = [];
            foreach ($condition as $column => $value) {
                if (is_array($value)) {
                    // Automatic IN: ['id' => [1,2,3]]
                    $placeholders = [];
                    foreach ($value as $i => $v) {
                        $paramName = ":in_" . count($this->params) . "_{$i}";
                        $placeholders[] = $paramName;
                        $this->params[$paramName] = $v;
                    }
                    $parts[] = "{$column} IN (" . implode(', ', $placeholders) . ")";
                } elseif ($value === null) {
                    $parts[] = "{$column} IS NULL";
                } else {
                    $paramName = ":p_" . count($this->params) . "_" . str_replace('.', '_', (string)$column);
                    $this->params[$paramName] = $value;
                    $parts[] = "{$column} = {$paramName}";
                }
            }
            return implode(' AND ', $parts);
        }

        $operator = strtoupper((string)($condition[0] ?? ''));

        // LIKE / NOT LIKE
        if ($operator === 'LIKE' || $operator === 'NOT LIKE') {
            $column = $condition[1];
            $value = $condition[2];
            $paramName = ":p_" . count($this->params) . "_" . str_replace('.', '_', (string)$column);

            // Auto-wrap with % if not already present
            if (!str_contains($value, '%')) {
                $value = "%{$value}%";
            }

            $currentOperator = $operator;
            if ($this->autoIlike && DatabaseManager::getDriver($this->connectionName) === 'pgsql') {
                $currentOperator = ($operator === 'LIKE') ? 'ILIKE' : 'NOT ILIKE';
            }

            $this->params[$paramName] = $value;
            return "{$column} {$currentOperator} {$paramName}";
        }

        // AND / OR grouping
        if ($operator === 'AND' || $operator === 'OR') {
            array_shift($condition);
            $parts = [];
            foreach ($condition as $subCondition) {
                if (is_array($subCondition)) {
                    $parts[] = '(' . $this->parseCondition($subCondition) . ')';
                } else {
                    $parts[] = $subCondition;
                }
            }
            return implode(" {$operator} ", $parts);
        }

        // Three-element format: [column, operator, value]
        if (count($condition) === 3) {
            $column = $condition[0];
            $op = strtoupper($condition[1]);
            $value = $condition[2];

            if ($op === 'IN' || $op === 'NOT IN') {
                if (is_array($value)) {
                    $placeholders = [];
                    foreach ($value as $i => $v) {
                        $paramName = ":in_" . count($this->params) . "_{$i}";
                        $placeholders[] = $paramName;
                        $this->params[$paramName] = $v;
                    }
                    return "{$column} {$op} (" . implode(', ', $placeholders) . ")";
                }
                return "{$column} {$op} ({$value})";
            }

            if ($op === 'BETWEEN' && is_array($value) && count($value) === 2) {
                $p1 = ":bet_" . count($this->params) . "_1";
                $p2 = ":bet_" . count($this->params) . "_2";
                $this->params[$p1] = $value[0];
                $this->params[$p2] = $value[1];
                return "{$column} BETWEEN {$p1} AND {$p2}";
            }

            $paramName = ":p_" . count($this->params) . "_" . str_replace('.', '_', $column);
            $this->params[$paramName] = $value;
            return "{$column} {$op} {$paramName}";
        }

        return '';
    }

    /**
     * Static helper to start a new query
     */
    public static function find(?string $connection = null): self
    {
        return new self($connection);
    }
}
