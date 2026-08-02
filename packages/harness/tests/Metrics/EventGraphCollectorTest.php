<?php

declare(strict_types=1);

namespace Flair\Harness\Tests\Metrics;

use Flair\Harness\Metrics\EventGraphCollector;
use Flair\Harness\Tests\Metrics\Fixtures\FakeEventA;
use Flair\Harness\Tests\Metrics\Fixtures\FakeEventB;
use Flair\Kernel\Core\Ecs\WorldState;
use PHPUnit\Framework\TestCase;

final class EventGraphCollectorTest extends TestCase
{
    public function testTallyCountsOccurrencesByType(): void
    {
        $collector = new EventGraphCollector();

        $collector->tally([new FakeEventA(), new FakeEventA(), new FakeEventB()]);
        $collector->tally([new FakeEventA()]);

        $snapshot = $collector->snapshot();

        self::assertSame(4, $snapshot->totalEvents);
        self::assertSame([
            FakeEventA::class => 3,
            FakeEventB::class => 1,
        ], $snapshot->volumeByType);
    }

    public function testTallyOfEmptyListChangesNothing(): void
    {
        $collector = new EventGraphCollector();

        $collector->tally([]);

        $snapshot = $collector->snapshot();

        self::assertSame(0, $snapshot->totalEvents);
        self::assertSame([], $snapshot->volumeByType);
    }

    public function testRecordQueueDepthReadsSchedulerCountForEachYear(): void
    {
        $collector = new EventGraphCollector();
        $world = new WorldState();

        $collector->recordQueueDepth(1, $world);
        $collector->recordQueueDepth(2, $world);

        self::assertSame([
            ['year' => 1, 'schedulerBacklog' => 0],
            ['year' => 2, 'schedulerBacklog' => 0],
        ], $collector->snapshot()->schedulerBacklogByYear);
    }

    public function testSnapshotOfUnusedCollectorIsAllZero(): void
    {
        $snapshot = (new EventGraphCollector())->snapshot();

        self::assertSame(0, $snapshot->totalEvents);
        self::assertSame([], $snapshot->volumeByType);
        self::assertSame([], $snapshot->schedulerBacklogByYear);
    }
}
