<?php

declare(strict_types=1);

namespace Flair\Kernel\Tests\Football\Support;

use Flair\Kernel\Core\Ruleset\MarketValueBalance;
use Flair\Kernel\Core\Ruleset\PositionBalance;
use Flair\Kernel\Core\Support\SimDate;
use Flair\Kernel\Football\Components\PlayerPotentials;
use Flair\Kernel\Football\Components\Position;
use Flair\Kernel\Football\Support\MarketValueModel;
use Flair\Kernel\Football\Support\PositionModel;
use PHPUnit\Framework\TestCase;

final class MarketValueModelTest extends TestCase
{
    public function testValueAtReferenceQualityPeakAgeAndFullContractEqualsBaseValueCents(): void
    {
        $value = self::value(perceivedQuality: 50, ageYears: 27.0, potentials: self::potentials(27, 27, 27));

        self::assertSame(5_000_000, $value);
    }

    public function testQualityMultiplierIsFlooredBelowMinimum(): void
    {
        $value = self::value(perceivedQuality: 1, ageYears: 27.0, potentials: self::potentials(27, 27, 27));

        self::assertSame(500_000, $value);
    }

    public function testQualityMultiplierIsCeiledAboveMaximum(): void
    {
        $value = self::value(perceivedQuality: 1000, ageYears: 27.0, potentials: self::potentials(27, 27, 27));

        self::assertSame(25_000_000, $value);
    }

    public function testAgeCurveRampsLinearlyTowardYouthPremiumBeforeThePeak(): void
    {
        // 3 ans avant le pic, dans la fenetre de 6 ans : t = 0.5.
        $value = self::value(perceivedQuality: 50, ageYears: 24.0, potentials: self::potentials(27, 27, 27));

        self::assertSame(6_250_000, $value);
    }

    public function testAgeCurvePlateausAtYouthPremiumCeilingBeyondTheWindow(): void
    {
        $value = self::value(perceivedQuality: 50, ageYears: 16.0, potentials: self::potentials(27, 27, 27));

        self::assertSame(7_500_000, $value);
    }

    public function testAgeCurveDeclinesAfterThePeak(): void
    {
        // 2 ans apres le pic : 1.0 - 0.15 x 2 = 0.7.
        $value = self::value(perceivedQuality: 50, ageYears: 29.0, potentials: self::potentials(27, 27, 27));

        self::assertSame(3_500_000, $value);
    }

    public function testAgeCurveFloorsFarPastThePeak(): void
    {
        $value = self::value(perceivedQuality: 50, ageYears: 127.0, potentials: self::potentials(27, 27, 27));

        self::assertSame(500_000, $value);
    }

    public function testModifierIsFlooredWhenScarcityAndWealthAreBothLow(): void
    {
        $value = self::value(
            perceivedQuality: 50,
            ageYears: 27.0,
            potentials: self::potentials(27, 27, 27),
            positionScarcity: 0.1,
            buyerWealthFactor: 0.1,
        );

        self::assertSame(2_000_000, $value);
    }

    public function testModifierIsCeiledWhenScarcityAndWealthAreBothHigh(): void
    {
        $value = self::value(
            perceivedQuality: 50,
            ageYears: 27.0,
            potentials: self::potentials(27, 27, 27),
            positionScarcity: 3.0,
            buyerWealthFactor: 3.0,
        );

        self::assertSame(12_500_000, $value);
    }

    public function testContractFactorDecaysLinearlyTowardExpiry(): void
    {
        // Un an restant sur 1.5 an de pleine valeur : t = 1/1.5.
        $value = self::value(
            perceivedQuality: 50,
            ageYears: 27.0,
            potentials: self::potentials(27, 27, 27),
            contractExpiresOn: new SimDate(365),
        );

        self::assertSame(3_416_667, $value);
    }

    public function testContractFactorFloorsAtExpiry(): void
    {
        $value = self::value(
            perceivedQuality: 50,
            ageYears: 27.0,
            potentials: self::potentials(27, 27, 27),
            contractExpiresOn: new SimDate(0),
        );

        self::assertSame(250_000, $value);
    }

    public function testGlobalInflationIndexScalesTheResultLinearly(): void
    {
        $value = self::value(
            perceivedQuality: 50,
            ageYears: 27.0,
            potentials: self::potentials(27, 27, 27),
            globalInflationIndex: 1.2,
        );

        self::assertSame(6_000_000, $value);
    }

    public function testPeakAgeIsTheRoundedMeanOfTheThreePeaks(): void
    {
        $potentials = self::potentials(physicalPeakAge: 24, technicalPeakAge: 26, mentalPeakAge: 29);

        // 79 / 3 = 26,33... arrondi a 26 : a 26 ans, encore au pic (ageCurve = 1.0).
        self::assertSame(5_000_000, self::value(perceivedQuality: 50, ageYears: 26.0, potentials: $potentials));

        // A 27 ans, un an apres un pic de 26 (pas 27) : 1.0 - 0.15 x 1 = 0.85.
        self::assertSame(4_250_000, self::value(perceivedQuality: 50, ageYears: 27.0, potentials: $potentials));
    }

    private static function value(
        int $perceivedQuality,
        float $ageYears,
        PlayerPotentials $potentials,
        ?SimDate $now = null,
        ?SimDate $contractExpiresOn = null,
        float $positionScarcity = 1.0,
        float $buyerWealthFactor = 1.0,
        float $globalInflationIndex = 1.0,
        ?MarketValueBalance $balance = null,
    ): int {
        return MarketValueModel::value(
            perceivedQuality: $perceivedQuality,
            ageYears: $ageYears,
            potentials: $potentials,
            now: $now ?? new SimDate(0),
            contractExpiresOn: $contractExpiresOn ?? new SimDate(1_000),
            positionScarcity: $positionScarcity,
            buyerWealthFactor: $buyerWealthFactor,
            globalInflationIndex: $globalInflationIndex,
            balance: $balance ?? new MarketValueBalance(),
        );
    }

    private static function potentials(int $physicalPeakAge, int $technicalPeakAge, int $mentalPeakAge): PlayerPotentials
    {
        return new PlayerPotentials(
            ceiling: 50,
            archetype: Position::Midfielder,
            ceilings: PositionModel::ceilings(50, Position::Midfielder, [], new PositionBalance()),
            physicalPeakAge: $physicalPeakAge,
            technicalPeakAge: $technicalPeakAge,
            mentalPeakAge: $mentalPeakAge,
            growthRate: 1.0,
            fragility: 1.0,
        );
    }
}
