<?php

declare(strict_types=1);

namespace Wibiesana\Padi\Core;

use PDO;

/**
 * Query Builder Class
 * 
 * Provides a fluent interface for building and executing SQL queries,
 * similar to yii\db\Query.
 */
class Query
{
    public const VERSION = '1.0.4';
    protected ?PDO $db = null;
    protected ?string $connectionName = null;
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
        // Not natively supported in parseCondition yet, but easy to add via string
        $p1 = ":nbet_" . count($this->params) . "_1";
        $p2 = ":nbet_" . count($this->params) . "_2";
        $this->params[$p1] = $start;
        $this->params[$p2] = $end;
        return $this->andWhere("$column NOT BETWEEN $p1 AND $p2");
    }

    /**
     * Add WHERE NULL condition
     */
    public function whereNull(string $column): self
    {
        return $this->andWhere("$column IS NULL");
    }

    /**
     * Add WHERE NOT NULL condition
     */
    public function whereNotNull(string $column): self
    {
        return $this->andWhere("$column IS NOT NULL");
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
     * Get current parameters
     */
    public function getParams(): array
    {
        return $this->params;
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
     * Add to the existing ORDER BY clause
     */
    public function addOrderBy(string|array $columns): self
    {
        if (is_string($columns)) {
            $columns = [$columns];
        }

        if (empty($this->orderBy)) {
            $this->orderBy = $columns;
        } else {
            $this->orderBy = array_merge($this->orderBy, $columns);
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
     * Paginate results
     * @return array [data, total, per_page, current_page, last_page]
     */
    public function paginate(int $perPage = 25, int $page = 1): array
    {
        $this->limit($perPage);
        $this->offset(($page - 1) * $perPage);

        $total = $this->count();
        $data = $this->all();

        return [
            'data' => $data,
            'total' => $total,
            'per_page' => $perPage,
            'current_page' => $page,
            'last_page' => (int) ceil($total / $perPage)
        ];
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
     * Calculate the sum of the specified column
     */
    public function sum(string $q): mixed
    {
        $oldSelect = $this->select;
        $this->select = ["SUM($q)"];
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
        $this->select = ["AVG($q)"];
        $result = $this->scalar();
        $this->select = $oldSelect;
        return $result;
    }

    /**
     * Alias for average()
     */
    public function avg(string $q): mixed
    {
        return $this->average($q);
    }

    /**
     * Find the minimum value of the specified column
     */
    public function min(string $q): mixed
    {
        $oldSelect = $this->select;
        $this->select = ["MIN($q)"];
        $result = $this->scalar();
        $this->select = $oldSelect;
        return $result;
    }

    /**
     * Find the maximum value of the specified column
     */
    public function max(string $q): mixed
    {
        $oldSelect = $this->select;
        $this->select = ["MAX($q)"];
        $result = $this->scalar();
        $this->select = $oldSelect;
        return $result;
    }

    /**
     * Execute DELETE query based on conditions
     * @return int Number of affected rows
     */
    public function delete(): int
    {
        if (empty($this->from)) {
            throw new \Exception("Table name (from) must be specified for delete operation.");
        }

        $sql = 'DELETE FROM ' . $this->from;
        if (!empty($this->where)) {
            $sql .= ' WHERE ' . $this->buildWhere($this->where);
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($this->params);
        Database::logQuery($sql, $this->params);

        return $stmt->rowCount();
    }

    /**
     * Execute UPDATE query based on conditions
     * @param array $data Attribute values (name => value) to be saved
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

        $set = [];
        foreach ($data as $column => $value) {
            $paramName = ":upd_" . str_replace('.', '_', (string)$column);
            $set[] = "$column = $paramName";
            $this->params[$paramName] = $value;
        }

        $sql = 'UPDATE ' . $this->from . ' SET ' . implode(', ', $set);
        if (!empty($this->where)) {
            $sql .= ' WHERE ' . $this->buildWhere($this->where);
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($this->params);
        Database::logQuery($sql, $this->params);

        return $stmt->rowCount();
    }

    /**
     * Execute INSERT query
     * @param array $data Attribute values (name => value) to be inserted
     * @return string|int Last inserted ID
     */
    public function insert(array $data)
    {
        if (empty($this->from)) {
            throw new \Exception("Table name (from) must be specified for insert operation.");
        }

        if (empty($data)) {
            return false;
        }

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
        $stmt->execute($this->params);
        Database::logQuery($sql, $this->params);

        return $this->db->lastInsertId();
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
     * Get the raw SQL with parameters interpolated (for debugging)
     */
    public function rawSql(): string
    {
        $sql = $this->buildSql();
        foreach ($this->params as $key => $value) {
            if (is_string($value)) {
                $value = "'" . addslashes($value) . "'";
            } elseif ($value === null) {
                $value = 'NULL';
            } elseif (is_bool($value)) {
                $value = $value ? '1' : '0';
            }
            $sql = str_replace($key, (string)$value, $sql);
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

            $currentOperator = $operator;
            // Handle PostgreSQL ILIKE
            if ($this->autoIlike && DatabaseManager::getDriver($this->connectionName) === 'pgsql') {
                $currentOperator = ($operator === 'LIKE') ? 'ILIKE' : 'NOT ILIKE';
            }

            $this->params[$paramName] = $value;
            return "$column $currentOperator $paramName";
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
