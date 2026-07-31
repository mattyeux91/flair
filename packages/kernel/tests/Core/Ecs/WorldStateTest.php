<?php

declare(strict_types=1);

namespace Flair\Kernel\Tests\Core\Ecs;

use Flair\Kernel\Core\Ecs\EntityIdAllocator;
use Flair\Kernel\Core\Ecs\WorldState;
use PHPUnit\Framework\TestCase;

final class WorldStateTest extends TestCase
{
    public function testCreateEntityDelegatesToTheAllocator(): void
    {
        $world = new WorldState(new EntityIdAllocator(next: 500));

        self::assertSame(500, $world->createEntity());
        self::assertSame(501, $world->createEntity());
    }

    public function testComponentsReturnsTheSameStoreInstanceForTheSameType(): void
    {
        $world = new WorldState();

        $first = $world->components(WorldStateTestComponentA::class);
        $second = $world->components(WorldStateTestComponentA::class);

        self::assertSame($first, $second);
    }

    public function testComponentsAreIndependentAcrossDifferentTypes(): void
    {
        $world = new WorldState();
        $entity = $world->createEntity();

        $world->components(WorldStateTestComponentA::class)->set($entity, new WorldStateTestComponentA(1));

        self::assertNull($world->components(WorldStateTestComponentB::class)->get($entity));
    }

    public function testSingletonRoundTrip(): void
    {
        $world = new WorldState();
        $value = new WorldStateTestComponentA(42);

        $world->setSingleton($value);

        self::assertSame($value, $world->singleton(WorldStateTestComponentA::class));
    }

    public function testSingletonOnAnUnknownTypeReturnsNull(): void
    {
        $world = new WorldState();

        self::assertNull($world->singleton(WorldStateTestComponentA::class));
    }

    public function testSetSingletonReplacesThePreviousValueOfTheSameType(): void
    {
        $world = new WorldState();

        $world->setSingleton(new WorldStateTestComponentA(1));
        $world->setSingleton(new WorldStateTestComponentA(2));

        self::assertSame(2, $world->singleton(WorldStateTestComponentA::class)?->value);
    }
}

final class WorldStateTestComponentA
{
    public function __construct(public int $value = 0)
    {
    }
}

final class WorldStateTestComponentB
{
    public function __construct(public string $value = '')
    {
    }
}
