<?php

namespace Phunkie\Phetch\Functions;

use Phunkie\Effect\IO\IO;
use Phunkie\Phetch\Connection\Connection;
use Phunkie\Phetch\Query\QueryBuilder;
use Phunkie\Phetch\Attributes\Table;
use Phunkie\Phetch\Query;
use Phunkie\Types\ImmList;
use ReflectionClass;

use function Phunkie\Effect\Functions\io\io;
use function None;
use function Some;

/**
 * Find a record by ID.
 * returns Query<Option<T>>
 */
function find(string $model, mixed $id): Query
{
    return new Query(function(Connection $conn) use ($model, $id) {
        return io(function() use ($conn, $model, $id) {
            $table = getTableName($model);
            $stmt = $conn->pdo()->prepare("SELECT * FROM $table WHERE id = ?");
            $stmt->execute([$id]);
            $data = $stmt->fetch();
            
            return $data === false ? None() : Some(hydrate($model, $data));
        });
    });
}

/**
 * Find a record by column value.
 * returns Query<Option<T>>
 */
function findBy(string $model, string $column, mixed $value): Query
{
    return new Query(function(Connection $conn) use ($model, $column, $value) {
        return io(function() use ($conn, $model, $column, $value) {
            $table = getTableName($model);
            $stmt = $conn->pdo()->prepare("SELECT * FROM $table WHERE $column = ? LIMIT 1");
            $stmt->execute([$value]);
            $data = $stmt->fetch();
            
            return $data === false ? None() : Some(hydrate($model, $data));
        });
    });
}

/**
 * Get all records.
 * returns Query<ImmList<T>>
 */
function all(string $model): Query
{
    return (new QueryBuilder($model, getTableName($model)))->get();
}

/**
 * Create a new record.
 * returns Query<T>
 */
function create(string $model, array $data): Query
{
    return new Query(function(Connection $conn) use ($model, $data) {
        return io(function() use ($conn, $model, $data) {
            $table = getTableName($model);
            $pdo = $conn->pdo();
            
            $cols = implode(', ', array_keys($data));
            $placeholders = implode(', ', array_fill(0, count($data), '?'));
            
            $stmt = $pdo->prepare("INSERT INTO $table ($cols) VALUES ($placeholders)");
            $stmt->execute(array_values($data));
            
            $id = $pdo->lastInsertId();
            
            $stmt = $pdo->prepare("SELECT * FROM $table WHERE id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            
            return hydrate($model, $row);
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
    
    return (new QueryBuilder($model, getTableName($model)))->where($column, $operator, $value);
}

/**
 * Update a record.
 * returns Query<Option<T>>
 */
function update(string $model, mixed $id, array $data): Query
{
    return new Query(function(Connection $conn) use ($model, $id, $data) {
        return io(function() use ($conn, $model, $id, $data) {
            $table = getTableName($model);
            $pdo = $conn->pdo();
            
            $sets = [];
            $params = [];
            foreach ($data as $k => $v) {
                $sets[] = "$k = ?";
                $params[] = $v;
            }
            $params[] = $id;
            
            $stmt = $pdo->prepare("UPDATE $table SET " . implode(', ', $sets) . " WHERE id = ?");
            $stmt->execute($params);
            
            $stmt = $pdo->prepare("SELECT * FROM $table WHERE id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            
            return $row === false ? None() : Some(hydrate($model, $row));
        });
    });
}

/**
 * Delete a record.
 * returns Query<bool>
 */
function delete(string $model, mixed $id): Query
{
    return new Query(function(Connection $conn) use ($model, $id) {
        return io(function() use ($conn, $model, $id) {
            $table = getTableName($model);
            $stmt = $conn->pdo()->prepare("DELETE FROM $table WHERE id = ?");
            $stmt->execute([$id]);
            return $stmt->rowCount() > 0;
        });
    });
}

// --- Internals ---

function getTableName(string $class): string
{
    $ref = new ReflectionClass($class);
    $attr = $ref->getAttributes(Table::class);
    
    if (empty($attr)) {
        $parts = explode('\\', $class);
        return strtolower(end($parts)) . 's';
    }
    
    return $attr[0]->newInstance()->name;
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
        $name = $param->getName();
        if (array_key_exists($name, $data)) {
            $args[] = $data[$name];
        } else {
             if ($param->isDefaultValueAvailable()) {
                 $args[] = $param->getDefaultValue();
             } else {
                 throw new \Exception("Missing data for parameter '$name' in class $class");
             }
        }
    }
    
    return $ref->newInstanceArgs($args);
}
