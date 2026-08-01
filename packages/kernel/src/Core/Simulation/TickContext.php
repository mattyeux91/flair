<?php

declare(strict_types=1);

namespace Flair\Kernel\Core\Simulation;

use Flair\Kernel\Core\Messaging\Intent;
use Flair\Kernel\Core\Ruleset\Ruleset;

/**
 * Les entrees d'un tick (docs/11-architecture-generale.md §1) : tout ce dont
 * step() a besoin en plus du WorldState, passe en donnees plutot qu'accede
 * via un service (docs/11- §8, DIP).
 */
final readonly class TickContext
{
    /** @param list<Intent> $intents */
    public function __construct(
        public int $tick,
        public int $seed,
        public array $intents,
        public Ruleset $ruleset,
    ) {
    }
}
