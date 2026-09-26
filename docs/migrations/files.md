# Migration Files

A migration file contains a class that implements the `Phunkie\Phetch\Migration\Migration` interface.

It must define `up()` and `down()` methods, both returning `Query<mixed>`.

```php
<?php

use Phunkie\Phetch\Migration\Migration;
use Phunkie\Phetch\Query;
use function Phunkie\Effect\Functions\io\io;

class CreateUsers implements Migration
{
    public function up(): Query
    {
        return new Query(fn($conn) => io(fn() => 
            $conn->pdo()->exec("
                CREATE TABLE users (
                    id INTEGER PRIMARY KEY,
                    name TEXT,
                    email TEXT
                )
            ")
        ));
    }

    public function down(): Query
    {
        return new Query(fn($conn) => io(fn() => 
            $conn->pdo()->exec("DROP TABLE users")
        ));
    }
}
```

Since `up` and `down` return `Query`, you can compose multiple operations using `flatMap` if needed, although simple raw SQL execution via `Connection` is common for schema changes.
