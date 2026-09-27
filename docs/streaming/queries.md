# Streaming

`stream()` on a builder yields the rows through a [phunkie/streams](https://github.com/phunkie/streams) `Stream`: each row is fetched from the statement and hydrated only when the stream pulls it, and the stream hands it on before pulling the next, so a result set of any size is processed in constant memory.

Measured on 2026-09-27 with 200,000 rows of 100 bytes in SQLite, through `all(Entry::class)->stream()`, a `map`, an `evalTap` sink and `drain()`:

| | |
|---|---|
| Peak memory above baseline | 0 MB, 4 MB in total for the process |
| First row at the sink | 2.4 ms into a 0.81 s run |

```php
$active = where(User::class, 'active', true)->stream();   // Query<Stream<User>>
```

Running the query executes the statement and hands back the stream; nothing is fetched until the stream is compiled.

## Per driver

The statement is prepared so that the driver does not buffer the result set:

- SQLite steps through the result incrementally; nothing to set up.
- MySQL buffers the whole result client-side by default. `stream()` turns `PDO::MYSQL_ATTR_USE_BUFFERED_QUERY` off while the statement runs and restores the connection's setting after. Until the stream has been drained no other statement can run on that connection: MySQL refuses with "Cannot execute queries while other unbuffered queries are active". Give an export its own connection when something else must run alongside it.
- PostgreSQL's PDO driver has no unbuffered mode; `stream()` prepares the statement with `PDO::ATTR_CURSOR => PDO::CURSOR_SCROLL`, a server-side cursor the driver fetches from row by row.

## Transforming and compiling

The stream takes the usual operations, `map`, `filter`, `take`, `evalTap`, and compiles to a list or to nothing:

```php
$names = $active->run($conn)->unsafeRun()
    ->map(fn(User $user) => $user->name)
    ->compile()
    ->toList();                                           // ImmList<string>

$active->run($conn)->unsafeRun()
    ->evalTap(fn(User $user) => io(fn() => print($user->email . "\n")))
    ->compile()
    ->drain()
    ->unsafeRun();                                        // IO<Unit>, one effect per row
```

`take($n)` and `takeWhile($p)` halt the pull: `->take(10)` on a million-row statement fetches ten rows.

## As an HTTP body

An http4p response takes a stream as its body. The server writes and flushes each chunk before the next row is fetched, so an export reaches the client as the rows are read and never holds them:

```php
GET('/users/export', fn() =>
    where(User::class, 'active', true)->stream()->run($conn)
        ->flatMap(fn(Stream $users) => Ok($users->map(fn(User $user) => json_encode($user) . "\n")))
        ->map(fn(Response $response) => $response->withHeader('content-type', 'application/x-ndjson'))
);
```

Each row is a `JsonSerializable`-aware `json_encode` away from a line of newline-delimited JSON; phunkie's collections and your value objects render as you would expect. [Http4p Integration](../integration/http4p.md) shows the service on the other end reading the lines back into models.

## When not to stream

`get()` is the right call for anything that fits in memory and is used more than once, and for anything that must be counted, sorted in PHP or joined with another result. Streaming pays off for exports, reports and batch jobs that touch each row once.
