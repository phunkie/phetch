# Quick Start

Define a model, connect, and run a query.

```php
<?php

use Phunkie\Phetch\Attributes\Generated;
use Phunkie\Phetch\Attributes\Table;
use Phunkie\Types\Option;

use function Phunkie\Phetch\Functions\{connect, create, find, where};

require_once __DIR__ . '/vendor/autoload.php';

#[Table('users')]
final readonly class User
{
    public function __construct(
        #[Generated] public int $id,
        public string $name,
        public string $email,
        public bool $active = true,
    ) {
    }
}

$conn = connect('sqlite:app.sqlite')->unsafeRun();

$ada = create(User::class, ['name' => 'Ada', 'email' => 'ada@example.com'])
    ->run($conn)
    ->unsafeRun();                                                  // User

$maybe = find(User::class, $ada->id)->run($conn)->unsafeRun();      // Option<User>

$active = where(User::class, 'active', true)->orderBy('name')
    ->run($conn)
    ->unsafeRun();                                                  // ImmList<User>
```

Three things to notice:

1. A model is a plain readonly class. Rows are mapped to constructor parameters by name; `#[Generated]` marks what the database produces. See [Models](../core/models.md).
2. Every function returns a `Query`, a description of database work. Nothing happens until `run($conn)` binds a connection and the resulting `IO` is run. See [Queries](../core/queries.md).
3. Values are always bound as parameters, and table and column names are validated and quoted. See [CRUD operations](../crud/operations.md).

## Schema

Phetch does not create tables from models. Write migrations and run them with the CLI:

```bash
vendor/bin/phetch make:migration CreateUsers
vendor/bin/phetch migrate
```

See [Migrations](../migrations/getting-started.md).

## Next

- [Query Builder](../querying/builder.md) for `where`, `whereIn`, `orderBy`, `limit`, `first`, `count`
- [Relationships](../querying/relationships.md) for one-to-many and many-to-many through a link table
- [Http4p Integration](../integration/http4p.md) for a JSON API in one `routes.php`
