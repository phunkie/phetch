<?php

namespace Phunkie\Phetch;

use PDOException;
use RuntimeException;

/**
 * The database refused a write because it would break a constraint: a duplicate key, a missing
 * foreign row, a null in a required column. Every driver reports these under SQLSTATE class 23.
 */
final class ConstraintViolation extends RuntimeException
{
    public static function from(PDOException $exception): self
    {
        return new self(preg_replace('/^SQLSTATE\[\w+\]: [^:]+: \d+ /', '', $exception->getMessage()), 0, $exception);
    }

    public static function explains(PDOException $exception): bool
    {
        return str_starts_with((string) $exception->getCode(), '23');
    }
}
