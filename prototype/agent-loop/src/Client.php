<?php

declare(strict_types=1);

/**
 * Prototype jetable, hors monorepo (voir README.md) - pas de namespace
 * Flair\, pas de dependance au kernel.
 */
final class Client
{
    public function __construct(
        public readonly string $name,
        public readonly string $position,
        public readonly int $skill,
        public readonly int $age,
    ) {
    }
}
