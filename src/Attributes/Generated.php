<?php

namespace Phunkie\Phetch\Attributes;

use Attribute;

/**
 * Marks a constructor parameter whose value the server produces: a generated key, a timestamp,
 * something the service sets. It is never expected from client input.
 */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final class Generated
{
}
