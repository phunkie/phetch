<?php

namespace Phunkie\Phetch\Migration;

final readonly class MigrationStatus
{
    public function __construct(
        public string $name,
        public ?int $batch,
    ) {
    }

    public function ran(): bool
    {
        return null !== $this->batch;
    }
}
