<?php

declare(strict_types=1);

namespace Flair\Kernel\Tests\Core\Messaging;

use Flair\Kernel\Core\Messaging\DomainEvent;
use Flair\Kernel\Core\Messaging\Scheduler;
use PHPUnit\Framework\TestCase;

final class SchedulerTest extends TestCase
{
    public function testNothingIsDueBeforeItsScheduledTick(): void
    {
        $scheduler = new Scheduler();
        $scheduler->schedule(new SchedulerTestEvent(), atTick: 10, systemIndex: 0, entityId: 0, seq: 0);

        self::assertSame([], $scheduler->drainDueBy(9));
    }

    public function testAnEventAtOrBeforeTheRequestedTickIsDue(): void
    {
        $scheduler = new Scheduler();
        $event = new SchedulerTestEvent();
        $scheduler->schedule($event, atTick: 5, systemIndex: 0, entityId: 0, seq: 0);

        self::assertSame([$event], $scheduler->drainDueBy(5));
    }

    public function testDrainingRemovesTheEntryPermanently(): void
    {
        $scheduler = new Scheduler();
        $scheduler->schedule(new SchedulerTestEvent(), atTick: 5, systemIndex: 0, entityId: 0, seq: 0);

        $scheduler->drainDueBy(5);

        self::assertSame([], $scheduler->drainDueBy(5));
    }

    public function testAnEntryNotYetDueStaysQueuedUntilItsTickArrives(): void
    {
        $scheduler = new Scheduler();
        $event = new SchedulerTestEvent();
        $scheduler->schedule($event, atTick: 20, systemIndex: 0, entityId: 0, seq: 0);

        self::assertSame([], $scheduler->drainDueBy(5));
        self::assertSame(1, $scheduler->count());

        self::assertSame([$event], $scheduler->drainDueBy(20));
        self::assertSame(0, $scheduler->count());
    }

    public function testDrainedEventsAreOrderedByTheFullTupleRegardlessOfInsertionOrder(): void
    {
        $scheduler = new Scheduler();

        $tick2Sys1 = new SchedulerTestEvent();
        $tick1Sys5 = new SchedulerTestEvent();
        $tick1Sys0Entity9 = new SchedulerTestEvent();
        $tick1Sys0Entity1 = new SchedulerTestEvent();

        // Insertion volontairement desordonnee sur les 4 cles.
        $scheduler->schedule($tick2Sys1, atTick: 2, systemIndex: 1, entityId: 0, seq: 0);
        $scheduler->schedule($tick1Sys5, atTick: 1, systemIndex: 5, entityId: 0, seq: 0);
        $scheduler->schedule($tick1Sys0Entity9, atTick: 1, systemIndex: 0, entityId: 9, seq: 0);
        $scheduler->schedule($tick1Sys0Entity1, atTick: 1, systemIndex: 0, entityId: 1, seq: 0);

        self::assertSame(
            [$tick1Sys0Entity1, $tick1Sys0Entity9, $tick1Sys5, $tick2Sys1],
            $scheduler->drainDueBy(2),
        );
    }

    public function testTiesOnTickSystemIndexAndEntityAreBrokenBySeq(): void
    {
        $scheduler = new Scheduler();

        $seq2 = new SchedulerTestEvent();
        $seq0 = new SchedulerTestEvent();
        $seq1 = new SchedulerTestEvent();

        $scheduler->schedule($seq2, atTick: 1, systemIndex: 0, entityId: 0, seq: 2);
        $scheduler->schedule($seq0, atTick: 1, systemIndex: 0, entityId: 0, seq: 0);
        $scheduler->schedule($seq1, atTick: 1, systemIndex: 0, entityId: 0, seq: 1);

        self::assertSame([$seq0, $seq1, $seq2], $scheduler->drainDueBy(1));
    }
}

final class SchedulerTestEvent implements DomainEvent
{
}
