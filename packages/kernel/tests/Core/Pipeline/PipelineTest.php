<?php

declare(strict_types=1);

namespace Flair\Kernel\Tests\Core\Pipeline;

use Flair\Kernel\Core\Ecs\WorldState;
use Flair\Kernel\Core\Messaging\DecisionRequest;
use Flair\Kernel\Core\Messaging\DomainEvent;
use Flair\Kernel\Core\Messaging\Intent;
use Flair\Kernel\Core\Messaging\OutQueue;
use Flair\Kernel\Core\Messaging\Scheduler;
use Flair\Kernel\Core\Pipeline\Pipeline;
use Flair\Kernel\Core\Pipeline\System;
use Flair\Kernel\Core\Pipeline\SystemContext;
use Flair\Kernel\Core\Ruleset\Ruleset;
use PHPUnit\Framework\TestCase;

final class PipelineTest extends TestCase
{
    public function testSystemsRunInDeclaredOrder(): void
    {
        $log = [];
        $a = new PipelineTestRecordingSystem('a', onUpdate: function () use (&$log): void {
            $log[] = 'a';
        });
        $b = new PipelineTestRecordingSystem('b', onUpdate: function () use (&$log): void {
            $log[] = 'b';
        });
        $c = new PipelineTestRecordingSystem('c', onUpdate: function () use (&$log): void {
            $log[] = 'c';
        });

        $pipeline = new Pipeline([$a, $b, $c]);
        $pipeline->tick(new WorldState(), tick: 1, worldSeed: 1, ruleset: $this->ruleset(), intents: []);

        self::assertSame(['a', 'b', 'c'], $log);
    }

    public function testASystemOnlyHandlesEventsItSubscribesTo(): void
    {
        $x = new PipelineTestRecordingSystem('x', subscribesTo: [PipelineTestEventX::class]);
        $y = new PipelineTestRecordingSystem('y', subscribesTo: [PipelineTestEventY::class]);

        $scheduler = new Scheduler();
        $scheduler->schedule(new PipelineTestEventX(), atTick: 1, systemIndex: 0, entityId: 0, seq: 0);
        $scheduler->schedule(new PipelineTestEventY(), atTick: 1, systemIndex: 0, entityId: 0, seq: 1);
        $world = new WorldState(scheduler: $scheduler);

        $pipeline = new Pipeline([$x, $y]);
        $pipeline->tick($world, tick: 1, worldSeed: 1, ruleset: $this->ruleset(), intents: []);

        self::assertSame(['handle:' . PipelineTestEventX::class, 'update'], $x->log);
        self::assertSame(['handle:' . PipelineTestEventY::class, 'update'], $y->log);
    }

    public function testHandledEventsComeFromBothSchedulerAndOutQueue(): void
    {
        $recorder = new PipelineTestRecordingSystem(
            'r',
            subscribesTo: [PipelineTestEventX::class, PipelineTestEventY::class],
        );

        $scheduler = new Scheduler();
        $scheduler->schedule(new PipelineTestEventX(), atTick: 1, systemIndex: 0, entityId: 0, seq: 0);

        $outQueue = new OutQueue();
        $outQueue->emit(new PipelineTestEventY(), systemIndex: 0, entityId: 0, seq: 0);

        $world = new WorldState(scheduler: $scheduler, outQueue: $outQueue);

        $pipeline = new Pipeline([$recorder]);
        $pipeline->tick($world, tick: 1, worldSeed: 1, ruleset: $this->ruleset(), intents: []);

        self::assertSame(
            ['handle:' . PipelineTestEventX::class, 'handle:' . PipelineTestEventY::class, 'update'],
            $recorder->log,
        );
    }

    public function testAnEventEmittedDuringThisTickIsNeverHandledInTheSameTick(): void
    {
        $selfSubscriber = new PipelineTestRecordingSystem(
            'self',
            subscribesTo: [PipelineTestEventX::class],
            onHandle: function (DomainEvent $event, SystemContext $ctx): void {
                $ctx->emit(new PipelineTestEventX(), entityId: 0);
            },
        );

        $scheduler = new Scheduler();
        $scheduler->schedule(new PipelineTestEventX(), atTick: 1, systemIndex: 0, entityId: 0, seq: 0);
        $world = new WorldState(scheduler: $scheduler);

        $pipeline = new Pipeline([$selfSubscriber]);
        $pipeline->tick($world, tick: 1, worldSeed: 1, ruleset: $this->ruleset(), intents: []);

        // Un seul handle() ce tick, malgre l'emission d'un evenement du meme
        // type pendant handle().
        self::assertSame(['handle:' . PipelineTestEventX::class, 'update'], $selfSubscriber->log);
        // L'evenement emis n'a pas disparu : il attend le prochain drain().
        self::assertSame(1, $world->outQueue()->count());

        $selfSubscriber->log = [];
        $pipeline->tick($world, tick: 2, worldSeed: 1, ruleset: $this->ruleset(), intents: []);

        self::assertSame(['handle:' . PipelineTestEventX::class, 'update'], $selfSubscriber->log);
    }

