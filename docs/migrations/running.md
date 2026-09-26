# Running Migrations

To run all pending migrations:

```bash
vendor/bin/phetch migrate
```

This command compares the files in your migrations directory with the `migrations` table in the database and executes the `up()` method of any new files.

Output example:
```
Migrating: 2023_10_01_120000_CreateUsers
Migrated:  2023_10_01_120000_CreateUsers
```
