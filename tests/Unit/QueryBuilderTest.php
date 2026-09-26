<?php

namespace Tests\Unit;

use InvalidArgumentException;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use Phunkie\Phetch\Connection\Connection;
use Phunkie\Phetch\Identifier;
use Phunkie\Phetch\Query\QueryBuilder;

class QueryBuilderTest extends TestCase
{
    public function test_get_generates_quoted_sql_for_sqlite()
    {
        $conn = $this->connectionExpecting('sqlite', 'SELECT * FROM "users" WHERE "age" > ? ORDER BY "name" ASC LIMIT 10', [18]);

        $this->builder()->where('age', '>', 18)->orderBy('name')->limit(10)->get()->run($conn)->unsafeRun();
    }

    public function test_get_generates_backtick_quoted_sql_for_mysql()
    {
        $conn = $this->connectionExpecting('mysql', 'SELECT * FROM `users` WHERE `age` > ? ORDER BY `name` DESC', [18]);

        $this->builder()->where('age', '>', 18)->orderBy('name', 'desc')->get()->run($conn)->unsafeRun();
    }

    public function test_rejects_a_column_name_that_is_not_an_identifier()
    {
        $this->expectException(InvalidArgumentException::class);

        $this->builder()->where('name; DROP TABLE users; --', 'x');
    }

    public function test_rejects_an_unknown_operator()
    {
        $this->expectException(InvalidArgumentException::class);

        $this->builder()->where('age', '> 1 OR 1 = 1 --', 18);
    }

    public function test_rejects_an_order_direction_other_than_asc_or_desc()
    {
        $this->expectException(InvalidArgumentException::class);

        $this->builder()->orderBy('name', 'SIDEWAYS');
    }

    private function builder(): QueryBuilder
    {
        return new QueryBuilder('User', new Identifier('users'));
    }

    private function connectionExpecting(string $driver, string $sql, array $params): Connection
    {
        $pdo = $this->createMock(PDO::class);
        $stmt = $this->createMock(PDOStatement::class);
        $pdo->method('getAttribute')->with(PDO::ATTR_DRIVER_NAME)->willReturn($driver);
        $pdo->expects($this->once())->method('prepare')->with($sql)->willReturn($stmt);
        $stmt->expects($this->once())->method('execute')->with($params);
        $stmt->method('fetchAll')->willReturn([]);

        return new Connection($pdo);
    }
}
