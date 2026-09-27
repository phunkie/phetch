<?php

namespace Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use Phunkie\Phetch\Attributes\Table;
use Phunkie\Phetch\Connection\Connection;

use function Phunkie\Effect\Functions\io\io;
use function Phunkie\Phetch\Functions\{all, connect, where};

#[Table('entries')]
readonly class Entry {
    public function __construct(
        public int $id,
        public string $payload
    ) {}
}

class StreamingTest extends TestCase
{
    private const ROWS = 200000;

    private Connection $conn;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers())) {
            $this->markTestSkipped('SQLite PDO driver not available');
        }

        $this->conn = connect('sqlite::memory:')->unsafeRun();
        $pdo = $this->conn->pdo();
        $pdo->exec('CREATE TABLE entries (id INTEGER PRIMARY KEY, payload TEXT NOT NULL)');
        $pdo->beginTransaction();
        $insert = $pdo->prepare('INSERT INTO entries (payload) VALUES (?)');
        for ($i = 0; $i < self::ROWS; $i++) {
            $insert->execute([str_repeat('x', 100)]);
        }
        $pdo->commit();
    }

    public function test_streaming_two_hundred_thousand_rows_holds_one_row_at_a_time()
    {
        $count = 0;
        $counting = function (string $payload) use (&$count) {
            return io(function () use (&$count) {
                $count++;
            });
        };
        $before = memory_get_peak_usage();

        all(Entry::class)->stream()->run($this->conn)
            ->flatMap(fn ($entries) => $entries->map(fn (Entry $entry) => $entry->payload)->evalTap($counting)->compile()->drain())
            ->unsafeRun();

        $this->assertSame(self::ROWS, $count);
        $this->assertLessThan(8 * 1024 * 1024, memory_get_peak_usage() - $before);
    }

    public function test_take_stops_fetching_once_it_has_enough()
    {
        $first = all(Entry::class)->stream()->run($this->conn)->unsafeRun()->take(3)->compile()->toList();

        $this->assertSame([1, 2, 3], $first->map(fn (Entry $entry) => $entry->id)->toArray());
    }

    public function test_a_streamed_query_carries_its_criteria()
    {
        $ids = where(Entry::class, 'id', '<=', 2)->orderBy('id', 'desc')->stream()->run($this->conn)->unsafeRun()->map(fn (Entry $entry) => $entry->id)->compile()->toArray();

        $this->assertSame([2, 1], $ids);
    }
}
