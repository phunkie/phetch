# Phetch Documentation

## Getting Started

- [Installation](getting-started/installation.md): requirements, drivers, imports
- [Quick Start](getting-started/quick-start.md): a model, a connection, a query

## Core

- [Models](core/models.md): `#[Table]`, `#[Column]`, `#[Generated]`, hydration rules, typed columns and value objects
- [Connections](core/connections.md): `connect`, statements run on open, binding a query, quoting per driver
- [Queries](core/queries.md): `Query` as a value, `map`, `flatMap`, `pure`, `liftIO`, `mapN`, `traverse`
- [Errors and Transactions](core/errors-and-transactions.md): `RowNotFound`, `ConstraintViolation` and which `Constraint` it broke, `transaction`

## CRUD

- [Operations](crud/operations.md): `find`, `findOrFail`, `findBy`, `findOrCreate`, `all`, `create`, `insert`, `update`, `remove`

## Querying

- [Query Builder](querying/builder.md): `where`, `whereIn`, `orderBy`, `limit`, `offset`, `first`, `count`, `delete`, subqueries with `select`
- [Relationships](querying/relationships.md): many-to-one, one-to-many, many-to-many through a link table, composed views

## Streaming

- [Streaming Queries](streaming/queries.md): `stream()`, compiling, streaming HTTP bodies

## Integration

- [Http4p](integration/http4p.md): a JSON API in one `routes.php`, `decode` by model, `Recover`

## Advanced

- [Testing](advanced/testing.md): SQLite in memory with your migrations, testing routes, testing generated SQL

## Migrations

- [Getting Started](migrations/getting-started.md): the `phetch.php` configuration
- [Migration Files](migrations/files.md): writing `up()` and `down()`
- [Creating Migrations](migrations/create.md): `bin/phetch make:migration`
- [Running Migrations](migrations/running.md): `bin/phetch migrate`
- [Rollback](migrations/rollback.md): `bin/phetch rollback`
- [Status](migrations/status.md): `bin/phetch migrate:status`
- [Best Practices](migrations/best-practices.md)
