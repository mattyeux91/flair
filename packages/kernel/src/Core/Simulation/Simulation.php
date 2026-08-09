<?php

declare(strict_types=1);

namespace Flair\Kernel\Core\Simulation;

use Flair\Kernel\Core\Ecs\WorldState;
use Flair\Kernel\Core\Pipeline\Pipeline;

/**
 * La fonction pure du noyau (docs/11-architecture-generale.md §1) :
 * step(WorldState, TickContext): StepResult. Deterministe et sans I/O -
 * memes entrees + meme graine -> memes sorties - pas immuable au sens
 * strict : $state est mute en place par Pipeline::tick() comme partout
 * ailleurs dans le noyau, puis renvoye tel quel.
 *
 * N'assemble que Pipeline + WorldState : Scheduler/OutQueue ne sont plus
 * des parametres separes, ils vivent dans WorldState (voir son docblock).
 */
final class Simulation
{
    public function __construct(private Pipeline $pipeline)
    {
    }

    public function step(WorldState $state, TickContext $ctx): StepResult
    {
        $requests = $this->pipeline->tick($state, $ctx->tick, $ctx->seed, $ctx->ruleset, $ctx->intents);

        return new StepResult($state, $state->outQueue()->pending(), $requests);
    }
}
