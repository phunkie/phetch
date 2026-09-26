# Migration Best Practices

- **Immutability**: Once a migration has been committed and shared (or deployed to production), do not modify it. Create a new migration to make changes.
- **Down Methods**: Always implement the `down()` method to ensure changes can be reversible.
- **Atomicity**: Wrap schema changes in transactions if your database supports DDL transactions (PostgreSQL). Phetch does not automatically wrap migrations in transactions yet.
- **Testing**: Test your `up` and `down` logic locally before deploying.
