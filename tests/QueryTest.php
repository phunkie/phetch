<?php

namespace Tests;

use PHPUnit\Framework\TestCase;
use Phunkie\Phetch\Attributes\Table;
use Phunkie\Phetch\Connection\Connection;

use Phunkie\Phetch\Query;
use Phunkie\Types\Option;

use function Phunkie\Effect\Functions\io\io;
use function Phunkie\Phetch\Functions\{connect, find, create, where, all, remove};

#[Table('users')]
readonly class User {
    public function __construct(
        public int $id,
        public string $name,
        public string $email
    ) {}
}

class QueryTest extends TestCase
{
    private Connection $conn;

    protected function setUp(): void
    {
        if (!in_array('sqlite', \PDO::getAvailableDrivers())) {
            $this->markTestSkipped('SQLite PDO driver not available');
        }

        $this->conn = connect('sqlite::memory:')->unsafeRun();
        
        $pdo = $this->conn->pdo();
        $pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, email TEXT)");
    }

    public function test_find_returns_none_when_not_found()
    {
        $result = find(User::class, 999)->run($this->conn)->unsafeRun();
        $this->assertTrue($result->isEmpty());
    }

    public function test_create_and_find()
    {
        $user = create(User::class, ['name' => 'John', 'email' => 'john@test.com'])
            ->run($this->conn)
            ->unsafeRun();
        
        $this->assertInstanceOf(User::class, $user);
        $this->assertEquals(1, $user->id);
        $this->assertEquals('John', $user->name);
        
        // Find
        $opt = find(User::class, 1)->run($this->conn)->unsafeRun();
        $this->assertTrue($opt->isDefined());
        $this->assertEquals($user, $opt->get());
    }

    public function test_where_query()
    {
        // Compose create operations
        create(User::class, ['name' => 'A', 'email' => 'a@a.com'])
            ->flatMap(fn($_) => create(User::class, ['name' => 'B', 'email' => 'b@b.com']))
            ->run($this->conn)
            ->unsafeRun();
        
        // Where
        $list = where(User::class, 'name', 'A')->get()->run($this->conn)->unsafeRun();
        $this->assertEquals(1, $list->length);
        $this->assertEquals('A', $list->head->name);
        
        // All
        $all = all(User::class)->run($this->conn)->unsafeRun();
        $this->assertEquals(2, $all->length);
    }
    
    public function test_stream_yields_hydrated_models()
    {
        create(User::class, ['name' => 'A', 'email' => 'a@a.com'])
            ->flatMap(fn($_) => create(User::class, ['name' => 'B', 'email' => 'b@b.com']))
            ->run($this->conn)
            ->unsafeRun();

        $names = where(User::class, 'name', 'B')
            ->stream()
            ->run($this->conn)
            ->unsafeRun()
            ->map(fn(User $user) => $user->name)
            ->compile()
            ->toList();

        $this->assertEquals(ImmList('B'), $names);
    }

    public function test_remove_reports_whether_a_row_was_deleted()
    {
        create(User::class, ['name' => 'A', 'email' => 'a@a.com'])->run($this->conn)->unsafeRun();

        $this->assertTrue(remove(User::class, 1)->run($this->conn)->unsafeRun());
        $this->assertTrue(find(User::class, 1)->run($this->conn)->unsafeRun()->isEmpty());
        $this->assertFalse(remove(User::class, 1)->run($this->conn)->unsafeRun());
    }

    public function test_pure_lifts_a_value_into_a_query()
    {
        $this->assertSame(42, Query::pure(42)->run($this->conn)->unsafeRun());
    }

    public function test_lift_io_embeds_an_effect_into_a_query()
    {
        $calls = 0;
        $query = Query::liftIO(io(function () use (&$calls) {
            return ++$calls;
        }));

        $this->assertSame(0, $calls);
        $this->assertSame(1, $query->run($this->conn)->unsafeRun());
    }

    public function test_flat_map_can_branch_into_a_pure_query()
    {
        $greeting = find(User::class, 1)->flatMap(fn(Option $user) => $user->isDefined()
            ? Query::pure('Hello ' . $user->get()->name)
            : Query::pure('Hello stranger'));

        $this->assertSame('Hello stranger', $greeting->run($this->conn)->unsafeRun());

        create(User::class, ['name' => 'Ada', 'email' => 'ada@a.com'])->run($this->conn)->unsafeRun();

        $this->assertSame('Hello Ada', $greeting->run($this->conn)->unsafeRun());
    }

    public function test_hydration_handles_order()
    {
        $pdo = $this->conn->pdo();
        $pdo->exec("INSERT INTO users (email, name) VALUES ('c@c.com', 'C')");

        $opt = find(User::class, 1)->run($this->conn)->unsafeRun();
        $this->assertTrue($opt->isDefined());
        $user = $opt->get();
        $this->assertEquals('C', $user->name);
        $this->assertEquals('c@c.com', $user->email);
    }
}
