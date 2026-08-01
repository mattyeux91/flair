<?php

declare(strict_types=1);

namespace Flair\Kernel\Tests\Core\Ruleset;

use Flair\Kernel\Core\Ruleset\AgingBalance;
use PHPUnit\Framework\TestCase;

final class AgingBalanceTest extends TestCase
{
    public function testDefaultsMatchTheFirstJetDocumentedInAgingSystem(): void
    {
        $aging = new AgingBalance();

        self::assertSame(29.0, $aging->retirementEligibleAge);
        self::assertSame(0.15, $aging->retirementAgeWeight);
        self::assertSame(0.15, $aging->retirementFragilityWeight);
        self::assertSame(23.0, $aging->growthPrimeAgeThreshold);
        self::assertSame(0.3, $aging->growthPlateauFactor);
        self::assertSame(0.1, $aging->declineRatePerYear);
        self::assertSame(2.0, $aging->fragilityDeclineMultiplier);
    }

    public function testFieldsRoundTripWhenGivenExplicitly(): void
    {
        $aging = new AgingBalance(retirementEligibleAge: 30.0, retirementFragilityWeight: 0.5);

        self::assertSame(30.0, $aging->retirementEligibleAge);
        self::assertSame(0.5, $aging->retirementFragilityWeight);
    }
}
