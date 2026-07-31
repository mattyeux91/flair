<?php

declare(strict_types=1);

namespace Flair\Kernel\Tests\Core\Messaging;

use Flair\Kernel\Core\Messaging\DomainEvent;
use Flair\Kernel\Core\Messaging\OutQueue;
use PHPUnit\Framework\TestCase;

final class OutQueueTest extends TestCase
{
    public function testAnEmptyQueueDrainsToNothing(): void
    {
        $queue = new OutQueue();

        self::assertSame([], $queue->drain());
    }

    public function testEmitThenDrainReturnsTheEvent(): void
    {
        $queue = new OutQueue();
        $event = new OutQueueTestEvent();

        $queue->emit($event, systemIndex: 0, entityId: 0, seq: 0);

        self::assertSame([$event], $queue->drain());
    }

    public function testDrainingEmptiesTheQueue(): void
    {
        $queue = new OutQueue();
        $queue->emit(new OutQueueTestEvent(), systemIndex: 0, entityId: 0, seq: 0);

        $queue->drain();

        self::assertSame([], $queue->drain());
        self::assertSame(0, $queue->count());
    }

    public function testDrainedEventsAreOrderedByTheFullTupleRegardlessOfInsertionOrder(): void
    {
        $queue = new OutQueue();

        $sys1 = new OutQueueTestEvent();
        $sys0Entity9 = new OutQueueTestEvent();
        $sys0Entity1 = new OutQueueTestEvent();

        // Insertion volontairement desordonnee sur les 3 cles.
        $queue->emit($sys1, systemIndex: 1, entityId: 0, seq: 0);
        $queue->emit($sys0Entity9, systemIndex: 0, entityId: 9, seq: 0);
        $queue->emit($sys0Entity1, systemIndex: 0, entityId: 1, seq: 0);

        self::assertSame([$sys0Entity1, $sys0Entity9, $sys1], $queue->drain());
    }

    public function testTiesOnSystemIndexAndEntityAreBrokenBySeq(): void
    {
        $queue = new OutQueue();

        $seq2 = new OutQueueTestEvent();
        $seq0 = new OutQueueTestEvent();
        $seq1 = new OutQueueTestEvent();

        $queue->emit($seq2, systemIndex: 0, entityId: 0, seq: 2);
        $queue->emit($seq0, systemIndex: 0, entityId: 0, seq: 0);
        $queue->emit($seq1, systemIndex: 0, entityId: 0, seq: 1);

        self::assertSame([$seq0, $seq1, $seq2], $queue->drain());
    }

    public function testCountReflectsPendingEntriesAndResetsAfterDrain(): void
    {
        $queue = new OutQueue();

        self::assertSame(0, $queue->count());

        $queue->emit(new OutQueueTestEvent(), systemIndex: 0, entityId: 0, seq: 0);
        $queue->emit(new OutQueueTestEvent(), systemIndex: 0, entityId: 0, seq: 1);

        self::assertSame(2, $queue->count());

        $queue->drain();

        self::assertSame(0, $queue->count());
    }
}

final class OutQueueTestEvent implements DomainEvent
{
}
