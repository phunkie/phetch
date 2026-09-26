# Testing

Test against a real database. SQLite in memory is fast enough to create per test, and the project's own migrations give it the real schema, so the tests exercise the same queries, quoting and constraints as production.

```php
use PHPUnit\Framework\TestCase;
use Phunkie\Phetch\Connection\Connection;
use Phunkie\Phetch\Migration\Migrator;

use function Phunkie\Phetch\Functions\connect;

final class BookshopTest extends TestCase
{
    private Connection $conn;

    protected function setUp(): void
    {
        $this->conn = connect('sqlite::memory:')->unsafeRun();
        $this->conn->pdo()->exec('PRAGMA foreign_keys = ON');

        ob_start();
        (new Migrator(__DIR__ . '/../database/migrations'))->run()->run($this->conn)->unsafeRun();
        ob_end_clean();
    }

    public function test_a_book_belongs_to_its_author(): void
    {
        $ada = create(Author::class, ['name' => 'Ada', 'email' => new Email('ada@example.com')])->run($this->conn)->unsafeRun();
        create(Book::class, ['authorId' => $ada->id, 'title' => 'Notes'])->run($this->conn)->unsafeRun();

        $books = where(Book::class, 'author_id', $ada->id)->run($this->conn)->unsafeRun();

        $this->assertSame(['Notes'], $books->map(fn(Book $book) => $book->title)->toArray());
    }
}
```

The migrator prints what it runs; the output buffering keeps it out of the test report.

## Testing an http4p application

Drive the application callable with a `Request`, no server needed:

```php
$app = (require __DIR__ . '/../routes.php')($this->conn);

$response = $app(Request(Method::POST, '/authors', null, '{"name":"Ada","email":"ada@example.com"}'))->unsafeRun();

$this->assertSame(201, $response->status->code);
$body = json_decode(implode('', $response->body->compile()->toArray()), true);
```

## Testing generated SQL

When the exact statement matters, a `Connection` around a mocked `PDO` catches it without a database:

```php
$pdo = $this->createMock(PDO::class);
$stmt = $this->createMock(PDOStatement::class);
$pdo->method('getAttribute')->with(PDO::ATTR_DRIVER_NAME)->willReturn('mysql');
$pdo->expects($this->once())->method('prepare')->with('SELECT * FROM `users` WHERE `age` > ? ORDER BY `name` ASC LIMIT 10')->willReturn($stmt);
$stmt->expects($this->once())->method('execute')->with([18]);
$stmt->method('fetchAll')->willReturn([]);

where(User::class, 'age', '>', 18)->orderBy('name')->limit(10)->run(new Connection($pdo))->unsafeRun();
```

## Isolation

Each test gets its own connection and therefore its own in-memory database, so tests never share rows. For a file-backed database, wrap each test in `transaction()` and throw at the end to roll it back, or recreate the file in `setUp`.
