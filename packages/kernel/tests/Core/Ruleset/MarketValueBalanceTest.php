<?php

declare(strict_types=1);

namespace Flair\Kernel\Tests\Core\Ruleset;

use Flair\Kernel\Core\Ruleset\MarketValueBalance;
use PHPUnit\Framework\TestCase;

final class MarketValueBalanceTest extends TestCase
{
    public function testDefaultsAreStable(): void
    {
        $balance = new MarketValueBalance();

        self::assertSame(5_000_000, $balance->baseValueCents);
        self::assertSame(50, $balance->referenceQuality);
        self::assertSame(0.1, $balance->valueMultiplierMin);
        self::assertSame(5.0, $balance->valueMultiplierMax);
        self::assertSame(6.0, $balance->youthWindowYears);
        self::assertSame(1.5, $balance->youthPremiumCeiling);
        self::assertSame(0.15, $balance->agingDeclinePerYear);
        self::assertSame(0.1, $balance->agingFloorMultiplier);
        self::assertSame(0.4, $balance->modifierMin);
        self::assertSame(2.5, $balance->modifierMax);
        self::assertSame(1.5, $balance->contractFullValueYears);
        self::assertSame(0.05, $balance->contractFloorMultiplier);
    }

    public function testFieldsRoundTripWhenGivenExplicitly(): void
    {
        $balance = new MarketValueBalance(baseValueCents: 1, referenceQuality: 2, contractFloorMultiplier: 0.9);

        self::assertSame(1, $balance->baseValueCents);
        self::assertSame(2, $balance->referenceQuality);
        self::assertSame(0.9, $balance->contractFloorMultiplier);
    }
}
