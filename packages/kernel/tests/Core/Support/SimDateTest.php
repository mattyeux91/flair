<?php

declare(strict_types=1);

namespace Flair\Kernel\Tests\Core\Support;

use Flair\Kernel\Core\Support\SimDate;
use PHPUnit\Framework\TestCase;

final class SimDateTest extends TestCase
{
    public function testYearsSinceOneFullYearAgo(): void
    {
        $earlier = new SimDate(0);
        $now = new SimDate(365);

        self::assertSame(1.0, $now->yearsSince($earlier));
    }

    public function testYearsSinceIsZeroForTheSameDate(): void
    {
        $date = new SimDate(100);

        self::assertSame(0.0, $date->yearsSince($date));
    }

    public function testYearsSinceIsNegativeWhenTheReferenceIsLater(): void
    {
        $earlier = new SimDate(0);
        $later = new SimDate(365);

        self::assertSame(-1.0, $earlier->yearsSince($later));
    }

    public function testYearsSinceHandlesFractionalYears(): void
    {
        $earlier = new SimDate(0);
        $now = new SimDate(730);

        self::assertEqualsWithDelta(2.0, $now->yearsSince($earlier), 0.001);
    }
}