    public function testEndToEndWiringAcrossTicksWithARealSingletonAndScheduler(): void
    {
        // Systeme periodique : incremente un compteur (singleton) a chaque
        // tick, et programme un evenement pour le tick suivant des qu'il
        // atteint 2 - exerce singleton() + setSingleton() + schedule() via
        // de vraies instances de WorldState/Scheduler, pas des doublures.
        $counter = new PipelineTestRecordingSystem(
            'counter',
            onUpdate: function (SystemContext $ctx): void {
                $current = $ctx->singleton(PipelineTestCounter::class) ?? new PipelineTestCounter(0);
                $next = new PipelineTestCounter($current->value + 1);
                $ctx->setSingleton($next);

                if ($next->value === 2) {
                    $ctx->schedule(new PipelineTestEventX(), atTick: $ctx->tick + 1, entityId: 0);
                }
            },
            // Un singleton s'oppose comme un composant : sans ces deux
            // declarations, singleton()/setSingleton() refusent (docs/13- §2).
            reads: [PipelineTestCounter::class],
            writes: [PipelineTestCounter::class],
        );
        $reactor = new PipelineTestRecordingSystem('reactor', subscribesTo: [PipelineTestEventX::class]);

        $world = new WorldState();
        $pipeline = new Pipeline([$counter, $reactor]);

        $pipeline->tick($world, tick: 1, worldSeed: 1, ruleset: $this->ruleset(), intents: []); // compteur = 1
        self::assertSame(['update'], $reactor->log); // update() tourne chaque tick, meme sans handle()

        $reactor->log = [];
        $pipeline->tick($world, tick: 2, worldSeed: 1, ruleset: $this->ruleset(), intents: []); // compteur = 2, programme pour le tick 3
        self::assertSame(['update'], $reactor->log);

        $reactor->log = [];
        $pipeline->tick($world, tick: 3, worldSeed: 1, ruleset: $this->ruleset(), intents: []); // l'echeance arrive
        self::assertSame(['handle:' . PipelineTestEventX::class, 'update'], $reactor->log);
    }

    public function testWorldSeedIsThreadedThroughToEachSystemsRngStream(): void
    {
        $draws = [];
        $system = new PipelineTestRecordingSystem(
            'drawer',
            onUpdate: function (SystemContext $ctx) use (&$draws): void {
                $draws[] = $ctx->rng(entityId: 7)->nextUint32();
            },
        );

        $pipeline = new Pipeline([$system]);

        $pipeline->tick(new WorldState(), tick: 1, worldSeed: 777, ruleset: $this->ruleset(), intents: []);
        $pipeline->tick(new WorldState(), tick: 1, worldSeed: 777, ruleset: $this->ruleset(), intents: []);
        $pipeline->tick(new WorldState(), tick: 1, worldSeed: 999, ruleset: $this->ruleset(), intents: []);

        self::assertSame($draws[0], $draws[1]); // meme worldSeed -> meme tirage
        self::assertNotSame($draws[0], $draws[2]); // worldSeed different -> tirage different
    }

    public function testRulesetAndIntentsAreThreadedThroughToSystemContext(): void
    {
        $intent = new PipelineTestIntent();
        $ruleset = $this->ruleset('2026.1.0');
        $seenRuleset = null;
        $seenIntents = null;

        $system = new PipelineTestRecordingSystem(
            'reader',
            onUpdate: function (SystemContext $ctx) use (&$seenRuleset, &$seenIntents): void {
                $seenRuleset = $ctx->ruleset();
                $seenIntents = $ctx->intents();
            },
        );

        $pipeline = new Pipeline([$system]);
        $pipeline->tick(new WorldState(), tick: 1, worldSeed: 1, ruleset: $ruleset, intents: [$intent]);

        self::assertSame($ruleset, $seenRuleset);
        self::assertSame([$intent], $seenIntents);
    }

