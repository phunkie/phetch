# Phunkie Phetch

A functional database library for PHP inspired by Scala's Slick.

## Overview

Phetch provides a type-safe, composable way to interact with databases using functional programming principles. Built on top of Phunkie Effect and Streams, it offers:

- **Type-safe queries** - Compile-time query validation
- **Pure functions** - All operations as pure functions returning IO effects
- **Composable operations** - Build complex queries from simple parts
- **Lazy evaluation** - Queries are only executed when needed
- **Stream-based results** - Handle large result sets efficiently

## Installation

```bash
composer require phunkie/phetch
```

## Requirements

- PHP 8.2 || 8.3 || 8.4
- phunkie/phunkie ^1.0
- phunkie/effect ^1.2
- phunkie/streams ^1.0

## Quick Start

```php
use function Phunkie\Phetch\Functions\{find, create, where};
use function Phunkie\Http4p\Response\{Ok, NotFound};

// Define your model (plain readonly data)
#[Table('users')]
final readonly class User {
    public function __construct(
        public int $id,
        public string $name,
        public string $email,
    ) {}
}

// Connection setup
use function Phunkie\Phetch\Functions\connect;
$conn = connect('sqlite:...')->unsafeRun();

// Find by ID - returns Query<Option<User>>
find(User::class, 1)
    ->flatMap(fn($opt) => $opt->match(
        Some: fn($u) => Ok($u),
        None: fn() => NotFound()
    ))
    ->run($conn); // returns IO<Response>

// Create - returns Query<User>
create(User::class, ['name' => 'John', 'email' => 'john@example.com'])
    ->map(fn($user) => Ok($user));

// Query - returns Query<ImmList<User>>
where(User::class, 'active', true)
    ->orderBy('name')
    ->limit(10)
    ->get()
    ->map(fn($users) => Ok($users));
```

## Features

### Pure Function API

All database operations are pure functions returning Query effects (ReaderT):

```php
use function Phunkie\Phetch\Functions\{find, findBy, create, update, remove, where, all};

// Find by primary key - Query<Option<User>>
find(User::class, 1);

// Find by attribute - Query<Option<User>>
findBy(User::class, 'email', 'john@example.com');

// Get all - Query<ImmList<User>>
all(User::class);

// Create - Query<User>
create(User::class, ['name' => 'John', 'email' => 'john@example.com']);

// Update - Query<Option<User>>
update(User::class, 1, ['name' => 'Jane']);

// Delete - Query<bool>
remove(User::class, 1);
```

### Running Queries and Composing with IO

A `Query` is a function from a `Connection` to an `IO`. Nothing touches the database until you bind a connection with `run()` and then run the resulting `IO`:

```php
use Phunkie\Phetch\Query;
use function Phunkie\Phetch\Functions\{connect, find};

$conn = connect('sqlite:app.sqlite')->unsafeRun();

$user = find(User::class, 1)->run($conn)->unsafeRun(); // Option<User>
```

Queries compose with `map` and `flatMap`. `Query::pure` lifts a plain value and `Query::liftIO` lifts an effect, so a chain can branch into values or effects that need no connection and still end as one `Query`:

```php
$greeting = find(User::class, 1)->flatMap(fn(Option $user) => $user->isDefined()
    ? Query::pure('Hello ' . $user->get()->name)
    : Query::liftIO(io(fn() => 'Hello stranger')));

$greeting->run($conn); // IO<string>
```

### Composable Queries

Build queries functionally:

```php
// Query builder - returns Query<User>
$activeUsers = where(User::class, 'active', true)
    ->orderBy('name');

// Compose queries
$recentActiveUsers = $activeUsers
    ->where('created_at', '>', now()->subDays(7))
    ->limit(10)
    ->get();  // Query<ImmList<User>>
```

### Relationships

Define relationships as pure functions:

```php
// Define relationship function
function userPosts(int $userId): Query {
    return where(Post::class, 'user_id', $userId)
        ->orderBy('created_at', 'desc')
        ->get();
}

// Use in queries
find(User::class, 1)
    ->flatMap(fn($opt) => $opt->match(
        Some: fn($u) => userPosts($u->id)->map(fn($posts) => Ok($posts)),
        None: fn() => NotFound()
    ));
```

### Streaming Large Results

Handle large datasets with constant memory usage:

```php
use function Phunkie\Streams\Stream;

// Stream query results - Stream<IO, User>
where(User::class, 'active', true)
    ->stream()
    ->map(fn($user) => json_encode($user) . "\n")
    ->through(utf8Encode)  // Stream<IO, Byte>
    ->compile()
    ->drain()
    ->unsafeRun();

// Use in HTTP responses
GET('/users/export', fn() =>
    Ok(
        where(User::class, 'active', true)
            ->stream()
            ->map(fn($u) => json_encode($u))
            ->intersperse("\n")
            ->through(utf8Encode)
    )
);
```

