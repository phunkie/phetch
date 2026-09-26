# Queries

Every phetch function returns a `Query<A>`: a function from a `Connection` to an `IO<A>` from phunkie/effect. Building a query does nothing; binding a connection with `run()` gives an `IO`, and running the `IO` touches the database.

```php
$query = find(User::class, 1);        // Query<Option<User>>
$io = $query->run($conn);             // IO<Option<User>>
$user = $io->unsafeRun();             // Option<User>
```

This is what makes queries values: they can be built in one place, passed around, composed, and executed later, once, inside a transaction, or never.

## Composing queries

`map` transforms the result, `flatMap` sequences a query that depends on it:

```php
$booksOfFirstAuthor = find(Author::class, 1)->flatMap(fn(Option $author) => $author->fold(
    Query::pure(ImmList()),
    fn(Author $found) => where(Book::class, 'author_id', $found->id)->orderBy('title'),
));
```

`Query::pure($value)` lifts a plain value into a query and `Query::liftIO($io)` lifts an effect, so a chain can branch into values or effects that need no connection and still end as one query.

### mapN

Independent queries combine with `mapN`, which runs them in order and hands every result to one function. It keeps a view built from several sources flat:

```php
$view = findOrFail(Book::class, $id)->flatMap(fn(Book $book) => find(Author::class, $book->authorId)
    ->mapN([
        all(Tag::class)->whereIn('id', where(BookTag::class, 'book_id', $book->id)->select('tag_id')),
        where(Review::class, 'book_id', $book->id)->orderBy('created_at', 'DESC'),
    ], fn(Option $author, ImmList $tags, ImmList $reviews) => [
        'book' => $book,
        'author' => $author->getOrElse(null),
        'tags' => $tags,
        'reviews' => $reviews,
    ]));

$view->run($conn);   // IO<array>
```

### traverse

`Query::traverse($values, $f)` runs one query per value, in order, and collects the results in an `ImmList`:

```php
Query::traverse(['maths', 'computing'], fn(string $name) => findOrCreate(Tag::class, ['name' => $name]));
// Query<ImmList<Tag>>
```

## Composing in IO

Once a query is bound with `run()`, everything phunkie/effect offers applies: `flatMap` into other effects, `mapN` across effects, `attempt()` to keep an error as a value, `recover()` to answer one exception class. An HTTP handler typically runs its query and composes the response in `IO`:

```php
findOrFail(User::class, $id)->run($conn)->flatMap(fn(User $user) => Ok($user));
```

Stay inside `Query` for as long as the steps are database steps, especially when they must share a [transaction](errors-and-transactions.md); switch to `IO` when the next step is not.

## The builder is a query

`all(Model::class)` and `where(...)` return a `QueryBuilder`, which is itself a `Query<ImmList<T>>`: running it fetches every matching row. Calling `get()` is optional; `first()`, `count()`, `delete()` and `stream()` give the other shapes. See [Query Builder](../querying/builder.md).
