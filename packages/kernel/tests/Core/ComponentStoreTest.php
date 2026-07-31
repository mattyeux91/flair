<?php

declare(strict_types=1);

namespace Flair\Kernel\Tests\Core;

use Flair\Kernel\Core\ComponentStore;
use PHPUnit\Framework\TestCase;

final class ComponentStoreTest extends TestCase
{
    public function testSetThenGetReturnsTheSameComponent(): void
    {
        $store = new ComponentStore();
        $component = new class (42) {
            public function __construct(public int $value)
            {
            }
        };

        $store->set(7, $component);

        self::assertSame($component, $store->get(7));
    }

    public function testGetOnAnUnknownEntityReturnsNull(): void
    {
        $store = new ComponentStore();

        self::assertNull($store->get(999));
    }

    public function testRemoveMakesTheEntityDisappear(): void
    {
        $store = new ComponentStore();
        $store->set(1, 'component');

        $store->remove(1);

        self::assertNull($store->get(1));
        self::assertSame([], $store->entities());
    }

    public function testEntitiesAreAlwaysSortedByIdRegardlessOfInsertionOrder(): void
    {
        $store = new ComponentStore();

        foreach ([5, 1, 4, 2, 3] as $entity) {
            $store->set($entity, "c{$entity}");
        }

        self::assertSame([1, 2, 3, 4, 5], $store->entities());
    }

    public function testEntitiesReflectsRemovalsWhileStayingSorted(): void
    {
        $store = new ComponentStore();

        foreach ([3, 1, 2] as $entity) {
            $store->set($entity, true);
        }

        $store->remove(2);

        self::assertSame([1, 3], $store->entities());
    }

    public function testSetOverwritesTheExistingComponentForThatEntity(): void
    {
        $store = new ComponentStore();
        $store->set(1, 'first');
        $store->set(1, 'second');

        self::assertSame('second', $store->get(1));
        self::assertSame([1], $store->entities());
    }
}
