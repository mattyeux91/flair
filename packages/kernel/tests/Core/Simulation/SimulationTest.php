<?php

declare(strict_types=1);

namespace Flair\Kernel\Tests\Core\Simulation;

use Flair\Kernel\Core\Ecs\WorldState;
use Flair\Kernel\Core\Messaging\DomainEvent;
use Flair\Kernel\Core\Messaging\Intent;
use Flair\Kernel\Core\Pipeline\Pipeline;
use Flair\Kernel\Core\Pipeline\System;
use Flair\Kernel\Core\Pipeline\SystemContext;
use Flair\Kernel\Core\Ruleset\Ruleset;
use Flair\Kernel\Core\Simulation\Simulation;
use Flair\Kernel\Core\Simulation\TickContext;
use PHPUnit\Framework\TestCase;

final class SimulationTest extends TestCase
{
    public function testStepResultEventsAreExactlyWhatWasEmittedDuringThisTick(): void
    {
        $emitted = new SimulationTestEvent();
        $system = new SimulationTestSystem(
            onUpdate: function (SystemContext $ctx) use ($emitted): void {
                $ctx->emit($emitted, entityId: 1);
            },
        );

        $simulation = new Simulation(new Pipeline([$system]));
        $result = $simulation->step(new WorldState(), $this->context(tick: 1));

        self::assertSame([$emitted], $result->events);
    }

    public function testStepResultEventsDoNotIncludeEventsAlreadyHandledThisTick(): void
    {
        // Un fait planifie pour ce tick est consomme (handle()) pendant le
        // tick, mais ce n'est pas un nouvel emit() - il ne doit pas
        // ressortir dans StepResult.events, qui ne capture que les emit()
        // de CE tick.
        $system = new SimulationTestSystem(subscribesTo: [SimulationTestEvent::class]);

        $world = new WorldState();
        $world->scheduler()->schedule(new SimulationTestEvent(), atTick: 1, systemIndex: 0, entityId: 0, seq: 0);

        $simulation = new Simulation(new Pipeline([$system]));
        $result = $simulation->step($world, $this->context(tick: 1));

        self::assertSame([], $result->events);
    }

    public function testStepResultCarriesTheMutatedWorldState(): void
    {
        $world = new WorldState();
        $system = new SimulationTestSystem(
            onUpdate: function (SystemContext $ctx): void {
                $ctx->createEntity();
            },
        );

        $simulation = new Simulation(new Pipeline([$system]));
        $result = $simulation->step($world, $this->context(tick: 1));

        self::assertSame($world, $result->state);
    }

    public function testTickContextRulesetAndIntentsReachSystemContext(): void
    {
        $ruleset = new Ruleset('2026.1.0');
        $intent = new SimulationTestIntent();
        $seenRuleset = null;
        $seenIntents = null;

        $system = new SimulationTestSystem(
            onUpdate: function (SystemContext $ctx) use (&$seenRuleset, &$seenIntents): void {
                $seenRuleset = $ctx->ruleset();
                $seenIntents = $ctx->intents();
            },
        );

        $simulation = new Simulation(new Pipeline([$system]));
        $simulation->step(new WorldState(), new TickContext(tick: 1, seed: 1, intents: [$intent], ruleset: $ruleset));

        self::assertSame($ruleset, $seenRuleset);
        self::assertSame([$intent], $seenIntents);
    }

    public function testAScheduledEventSurvivesAcrossStepCallsOnTheSameWorldState(): void
    {
        // La raison d'etre de la decision "Scheduler/OutQueue dans
        // WorldState" : un seul WorldState reutilise a travers deux appels
        // a step() successifs, sans jamais reconstruire Scheduler/OutQueue a
        // la main - exactement ce que ferait le Host entre deux ticks.
        $reactor = new SimulationTestSystem(subscribesTo: [SimulationTestEvent::class]);
        $scheduler = new SimulationTestSystem(
            onUpdate: function (SystemContext $ctx): void {
                $ctx->schedule(new SimulationTestEvent(), atTick: $ctx->tick + 1, entityId: 0);
            },
        );

        $world = new WorldState();
        $simulation = new Simulation(new Pipeline([$scheduler, $reactor]));

        $simulation->step($world, $this->context(tick: 1));
        self::assertSame(['update'], $reactor->log);

        $reactor->log = [];
        $simulation->step($world, $this->context(tick: 2));
        self::assertSame(['handle:' . SimulationTestEvent::class, 'update'], $reactor->log);
    }

    private function context(int $tick, int $seed = 1): TickContext
    {
        return new TickContext(tick: $tick, seed: $seed, intents: [], ruleset: new Ruleset('test'));
    }
}

final class SimulationTestSystem implements System
{
    /** @var list<string> */
    public array $log = [];

    /** @param list<class-string> $subscribesTo */
    public function __construct(
        private readonly array $subscribesTo = [],
        private readonly ?\Closure $onUpdate = null,
    ) {
    }

    public function id(): string
    {
        return 'simulation-test-system';
    }

    public function reads(): array
    {
        return [];
    }

    public function writes(): array
    {
        return [];
    }

    public function removes(): array
    {
        return [];
    }

    public function subscribesTo(): array
    {
        return $this->subscribesTo;
    }

    public function handle(DomainEvent $event, SystemContext $ctx): void
    {
        $this->log[] = 'handle:' . $event::class;
    }

    public function update(SystemContext $ctx): void
    {
        $this->log[] = 'update';

        if ($this->onUpdate !== null) {
            ($this->onUpdate)($ctx);
        }
    }
}

final class SimulationTestEvent implements DomainEvent
{
}

final class SimulationTestIntent implements Intent
{
}
