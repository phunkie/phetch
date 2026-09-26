# Streaming

`stream()` on a builder yields the rows through a [phunkie/streams](https://github.com/phunkie/streams) `Stream`, pulling one row at a time from the statement and hydrating it as it goes, so a result set of any size is processed in constant memory.

```php
$active = where(User::class, 'active', true)->stream();   // Query<Stream<User>>
```

Running the query executes the statement and hands back the stream; nothing is fetched until the stream is compiled.

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

## As an HTTP body

An http4p response takes a stream as its body and writes it chunk by chunk, so an export never holds the rows in memory:

```php
GET('/users/export', fn() =>
    where(User::class, 'active', true)->stream()->run($conn)
        ->flatMap(fn(Stream $users) => Ok($users->map(fn(User $user) => json_encode($user) . "\n")))
        ->map(fn(Response $response) => $response->withHeader('content-type', 'application/x-ndjson'))
);
```

Each row is a `JsonSerializable`-aware `json_encode` away from a line of newline-delimited JSON; phunkie's collections and your value objects render as you would expect.

## When not to stream

`get()` is the right call for anything that fits in memory and is used more than once, and for anything that must be counted, sorted in PHP or joined with another result. Streaming pays off for exports, reports and batch jobs that touch each row once.
