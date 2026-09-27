<?php

namespace Phunkie\Phetch\Query;

use InvalidArgumentException;
use PDOStatement;
use Phunkie\Effect\IO\IO;
use Phunkie\Phetch\Connection\Connection;
use Phunkie\Phetch\Identifier;
use Phunkie\Phetch\Query;
use Phunkie\Streams\Type\Stream;
use Phunkie\Types\ImmList;
use Phunkie\Types\Option;

use function None;
use function Phunkie\Effect\Functions\io\io;
use function Phunkie\Phetch\Functions\execute;
use function Phunkie\Phetch\Functions\executeForStreaming;
use function Phunkie\Phetch\Functions\hydrate;
use function Some;
use function StreamFromPDO;

/**
 * A composable SELECT over one table. It is itself a Query<ImmList<T>>: running it fetches every matching row.
 *
 * @template T
 * @extends Query<ImmList<T>>
 */
class QueryBuilder extends Query
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
        private ?int $limit = null,
        private ?int $offset = null,
        private ?Identifier $projection = null
    ) {
        parent::__construct(fn(Connection $conn) => $this->get()->run($conn));
    }

    public function run($a): IO
    {
        return $this->get()->run($a);
    }

    public function where(string $column, mixed $operator, mixed $value = null): static
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

    /**
     * @param list<mixed>|QueryBuilder $values a list of values, or a builder projected with select() used as a subquery
     */
    public function whereIn(string $column, array|QueryBuilder $values): static
    {
        if ([] === $values) {
            throw new InvalidArgumentException('whereIn needs at least one value.');
        }

        if ($values instanceof QueryBuilder && null === $values->projection) {
            throw new InvalidArgumentException('A subquery needs a select() column.');
        }

        $new = clone $this;
        $new->criteria[] = [new Identifier($column), 'IN', $values];

        return $new;
    }

    /**
     * Project one column, which makes this builder usable as a subquery in whereIn().
     */
    public function select(string $column): static
    {
        $new = clone $this;
        $new->projection = new Identifier($column);

        return $new;
    }

    public function orderBy(string $column, string $direction = 'ASC'): static
    {
        $direction = strtoupper($direction);
        if (!in_array($direction, self::DIRECTIONS, true)) {
            throw new InvalidArgumentException(sprintf('"%s" is not a supported order direction.', $direction));
        }

        $new = clone $this;
        $new->orderBy[] = [new Identifier($column), $direction];

        return $new;
    }

    public function limit(int $limit): static
    {
        $new = clone $this;
        $new->limit = $limit;

        return $new;
    }

    public function offset(int $offset): static
    {
        $new = clone $this;
        $new->offset = $offset;

        return $new;
    }

    /**
     * @return Query<ImmList<T>>
     */
    public function get(): Query
    {
        return new Query(function (Connection $conn) {
            return io(function () use ($conn) {
                $rows = $this->execute($conn, $this->selectSql($conn))->fetchAll();

                return ImmList(...array_map(fn($row) => hydrate($this->model, $row), $rows));
            });
        });
    }

    /**
     * @return Query<Option<T>>
     */
    public function first(): Query
    {
        $one = $this->limit(1);

        return new Query(function (Connection $conn) use ($one) {
            return io(function () use ($conn, $one) {
                $row = $one->execute($conn, $one->selectSql($conn))->fetch();

                return $row === false ? None() : Some(hydrate($one->model, $row));
            });
        });
    }

    /**
     * @return Query<int>
     */
    public function count(): Query
    {
        return new Query(fn (Connection $conn) => io(fn() => (int) $this->execute($conn, $this->countSql($conn))->fetchColumn()));
    }

    /**
     * Delete the matching rows.
     *
     * @return Query<int> the number of rows deleted
     */
    public function delete(): Query
    {
        return new Query(fn (Connection $conn) => io(fn() => $this->execute($conn, 'DELETE FROM '.$conn->quote($this->table).$this->whereSql($conn))->rowCount()));
    }

    /**
     * The matching rows as a stream, each fetched from the statement and hydrated only when the stream
     * pulls it, with the driver set up not to buffer the result set.
     *
     * @return Query<Stream> Stream<IO, T> wrapped in Query
     */
    public function stream(): Query
    {
        return new Query(function (Connection $conn) {
            return io(function() use ($conn) {
                $stmt = executeForStreaming($conn, $this->selectSql($conn), $this->params());

                return StreamFromPDO($stmt)->map(fn($row) => hydrate($this->model, $row));
            });
        });
    }

    private function execute(Connection $conn, string $sql): PDOStatement
    {
        return execute($conn, $sql, $this->params());
    }

    /**
     * The bound values in the order the criteria, subqueries included, put their placeholders.
     *
     * @return list<mixed>
     */
    private function params(): array
    {
        $params = [];
        foreach ($this->criteria as [, $operator, $value]) {
            $params = match (true) {
                $value instanceof QueryBuilder => [...$params, ...$value->params()],
                'IN' === $operator => [...$params, ...$value],
                default => [...$params, $value],
            };
        }

        return $params;
    }

    private function selectSql(Connection $conn): string
    {
        $columns = null === $this->projection ? '*' : $conn->quote($this->projection);
        $sql = 'SELECT '.$columns.' FROM '.$conn->quote($this->table).$this->whereSql($conn);

        if (!empty($this->orderBy)) {
            $orders = array_map(fn($order) => sprintf('%s %s', $conn->quote($order[0]), $order[1]), $this->orderBy);
            $sql .= ' ORDER BY '.implode(', ', $orders);
        }

        if ($this->offset !== null && $this->limit === null) {
            throw new InvalidArgumentException('An offset needs a limit.');
        }

        if ($this->limit !== null) {
            $sql .= ' LIMIT '.$this->limit;
        }

        if ($this->offset !== null) {
            $sql .= ' OFFSET '.$this->offset;
        }

        return $sql;
    }

    private function countSql(Connection $conn): string
    {
        return 'SELECT COUNT(*) FROM '.$conn->quote($this->table).$this->whereSql($conn);
    }

    private function whereSql(Connection $conn): string
    {
        if (empty($this->criteria)) {
            return '';
        }

        $clauses = array_map(fn(array $criterion) => match (true) {
            $criterion[2] instanceof QueryBuilder => sprintf('%s IN (%s)', $conn->quote($criterion[0]), $criterion[2]->selectSql($conn)),
            'IN' === $criterion[1] => sprintf('%s IN (%s)', $conn->quote($criterion[0]), implode(', ', array_fill(0, count($criterion[2]), '?'))),
            default => sprintf('%s %s ?', $conn->quote($criterion[0]), $criterion[1]),
        }, $this->criteria);

        return ' WHERE '.implode(' AND ', $clauses);
    }
}