### Sequential Composition

Chain dependent operations:

```php
function sendWelcomeEmail(User $user): IO {
    return io(fn() => mail($user->email, 'Welcome!', '...'));
}

// Create user then send email (sequential - blocks until email sent)
create(User::class, $data)
    ->flatMap(fn($user) =>
        sendWelcomeEmail($user)
            ->map(fn($_) => Created($user))
    );
```

### Fire and Forget (Async)

Start background work without blocking the response:

```php
// Create user and send email asynchronously
create(User::class, $data)
    ->flatMap(fn($user) =>
        sendWelcomeEmail($user)
            ->start()  // Returns IO<AsyncHandle<Unit>> - forks to background fiber
            ->map(fn($_) => Created($user))  // Response sent immediately
    );

// Or explicitly discard the handle
create(User::class, $data)
    ->flatMap(fn($user) =>
        sendWelcomeEmail($user)->start()->productR(Created($user))
    );

// Custom execution context
use Phunkie\Effect\Concurrent\ParallelExecutionContext;

sendEmail($user)
    ->start(new ParallelExecutionContext())  // Use parallel threads if available
    ->map(fn($_) => Ok('Email queued'));
```

### Parallel Queries

Execute multiple queries concurrently:

```php
use function Phunkie\Effect\Functions\parallel;

// Fetch multiple users in parallel
parallel([
    find(User::class, 1),
    find(User::class, 2),
    find(User::class, 3)
])->map(fn($users) => Ok($users));

// Parallel different queries
parallel([
    'users' => all(User::class),
    'posts' => all(Post::class)
])->map(fn($results) => Ok($results));
```

### Integration with Http4p

Http4p handlers return `IO<Response>`. Compose the query, lift the response constructors with `Query::liftIO`, and bind the connection at the end with `run($conn)`:

```php
use Phunkie\Http4p\Request;
use Phunkie\Phetch\Query;
use Phunkie\Types\Option;

use function Phunkie\Http4p\Functions\{decode, HttpRoutes};
use function Phunkie\Http4p\Functions\response\{Ok, Created, NotFound, NoContent};
use function Phunkie\Http4p\Functions\routes\{GET, POST, PUT, DELETE};
use function Phunkie\Phetch\Functions\{all, find, create, update, remove, where};

$found = fn(int $id) => fn(Option $user) => $user->isDefined()
    ? Query::pure($user->get())
    : Query::liftIO(NotFound(['error' => sprintf('User %d not found', $id)]));

$routes = HttpRoutes(
    GET('/users', fn() =>
        all(User::class)->flatMap(fn($users) => Query::liftIO(Ok($users)))->run($conn)
    ),

    GET('/users/:id', fn(int $id) =>
        find(User::class, $id)->flatMap($found($id))->flatMap(fn($user) => Query::liftIO(Ok($user)))->run($conn)
    ),

    POST('/users', fn(Request $req) =>
        decode($req)->flatMap(fn(array $data) =>
            create(User::class, $data)->flatMap(fn($user) => Query::liftIO(Created($user)))->run($conn)
        )
    ),

    PUT('/users/:id', fn(int $id, Request $req) =>
        decode($req)->flatMap(fn(array $data) =>
            update(User::class, $id, $data)->flatMap($found($id))->flatMap(fn($user) => Query::liftIO(Ok($user)))->run($conn)
        )
    ),

    DELETE('/users/:id', fn(int $id) =>
        remove(User::class, $id)->flatMap(fn(bool $deleted) => Query::liftIO($deleted ? NoContent() : NotFound()))->run($conn)
    ),

    GET('/users/:id/posts', fn(int $id) =>
        find(User::class, $id)->flatMap($found($id))
            ->flatMap(fn($user) => where(Post::class, 'user_id', $user->id)->orderBy('created_at', 'DESC')->get())
            ->flatMap(fn($posts) => Query::liftIO(Ok($posts)))
            ->run($conn)
    ),
);
```

`ImmList`, `ImmMap`, `ImmSet` and tuples are `JsonSerializable` from phunkie 1.5, so query results can be handed to the response constructors directly.

## Documentation

See [docs/index.md](docs/index.md) for complete documentation.

## License

MIT Licence

## Acknowledgments

- Inspired by [Slick](https://scala-slick.org/)
- Built on [Phunkie](https://github.com/phunkie/phunkie)
