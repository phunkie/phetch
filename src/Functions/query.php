<?php

namespace Phunkie\Phetch\Functions;

use InvalidArgumentException;
use Phunkie\Phetch\Attributes\Column;
use Phunkie\Phetch\Attributes\Table;
use Phunkie\Phetch\Connection\Connection;
use Phunkie\Phetch\Identifier;
use Phunkie\Phetch\Query;
use Phunkie\Phetch\Query\QueryBuilder;
use Phunkie\Types\Option;
use ReflectionClass;
use ReflectionParameter;
use RuntimeException;

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
            $stmt = $conn->pdo()->prepare(sprintf('SELECT * FROM %s WHERE %s = ? LIMIT 1', $conn->quote($table), $conn->quote($column)));
            $stmt->execute([$value]);
            $data = $stmt->fetch();

            return $data === false ? None() : Some(hydrate($model, $data));
        });
    });
}

/**
 * Every record, as a builder that is also a Query<ImmList<T>> when run as it is.
 */
function all(string $model): QueryBuilder
{
    return new QueryBuilder($model, tableOf($model));
}

/**
 * Create a new record.
 * returns Query<T>
 */
function create(string $model, array $data): Query
{
    $table = tableOf($model);
    $columns = columnsOf($model, $data);

    return new Query(function(Connection $conn) use ($model, $table, $columns, $data) {
        return io(function() use ($conn, $model, $table, $columns, $data) {
            $pdo = $conn->pdo();
            $quoted = implode(', ', array_map(fn(Identifier $column) => $conn->quote($column), $columns));
            $placeholders = implode(', ', array_fill(0, count($columns), '?'));

            $stmt = $pdo->prepare(sprintf('INSERT INTO %s (%s) VALUES (%s)', $conn->quote($table), $quoted, $placeholders));
            $stmt->execute(array_values($data));

            return fetchById($conn, $model, $table, $pdo->lastInsertId())->get();
        });
    });
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

            $stmt = $conn->pdo()->prepare(sprintf('UPDATE %s SET %s WHERE %s = ?', $conn->quote($table), $sets, $conn->quote(primaryKey($model))));
            $stmt->execute([...array_values($data), $id]);

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
            $stmt = $conn->pdo()->prepare(sprintf('DELETE FROM %s WHERE %s = ?', $conn->quote($table), $conn->quote(primaryKey($model))));
            $stmt->execute([$id]);

            return $stmt->rowCount() > 0;
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
    $stmt = $conn->pdo()->prepare(sprintf('SELECT * FROM %s WHERE %s = ?', $conn->quote($table), $conn->quote(primaryKey($model))));
    $stmt->execute([$id]);
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
            $args[] = $data[$column];

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

function snakeCase(string $name): string
{
    return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $name));
}
