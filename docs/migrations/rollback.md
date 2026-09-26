# Rolling Back Migrations

To rollback the last batch of migrations (the set of migrations run in the execution):

```bash
vendor/bin/phetch rollback
```

This executes the `down()` method of the migrations in the last batch in reverse order.
