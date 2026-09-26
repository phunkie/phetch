<?php

namespace Phunkie\Phetch;

use Phunkie\Cats\Kleisli;
use Phunkie\Effect\IO\IO;
use Phunkie\Phetch\Connection\Connection;
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
