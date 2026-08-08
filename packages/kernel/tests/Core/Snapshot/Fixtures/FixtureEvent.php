<?php

declare(strict_types=1);

namespace Flair\Kernel\Tests\Core\Snapshot\Fixtures;

use Flair\Kernel\Core\Messaging\DomainEvent;

/** Fait de test : ce qui voyage dans le Scheduler et l'OutQueue. */
final class FixtureEvent implements DomainEvent
{
    public function __construct(public int $subjectId, public FixtureRank $rank)
    {
    }
}
