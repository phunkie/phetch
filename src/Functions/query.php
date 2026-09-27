<?php

namespace Phunkie\Phetch\Functions;

use BackedEnum;
use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use PDOException;
use PDOStatement;
use Phunkie\Phetch\Attributes\Column;
use Phunkie\Phetch\Attributes\Table;
use Phunkie\Phetch\Connection\Connection;
use Phunkie\Phetch\ConstraintViolation;
use Phunkie\Phetch\Identifier;
use Phunkie\Phetch\Query;
use Phunkie\Phetch\Query\QueryBuilder;
use Phunkie\Phetch\RowNotFound;
use Phunkie\Types\Option;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use RuntimeException;
use Stringable;
use Throwable;

use function Phunkie\Effect\Functions\io\io;
use function None;
use function Some;

/**
 * Find a record by primary key.
 * returns Query<Option<T>>
 */
function find(string $model, mixed $id): Query
{
    $table = tableOf($model);

    return new Query(fn(Connection $conn) => io(fn() => fetchById($conn, $model, $table, $id)));
}

/**
 * Find a record by column value.
 * returns Query<Option<T>>
 */
function findBy(string $model, string $column, mixed $value): Query
{
    $table = tableOf($model);
    $column = new Identifier($column);

    return new Query(function(Connection $conn) use ($model, $table, $column, $value) {
        return io(function() use ($conn, $model, $table, $column, $value) {
            $stmt = execute($conn, sprintf('SELECT * FROM %s WHERE %s = ? LIMIT 1', $conn->quote($table), $conn->quote($column)), [$value]);
            $data = $stmt->fetch();

            return $data === false ? None() : Some(hydrate($model, $data));
        });
    });
}

/**
 * Find a record by primary key, failing with RowNotFound when there is none.
 * returns Query<T>
 */
function findOrFail(string $model, mixed $id): Query
{
    return find($model, $id)->map(fn(Option $row) => $row->getOrElse(null) ?? throw RowNotFound::for($model, $id));
}

/**
 * The record matching every given column, created from them when there is none.
 * returns Query<T>
 */
function findOrCreate(string $model, array $by): Query
{
    $matching = all($model);
    foreach ($by as $column => $value) {
        $matching = $matching->where((string) $column, $value);
    }

    return $matching->first()->flatMap(fn(Option $row) => $row->fold(create($model, $by), fn($found) => Query::pure($found)));
}

/**
 * Every record, as a builder that is also a Query<ImmList<T>> when run as it is.
 */
function all(string $model): QueryBuilder
{
    return new QueryBuilder($model, tableOf($model));
}

/**
 * Create a new record and read it back by its generated key.
 * returns Query<T>
 */
function create(string $model, array $data): Query
{
    $table = tableOf($model);
    $columns = columnsOf($model, $data);

    return new Query(function(Connection $conn) use ($model, $table, $columns, $data) {
        return io(function() use ($conn, $model, $table, $columns, $data) {
            execute($conn, insertSql($conn, $table, $columns), array_values($data));

            return fetchById($conn, $model, $table, $conn->pdo()->lastInsertId())->get();
        });
    });
}

/**
 * Write a row without reading it back, for tables whose key is not generated.
 * returns Query<int> the number of rows written
 */
function insert(string $model, array $data): Query
{
    $table = tableOf($model);
    $columns = columnsOf($model, $data);

    return new Query(fn(Connection $conn) => io(fn() => execute($conn, insertSql($conn, $table, $columns), array_values($data))->rowCount()));
}

/**
 * Start a query.
 */
function where(string $model, string $column, mixed $operator, mixed $value = null): QueryBuilder
{
    if (func_num_args() === 3) {
        $value = $operator;
        $operator = '=';
    }

    return (new QueryBuilder($model, tableOf($model)))->where($column, $operator, $value);
}

/**
 * Update a record.
 * returns Query<Option<T>>
 */
