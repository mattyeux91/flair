<?php

declare(strict_types=1);

namespace Flair\Kernel\Tests\Core\Ruleset;

use Flair\Kernel\Core\Ruleset\RetirementBalance;
use PHPUnit\Framework\TestCase;

final class RetirementBalanceTest extends TestCase
{
    public function testDefaultsMatchTheFirstJetDocumentedInRetirementSystem(): void
    {
        $retirement = new RetirementBalance();

        self::assertSame(29.0, $retirement->retirementEligibleAge);
        self::assertSame(0.15, $retirement->retirementAgeWeight);
        self::assertSame(0.15, $retirement->retirementFragilityWeight);
    }

    public function testFieldsRoundTripWhenGivenExplicitly(): void
    {
        $retirement = new RetirementBalance(retirementEligibleAge: 30.0, retirementFragilityWeight: 0.5);

        self::assertSame(30.0, $retirement->retirementEligibleAge);
        self::assertSame(0.5, $retirement->retirementFragilityWeight);
    }
}
