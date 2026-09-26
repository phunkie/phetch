# Phetch Documentation

## Table of Contents

### Getting Started
- [Installation](getting-started/installation.md)
- [Quick Start](getting-started/quick-start.md)
- [Core Concepts](getting-started/core-concepts.md)

### Core Concepts
- [Pure Function API](core/pure-functions.md) - Understanding the functional approach
- [Models](core/models.md) - Defining data models with attributes
- [Type Safety](core/type-safety.md) - Compile-time query validation
- [IO Effects](core/effects.md) - All operations return `IO<T>`

### CRUD Operations
- [Finding Records](crud/find.md) - `find`, `findBy`, `all`
- [Creating Records](crud/create.md) - `create` function
- [Updating Records](crud/update.md) - `update` function
- [Deleting Records](crud/delete.md) - `delete` function

### Querying
- [Query Builder](querying/builder.md) - Using `where`, `orderBy`, `limit`
- [Composing Queries](querying/composition.md) - Building complex queries
- [Aggregates](querying/aggregates.md) - `count`, `sum`, `avg`, `min`, `max`
- [Raw Queries](querying/raw.md) - Executing raw SQL when needed

### Relationships
- [Defining Relationships](relationships/definition.md) - Pure function approach
- [One-to-Many](relationships/one-to-many.md) - Has many relationships
- [One-to-One](relationships/one-to-one.md) - Has one relationships
- [Many-to-Many](relationships/many-to-many.md) - Belongs to many relationships
- [Eager Loading](relationships/eager-loading.md) - Loading related data efficiently

### Streaming
- [Query Streaming](streaming/queries.md) - Streaming large result sets
- [Memory Efficiency](streaming/memory.md) - Constant memory usage
- [Integration with Http4p](streaming/http4p.md) - Streaming HTTP responses

### Async & Parallel
- [Parallel Queries](async/parallel.md) - Executing queries concurrently
- [Sequential Composition](async/sequential.md) - Chaining dependent operations with `flatMap`
- [Fire and Forget](async/fire-and-forget.md) - Background operations with `start()`

### Transactions
- [Transaction Basics](transactions/basics.md) - Using `transaction` function
- [Rollback](transactions/rollback.md) - Handling failures
- [Nested Transactions](transactions/nested.md) - Savepoints

### Integration
- [Http4p Integration](integration/http4p.md) - Building REST APIs
- [Validation](integration/validation.md) - Input validation
- [Authentication](integration/auth.md) - User authentication patterns

### Advanced
- [Connection Pooling](advanced/pooling.md) - Managing database connections
- [Query Optimization](advanced/optimization.md) - Performance tuning
- [Custom Types](advanced/custom-types.md) - Defining custom column types
- [Testing](advanced/testing.md) - Testing database code

### Migrations
- [Getting Started](migrations/getting-started.md) - Creating your first migration
- [Migration Files](migrations/files.md) - Writing migration files
- [Running Migrations](migrations/running.md) - Using `bin/phetch migrate`
- [Rollback](migrations/rollback.md) - Reverting migrations with `bin/phetch rollback`
- [Status](migrations/status.md) - Checking migration status with `bin/phetch migrate:status`
- [Creating Migrations](migrations/create.md) - Generating migration files with `bin/phetch make:migration`
- [Best Practices](migrations/best-practices.md) - Migration patterns and conventions

### Examples
- [REST API](examples/rest-api.md) - Complete CRUD API
- [Blog System](examples/blog.md) - Posts, comments, users
- [E-commerce](examples/ecommerce.md) - Products, orders, inventory
- [Real-time Updates](examples/realtime.md) - Streaming changes

### API Reference
- [Functions](api/functions.md) - All exported functions
- [Types](api/types.md) - Type definitions
- [Query DSL](api/query-dsl.md) - Query builder methods
