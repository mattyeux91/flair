<?php

declare(strict_types=1);

namespace Flair\Harness\Simulation;

use Flair\Kernel\Core\Ecs\WorldState;
use Flair\Kernel\Core\Messaging\DomainEvent;
use Flair\Kernel\Core\Ruleset\Ruleset;
use Flair\Kernel\Core\Simulation\Simulation;
use Flair\Kernel\Core\Simulation\TickContext;
use Flair\Kernel\Football\FootballPipeline;

/**
 * Enveloppe interactive autour de Simulation::step() : garde un WorldState
 * vivant en memoire (un seul process CLI, pas de persistance - cf.
 * bin/sandbox.php) et avance tick par tick ou par tranche sur demande.
 * Volontairement sans agregation : Metrics\Sampler existe deja pour ca, ce
 * n'est pas son role de le refaire. Renvoie les evenements bruts d'une
 * tranche, au tri deja impose par OutQueue (systemIndex, entityId, seq) -
 * jamais filtres ni tries a nouveau ici.
 */
final class StepRunner
{
    private readonly Simulation $simulation;
    private int $tick = 0;

    public function __construct(
        private readonly WorldState $world,
        private readonly Ruleset $ruleset,
        private readonly int $worldSeed,
    ) {
        $this->simulation = new Simulation(FootballPipeline::build());
    }

    /**
     * Avance de `$ticks` ticks (1 par defaut), renvoie la concatenation
     * brute des evenements emis sur la tranche, dans l'ordre des ticks
     * traverses.
     *
     * @return list<DomainEvent>
     */
    public function advance(int $ticks = 1): array
    {
        $events = [];

        for ($i = 0; $i < $ticks; $i++) {
            $this->tick++;
            $result = $this->simulation->step($this->world, new TickContext(
                tick: $this->tick,
                seed: $this->worldSeed,
                intents: [],
                ruleset: $this->ruleset,
            ));

            foreach ($result->events as $event) {
                $events[] = $event;
            }
        }

        return $events;
    }

    public function currentTick(): int
    {
        return $this->tick;
    }

    public function world(): WorldState
    {
        return $this->world;
    }
}
