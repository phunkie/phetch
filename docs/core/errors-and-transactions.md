# Errors and Transactions

## Failures a query can raise

| Exception | When |
|---|---|
| `Phunkie\Phetch\RowNotFound` | `findOrFail` finds no row; the message reads `Author 9 not found` |
| `Phunkie\Phetch\ConstraintViolation` | the database refuses a write: a duplicate key, a missing foreign row, a null in a required column. Every driver reports these under SQLSTATE class 23; the message is the driver's, without the PDO prefix, for example `UNIQUE constraint failed: authors.email` |
| `InvalidArgumentException` | a query is built with something it cannot accept: a name that is not an identifier, an unknown operator or order direction, an empty `update`, an `offset` without a `limit`, a `whereIn` without values, a subquery without `select` |
| `RuntimeException` | a row lacks a column for a required constructor parameter |
| `PDOException` | anything else PDO reports |

The `InvalidArgumentException`s are thrown when the query is built, before any IO runs. The others surface when the `IO` runs.

## Handling them

They are ordinary exceptions inside an `IO`, so phunkie/effect's combinators apply:

```php
create(Author::class, $data)->run($conn)
    ->flatMap(fn(Author $author) => Created($author))
    ->recover(ConstraintViolation::class, fn(ConstraintViolation $e) => Conflict(['error' => 'An author with that email already exists.']));
```

`attempt()` keeps the outcome as a `Validation` when the error is data rather than a failure. In an http4p application, `Recover` middleware answers a whole class of exceptions once for every route; see [Http4p Integration](../integration/http4p.md).

## Transactions

`transaction($query)` runs a query inside a database transaction: committed when it succeeds, rolled back when it throws, with the error propagated.

```php
use function Phunkie\Phetch\Functions\transaction;

$replaceTags = transaction(
    where(BookTag::class, 'book_id', $bookId)->delete()->flatMap(fn() =>
        Query::traverse($names, fn(TagName $name) => findOrCreate(Tag::class, ['name' => $name])
            ->flatMap(fn(Tag $tag) => insert(BookTag::class, ['bookId' => $bookId, 'tagId' => $tag->id]))))
);

$replaceTags->run($conn)->unsafeRun();
```

Everything that must share the transaction has to stay inside the one `Query` passed to `transaction`; the moment a step is bound with `run()` it is outside. Migrations run each file in its own transaction the same way.

MySQL commits implicitly around DDL, so only data changes roll back there; SQLite and PostgreSQL roll back DDL too.
