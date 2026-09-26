<?php

namespace Phunkie\Phetch;

use InvalidArgumentException;

final readonly class Identifier
{
    public function __construct(public string $name)
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
            throw new InvalidArgumentException(sprintf('"%s" is not a valid SQL identifier.', $name));
        }
    }

    public function quotedWith(string $quote): string
    {
        return $quote.$this->name.$quote;
    }
}
