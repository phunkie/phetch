# Connections

```php
use function Phunkie\Phetch\Functions\connect;

connect(string $dsn, ?string $username = null, ?string $password = null, array $options = []); // IO<Connection>
```

`connect` describes opening a PDO connection; the connection is made when the `IO` runs. The `Connection` it yields wraps the PDO handle with exceptions on error and associative fetches, and remembers the driver so it can quote identifiers for it.

```php
$conn = connect('mysql:host=localhost;dbname=shop', 'shop', $password)->unsafeRun();
$conn = connect('pgsql:host=localhost;dbname=shop', 'shop', $password)->unsafeRun();
$conn = connect('sqlite::memory:')->unsafeRun();
```

`$options` are passed to PDO as they are.

## Binding a query

A `Connection` is what a `Query` needs to become an `IO`:

```php
find(User::class, 1)->run($conn);   // IO<Option<User>>
```

Keep one connection for the life of a request or a script, and pass it to `run()` wherever a query is executed. In an HTTP application the routes receive it once; see [Http4p Integration](../integration/http4p.md).

## Reaching PDO

`Connection::pdo()` exposes the handle for anything phetch does not cover, such as raw SQL in migrations or driver pragmas:

```php
$conn->pdo()->exec('PRAGMA foreign_keys = ON');
```

## Quoting

`Connection::quote(Identifier $identifier)` returns the identifier quoted for the driver: backticks on MySQL, double quotes elsewhere. Every table and column name phetch puts into SQL goes through it, after `Identifier` has checked the name against `[A-Za-z_][A-Za-z0-9_]*`. A name that fails the check throws `InvalidArgumentException` when the query is built, before any IO runs.
