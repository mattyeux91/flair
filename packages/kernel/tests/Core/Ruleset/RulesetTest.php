<?php

declare(strict_types=1);

namespace Flair\Kernel\Tests\Core\Ruleset;

use Flair\Kernel\Core\Ruleset\Balance;
use Flair\Kernel\Core\Ruleset\Ruleset;
use PHPUnit\Framework\TestCase;

final class RulesetTest extends TestCase
{
    public function testVersionIsExposedAsGiven(): void
    {
        $ruleset = new Ruleset('2026.1.0');

        self::assertSame('2026.1.0', $ruleset->version);
    }

    public function testBalanceDefaultsToADefaultBalanceInstance(): void
    {
        $ruleset = new Ruleset('2026.1.0');

        self::assertEquals(new Balance(), $ruleset->balance);
    }

    public function testBalanceRoundTripsWhenGivenExplicitly(): void
    {
        $balance = new Balance(developmentRate: 1.5);
        $ruleset = new Ruleset('2026.1.0', $balance);

        self::assertSame($balance, $ruleset->balance);
    }
}
