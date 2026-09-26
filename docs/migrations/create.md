# Creating Migrations

To create a new migration file, use the `make:migration` command:

```bash
vendor/bin/phetch make:migration CreateUsers
```

This will create a timestamped file in your migrations directory (e.g., `database/migrations/2023_10_01_120000_CreateUsers.php`).

The class name matches the argument provided (`CreateUsers`).
