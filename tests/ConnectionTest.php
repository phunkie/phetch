<?php

namespace Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use Phunkie\Phetch\Attributes\Generated;
use Phunkie\Phetch\Attributes\Table;
use Phunkie\Phetch\Connection\Connection;
use Phunkie\Phetch\Constraint;
use Phunkie\Phetch\ConstraintViolation;

use function Phunkie\Phetch\Functions\{connect, create, find, remove};

#[Table('publishers')]
readonly class Publisher {
    public function __construct(
        #[Generated]
        public int $id,
        public string $name
    ) {}
}

#[Table('titles')]
readonly class Title {
    public function __construct(
        #[Generated]
        public int $id,
        public int $publisherId,
        public string $name,
        public int $stars = 3
    ) {}
}

class ConnectionTest extends TestCase
{
    private Connection $conn;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers())) {
            $this->markTestSkipped('SQLite PDO driver not available');
        }

        $this->conn = connect('sqlite::memory:', statements: ['PRAGMA foreign_keys = ON'])->unsafeRun();

        $pdo = $this->conn->pdo();
        $pdo->exec('CREATE TABLE publishers (id INTEGER PRIMARY KEY, name TEXT NOT NULL UNIQUE)');
        $pdo->exec('CREATE TABLE titles (id INTEGER PRIMARY KEY, publisher_id INTEGER NOT NULL REFERENCES publishers(id), name TEXT NOT NULL, stars INTEGER NOT NULL DEFAULT 3 CHECK (stars BETWEEN 1 AND 5))');
    }

    public function test_statements_run_once_the_connection_is_open()
    {
        $this->assertSame(1, (int) $this->conn->pdo()->query('PRAGMA foreign_keys')->fetchColumn());
    }

    public function test_statements_run_in_order()
    {
        $conn = new Connection(new PDO('sqlite::memory:'), ['CREATE TABLE log (line TEXT)', "INSERT INTO log VALUES ('first')", "INSERT INTO log VALUES ('second')"]);

        $this->assertSame(['first', 'second'], $conn->pdo()->query('SELECT line FROM log')->fetchAll(PDO::FETCH_COLUMN));
    }

    public function test_a_duplicate_key_is_a_unique_violation()
    {
        create(Publisher::class, ['name' => 'Penguin'])->run($this->conn)->unsafeRun();

        $violation = $this->violationFrom(fn() => create(Publisher::class, ['name' => 'Penguin'])->run($this->conn)->unsafeRun());

        $this->assertSame(Constraint::Unique, $violation->constraint);
        $this->assertSame('UNIQUE constraint failed: publishers.name', $violation->getMessage());
    }

    public function test_a_missing_foreign_row_is_a_foreign_key_violation()
    {
        $violation = $this->violationFrom(fn() => create(Title::class, ['publisherId' => 9, 'name' => 'Notes'])->run($this->conn)->unsafeRun());

        $this->assertSame(Constraint::ForeignKey, $violation->constraint);
    }

    public function test_deleting_a_referenced_row_is_a_foreign_key_violation()
    {
        $publisher = create(Publisher::class, ['name' => 'Penguin'])->run($this->conn)->unsafeRun();
        create(Title::class, ['publisherId' => $publisher->id, 'name' => 'Notes'])->run($this->conn)->unsafeRun();

        $violation = $this->violationFrom(fn() => remove(Publisher::class, $publisher->id)->run($this->conn)->unsafeRun());

        $this->assertSame(Constraint::ForeignKey, $violation->constraint);
        $this->assertTrue(find(Publisher::class, $publisher->id)->run($this->conn)->unsafeRun()->isDefined());
    }

    public function test_a_null_in_a_required_column_is_a_not_null_violation()
    {
        $violation = $this->violationFrom(fn() => create(Publisher::class, ['name' => null])->run($this->conn)->unsafeRun());

        $this->assertSame(Constraint::NotNull, $violation->constraint);
    }

    public function test_a_value_outside_a_check_is_a_check_violation()
    {
        $publisher = create(Publisher::class, ['name' => 'Penguin'])->run($this->conn)->unsafeRun();

        $violation = $this->violationFrom(fn() => create(Title::class, ['publisherId' => $publisher->id, 'name' => 'Notes', 'stars' => 9])->run($this->conn)->unsafeRun());

        $this->assertSame(Constraint::Check, $violation->constraint);
    }

    private function violationFrom(callable $write): ConstraintViolation
    {
        try {
            $write();
        } catch (ConstraintViolation $e) {
            return $e;
        }

        $this->fail('Expected a ConstraintViolation.');
    }
}
