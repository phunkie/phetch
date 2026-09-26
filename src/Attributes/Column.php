<?php

namespace Phunkie\Phetch\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final class Column
{
    public function __construct(public string $name)
    {
    }
}
