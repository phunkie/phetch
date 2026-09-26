<?php

namespace Phunkie\Phetch\Query;

use Phunkie\Effect\IO\IO;
use Phunkie\Phetch\Connection\Connection;
use Phunkie\Phetch\Query; 
use Phunkie\Streams\Type\Stream;
use Phunkie\Types\ImmList;
use Phunkie\Phetch\Functions;

use function Phunkie\Effect\Functions\io\io;
use function Phunkie\Streams\Functions\pdo\StreamFromPDO;

class QueryBuilder
{
    public function __construct(
        private string $model,
        private string $table,
        private array $criteria = [],
        private array $orderBy = [],
        private ?int $limit = null
    ) {}

    public function where(string $column, mixed $operator, mixed $value = null): self
    {
        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }

        $new = clone $this;
        $new->criteria[] = [$column, $operator, $value];
        return $new;
    }

    public function orderBy(string $column, string $direction = 'ASC'): self
    {
        $new = clone $this;
        $new->orderBy[] = [$column, $direction];
        return $new;
    }

    public function limit(int $limit): self
    {
        $new = clone $this;
        $new->limit = $limit;
        return $new;
    }

    private function buildSql(&$params): string
    {
        $sql = "SELECT * FROM {$this->table}";
        $params = [];

        if (!empty($this->criteria)) {
            $clauses = [];
            foreach ($this->criteria as $criterion) {
                [$col, $op, $val] = $criterion;
                $clauses[] = "$col $op ?";
                $params[] = $val;
            }
            $sql .= " WHERE " . implode(' AND ', $clauses);
        }

        if (!empty($this->orderBy)) {
            $orders = array_map(fn($o) => "{$o[0]} {$o[1]}", $this->orderBy);
            $sql .= " ORDER BY " . implode(', ', $orders);
        }

        if ($this->limit !== null) {
            $sql .= " LIMIT {$this->limit}";
        }

        return $sql;
    }

    /**
     * @return Query<ImmList>
     */
    public function get(): Query
    {
        return new Query(function (Connection $conn) {
            return io(function () use ($conn) {
                $params = [];
                $sql = $this->buildSql($params);
                
                $stmt = $conn->pdo()->prepare($sql);
                $stmt->execute($params);
                $rows = $stmt->fetchAll();
                
                return ImmList(...array_map(fn($row) => \Phunkie\Phetch\Functions\hydrate($this->model, $row), $rows));
            });
        });
    }

    /**
     * @return Query<Stream> Stream<IO, T> wrapped in Query
     */
    public function stream(): Query
    {
        return new Query(function (Connection $conn) {
            return io(function() use ($conn) {
                 $params = [];
                 $sql = $this->buildSql($params);
                 $stmt = $conn->pdo()->prepare($sql);
                 $stmt->execute($params);

                 return StreamFromPDO($stmt)->map(fn($row) => \Phunkie\Phetch\Functions\hydrate($this->model, $row));
            });
        });
    }
}
