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

    public function test_offset_follows_limit()
    {
        $conn = $this->connectionExpecting('sqlite', 'SELECT * FROM "users" ORDER BY "name" ASC LIMIT 10 OFFSET 20', []);

        $this->builder()->orderBy('name')->limit(10)->offset(20)->get()->run($conn)->unsafeRun();
    }

    public function test_count_generates_a_count_query()
    {
        $conn = $this->connectionExpecting('sqlite', 'SELECT COUNT(*) FROM "users" WHERE "age" > ?', [18], fetchColumn: '3');

        $this->assertSame(3, $this->builder()->where('age', '>', 18)->count()->run($conn)->unsafeRun());
    }

    public function test_first_limits_to_one_row()
    {
        $conn = $this->connectionExpecting('sqlite', 'SELECT * FROM "users" WHERE "age" > ? LIMIT 1', [18]);

        $this->assertTrue($this->builder()->where('age', '>', 18)->first()->run($conn)->unsafeRun()->isEmpty());
    }

    public function test_where_in_binds_one_placeholder_per_value()
    {
        $conn = $this->connectionExpecting('sqlite', 'SELECT * FROM "users" WHERE "age" > ? AND "id" IN (?, ?)', [18, 1, 3]);

        $this->builder()->where('age', '>', 18)->whereIn('id', [1, 3])->get()->run($conn)->unsafeRun();
    }

    public function test_where_in_embeds_a_subquery_with_its_parameters_in_order()
    {
        $conn = $this->connectionExpecting('sqlite', 'SELECT * FROM "users" WHERE "active" = ? AND "id" IN (SELECT "member_id" FROM "memberships" WHERE "group_id" = ?) AND "age" > ?', [1, 3, 18]);

        $this->builder()->where('active', 1)
            ->whereIn('id', (new QueryBuilder('Membership', new Identifier('memberships')))->where('group_id', 3)->select('member_id'))
            ->where('age', '>', 18)
            ->get()->run($conn)->unsafeRun();
    }

    public function test_delete_removes_the_matching_rows()
    {
        $conn = $this->connectionExpecting('sqlite', 'DELETE FROM "users" WHERE "age" > ?', [18], rowCount: 4);

        $this->assertSame(4, $this->builder()->where('age', '>', 18)->delete()->run($conn)->unsafeRun());
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

    public function test_stream_on_mysql_leaves_the_result_set_unbuffered_while_the_statement_runs()
    {
        $events = [];
        $pdo = $this->createMock(PDO::class);
        $stmt = $this->createMock(PDOStatement::class);
        $pdo->method('getAttribute')->willReturnCallback(fn (int $attribute) => PDO::ATTR_DRIVER_NAME === $attribute ? 'mysql' : true);
        $pdo->method('setAttribute')->willReturnCallback(function (int $attribute, mixed $value) use (&$events) {
            if (1000 === $attribute) {
                $events[] = $value ? 'buffered' : 'unbuffered';
            }

            return true;
        });
        $pdo->method('prepare')->with('SELECT * FROM `users`')->willReturn($stmt);
        $stmt->method('execute')->willReturnCallback(function () use (&$events) {
            $events[] = 'execute';

            return true;
        });
        $stmt->method('fetch')->willReturn(false);

        $this->builder()->stream()->run(new Connection($pdo))->unsafeRun()->compile()->toArray();

        $this->assertSame(['unbuffered', 'execute', 'buffered'], $events);
    }

    public function test_stream_on_postgresql_reads_through_a_server_side_cursor()
    {
        $pdo = $this->createMock(PDO::class);
        $stmt = $this->createMock(PDOStatement::class);
        $pdo->method('getAttribute')->with(PDO::ATTR_DRIVER_NAME)->willReturn('pgsql');
        $pdo->expects($this->once())->method('prepare')->with('SELECT * FROM "users" WHERE "age" > ?', [PDO::ATTR_CURSOR => PDO::CURSOR_SCROLL])->willReturn($stmt);
        $stmt->expects($this->once())->method('execute')->with([18]);
        $stmt->method('fetch')->willReturn(false);

        $this->builder()->where('age', '>', 18)->stream()->run(new Connection($pdo))->unsafeRun()->compile()->toArray();
    }

    public function test_stream_on_sqlite_prepares_a_plain_statement()
    {
        $prepared = [];
        $pdo = $this->createMock(PDO::class);
        $stmt = $this->createMock(PDOStatement::class);
        $pdo->method('getAttribute')->with(PDO::ATTR_DRIVER_NAME)->willReturn('sqlite');
        $pdo->method('prepare')->willReturnCallback(function (string $sql, array $options = []) use (&$prepared, $stmt) {
            $prepared[] = [$sql, $options];

            return $stmt;
        });
        $stmt->method('fetch')->willReturn(false);

        $rows = $this->builder()->stream()->run(new Connection($pdo))->unsafeRun()->compile()->toArray();

        $this->assertSame([['SELECT * FROM "users"', []]], $prepared);
        $this->assertSame([], $rows);
    }

    private function builder(): QueryBuilder
    {
        return new QueryBuilder('User', new Identifier('users'));
    }

    private function connectionExpecting(string $driver, string $sql, array $params, mixed $fetchColumn = false, int $rowCount = 0): Connection
    {
        $pdo = $this->createMock(PDO::class);
        $stmt = $this->createMock(PDOStatement::class);
        $pdo->method('getAttribute')->with(PDO::ATTR_DRIVER_NAME)->willReturn($driver);
        $pdo->expects($this->once())->method('prepare')->with($sql)->willReturn($stmt);
        $stmt->expects($this->once())->method('execute')->with($params);
        $stmt->method('fetchAll')->willReturn([]);
        $stmt->method('fetch')->willReturn(false);
        $stmt->method('fetchColumn')->willReturn($fetchColumn);
        $stmt->method('rowCount')->willReturn($rowCount);

        return new Connection($pdo);
    }
}
