<?php

declare(strict_types=1);

namespace Flair\Kernel\Core\Simulation;

use Flair\Kernel\Core\Ecs\WorldState;
use Flair\Kernel\Core\Messaging\DomainEvent;

/**
 * La sortie d'un tick (docs/11-architecture-generale.md §1) : le WorldState
 * (mute en place, cf. Simulation::step()) et les Faits emis pendant ce tick
 * - exactement ce que le Host journalise et diffuse (docs/13- §8 :
 * eventStore->append(), stream->publish()).
 */
final readonly class StepResult
{
    /** @param list<DomainEvent> $events */
    public function __construct(
        public WorldState $state,
        public array $events,
    ) {
    }
}
