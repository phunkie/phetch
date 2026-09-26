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
        return new Query(fn(Connection $c) => 
            $this->run($c)->flatMap(fn($a) => $f($a)->run($c))
        );
    }
}
