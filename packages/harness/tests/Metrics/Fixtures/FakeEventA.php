<?php

declare(strict_types=1);

namespace Flair\Harness\Tests\Metrics\Fixtures;

use Flair\Kernel\Core\Messaging\DomainEvent;

/** Fait factice, pour tester EventGraphCollector::tally() sans dependre d'un evenement football reel. */
final class FakeEventA implements DomainEvent
{
}
