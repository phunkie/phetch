# Relationships

Phetch has no relationship layer, no lazy loading and no proxies. A relationship is a function that returns a query, and a view that needs several is composed from them. This keeps every query visible and every load explicit.

## Many-to-one

A book holds its author's key; loading the author is a `find`:

```php
$authorOf = fn(Book $book) => find(Author::class, $book->authorId);   // Query<Option<Author>>
```

## One-to-many

The books of an author are a filtered builder:

```php
$booksOf = fn(Author $author) => where(Book::class, 'author_id', $author->id)->orderBy('published_on');   // Query<ImmList<Book>>
```

## Many-to-many

A link table is a model without a generated key:

```php
#[Table('book_tags')]
final readonly class BookTag
{
    public function __construct(public int $bookId, public int $tagId) {}
}
```

Navigating it is a subquery, one statement in each direction:

```php
$tagsOf = fn(Book $book) => all(Tag::class)
    ->whereIn('id', where(BookTag::class, 'book_id', $book->id)->select('tag_id'))
    ->orderBy('name');

$booksTagged = fn(Tag $tag) => all(Book::class)
    ->whereIn('id', where(BookTag::class, 'tag_id', $tag->id)->select('book_id'))
    ->orderBy('title');
```

Linking is an `insert`; unlinking is a `delete` on the builder. Replacing a book's tags, creating the unknown ones, is one transaction:

```php
$replaceTags = fn(Book $book, array $names) => transaction(
    where(BookTag::class, 'book_id', $book->id)->delete()->flatMap(fn() =>
        Query::traverse($names, fn(string $name) => findOrCreate(Tag::class, ['name' => $name])
            ->flatMap(fn(Tag $tag) => insert(BookTag::class, ['bookId' => $book->id, 'tagId' => $tag->id])->map(fn() => $tag))))
);   // Query<ImmList<Tag>>
```

## Composing a view

Several relationships of one row combine with `mapN`, which runs the queries in order and hands every result to one function:

```php
$bookView = fn(int $id) => findOrFail(Book::class, $id)->flatMap(fn(Book $book) =>
    $authorOf($book)->mapN([
        null === $book->publisherId ? Query::pure(None()) : find(Publisher::class, $book->publisherId),
        $tagsOf($book),
        where(Review::class, 'book_id', $book->id)->orderBy('created_at', 'DESC'),
    ], fn(Option $author, Option $publisher, ImmList $tags, ImmList $reviews) => [
        'id' => $book->id,
        'title' => $book->title,
        'author' => $author->getOrElse(null),
        'publisher' => $publisher->getOrElse(null),
        'tags' => $tags->map(fn(Tag $tag) => $tag->name),
        'reviews' => $reviews,
    ]));
```

That is five statements for one view, run only when the query is. When a parent row must exist before a child is written, chain the lookup first:

```php
findOrFail(Author::class, $authorId)->flatMap(fn() => create(Book::class, $data));
```

## Foreign keys

Phetch leaves referential integrity to the database. Declare the constraints in your migrations, and on SQLite enable them per connection with `PRAGMA foreign_keys = ON`. A write that breaks one fails with `ConstraintViolation`; see [Errors and Transactions](../core/errors-and-transactions.md).
