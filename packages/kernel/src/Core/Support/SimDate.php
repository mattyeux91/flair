<?php

declare(strict_types=1);

namespace Flair\Kernel\Core\Support;

/**
 * Le seul temps connu du noyau (docs/13-moteur-de-simulation.md §1) : un
 * compteur de jours simules, jamais une horloge murale. 1 tick = 1 jour
 * simule, donc `$ctx->tick` est directement utilisable comme `epochDay` -
 * pas besoin d'un WorldClock ni d'un epoch reel tant que seule la difference
 * entre deux dates compte.
 */
final readonly class SimDate
{
    public function __construct(public int $epochDay)
    {
    }

    public function yearsSince(self $earlier): float
    {
        return ($this->epochDay - $earlier->epochDay) / 365.0;
    }
}
