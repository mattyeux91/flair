<?php

declare(strict_types=1);

namespace Flair\Kernel\Tests\Core\Ruleset;

use Flair\Kernel\Core\Ruleset\TransferBalance;
use PHPUnit\Framework\TestCase;

final class TransferBalanceTest extends TestCase
{
    public function testDefaultsAreStable(): void
    {
        $balance = new TransferBalance();

        self::assertSame(200, $balance->negotiationOpeningDayOfYear);
        self::assertSame(6, $balance->maxRounds);
        self::assertSame(0.75, $balance->openingOfferShare);
        self::assertSame(1.15, $balance->buyerFlexMargin);
        self::assertSame(0.5, $balance->sellerConcessionShare);
        self::assertSame(0.5, $balance->buyerConcessionShare);
        self::assertSame(0.05, $balance->breakBaseProbability);
        self::assertSame(0.05, $balance->breakRoundGrowth);
        self::assertSame(0.3, $balance->breakGapWeight);
        self::assertSame(0.3, $balance->financialDistressWeight);
        self::assertSame(5_000_000, $balance->financialDistressScaleCents);
        self::assertSame(0.05, $balance->squadDepthDiscountPerSurplusPlayer);
        self::assertSame(0.6, $balance->squadDepthDiscountFloor);
        self::assertSame(0.5, $balance->positionScarcityMin);
        self::assertSame(2.0, $balance->positionScarcityMax);
        self::assertSame(0.5, $balance->buyerWealthMin);
        self::assertSame(2.0, $balance->buyerWealthMax);
    }

    public function testFieldsRoundTripWhenGivenExplicitly(): void
    {
        $balance = new TransferBalance(maxRounds: 3, openingOfferShare: 0.5);

        self::assertSame(3, $balance->maxRounds);
        self::assertSame(0.5, $balance->openingOfferShare);
    }
}
