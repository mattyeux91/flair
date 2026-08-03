<?php

declare(strict_types=1);

namespace Flair\Kernel\Tests\Core\Pipeline;

use Flair\Kernel\Core\Ecs\WorldState;
use Flair\Kernel\Core\Messaging\DomainEvent;
use Flair\Kernel\Core\Messaging\Intent;
use Flair\Kernel\Core\Messaging\OutQueue;
use Flair\Kernel\Core\Messaging\Scheduler;
use Flair\Kernel\Core\Pipeline\SeqCounter;
use Flair\Kernel\Core\Pipeline\SystemAccess;
use Flair\Kernel\Core\Pipeline\SystemContext;
use Flair\Kernel\Core\Ruleset\Ruleset;
use Flair\Kernel\Tests\Core\Pipeline\Fixtures\DeclaredSystem;
use PHPUnit\Framework\TestCase;

final class SystemContextTest extends TestCase
{
    public function testExposesTheTickItWasConstructedWith(): void
    {
        $ctx = $this->makeContext(tick: 42);

        self::assertSame(42, $ctx->tick);
    }

    public function testWriteDelegatesToTheUnderlyingWorldState(): void
    {
        $world = new WorldState();
        $ctx = $this->makeContext(world: $world);
        $entity = $ctx->createEntity();
        $component = new SystemContextTestComponent(1);

        $ctx->write(SystemContextTestComponent::class)->set($entity, $component);

        self::assertSame($component, $world->components(SystemContextTestComponent::class)->get($entity));
        self::assertSame($component, $ctx->read(SystemContextTestComponent::class)->get($entity));
    }

    public function testCreateEntityDelegatesToTheUnderlyingWorldState(): void
    {
        $world = new WorldState();
        $ctx = $this->makeContext(world: $world);

        $entityFromContext = $ctx->createEntity();
        $entityFromWorld = $world->createEntity();

        self::assertSame($entityFromContext + 1, $entityFromWorld);
    }

    public function testSingletonRoundTripsThroughTheUnderlyingWorldState(): void
    {
        $world = new WorldState();
        $ctx = $this->makeContext(world: $world);
        $value = new SystemContextTestComponent(7);

        $ctx->setSingleton($value);

        self::assertSame($value, $world->singleton(SystemContextTestComponent::class));
        self::assertSame($value, $ctx->singleton(SystemContextTestComponent::class));
    }

    public function testScheduleForwardsToTheSchedulerWithItsOwnSystemIndex(): void
    {
        $scheduler = new Scheduler();
        $ctx = $this->makeContext(scheduler: $scheduler, systemIndex: 3);
        $event = new SystemContextTestEvent();

        $ctx->schedule($event, atTick: 10, entityId: 5);

        self::assertSame([$event], $scheduler->drainDueBy(10));
    }

    public function testEmitForwardsToTheOutQueueWithItsOwnSystemIndex(): void
    {
        $outQueue = new OutQueue();
        $ctx = $this->makeContext(outQueue: $outQueue, systemIndex: 2);
        $event = new SystemContextTestEvent();

        $ctx->emit($event, entityId: 5);

        self::assertSame([$event], $outQueue->drain());
    }

    public function testSeqIsSharedAcrossContextsAndDeterminesEmissionOrderWithinTheSameTick(): void
    {
        $outQueue = new OutQueue();
        $seq = new SeqCounter();

        // Meme systemIndex des deux cotes : seul `seq` differencie l'ordre de sortie.
        $systemA = $this->makeContext(outQueue: $outQueue, seq: $seq, systemIndex: 0);
        $systemB = $this->makeContext(outQueue: $outQueue, seq: $seq, systemIndex: 0);

        $first = new SystemContextTestEvent();
        $second = new SystemContextTestEvent();
        $third = new SystemContextTestEvent();

        // Appels entrelaces entre deux "systemes", comme dans un vrai tick.
        $systemA->emit($first, entityId: 0);
        $systemB->emit($second, entityId: 0);
        $systemA->emit($third, entityId: 0);

        self::assertSame([$first, $second, $third], $outQueue->drain());
    }

    public function testRngIsDeterministicForTheSameStreamKeyAndDivergesOtherwise(): void
    {
        $a = $this->makeContext(tick: 10, systemId: 'aging', worldSeed: 777);
        $b = $this->makeContext(tick: 10, systemId: 'aging', worldSeed: 777);
        $differentSystem = $this->makeContext(tick: 10, systemId: 'training', worldSeed: 777);

        self::assertSame($a->rng(entityId: 42)->nextUint32(), $b->rng(entityId: 42)->nextUint32());
        self::assertNotSame(
            $a->rng(entityId: 42)->nextUint32(),
            $differentSystem->rng(entityId: 42)->nextUint32(),
        );
    }

    public function testRulesetRoundTripsThroughTheConstructedValue(): void
    {
        $ruleset = new Ruleset('2026.1.0');
        $ctx = $this->makeContext(ruleset: $ruleset);

        self::assertSame($ruleset, $ctx->ruleset());
    }

    public function testIntentsRoundTripThroughTheConstructedList(): void
    {
        $intent = new SystemContextTestIntent();
        $ctx = $this->makeContext(intents: [$intent]);

        self::assertSame([$intent], $ctx->intents());
    }

    /**
     * Declarations permissives par defaut sur le seul composant manipule ici :
     * ce cas de test porte sur la delegation au WorldState, pas sur le
     * controle des declarations (SystemContextAccessTest s'en charge).
     *
     * @param list<Intent> $intents
     */
    private function makeContext(
        int $tick = 1,
        int $systemIndex = 0,
        string $systemId = 'test-system',
        int $worldSeed = 1,
        ?Ruleset $ruleset = null,
        array $intents = [],
        ?WorldState $world = null,
        ?Scheduler $scheduler = null,
        ?OutQueue $outQueue = null,
        ?SeqCounter $seq = null,
    ): SystemContext {
        return new SystemContext(
            tick: $tick,
            systemIndex: $systemIndex,
            access: SystemAccess::of(new DeclaredSystem(
                id: $systemId,
                reads: [SystemContextTestComponent::class],
                writes: [SystemContextTestComponent::class],
            )),
            worldSeed: $worldSeed,
            ruleset: $ruleset ?? new Ruleset('test'),
            intents: $intents,
            world: $world ?? new WorldState(),
            scheduler: $scheduler ?? new Scheduler(),
            outQueue: $outQueue ?? new OutQueue(),
            seq: $seq ?? new SeqCounter(),
        );
    }
}

final class SystemContextTestEvent implements DomainEvent
{
}

final class SystemContextTestComponent
{
    public function __construct(public int $value)
    {
    }
}

final class SystemContextTestIntent implements Intent
{
}
