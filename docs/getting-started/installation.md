# Installation

```bash
composer require phunkie/phetch
```

## Requirements

- PHP 8.2 or later, with the PDO driver for your database (`pdo_sqlite`, `pdo_mysql` or `pdo_pgsql`)
- phunkie/phunkie ^1.5
- phunkie/effect ^1.4
- phunkie/streams ^1.2

## What you get

- `Phunkie\Phetch\Functions`: the query functions, `connect`, `find`, `create`, `where` and the rest
- `Phunkie\Phetch\Query`: the query type, a function from a `Connection` to an `IO`
- `Phunkie\Phetch\Query\QueryBuilder`: the composable `SELECT`
- `Phunkie\Phetch\Attributes`: `Table`, `Column`, `Generated`
- `Phunkie\Phetch\Migration`: the migrator behind `vendor/bin/phetch`

Import the functions you use:

```php
use function Phunkie\Phetch\Functions\{connect, find, create, where};
```

## Drivers

Identifiers are quoted for the driver behind the connection: backticks on MySQL, double quotes on SQLite and PostgreSQL. Values are always bound as prepared-statement parameters.

SQLite enforces foreign keys only when asked, per connection:

```php
connect('sqlite:app.sqlite')
    ->flatMap(fn(Connection $conn) => io(fn() => $conn->pdo()->exec('PRAGMA foreign_keys = ON'))->map(fn() => $conn));
```
