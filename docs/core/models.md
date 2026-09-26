# Models

A model is any class whose constructor parameters describe a row. Phetch never subclasses or proxies it: a readonly class with promoted constructor properties is the whole of it.

```php
use Phunkie\Phetch\Attributes\Column;
use Phunkie\Phetch\Attributes\Generated;
use Phunkie\Phetch\Attributes\Table;

#[Table('books')]
final readonly class Book
{
    public function __construct(
        #[Generated] public int $id,
        public int $authorId,
        #[Column('published_year')] public int $year,
        public string $title,
        public Format $format,
        public DateTimeImmutable $publishedOn,
        public ?string $subtitle = null,
    ) {
    }
}
```

## Table and primary key

- `#[Table('books')]` names the table. Without it, the lowercased short class name plus `s` is used: `Book` reads `books`.
- `#[Table('accounts', primaryKey: 'account_id')]` names the primary key column. The default is `id`. `find`, `update` and `remove` address rows by it, and `create` reads the new row back through it.

## Columns

Each constructor parameter is fed from one column of the row:

1. `#[Column('published_year')]` names the column explicitly.
2. Otherwise the parameter name is tried as the column name.
3. Otherwise its snake_case form is tried, so `$publishedOn` reads `published_on`.
4. A parameter with a default value keeps it when no column matches. A required parameter with no column fails the hydration with a `RuntimeException` naming the parameter and the columns the row had.

Writes go the other way: the arrays given to `create`, `insert` and `update` may be keyed by constructor parameter name or by column name. A parameter name is written to its `#[Column]`, or to the snake_case form of the name.

## Generated parameters

`#[Generated]` marks a parameter whose value the server produces: an auto-increment key, a UUID, a timestamp the service sets. Phetch reads it like any other column. Its purpose is input: http4p's `decode($req, Book::class)` never expects a generated parameter from a client and drops it if sent. Do not mark a parameter generated just because one route supplies it from elsewhere; path parameters and route-provided values have their own rules on the http4p side.

## Typed columns

Columns are scalars; parameters need not be. On the way in, a parameter's declared type decides how the scalar is rebuilt:

| Parameter type | Column value becomes |
|---|---|
| `int`, `float`, `string`, `bool`, `?T` | the scalar as PDO returns it |
| a backed enum, `Format` | `Format::from($value)` |
| `DateTimeImmutable`, `DateTime`, `DateTimeInterface` | `new DateTimeImmutable($value)` (or the declared class) |
| any other class, `Email` | `new Email($value)` |

On the way out, a value is stored as:

| Value | Stored as |
|---|---|
| a backed enum | its backing value |
| `DateTimeInterface` | `Y-m-d H:i:s` |
| `Stringable` | its string |
| an object with a single public property, `Rating` | that property |
| `bool` | `1` or `0` |

So a value object that validates itself in its constructor is a column type:

```php
final readonly class Email implements Stringable
{
    public function __construct(public string $value)
    {
        if (false === filter_var($value, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('must be an email address');
        }
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
```

Implement `JsonSerializable` on such a class when it should render as a scalar in JSON.

## Link tables

A many-to-many link table has no generated key, so it is a model like any other, written with `insert` rather than `create`:

```php
#[Table('book_tags')]
final readonly class BookTag
{
    public function __construct(public int $bookId, public int $tagId) {}
}
```

See [Relationships](../querying/relationships.md).
