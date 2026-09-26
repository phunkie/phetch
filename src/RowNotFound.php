<?php

namespace Phunkie\Phetch;

use RuntimeException;

/**
 * A row that a query needed does not exist.
 */
final class RowNotFound extends RuntimeException
{
    public static function for(string $model, mixed $id): self
    {
        $parts = explode('\\', $model);

        return new self(sprintf('%s %s not found', end($parts), (string) $id));
    }
}
