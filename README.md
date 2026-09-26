# Phunkie Phetch

A functional database library for PHP inspired by Scala's Slick.

## Overview

Phetch describes database work as values. Every operation is a `Query`, a function from a `Connection` to an `IO` from [phunkie/effect](https://github.com/phunkie/effect). Nothing runs until you bind a connection and run the effect, so queries compose with `map` and `flatMap` like any other value and slot straight into an [http4p](https://github.com/phunkie/http4p) handler.

- **Plain readonly models** hydrated by constructor parameter, with `#[Table]` and `#[Column]` where names differ
- **Pure functions** for the CRUD cases and a composable builder for the rest
- **Identifiers validated and quoted** per driver, values always bound as parameters
- **Streaming** of large result sets through [phunkie/streams](https://github.com/phunkie/streams)
- **Migrations** with a small CLI

## Installation

```bash
composer require phunkie/phetch
```

Requires PHP 8.2 or later with the PDO driver for your database, and phunkie/phunkie ^1.5, phunkie/effect ^1.3, phunkie/streams ^1.2.

## Quick Start

```php
use Phunkie\Phetch\Attributes\Generated;
use Phunkie\Phetch\Attributes\Table;
use Phunkie\Types\Option;

use function Phunkie\Phetch\Functions\{connect, create, find};

#[Table('users')]
final readonly class User
{
    public function __construct(
        #[Generated] public int $id,
        public string $name,
        public string $email,
    ) {
    }
}

$conn = connect('sqlite:app.sqlite')->unsafeRun();

$user = create(User::class, ['name' => 'Ada', 'email' => 'ada@example.com'])
    ->run($conn)
    ->unsafeRun();                                        // User

$maybe = find(User::class, $user->id)->run($conn)->unsafeRun(); // Option<User>
```

## Models

A model is any class whose constructor parameters match the row. Rows are mapped to parameters by name, so a `readonly` class with promoted constructor properties is all you need.

- `#[Table('users')]` names the table. Without it, the lowercased short class name plus `s` is used.
- `#[Table('accounts', primaryKey: 'account_id')]` names the primary key. The default is `id`.
- A parameter named `publishedYear` is fed from a `publishedYear` column if there is one, otherwise from `published_year`.
- `#[Column('author_id')]` on a parameter maps it explicitly.
- `#[Generated]` marks a parameter whose value the server produces, such as an auto-increment key or a timestamp. Phetch reads it like any other column; http4p's `decode` never expects it from a client.

```php
use Phunkie\Phetch\Attributes\Column;
use Phunkie\Phetch\Attributes\Table;

use Phunkie\Phetch\Attributes\Generated;

#[Table('books')]
final readonly class Book
{
    public function __construct(
        #[Generated] public int $id,
        #[Column('author_id')] public int $writer,
        public string $title,
        public int $publishedYear,
    ) {
    }
}
```

The arrays you pass to `create` and `update` may be keyed by column name or by constructor parameter name; a parameter name is written to its `#[Column]`, or to the snake_case form of the name.

## Connections

```php
use function Phunkie\Phetch\Functions\connect;

connect(string $dsn, ?string $username = null, ?string $password = null, array $options = []); // IO<Connection>
```

The connection wraps a PDO handle in exception mode with associative fetches. Keep it and pass it to `run()`.

## Queries

A `Query<A>` is a function `Connection -> IO<A>`. Bind the connection with `run()` and compose the rest in `IO`:

```php
find(User::class, 1)->run($conn)                                     // IO<Option<User>>
    ->flatMap(fn(Option $user) => $user->fold(
        io(fn() => 'Hello stranger'),
        fn(User $found) => io(fn() => 'Hello ' . $found->name),
    ));
```

Queries also compose among themselves with `map` and `flatMap`, which keeps a chain of database steps as one value until it is run. `Query::pure` lifts a plain value and `Query::liftIO` lifts an effect into such a chain:

```php
use Phunkie\Phetch\Query;

$authorWithBooks = find(User::class, 1)->flatMap(fn(Option $user) => $user->fold(
    Query::pure(None()),
    fn(User $found) => where(Post::class, 'user_id', $found->id)->get()->map(fn($posts) => Some(Pair($found, $posts))),
));

$authorWithBooks->run($conn); // IO<Option<Pair<User, ImmList<Post>>>>
```

Several independent queries combine with `mapN`, and one query per value runs with `traverse`:

```php
findOrFail(Book::class, $id)->flatMap(fn(Book $book) => find(Author::class, $book->authorId)
    ->mapN([$tagsOf($book), where(Review::class, 'book_id', $book->id)->get()], fn(Option $author, ImmList $tags, ImmList $reviews) => [...]));

Query::traverse($names, fn(string $name) => findOrCreate(Tag::class, ['name' => $name])); // Query<ImmList<Tag>>
```

## CRUD Functions

```php
use function Phunkie\Phetch\Functions\{find, findOrFail, findBy, findOrCreate, all, create, insert, update, remove, where, transaction};

find(User::class, 1);                                       // Query<Option<User>>
findOrFail(User::class, 1);                                 // Query<User>, fails with RowNotFound
findBy(User::class, 'email', 'ada@example.com');            // Query<Option<User>>
findOrCreate(Tag::class, ['name' => 'maths']);              // Query<Tag>, created from the given columns when missing
all(User::class);                                           // Query<ImmList<User>>, also a builder
create(User::class, ['name' => 'Ada', 'email' => '...']);   // Query<User>, read back by its generated key
insert(BookTag::class, ['bookId' => 1, 'tagId' => 2]);     // Query<int>, for rows without a generated key
update(User::class, 1, ['name' => 'Ada Lovelace']);         // Query<Option<User>>, None when the id is unknown
remove(User::class, 1);                                     // Query<bool>, true when a row was deleted
where(User::class, 'active', true);                         // a builder, see below
transaction($query);                                        // Query<A>, committed on success, rolled back when it throws
```

A write the database refuses, a duplicate key or a missing foreign row, fails with `ConstraintViolation`, whatever the driver. Enums, dates, `Stringable` objects and value objects with a single public property are stored as scalars and rebuilt on the way back.

Table and column names must be identifiers (`[A-Za-z_][A-Za-z0-9_]*`) and are quoted for the driver. Anything else, including an empty array for `update`, throws `InvalidArgumentException` when the query is built, before any IO runs. Values are always bound as prepared-statement parameters.

## Query Builder

`where()` and `all()` return a builder. Each call returns a new builder, so partial queries can be shared.

```php
$adults = where(User::class, 'age', '>=', 18)->orderBy('name');

$adults->get();                                   // Query<ImmList<User>>
$adults->first();                                 // Query<Option<User>>
$adults->count();                                 // Query<int>
$adults->limit(20)->offset(40)->get();            // page three
$adults->where('active', true)->get();            // criteria combine with AND
$adults->stream();                                // Query<Stream<User>>
```

- `where($column, $value)` or `where($column, $operator, $value)` with one of `=`, `!=`, `<>`, `<`, `<=`, `>`, `>=`, `LIKE`, `NOT LIKE`, `IS`, `IS NOT`
- `whereIn($column, [...])`, or `whereIn($column, $builder->select('id'))` to embed another builder as a subquery
- `orderBy($column, 'ASC' | 'DESC')`
- `limit($n)` and `offset($n)`; an offset needs a limit
- `delete()` removes the matching rows, `Query<int>`

Relationships through a link table are one query:

```php
$tagsOf = fn(Book $book) => all(Tag::class)
    ->whereIn('id', where(BookTag::class, 'book_id', $book->id)->select('tag_id'))
    ->orderBy('name');
```

A builder is itself a `Query<ImmList<T>>`, so `all(User::class)->run($conn)` fetches every row without calling `get()`.

## Relationships

There is no relationship layer. A relationship is a function that returns a query:

```php
function booksOf(User $author): Query
{
    return where(Book::class, 'author_id', $author->id)->orderBy('published_year')->get();
}

find(User::class, 1)->flatMap(fn(Option $author) => $author->isDefined()
    ? booksOf($author->get())
    : Query::pure(ImmList()));
```

## Streaming

`stream()` yields the rows through a [phunkie/streams](https://github.com/phunkie/streams) `Stream`, hydrating each row as it is pulled:

```php
$names = where(User::class, 'active', true)->stream()
    ->run($conn)
    ->unsafeRun()                                   // Stream<User>
    ->map(fn(User $user) => $user->name)
    ->compile()
    ->toList();

where(User::class, 'active', true)->stream()
    ->run($conn)
    ->unsafeRun()
    ->evalTap(fn(User $user) => io(fn() => print($user->email . "\n")))
    ->compile()
    ->drain()
    ->unsafeRun();
```

A stream can be an HTTP body. See the http4p section.

## Effects Around Queries

Anything that returns an `IO` joins a query chain through `Query::liftIO`. To run something after a write without waiting for it, fork it with `start()`:

```php
function sendWelcomeEmail(User $user): IO
{
    return io(fn() => mail($user->email, 'Welcome', '...'));
}

create(User::class, $data)
    ->flatMap(fn(User $user) => Query::liftIO(sendWelcomeEmail($user)->map(fn() => $user)));       // waits

create(User::class, $data)
    ->flatMap(fn(User $user) => Query::liftIO(sendWelcomeEmail($user)->start()->map(fn() => $user))); // forks
```

## Migrations

Create `phetch.php` in the project root:

```php
<?php

return [
    'dsn' => 'sqlite:' . __DIR__ . '/database/app.sqlite',
    'migrations' => __DIR__ . '/database/migrations',
];
```

| Command | Effect |
|---------|--------|
| `vendor/bin/phetch make:migration CreateUsers` | Writes `database/migrations/<timestamp>_CreateUsers.php` |
| `vendor/bin/phetch migrate` | Runs pending migrations, each in its own transaction |
| `vendor/bin/phetch rollback` | Rolls back the last batch |
| `vendor/bin/phetch migrate:status` | Lists every migration and the batch it ran in |
| `vendor/bin/phetch migrate:init` | Creates the `migrations` table |

A migration is a class named after the file without its timestamp, returning a `Query` from `up()` and `down()`:

```php
use Phunkie\Phetch\Migration\Migration;
use Phunkie\Phetch\Query;

use function Phunkie\Effect\Functions\io\io;

class CreateUsers implements Migration
{
    public function up(): Query
    {
        return new Query(fn($conn) => io(fn() => $conn->pdo()->exec(
            'CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT NOT NULL, email TEXT NOT NULL UNIQUE)'
        )));
    }

    public function down(): Query
    {
        return new Query(fn($conn) => io(fn() => $conn->pdo()->exec('DROP TABLE users')));
    }
}
```

See [docs/migrations](docs/migrations/getting-started.md) for details.

## Integration with Http4p

Http4p handlers return `IO<Response>`. Run the query, then compose the response in `IO`. `decode($req, User::class)` validates the body against the entity's constructor: the path parameters fill the parameters of the same name, on `POST` and `PUT` every parameter without a default is required except those marked `#[Generated]`, a `PATCH` may send any subset, and a bad body is answered with a `400` listing the errors before the handler runs. `findOrFail` and `ConstraintViolation` become `404` and `409` through http4p's `Recover` middleware, once for the whole app. The response constructors take the body as their first argument, so `->flatMap(Ok(...))` is all a handler needs to answer:

```php
<?php

use Phunkie\Http4p\Request;
use Phunkie\Http4p\Router;
use Phunkie\Phetch\Connection\Connection;
use Phunkie\Phetch\ConstraintViolation;
use Phunkie\Phetch\RowNotFound;

use function Phunkie\Http4p\Functions\decode;
use function Phunkie\Http4p\Functions\HttpRoutes;
use function Phunkie\Http4p\Functions\middleware\{Recover, Through};
use function Phunkie\Http4p\Functions\response\{Conflict, Created, NoContent, NotFound, Ok};
use function Phunkie\Http4p\Functions\routes\{DELETE, GET, PATCH, POST};
use function Phunkie\Phetch\Functions\{all, create, findOrFail, remove, update, where};

return fn(Connection $conn): callable => Through(
    new Router(HttpRoutes(
        GET('/users', fn() => all(User::class)->run($conn)->flatMap(Ok(...))),

        GET('/users/:id', fn(int $id) => findOrFail(User::class, $id)->run($conn)->flatMap(Ok(...))),

        POST('/users', fn(Request $req) =>
            decode($req, User::class)
                ->flatMap(fn(array $data) => create(User::class, $data)->run($conn))
                ->flatMap(Created(...))
        ),

        PATCH('/users/:id', fn(int $id, Request $req) =>
            decode($req, User::class)
                ->flatMap(fn(array $data) => findOrFail(User::class, $id)->flatMap(fn() => update(User::class, $id, $data))->run($conn))
                ->flatMap(fn(Option $user) => Ok($user->get()))
        ),

        DELETE('/users/:id', fn(int $id) =>
            remove(User::class, $id)->run($conn)->flatMap(fn(bool $deleted) => $deleted ? NoContent() : NotFound())
        ),

        GET('/users/:userId/posts', fn(int $userId) =>
            findOrFail(User::class, $userId)
                ->flatMap(fn(User $user) => where(Post::class, 'user_id', $user->id)->orderBy('created_at', 'DESC'))
                ->run($conn)->flatMap(Ok(...))
        ),

        GET('/users/export', fn() =>
            where(User::class, 'active', true)->stream()->run($conn)
                ->flatMap(fn($users) => Ok($users->map(fn(User $user) => json_encode($user) . "\n")))
        ),
    )),
    Recover(RowNotFound::class, fn(RowNotFound $e) => NotFound(['error' => $e->getMessage()])),
    Recover(ConstraintViolation::class, fn(ConstraintViolation $e) => Conflict(['error' => $e->getMessage()])),
);
```

```php
// public/index.php
$app = require dirname(__DIR__) . '/routes.php';

connect('sqlite:app.sqlite')
    ->flatMap(fn(Connection $conn) => (new PhpServer($app($conn)))->run())
    ->unsafeRun();
```

`ImmList`, `ImmMap`, `ImmSet` and tuples are `JsonSerializable` from phunkie 1.5, so query results go straight into the response constructors. The decoded array carries only the entity's own fields, so request data never reaches `create` or `update` unfiltered.

## Testing

Run the real code against an in-memory SQLite database migrated by your own migration files:

```php
$conn = connect('sqlite::memory:')->unsafeRun();
(new Migrator(__DIR__ . '/../database/migrations'))->run()->run($conn)->unsafeRun();
```

## Documentation

Full documentation is in [docs/](docs/index.md).

- [Quick Start](docs/getting-started/quick-start.md)
- [Models](docs/core/models.md) and [Queries](docs/core/queries.md)
- [CRUD Operations](docs/crud/operations.md) and the [Query Builder](docs/querying/builder.md)
- [Relationships](docs/querying/relationships.md)
- [Errors and Transactions](docs/core/errors-and-transactions.md)
- [Streaming](docs/streaming/queries.md)
- [Http4p Integration](docs/integration/http4p.md)
- [Testing](docs/advanced/testing.md)
- [Migrations](docs/migrations/getting-started.md)

## License

MIT Licence

## Acknowledgments

- Inspired by [Slick](https://scala-slick.org/)
- Built on [Phunkie](https://github.com/phunkie/phunkie)
