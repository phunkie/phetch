# Phunkie Phetch

A functional database library for PHP inspired by Scala's Slick.

## Overview

Phetch provides a type-safe, composable way to interact with databases using functional programming principles. Built on top of Phunkie Effect and Streams, it offers:

- **Type-safe queries** - Compile-time query validation
- **Composable operations** - Build complex queries from simple parts
- **Lazy evaluation** - Queries are only executed when needed
- **Effect management** - Database operations as IO effects
- **Stream-based results** - Handle large result sets efficiently

## Installation

```bash
composer require phunkie/phetch
```

## Requirements

- PHP 8.2 or higher
- phunkie/phunkie ^1.0
- phunkie/effect ^1.0
- phunkie/streams ^1.0

## Quick Start

```php
use Phunkie\Phetch\Schema;
use Phunkie\Phetch\Table;
use function Phunkie\Phetch\Column;
use function Phunkie\Phetch\Table;

// Define your schema
function UserTable(): Table {
    return Table(
        Column('id', Int, primaryKey: true, autoIncrement: true),
        Column('name', String, nullable: false),
        Column('email', String, nullable: false),
    )->mapTo(User::class);
}

// Query with type safety
$users = from(UserTable())
    ->where(fn($u) => $u->email->like('%@example.com'))
    ->select(fn($u) => [$u->id, $u->name])
    ->run();
```

## Features

### Type-Safe Schema Definition

Define your database schema with full type safety:

```php
#[Table('users')]
#[Schema(static fn() => UserTable(
    id: Int,
    name: String,
    email: String
))]
final class User {
    public function __construct(
        public int $id,
        public string $name,
        public string $email
    ) {}
}
```

### Composable Queries

Build queries functionally:

```php
$activeUsers = from(UserTable())
    ->where(fn($u) => $u->active->eq(true))
    ->orderBy(fn($u) => $u->name->asc());

$recentUsers = $activeUsers
    ->where(fn($u) => $u->createdAt->gt(now()->subDays(7)));
```

### Effect Integration

All database operations return IO effects:

```php
$program = from(UserTable())
    ->where(fn($u) => $u->id->eq(1))
    ->firstOption()
    ->flatMap(fn($user) => 
        $user->match(
            Some: fn($u) => updateUser($u),
            None: fn() => io(fn() => null)
        )
    );

$result = $program->unsafeRun();
```

## Documentation

Coming soon.

## License

MIT Licence

## Acknowledgments

- Inspired by [Slick](https://scala-slick.org/)
- Built on [Phunkie](https://github.com/phunkie/phunkie)
