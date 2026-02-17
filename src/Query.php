<?php

declare(strict_types=1);

namespace Core;

use PDO;

/**
 * Query Builder Class
 * 
 * Provides a fluent interface for building and executing SQL queries,
 * similar to yii\db\Query.
 */
class Query
{
    protected ?PDO $db = null;
    protected string|array $select = ['*'];
    protected ?string $from = null;
    protected array $where = [];
    protected array $params = [];
    protected array $join = [];
    protected array $orderBy = [];
    protected array $groupBy = [];
    protected ?string $having = null;
    protected ?int $limit = null;
    protected ?int $offset = null;
    protected bool $distinct = false;

    public function __construct(?string $connection = null)
    {
        $this->db = Database::connection($connection);
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
     * Add columns to select
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
     * Set the table to select from
     */
    public function from(string $table): self
    {
        $this->from = $table;
        return $this;
    }

    /**
     * Add WHERE condition
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
     * Add parameters for binding
     */
    public function addParams(array $params): self
    {
        $this->params = array_merge($this->params, $params);
        return $this;
    }

    /**
     * Add JOIN
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
     * Add ORDER BY
     */
    public function orderBy(string|array $columns): self
    {
        if (is_string($columns)) {
            $this->orderBy = [$columns];
        } else {
            $this->orderBy = $columns;
        }
        return $this;
    }

    /**
     * Add GROUP BY
     */
    public function groupBy(string|array $columns): self
    {
        if (is_string($columns)) {
            $this->groupBy = [$columns];
        } else {
            $this->groupBy = $columns;
        }
        return $this;
    }

    /**
     * Add HAVING condition
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
     * Execute query and return all results
     */
    public function all(): array
    {
        $sql = $this->buildSql();
        $stmt = $this->db->prepare($sql);
        $stmt->execute($this->params);
        Database::logQuery($sql, $this->params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Execute query and return a single row
     */
    public function one(): ?array
    {
        $this->limit(1);
        $sql = $this->buildSql();
        $stmt = $this->db->prepare($sql);
        $stmt->execute($this->params);
        Database::logQuery($sql, $this->params);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Execute query and return a scalar value (e.g., from COUNT)
     */
    public function scalar()
    {
        $sql = $this->buildSql();
        $stmt = $this->db->prepare($sql);
        $stmt->execute($this->params);
        Database::logQuery($sql, $this->params);
        return $stmt->fetchColumn();
    }

    /**
     * Execute query and return a column of results
     */
    public function column(): array
    {
        $sql = $this->buildSql();
        $stmt = $this->db->prepare($sql);
        $stmt->execute($this->params);
        Database::logQuery($sql, $this->params);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Execute COUNT query
     */
    public function count(string $q = '*'): int
    {
        $oldSelect = $this->select;
        $this->select = ["COUNT($q)"];
        $count = (int)$this->scalar();
        $this->select = $oldSelect;
        return $count;
    }

    /**
     * Check if any records exist
     */
    public function exists(): bool
    {
        return $this->one() !== null;
    }

    /**
     * Build the final SQL string
     */
    public function buildSql(): string
    {
        $sql = 'SELECT ';
        if ($this->distinct) {
            $sql .= 'DISTINCT ';
        }

        if (is_array($this->select)) {
            $sql .= implode(', ', $this->select);
        } else {
            $sql .= $this->select;
        }

        $sql .= ' FROM ' . $this->from;

        if (!empty($this->join)) {
            foreach ($this->join as $join) {
                [$type, $table, $on] = $join;
                $sql .= " $type $table";
                if ($on) {
                    $sql .= " ON $on";
                }
            }
        }

        if (!empty($this->where)) {
            $sql .= ' WHERE ' . $this->buildWhere($this->where);
        }

        if (!empty($this->groupBy)) {
            $sql .= ' GROUP BY ' . implode(', ', $this->groupBy);
        }

        if ($this->having) {
            $sql .= ' HAVING ' . $this->having;
        }

        if (!empty($this->orderBy)) {
            $orders = [];
            foreach ($this->orderBy as $column => $direction) {
                if (is_int($column)) {
                    $orders[] = $direction;
                } else {
                    // Normalize direction (handle SORT_ASC/SORT_DESC constants)
                    if (is_int($direction)) {
                        $direction = $direction === 3 ? 'DESC' : 'ASC'; // 3 = SORT_DESC, 4 = SORT_ASC
                    }
                    $orders[] = "$column $direction";
                }
            }
            $sql .= ' ORDER BY ' . implode(', ', $orders);
        }

        if ($this->limit !== null) {
            $sql .= ' LIMIT ' . $this->limit;
        }

        if ($this->offset !== null) {
            $sql .= ' OFFSET ' . $this->offset;
        }

        return $sql;
    }

    /**
     * Build WHERE clause
     */
    protected function buildWhere(array $conditions): string
    {
        if (empty($conditions)) {
            return '';
        }

        $parts = [];
        foreach ($conditions as $key => $value) {
            if (is_int($key)) {
                if (is_array($value)) {
                    $parts[] = $this->parseCondition($value);
                } else {
                    $parts[] = $value;
                }
            } else {
                // Handle directly passed associative arrays
                $parts[] = $this->parseCondition([$key => $value]);

                // Add AND if not the last element and next isn't an operator
                $keys = array_keys($conditions);
                $currentIndex = array_search($key, $keys);
                if ($currentIndex < count($keys) - 1) {
                    $nextKey = $keys[$currentIndex + 1];
                    if (is_int($nextKey) && in_array(strtoupper((string)$conditions[$nextKey]), ['AND', 'OR'])) {
                        // Let the loop handle it
                    } else {
                        $parts[] = 'AND';
                    }
                }
            }
        }

        return implode(' ', array_filter($parts));
    }

    /**
     * Parse a single condition array
     */
    protected function parseCondition(array $condition): string
    {
        if (empty($condition)) return '';

        // Check if it's an associative array (hash format: ['col' => 'val'])
        if (!isset($condition[0])) {
            $parts = [];
            foreach ($condition as $column => $value) {
                if (is_array($value)) {
                    // Automatic IN support: ['id' => [1,2,3]]
                    $placeholders = [];
                    foreach ($value as $i => $v) {
                        $paramName = ":in_" . count($this->params) . "_" . $i;
                        $placeholders[] = $paramName;
                        $this->params[$paramName] = $v;
                    }
                    $parts[] = "$column IN (" . implode(', ', $placeholders) . ")";
                } elseif ($value === null) {
                    // Automatic NULL support: ['deleted_at' => null]
                    $parts[] = "$column IS NULL";
                } else {
                    $paramName = ":p_" . count($this->params) . "_" . str_replace('.', '_', (string)$column);
                    $this->params[$paramName] = $value;
                    $parts[] = "$column = $paramName";
                }
            }
            return implode(' AND ', $parts);
        }

        $operator = strtoupper((string)($condition[0] ?? ''));

        // Handle [operator, column, value] format: ['like', 'title', 'query']
        if (in_array($operator, ['LIKE', 'NOT LIKE'])) {
            $column = $condition[1];
            $value = $condition[2];
            $paramName = ":p_" . count($this->params) . "_" . str_replace('.', '_', (string)$column);

            // Auto-wrap with % if not already present
            if (strpos($value, '%') === false) {
                $value = "%$value%";
            }

            $this->params[$paramName] = $value;
            return "$column $operator $paramName";
        }

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
            return implode(" $operator ", $parts);
        }

        if (count($condition) === 3) {
            $column = $condition[0];
            $op = strtoupper($condition[1]);
            $value = $condition[2];

            if ($op === 'IN' || $op === 'NOT IN') {
                if (is_array($value)) {
                    $placeholders = [];
                    foreach ($value as $i => $v) {
                        $paramName = ":in_" . count($this->params) . "_" . $i;
                        $placeholders[] = $paramName;
                        $this->params[$paramName] = $v;
                    }
                    return "$column $op (" . implode(', ', $placeholders) . ")";
                }
                return "$column $op ($value)";
            }

            if ($op === 'BETWEEN' && is_array($value) && count($value) === 2) {
                $p1 = ":bet_" . count($this->params) . "_1";
                $p2 = ":bet_" . count($this->params) . "_2";
                $this->params[$p1] = $value[0];
                $this->params[$p2] = $value[1];
                return "$column BETWEEN $p1 AND $p2";
            }

            $paramName = ":p_" . count($this->params) . "_" . str_replace('.', '_', $column);
            $this->params[$paramName] = $value;
            return "$column $op $paramName";
        }

        return '';
    }

    /**
     * Simple static helper to start a query
     */
    public static function find(?string $connection = null): self
    {
        return new self($connection);
    }
}