    /**
     * Les questions sortent du tick, **et n'entrent nulle part ailleurs**.
     * C'est la moitie du contrat de docs/16- §1 qu'un test peut tenir : une
     * question n'est ni journalisee (elle n'est pas dans l'OutQueue, donc pas
     * dans `StepResult::$events`), ni relue par un systeme au tick suivant.
     */
    public function testQuestionsLeaveTheTickWithoutEnteringTheEventFlow(): void
    {
        $request = new PipelineTestRequest();
        $asker = new PipelineTestRecordingSystem(
            'asker',
            onUpdate: function (SystemContext $ctx) use ($request): void {
                $ctx->ask($request, entityId: 3);
            },
        );
        $listener = new PipelineTestRecordingSystem('listener', subscribesTo: [PipelineTestEventX::class]);

        $world = new WorldState();
        $pipeline = new Pipeline([$asker, $listener]);

        $asked = $pipeline->tick($world, tick: 1, worldSeed: 1, ruleset: $this->ruleset(), intents: []);
        self::assertSame([$request], $asked);
        self::assertSame([], $world->outQueue()->pending(), 'Une question n\'est pas un Fait.');

        // Au tick suivant : la question ne revient pas, et personne ne l'a
        // recue. Une OutQueue rejouerait son contenu ici.
        $again = $pipeline->tick($world, tick: 2, worldSeed: 1, ruleset: $this->ruleset(), intents: []);
        self::assertSame([$request], $again, 'La question du tick 2 est celle du tick 2, pas un rejeu.');
        self::assertSame(['update', 'update'], $listener->log);
    }

    /**
     * L'ordre total de docs/13- §4.5, applique aux questions : l'ordre
     * d'insertion ne doit jamais transparaitre. Ici le second systeme
     * interroge l'entite **1**, le premier l'entite **9** - trier par
     * `(systemIndex, entityId)` les laisse donc dans l'ordre des systemes,
     * quand un tri par entite seule les inverserait.
     */
    public function testQuestionsComeOutInTotalOrderNotInsertionOrder(): void
    {
        $first = new PipelineTestRequest();
        $second = new PipelineTestRequest();

        $a = new PipelineTestRecordingSystem('a', onUpdate: function (SystemContext $ctx) use ($first): void {
            $ctx->ask($first, entityId: 9);
        });
        $b = new PipelineTestRecordingSystem('b', onUpdate: function (SystemContext $ctx) use ($second): void {
            $ctx->ask($second, entityId: 1);
        });

        $asked = (new Pipeline([$a, $b]))->tick(new WorldState(), tick: 1, worldSeed: 1, ruleset: $this->ruleset(), intents: []);

        self::assertSame([$first, $second], $asked);
    }

    private function ruleset(string $version = 'test'): Ruleset
    {
        return new Ruleset($version);
    }
}

final class PipelineTestRequest implements DecisionRequest
{
    public function __construct(public int $expiresAtTick = 1)
    {
    }
}

final class PipelineTestRecordingSystem implements System
{
    /** @var list<string> */
    public array $log = [];

    /**
     * @param list<class-string> $subscribesTo
     * @param list<class-string> $reads
     * @param list<class-string> $writes
     */
    public function __construct(
        private readonly string $name,
        private readonly array $subscribesTo = [],
        private readonly ?\Closure $onHandle = null,
        private readonly ?\Closure $onUpdate = null,
        private readonly array $reads = [],
        private readonly array $writes = [],
    ) {
    }

    public function id(): string
    {
        return $this->name;
    }

    public function reads(): array
    {
        return $this->reads;
    }

    public function writes(): array
    {
        return $this->writes;
    }

    public function removes(): array
    {
        return [];
    }

    public function creates(): array
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

        if ($this->onHandle !== null) {
            ($this->onHandle)($event, $ctx);
        }
    }

    public function update(SystemContext $ctx): void
    {
        $this->log[] = 'update';

        if ($this->onUpdate !== null) {
            ($this->onUpdate)($ctx);
        }
    }
}

final class PipelineTestEventX implements DomainEvent
{
}

final class PipelineTestEventY implements DomainEvent
{
}

final class PipelineTestCounter
{
    public function __construct(public int $value)
    {
    }
}

final class PipelineTestIntent implements Intent
{
}
