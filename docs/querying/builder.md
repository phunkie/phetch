# Query Builder

`all(Model::class)` and `where(...)` return a `QueryBuilder`. Every method returns a new builder, so a partial query can be shared and refined; the original is never changed.

```php
use function Phunkie\Phetch\Functions\{all, where};

$adults = where(User::class, 'age', '>=', 18)->orderBy('name');

$adults->get();                         // Query<ImmList<User>>
$adults->first();                       // Query<Option<User>>
$adults->count();                       // Query<int>
$adults->limit(20)->offset(40)->get();  // page three
$adults->where('active', true)->get();  // criteria combine with AND
$adults->delete();                      // Query<int>, rows deleted
$adults->stream();                      // Query<Stream<User>>, see Streaming
```

A builder is itself a `Query<ImmList<T>>`: `$adults->run($conn)` fetches the rows without `get()`.

## Criteria

- `where($column, $value)` compares with `=`.
- `where($column, $operator, $value)` with one of `=`, `!=`, `<>`, `<`, `<=`, `>`, `>=`, `LIKE`, `NOT LIKE`, `IS`, `IS NOT`. Anything else throws `InvalidArgumentException` when the builder is built.
- `whereIn($column, [1, 2, 3])` binds one placeholder per value; an empty list is refused.
- `whereIn($column, $builder->select('id'))` embeds another builder as a subquery. See below.

Criteria always combine with `AND`. There is no `OR`; express alternatives as separate queries or with `whereIn`.

## Ordering and paging

- `orderBy($column, 'ASC' | 'DESC')`, any case, anything else refused; call it again to order by a second column.
- `limit($n)`; `offset($n)` needs a limit, and says so when the query is built.

## Subqueries

`select($column)` projects a builder to one column, which makes it usable inside `whereIn`. The subquery is rendered inline and its parameters are bound in order with the outer ones, so a relationship through a link table is one statement:

```php
$tagsOf = fn(Book $book) => all(Tag::class)
    ->whereIn('id', where(BookTag::class, 'book_id', $book->id)->select('tag_id'))
    ->orderBy('name');

// SELECT * FROM "tags" WHERE "id" IN (SELECT "tag_id" FROM "book_tags" WHERE "book_id" = ?) ORDER BY "name" ASC
```

Subqueries nest:

```php
$booksTagged = fn(string $name) => all(Book::class)
    ->whereIn('id', all(BookTag::class)
        ->whereIn('tag_id', where(Tag::class, 'name', $name)->select('id'))
        ->select('book_id'));
```

A builder projected with `select` is only meant as a subquery; running it directly still hydrates full models from what the projection returns, which is rarely what you want.

## Shapes

| Method | Yields | Query |
|---|---|---|
| `get()` or the builder itself | `ImmList<T>` | `SELECT * ... ` |
| `first()` | `Option<T>` | `... LIMIT 1` |
| `count()` | `int` | `SELECT COUNT(*) ...`, ordering and paging ignored |
| `delete()` | `int` | `DELETE FROM ... WHERE ...` |
| `stream()` | `Stream<T>` | `SELECT * ...`, rows pulled one at a time |

## Identifiers

Every column and table name goes through `Identifier`, which accepts `[A-Za-z_][A-Za-z0-9_]*` and nothing else, and is quoted for the driver by the connection. Passing a request parameter as a column name is therefore safe to attempt and fails loudly rather than silently: build column names from your own vocabulary and validate user choices against it first.
