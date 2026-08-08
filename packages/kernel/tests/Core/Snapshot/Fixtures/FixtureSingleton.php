<?php

declare(strict_types=1);

namespace Flair\Kernel\Tests\Core\Snapshot\Fixtures;

/** Singleton de test (adresse par type, docs/12- §3 bis). */
final readonly class FixtureSingleton
{
    public function __construct(public int $total = 0)
    {
    }
}
