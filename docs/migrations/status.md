# Migration Status

```bash
vendor/bin/phetch migrate:status
```

Lists every migration file in the configured directory, in run order, with the batch it ran in:

```
Ran      1      2026_09_26_100229_CreateAuthors
Ran      1      2026_09_26_100230_CreateBooks
Pending         2026_09_27_090000_AddPublishers
```

Programmatically, `Migrator::status()` returns a `Query<ImmList<MigrationStatus>>`; each `MigrationStatus` carries the migration `name`, its `batch` (null when pending) and `ran()`.
