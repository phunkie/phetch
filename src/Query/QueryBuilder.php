<?php

namespace Phunkie\Phetch\Query;

use InvalidArgumentException;
use Phunkie\Phetch\Connection\Connection;
use Phunkie\Phetch\Identifier;
use Phunkie\Phetch\Query;
use Phunkie\Streams\Type\Stream;
use Phunkie\Types\ImmList;

use function Phunkie\Effect\Functions\io\io;
use function Phunkie\Phetch\Functions\hydrate;
use function StreamFromPDO;

class QueryBuilder
{
    private const OPERATORS = ['=', '!=', '<>', '<', '<=', '>', '>=', 'LIKE', 'NOT LIKE', 'IS', 'IS NOT'];

    private const DIRECTIONS = ['ASC', 'DESC'];

    /**
     * @param list<array{0: Identifier, 1: string, 2: mixed}> $criteria
     * @param list<array{0: Identifier, 1: string}> $orderBy
     */
    public function __construct(
        private string $model,
        private Identifier $table,
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

        $operator = strtoupper((string) $operator);
        if (!in_array($operator, self::OPERATORS, true)) {
            throw new InvalidArgumentException(sprintf('"%s" is not a supported operator.', $operator));
        }

        $new = clone $this;
        $new->criteria[] = [new Identifier($column), $operator, $value];

        return $new;
    }

    public function orderBy(string $column, string $direction = 'ASC'): self
    {
        $direction = strtoupper($direction);
        if (!in_array($direction, self::DIRECTIONS, true)) {
            throw new InvalidArgumentException(sprintf('"%s" is not a supported order direction.', $direction));
        }

        $new = clone $this;
        $new->orderBy[] = [new Identifier($column), $direction];

        return $new;
    }

    public function limit(int $limit): self
    {
        $new = clone $this;
        $new->limit = $limit;

        return $new;
    }

    /**
     * @return Query<ImmList>
     */
    public function get(): Query
    {
        return new Query(function (Connection $conn) {
            return io(function () use ($conn) {
                $params = [];
                $stmt = $conn->pdo()->prepare($this->sql($conn, $params));
                $stmt->execute($params);
                $rows = $stmt->fetchAll();

                return ImmList(...array_map(fn($row) => hydrate($this->model, $row), $rows));
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
                $stmt = $conn->pdo()->prepare($this->sql($conn, $params));
                $stmt->execute($params);

                return StreamFromPDO($stmt)->map(fn($row) => hydrate($this->model, $row));
            });
        });
    }

    private function sql(Connection $conn, array &$params): string
    {
        $sql = 'SELECT * FROM '.$conn->quote($this->table);
        $params = [];

        if (!empty($this->criteria)) {
            $clauses = [];
            foreach ($this->criteria as [$column, $operator, $value]) {
                $clauses[] = sprintf('%s %s ?', $conn->quote($column), $operator);
                $params[] = $value;
            }
            $sql .= ' WHERE '.implode(' AND ', $clauses);
        }

        if (!empty($this->orderBy)) {
            $orders = array_map(fn($order) => sprintf('%s %s', $conn->quote($order[0]), $order[1]), $this->orderBy);
            $sql .= ' ORDER BY '.implode(', ', $orders);
        }

        if ($this->limit !== null) {
            $sql .= ' LIMIT '.$this->limit;
        }

        return $sql;
    }
}
