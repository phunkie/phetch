# Http4p Integration

[phunkie/http4p](https://github.com/phunkie/http4p) handlers return `IO<Response>`. A phetch query becomes one by running it against the connection and answering with a response constructor. Three http4p features carry most of the weight:

- `decode($req, Author::class)` validates the JSON body against the model's constructor: the path parameters fill the parameters of the same name, `#[Generated]` parameters are never expected, on `POST` and `PUT` every other parameter without a default is required, a `PATCH` may send any subset, value objects and enums are built from the scalars, and a bad body is answered with a `400` listing one error per field before the handler runs.
- The response constructors take the body as their first argument, so `->flatMap(Ok(...))` answers with whatever the effect produced.
- `Recover(SomeException::class, $handler)` middleware answers one exception class for the whole app, so `RowNotFound` and `ConstraintViolation` become `404` and `409` in two lines.

## routes.php

A file that receives the connection and returns the application is all the structure needed:

```php
<?php

use Phunkie\Http4p\Request;
use Phunkie\Http4p\Router;
use Phunkie\Phetch\Connection\Connection;
use Phunkie\Phetch\ConstraintViolation;
use Phunkie\Phetch\RowNotFound;
use Phunkie\Types\ImmList;
use Phunkie\Types\Option;

use function Phunkie\Http4p\Functions\decode;
use function Phunkie\Http4p\Functions\HttpRoutes;
use function Phunkie\Http4p\Functions\middleware\{Recover, Through};
use function Phunkie\Http4p\Functions\response\{Conflict, Created, NoContent, NotFound, Ok};
use function Phunkie\Http4p\Functions\routes\{DELETE, GET, PATCH, POST};
use function Phunkie\Phetch\Functions\{all, create, find, findOrFail, remove, update, where};

return fn(Connection $conn): callable => Through(
    new Router(HttpRoutes(
        GET('/authors', fn() => all(Author::class)->orderBy('name')->run($conn)->flatMap(Ok(...))),

        GET('/authors/:id', fn(int $id) => findOrFail(Author::class, $id)->run($conn)->flatMap(Ok(...))),

        POST('/authors', fn(Request $req) =>
            decode($req, Author::class)
                ->flatMap(fn(array $data) => create(Author::class, $data)->run($conn))
                ->flatMap(Created(...))
                ->recover(ConstraintViolation::class, fn() => Conflict(['error' => 'An author with that email already exists.']))
        ),

        PATCH('/authors/:id', fn(int $id, Request $req) =>
            decode($req, Author::class)
                ->flatMap(fn(array $data) => findOrFail(Author::class, $id)->flatMap(fn() => update(Author::class, $id, $data))->run($conn))
                ->flatMap(fn(Option $author) => Ok($author->get()))
        ),

        DELETE('/authors/:id', fn(int $id) =>
            remove(Author::class, $id)->run($conn)->flatMap(fn(bool $deleted) => $deleted ? NoContent() : NotFound())
        ),

        POST('/authors/:authorId/books', fn(int $authorId, Request $req) =>
            decode($req, Book::class)
                ->flatMap(fn(array $data) => findOrFail(Author::class, $authorId)->flatMap(fn() => create(Book::class, $data))->run($conn))
                ->flatMap(Created(...))
        ),

        GET('/books/:id', fn(int $id) =>
            findOrFail(Book::class, $id)->flatMap(fn(Book $book) => find(Author::class, $book->authorId)
                ->mapN([
                    all(Tag::class)->whereIn('id', where(BookTag::class, 'book_id', $book->id)->select('tag_id'))->orderBy('name'),
                ], fn(Option $author, ImmList $tags) => ['book' => $book, 'author' => $author->getOrElse(null), 'tags' => $tags]))
            ->run($conn)->flatMap(Ok(...))
        ),

        GET('/books', fn(Request $req) =>
            (null === $req->query('tag')
                ? all(Book::class)
                : all(Book::class)->whereIn('id', all(BookTag::class)->whereIn('tag_id', where(Tag::class, 'name', $req->query('tag'))->select('id'))->select('book_id')))
            ->orderBy('title')->run($conn)->flatMap(Ok(...))
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

## What the body carries

`POST /authors/:authorId/books` needs no `author_id` in the body: the path parameter named `authorId` fills the constructor parameter. Whatever else the route knows can be passed to `decode` as a third argument, `decode($req, Book::class, ['ownerId' => $user->id])`. The decoded array carries only the model's own parameters, keyed by parameter name, so it goes straight into `create` or `update`.

## Where errors become responses

- A body that does not fit: `400` from the router, before the handler.
- `findOrFail` on a missing row: `RowNotFound`, answered `404` by the `Recover` at the bottom.
- A duplicate key or a missing foreign row: `ConstraintViolation`, answered `409`; a route that wants its own message adds `->recover(...)` and wins, since it runs first.
- Anything else: `500` from the server.

## Serialisation

Models render as their public properties; `ImmList`, `ImmMap`, `ImmSet` and tuples are `JsonSerializable` from phunkie 1.5; http4p's encoder writes dates as ISO 8601 and backed enums by value. A value object renders as an object with its properties unless it implements `JsonSerializable`.
