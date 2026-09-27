<?php

namespace Phunkie\Phetch;

use PDOException;

/**
 * The kind of constraint a write broke. Each driver reports it differently: PostgreSQL by a distinct
 * SQLSTATE, MySQL by its driver code under SQLSTATE 23000, SQLite only in the message text.
 */
enum Constraint
{
    case Unique;
    case ForeignKey;
    case NotNull;
    case Check;
    case Other;

    public static function reportedBy(PDOException $exception): self
    {
        [$sqlState, $driverCode, $message] = ($exception->errorInfo ?? []) + [null, null, ''];

        return match (true) {
            '23505' === $sqlState, 1062 === $driverCode, str_starts_with($message, 'UNIQUE constraint failed') => self::Unique,
            '23503' === $sqlState, in_array($driverCode, [1216, 1217, 1451, 1452], true), str_starts_with($message, 'FOREIGN KEY constraint failed') => self::ForeignKey,
            '23502' === $sqlState, 1048 === $driverCode, str_starts_with($message, 'NOT NULL constraint failed') => self::NotNull,
            '23514' === $sqlState, 3819 === $driverCode, str_starts_with($message, 'CHECK constraint failed') => self::Check,
            default => self::Other,
        };
    }
}
