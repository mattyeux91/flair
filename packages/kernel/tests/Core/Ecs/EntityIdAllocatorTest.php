<?php

declare(strict_types=1);

namespace Flair\Kernel\Tests\Core\Ecs;

use Flair\Kernel\Core\Ecs\EntityIdAllocator;
use PHPUnit\Framework\TestCase;

final class EntityIdAllocatorTest extends TestCase
{
    public function testAllocatesMonotonicallyIncreasingIds(): void
    {
        $allocator = new EntityIdAllocator();

        $ids = [];
        for ($i = 0; $i < 1000; $i++) {
            $ids[] = $allocator->allocate();
        }

        self::assertSame(range(1, 1000), $ids);
    }

    public function testNeverAllocatesTheReservedZeroValue(): void
    {
        $allocator = new EntityIdAllocator();

        self::assertNotSame(0, $allocator->allocate());
    }

    public function testCanResumeFromAGivenCounter(): void
    {
        $allocator = new EntityIdAllocator(next: 500);

        self::assertSame(500, $allocator->allocate());
        self::assertSame(501, $allocator->allocate());
    }

    public function testAllocatedIdsAreNeverReused(): void
    {
        $allocator = new EntityIdAllocator();

        $first = [];
        for ($i = 0; $i < 100; $i++) {
            $first[] = $allocator->allocate();
        }

        $next = $allocator->allocate();

        self::assertNotContains($next, $first);
        self::assertGreaterThan(max($first), $next);
    }
}
