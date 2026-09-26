# Getting Started with Migrations

Phetch provides a simple, CLI-based migration system.

## Configuration

Create a `phetch.php` file in your project root returning an array config:

```php
<?php

return [
    'dsn' => 'sqlite:database.sqlite',
    // 'username' => 'root',
    // 'password' => 'secret',
    'migrations' => 'database/migrations'
];
```

## Initialization

The migration table is automatically created when you run your first migration. You can also initialize it manually:

```bash
vendor/bin/phetch migrate:init
```
