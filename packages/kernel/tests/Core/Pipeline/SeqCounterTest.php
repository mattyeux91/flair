<?php

declare(strict_types=1);

namespace Flair\Kernel\Tests\Core\Pipeline;

use Flair\Kernel\Core\Pipeline\SeqCounter;
use PHPUnit\Framework\TestCase;

final class SeqCounterTest extends TestCase
{
    public function testProducesAStrictlyIncreasingSequenceStartingAtZero(): void
    {
        $counter = new SeqCounter();

        $values = [];
        for ($i = 0; $i < 1000; $i++) {
            $values[] = $counter->next();
        }

        self::assertSame(range(0, 999), $values);
    }
}
