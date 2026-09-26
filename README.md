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
use Phunkie\Phetch\Attributes\Table;
use Phunkie\Types\Option;

use function Phunkie\Phetch\Functions\{connect, create, find};

#[Table('users')]
final readonly class User
{
    public function __construct(
        public int $id,
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

```php
use Phunkie\Phetch\Attributes\Column;
use Phunkie\Phetch\Attributes\Table;

#[Table('books')]
final readonly class Book
{
    public function __construct(
        public int $id,
        #[Column('author_id')] public int $writer,
        public string $title,
        public int $publishedYear,
    ) {
    }
}
```

The arrays you pass to `create` and `update` use column names.

## Connections

```php
use function Phunkie\Phetch\Functions\connect;

connect(string $dsn, ?string $username = null, ?string $password = null, array $options = []); // IO<Connection>
```

The connection wraps a PDO handle in exception mode with associative fetches. Keep it and pass it to `run()`.

## Queries

A `Query<A>` is a function `Connection -> IO<A>`. Bind the connection with `run()`, then run the `IO`:

```php
find(User::class, 1)->run($conn)->unsafeRun();
```

Queries compose with `map` and `flatMap`. `Query::pure` lifts a plain value and `Query::liftIO` lifts an effect, so a chain can branch into values or effects that need no connection and still end as one `Query`:

```php
use Phunkie\Phetch\Query;

$greeting = find(User::class, 1)->flatMap(fn(Option $user) => $user->isDefined()
    ? Query::pure('Hello ' . $user->get()->name)
    : Query::liftIO(io(fn() => 'Hello stranger')));

$greeting->run($conn); // IO<string>
```

## CRUD Functions

```php
use function Phunkie\Phetch\Functions\{find, findBy, all, create, update, remove, where};

find(User::class, 1);                                       // Query<Option<User>>
findBy(User::class, 'email', 'ada@example.com');            // Query<Option<User>>
all(User::class);                                           // Query<ImmList<User>>, also a builder
create(User::class, ['name' => 'Ada', 'email' => '...']);   // Query<User>
update(User::class, 1, ['name' => 'Ada Lovelace']);         // Query<Option<User>>, None when the id is unknown
remove(User::class, 1);                                     // Query<bool>, true when a row was deleted
where(User::class, 'active', true);                         // a builder, see below
```

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
- `orderBy($column, 'ASC' | 'DESC')`
- `limit($n)` and `offset($n)`; an offset needs a limit

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

Http4p handlers return `IO<Response>`. Compose the query, lift the response constructors with `Query::liftIO`, and bind the connection at the end with `run($conn)`:

```php
use Phunkie\Http4p\Request;
use Phunkie\Phetch\Query;
use Phunkie\Types\Option;

use function Phunkie\Http4p\Functions\{decode, HttpRoutes};
use function Phunkie\Http4p\Functions\response\{Ok, Created, NotFound, NoContent};
use function Phunkie\Http4p\Functions\routes\{GET, POST, PUT, DELETE};
use function Phunkie\Phetch\Functions\{all, find, create, update, remove, where};

$author = fn(int $id, callable $onFound) => find(User::class, $id)->flatMap(fn(Option $user) => $user->isDefined()
    ? $onFound($user->get())
    : Query::liftIO(NotFound(['error' => sprintf('User %d not found', $id)])));

$routes = HttpRoutes(
    GET('/users', fn() =>
        all(User::class)->flatMap(fn($users) => Query::liftIO(Ok($users)))->run($conn)
    ),

    GET('/users/:id', fn(int $id) =>
        $author($id, fn(User $user) => Query::liftIO(Ok($user)))->run($conn)
    ),

    POST('/users', fn(Request $req) =>
        decode($req)->flatMap(fn(array $data) =>
            create(User::class, $data)->flatMap(fn($user) => Query::liftIO(Created($user)))->run($conn)
        )
    ),

    PUT('/users/:id', fn(int $id, Request $req) =>
        decode($req)->flatMap(fn(array $data) =>
            update(User::class, $id, $data)->flatMap(fn(Option $user) => Query::liftIO($user->isDefined()
                ? Ok($user->get())
                : NotFound(['error' => sprintf('User %d not found', $id)])))->run($conn)
        )
    ),

    DELETE('/users/:id', fn(int $id) =>
        remove(User::class, $id)->flatMap(fn(bool $deleted) => Query::liftIO($deleted ? NoContent() : NotFound()))->run($conn)
    ),

    GET('/users/:id/posts', fn(int $id) =>
        $author($id, fn(User $user) => where(Post::class, 'user_id', $user->id)->orderBy('created_at', 'DESC')
            ->flatMap(fn($posts) => Query::liftIO(Ok($posts))))->run($conn)
    ),

    GET('/users/export', fn() =>
        where(User::class, 'active', true)->stream()
            ->flatMap(fn($users) => Query::liftIO(Ok($users->map(fn(User $user) => json_encode($user) . "\n"))))
            ->run($conn)
    ),
);
```

A branch that misses must produce the whole response itself, as `$author` does; mapping `Ok` over the result afterwards would wrap the `NotFound` response in a 200.

`ImmList`, `ImmMap`, `ImmSet` and tuples are `JsonSerializable` from phunkie 1.5, so query results can be handed to the response constructors directly. Never pass request data straight into `create` or `update`: pick the columns you accept first.

## Testing

Run the real code against an in-memory SQLite database migrated by your own migration files:

```php
$conn = connect('sqlite::memory:')->unsafeRun();
(new Migrator(__DIR__ . '/../database/migrations'))->run()->run($conn)->unsafeRun();
```

## Documentation

- [Migrations](docs/index.md)

## License

MIT Licence

## Acknowledgments

- Inspired by [Slick](https://scala-slick.org/)
- Built on [Phunkie](https://github.com/phunkie/phunkie)