function update(string $model, mixed $id, array $data): Query
{
    if ([] === $data) {
        throw new InvalidArgumentException('An update needs at least one column.');
    }

    $table = tableOf($model);
    $columns = columnsOf($model, $data);

    return new Query(function(Connection $conn) use ($model, $table, $columns, $id, $data) {
        return io(function() use ($conn, $model, $table, $columns, $id, $data) {
            $sets = implode(', ', array_map(fn(Identifier $column) => $conn->quote($column).' = ?', $columns));

            execute($conn, sprintf('UPDATE %s SET %s WHERE %s = ?', $conn->quote($table), $sets, $conn->quote(primaryKey($model))), [...array_values($data), $id]);

            return fetchById($conn, $model, $table, $id);
        });
    });
}

/**
 * Remove a record.
 * returns Query<bool>
 */
function remove(string $model, mixed $id): Query
{
    $table = tableOf($model);

    return new Query(function(Connection $conn) use ($model, $table, $id) {
        return io(function() use ($conn, $model, $table, $id) {
            $stmt = execute($conn, sprintf('DELETE FROM %s WHERE %s = ?', $conn->quote($table), $conn->quote(primaryKey($model))), [$id]);

            return $stmt->rowCount() > 0;
        });
    });
}

/**
 * Run a query inside a transaction: committed when it succeeds, rolled back when it throws.
 *
 * @template A
 * @param Query<A> $query
 * @return Query<A>
 */
function transaction(Query $query): Query
{
    return new Query(function(Connection $conn) use ($query) {
        return io(function() use ($conn, $query) {
            $pdo = $conn->pdo();
            $pdo->beginTransaction();

            try {
                $result = $query->run($conn)->unsafeRun();
                $pdo->commit();

                return $result;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                throw $e;
            }
        });
    });
}

// --- Internals ---

function tableOf(string $class): Identifier
{
    $table = tableAttribute($class);

    if (null === $table) {
        $parts = explode('\\', $class);

        return new Identifier(strtolower(end($parts)) . 's');
    }

    return new Identifier($table->name);
}

function primaryKey(string $class): Identifier
{
    return new Identifier(tableAttribute($class)->primaryKey ?? 'id');
}

function tableAttribute(string $class): ?Table
{
    $attributes = (new ReflectionClass($class))->getAttributes(Table::class);

    return [] === $attributes ? null : $attributes[0]->newInstance();
}

/**
 * Prepare and run a statement, binding values as the driver can take them.
 *
 * @throws ConstraintViolation when the database refuses the write
 */
function execute(Connection $conn, string $sql, array $params): PDOStatement
{
    return guarded(fn() => $conn->run($sql, bound($params)));
}

/**
 * Prepare and run a statement whose rows are to be fetched one at a time, the driver set up not to buffer them.
 *
 * @throws ConstraintViolation when the database refuses the statement
 */
function executeForStreaming(Connection $conn, string $sql, array $params): PDOStatement
{
    return guarded(fn() => $conn->stream($sql, bound($params)));
}

/**
 * The values as the driver can take them.
 *
 * @param list<mixed> $params
 * @return list<mixed>
 */
function bound(array $params): array
{
    return array_map(fn($value) => toColumnValue($value), $params);
}

/**
 * Run a statement, turning a refusal by the database into a ConstraintViolation.
 *
 * @param callable(): PDOStatement $statement
 * @throws ConstraintViolation
 */
function guarded(callable $statement): PDOStatement
{
    try {
        return $statement();
    } catch (PDOException $e) {
        throw ConstraintViolation::explains($e) ? ConstraintViolation::from($e) : $e;
    }
}

function insertSql(Connection $conn, Identifier $table, array $columns): string
{
    $quoted = implode(', ', array_map(fn(Identifier $column) => $conn->quote($column), $columns));
    $placeholders = implode(', ', array_fill(0, count($columns), '?'));

    return sprintf('INSERT INTO %s (%s) VALUES (%s)', $conn->quote($table), $quoted, $placeholders);
}

