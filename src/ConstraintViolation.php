<?php

namespace Phunkie\Phetch;

use PDOException;
use RuntimeException;

/**
 * The database refused a write because it would break a constraint: a duplicate key, a missing
 * foreign row, a null in a required column. Every driver reports these under SQLSTATE class 23;
 * which constraint it was is in $constraint, the driver's message without the PDO prefix in the message.
 */
final class ConstraintViolation extends RuntimeException
{
    public function __construct(string $message, public readonly Constraint $constraint, ?PDOException $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public static function from(PDOException $exception): self
    {
        return new self(preg_replace('/^SQLSTATE\[\w+\]: [^:]+: \d+ /', '', $exception->getMessage()), Constraint::reportedBy($exception), $exception);
    }

    public static function explains(PDOException $exception): bool
    {
        return str_starts_with((string) $exception->getCode(), '23');
    }
}
