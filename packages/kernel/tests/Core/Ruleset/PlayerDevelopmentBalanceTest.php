<?php

declare(strict_types=1);

namespace Flair\Kernel\Tests\Core\Ruleset;

use Flair\Kernel\Core\Ruleset\PlayerDevelopmentBalance;
use PHPUnit\Framework\TestCase;

final class PlayerDevelopmentBalanceTest extends TestCase
{
    public function testDefaultsMatchTheFirstJetDocumentedInPlayerDevelopmentSystem(): void
    {
        $development = new PlayerDevelopmentBalance();

        self::assertSame(23.0, $development->growthPrimeAgeThreshold);
        self::assertSame(0.3, $development->growthPlateauFactor);
        self::assertSame(1.0, $development->declineRatePerYear);
        self::assertSame(2.0, $development->physicalDeclineMultiplier);
        self::assertSame(1.0, $development->technicalDeclineMultiplier);
        self::assertSame(0.5, $development->mentalDeclineMultiplier);
    }

    public function testFieldsRoundTripWhenGivenExplicitly(): void
    {
        $development = new PlayerDevelopmentBalance(growthPrimeAgeThreshold: 21.0, physicalDeclineMultiplier: 3.0);

        self::assertSame(21.0, $development->growthPrimeAgeThreshold);
        self::assertSame(3.0, $development->physicalDeclineMultiplier);
    }
}
