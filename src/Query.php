<?php

namespace Phunkie\Phetch;

use Phunkie\Cats\Kleisli;
use Phunkie\Effect\IO\IO;
use Phunkie\Phetch\Connection\Connection;
use Phunkie\Types\ImmList;
use function ImmList;
use function Phunkie\Effect\Functions\io\io;

/**
 * @template A
 * @extends Kleisli<Connection, A, IO>
 */
class Query extends Kleisli
{
    /**
     * @param callable(Connection): IO<A> $run
     */
    public function __construct(callable $run)
    {
        parent::__construct($run);
    }

    /**
     * A query that yields the value without touching the connection.
     *
     * @template B
     * @param B $value
     * @return Query<B>
     */
    public static function pure(mixed $value): Query
    {
        return new Query(fn(Connection $c) => io(fn() => $value));
    }

    /**
     * A query that runs the effect without touching the connection.
     *
     * @template B
     * @param IO<B> $io
     * @return Query<B>
     */
    public static function liftIO(IO $io): Query
    {
        return new Query(fn(Connection $c) => $io);
    }

    /**
     * One query per value, run in order, with the results collected in a list.
     *
     * @template B
     * @template C
     * @param iterable<B> $values
     * @param callable(B): Query<C> $f
     * @return Query<ImmList<C>>
     */
    public static function traverse(iterable $values, callable $f): Query
    {
        $collected = self::pure([]);
        foreach ($values as $value) {
            $collected = $collected->flatMap(fn(array $done) => $f($value)->map(fn($result) => [...$done, $result]));
        }

        return $collected->map(fn(array $results) => ImmList(...$results));
    }

    /**
     * This query and then the given ones, in order, with every result combined by $f.
     *
     * @template B
     * @param list<Query<mixed>> $queries
     * @param callable(mixed ...$results): B $f
     * @return Query<B>
     */
    public function mapN(array $queries, callable $f): Query
    {
        return new Query(fn(Connection $c) => $this->run($c)->mapN(array_map(fn(Query $query) => $query->run($c), $queries), $f));
    }

    /**
     * @template B
     * @param callable(A): B $f
     * @return Query<B>
     */
    public function map(callable $f): Query
    {
        return new Query(fn(Connection $c) => $this->run($c)->map($f));
    }

    /**
     * @template B
     * @param callable(A): Query<B> $f
     * @return Query<B>
     */
    public function flatMap(callable $f): Query
    {
        return new Query(
            fn(Connection $c) => 
            $this->run($c)->flatMap(fn($a) => $f($a)->run($c))
        );
    }
}
