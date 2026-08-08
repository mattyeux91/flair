<?php

declare(strict_types=1);

namespace Flair\Kernel\Tests\Core\Ruleset;

use Flair\Kernel\Core\Ruleset\InflationBalance;
use PHPUnit\Framework\TestCase;

final class InflationBalanceTest extends TestCase
{
    /**
     * Le defaut a zero n'est pas une commodite : il garantit que tout le
     * mecanisme d'inflation est un **no-op strict** tant qu'on ne l'active pas,
     * ce que `Harness\Tests\Regression\InflationRegressionTest` verifie sur 20
     * saisons (docs/17- point 5).
     */
    public function testInflationIsDisabledByDefault(): void
    {
        self::assertSame(0.0, (new InflationBalance())->marketInflationTarget);
    }

    public function testDefaultsAreStable(): void
    {
        $balance = new InflationBalance();

        self::assertSame(0.0, $balance->marketInflationTarget);
        self::assertSame(0.10, $balance->toleranceBand);
    }

    public function testFieldsRoundTripWhenGivenExplicitly(): void
    {
        $balance = new InflationBalance(marketInflationTarget: 0.03, toleranceBand: 0.05);

        self::assertSame(0.03, $balance->marketInflationTarget);
        self::assertSame(0.05, $balance->toleranceBand);
    }
}