/**
 * The scalar a value is stored as: enums by their backing value, dates as "Y-m-d H:i:s", string objects
 * as text, and a value object with a single public property by that property.
 */
function toColumnValue(mixed $value): mixed
{
    return match (true) {
        $value instanceof BackedEnum => $value->value,
        $value instanceof DateTimeInterface => $value->format('Y-m-d H:i:s'),
        $value instanceof Stringable => (string) $value,
        is_object($value) && 1 === count(get_object_vars($value)) => current(get_object_vars($value)),
        is_bool($value) => (int) $value,
        default => $value,
    };
}

/**
 * The columns the keys of $data address: a constructor parameter name maps to its column, anything else is a column name.
 *
 * @return list<Identifier>
 */
function columnsOf(string $model, array $data): array
{
    $byParameter = [];
    $constructor = (new ReflectionClass($model))->getConstructor();
    foreach ($constructor?->getParameters() ?? [] as $param) {
        $byParameter[$param->getName()] = columnOf($param);
    }

    return array_map(fn($key) => new Identifier($byParameter[$key] ?? (string) $key), array_keys($data));
}

/**
 * The column a constructor parameter is stored in: its #[Column] name, else the snake_case form of its name.
 */
function columnOf(ReflectionParameter $param): string
{
    $attributes = $param->getAttributes(Column::class);

    return [] === $attributes ? snakeCase($param->getName()) : $attributes[0]->newInstance()->name;
}

/**
 * returns Option<T>
 */
function fetchById(Connection $conn, string $model, Identifier $table, mixed $id): Option
{
    $stmt = execute($conn, sprintf('SELECT * FROM %s WHERE %s = ?', $conn->quote($table), $conn->quote(primaryKey($model))), [$id]);
    $row = $stmt->fetch();

    return $row === false ? None() : Some(hydrate($model, $row));
}

function hydrate(string $class, array $data): object
{
    $ref = new ReflectionClass($class);
    $constructor = $ref->getConstructor();

    if (!$constructor) {
        return $ref->newInstance();
    }

    $args = [];
    foreach ($constructor->getParameters() as $param) {
        $column = columnFor($param, $data);
        if (null !== $column) {
            $args[] = fromColumnValue($param, $data[$column]);

            continue;
        }

        if ($param->isDefaultValueAvailable()) {
            $args[] = $param->getDefaultValue();

            continue;
        }

        throw new RuntimeException(sprintf('No column for parameter "%s" of "%s"; the row has %s.', $param->getName(), $class, implode(', ', array_keys($data))));
    }

    return $ref->newInstanceArgs($args);
}

/**
 * The row key feeding a constructor parameter: its #[Column] name, else the parameter name, else its snake_case form.
 */
function columnFor(ReflectionParameter $param, array $data): ?string
{
    $attributes = $param->getAttributes(Column::class);
    $candidates = [] === $attributes
        ? [$param->getName(), snakeCase($param->getName())]
        : [$attributes[0]->newInstance()->name];

    foreach ($candidates as $candidate) {
        if (array_key_exists($candidate, $data)) {
            return $candidate;
        }
    }

    return null;
}

/**
 * The value a stored scalar becomes for a parameter typed with a backed enum, a date class or a value object.
 */
function fromColumnValue(ReflectionParameter $param, mixed $value): mixed
{
    $type = $param->getType();
    if (null === $value || !$type instanceof ReflectionNamedType || $type->isBuiltin()) {
        return $value;
    }

    $class = $type->getName();

    return match (true) {
        is_subclass_of($class, BackedEnum::class) => $class::from($value),
        $class === DateTimeInterface::class => new DateTimeImmutable($value),
        is_a($class, DateTimeInterface::class, true) => new $class($value),
        default => new $class($value),
    };
}

function snakeCase(string $name): string
{
    return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $name));
}
