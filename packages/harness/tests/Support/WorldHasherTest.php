<?php

declare(strict_types=1);

namespace Flair\Harness\Tests\Support;

use Flair\Harness\Population\PopulationFactory;
use Flair\Harness\Population\PopulationSpec;
use Flair\Harness\Support\WorldHasher;
use Flair\Harness\Tests\Metrics\Fixtures\FakeEventA;
use Flair\Harness\Tests\Metrics\Fixtures\FakeEventB;
use Flair\Kernel\Core\Ecs\WorldState;
use PHPUnit\Framework\TestCase;

final class WorldHasherTest extends TestCase
{
    public function testHashWorldIsStableForTheSameState(): void
    {
        $spec = new PopulationSpec(playerCount: 20, years: 1, seed: 42, clubCount: 4);

        $worldA = new WorldState();
        (new PopulationFactory())->populate($worldA, $spec);

        $worldB = new WorldState();
        (new PopulationFactory())->populate($worldB, $spec);

        self::assertSame(WorldHasher::hashWorld($worldA), WorldHasher::hashWorld($worldB));
    }

    public function testHashWorldDiffersWhenPopulationDiffers(): void
    {
        $worldA = new WorldState();
        (new PopulationFactory())->populate($worldA, new PopulationSpec(playerCount: 20, years: 1, seed: 42, clubCount: 4));

        $worldB = new WorldState();
        (new PopulationFactory())->populate($worldB, new PopulationSpec(playerCount: 20, years: 1, seed: 7, clubCount: 4));

        self::assertNotSame(WorldHasher::hashWorld($worldA), WorldHasher::hashWorld($worldB));
    }

    public function testHashWorldOfEmptyWorldIsStable(): void
    {
        self::assertSame(WorldHasher::hashWorld(new WorldState()), WorldHasher::hashWorld(new WorldState()));
    }

    public function testHashEventSequenceIsStableForTheSameSequence(): void
    {
        $events = [new FakeEventA(), new FakeEventB()];

        self::assertSame(WorldHasher::hashEventSequence($events), WorldHasher::hashEventSequence($events));
    }

    public function testHashEventSequenceDiffersWhenOrderDiffers(): void
    {
        $a = WorldHasher::hashEventSequence([new FakeEventA(), new FakeEventB()]);
        $b = WorldHasher::hashEventSequence([new FakeEventB(), new FakeEventA()]);

        self::assertNotSame($a, $b);
    }

    public function testHashEventSequenceOfEmptyListIsStable(): void
    {
        self::assertSame(WorldHasher::hashEventSequence([]), WorldHasher::hashEventSequence([]));
    }
}
