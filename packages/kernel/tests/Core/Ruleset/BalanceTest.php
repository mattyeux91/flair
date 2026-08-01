<?php

declare(strict_types=1);

namespace Flair\Kernel\Tests\Core\Ruleset;

use Flair\Kernel\Core\Ruleset\Balance;
use PHPUnit\Framework\TestCase;

final class BalanceTest extends TestCase
{
    public function testDevelopmentRateDefaultsToOne(): void
    {
        $balance = new Balance();

        self::assertSame(1.0, $balance->developmentRate);
    }

    public function testDevelopmentRateRoundTripsWhenGivenExplicitly(): void
    {
        $balance = new Balance(developmentRate: 1.5);

        self::assertSame(1.5, $balance->developmentRate);
    }
}
