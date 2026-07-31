<?php

declare(strict_types=1);

namespace Flair\Kernel\Tests\Core\Pipeline;

use Flair\Kernel\Core\Ecs\WorldState;
use Flair\Kernel\Core\Messaging\DomainEvent;
use Flair\Kernel\Core\Messaging\OutQueue;
use Flair\Kernel\Core\Messaging\Scheduler;
use Flair\Kernel\Core\Pipeline\Pipeline;
use Flair\Kernel\Core\Pipeline\System;
use Flair\Kernel\Core\Pipeline\SystemContext;
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
        $pipeline->tick(new WorldState(), new Scheduler(), new OutQueue(), tick: 1);

        self::assertSame(['a', 'b', 'c'], $log);
    }

    public function testASystemOnlyHandlesEventsItSubscribesTo(): void
    {
        $x = new PipelineTestRecordingSystem('x', subscribesTo: [PipelineTestEventX::class]);
        $y = new PipelineTestRecordingSystem('y', subscribesTo: [PipelineTestEventY::class]);

        $scheduler = new Scheduler();
        $scheduler->schedule(new PipelineTestEventX(), atTick: 1, systemIndex: 0, entityId: 0, seq: 0);
        $scheduler->schedule(new PipelineTestEventY(), atTick: 1, systemIndex: 0, entityId: 0, seq: 1);

        $pipeline = new Pipeline([$x, $y]);
        $pipeline->tick(new WorldState(), $scheduler, new OutQueue(), tick: 1);

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

        $pipeline = new Pipeline([$recorder]);
        $pipeline->tick(new WorldState(), $scheduler, $outQueue, tick: 1);

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
        $outQueue = new OutQueue();

        $pipeline = new Pipeline([$selfSubscriber]);
        $pipeline->tick(new WorldState(), $scheduler, $outQueue, tick: 1);

        // Un seul handle() ce tick, malgre l'emission d'un evenement du meme
        // type pendant handle().
        self::assertSame(['handle:' . PipelineTestEventX::class, 'update'], $selfSubscriber->log);
        // L'evenement emis n'a pas disparu : il attend le prochain drain().
        self::assertSame(1, $outQueue->count());

        $selfSubscriber->log = [];
        $pipeline->tick(new WorldState(), $scheduler, $outQueue, tick: 2);

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
        );
        $reactor = new PipelineTestRecordingSystem('reactor', subscribesTo: [PipelineTestEventX::class]);

        $world = new WorldState();
        $scheduler = new Scheduler();
        $outQueue = new OutQueue();
        $pipeline = new Pipeline([$counter, $reactor]);

        $pipeline->tick($world, $scheduler, $outQueue, tick: 1); // compteur = 1
        self::assertSame(['update'], $reactor->log); // update() tourne chaque tick, meme sans handle()

        $reactor->log = [];
        $pipeline->tick($world, $scheduler, $outQueue, tick: 2); // compteur = 2, programme pour le tick 3
        self::assertSame(['update'], $reactor->log);

        $reactor->log = [];
        $pipeline->tick($world, $scheduler, $outQueue, tick: 3); // l'echeance arrive
        self::assertSame(['handle:' . PipelineTestEventX::class, 'update'], $reactor->log);
    }
}

final class PipelineTestRecordingSystem implements System
{
    /** @var list<string> */
    public array $log = [];

    /** @param list<class-string> $subscribesTo */
    public function __construct(
        private readonly string $name,
        private readonly array $subscribesTo = [],
        private readonly ?\Closure $onHandle = null,
        private readonly ?\Closure $onUpdate = null,
    ) {
    }

    public function id(): string
    {
        return $this->name;
    }

    public function reads(): array
    {
        return [];
    }

    public function writes(): array
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
