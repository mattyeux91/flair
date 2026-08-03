<?php

declare(strict_types=1);

namespace Flair\Harness\Tests\Metrics;

use Flair\Harness\Metrics\Stats;
use PHPUnit\Framework\TestCase;

final class StatsTest extends TestCase
{
    public function testMeanOfKnownValues(): void
    {
        self::assertSame(20.0, Stats::mean([10.0, 20.0, 30.0]));
    }

    public function testMeanOfEmptyListIsZero(): void
    {
        self::assertSame(0.0, Stats::mean([]));
    }

    public function testStddevOfKnownValues(): void
    {
        // 2,4,4,4,5,5,7,9 -> ecart-type population = 2.0 (exemple classique)
        self::assertEqualsWithDelta(2.0, Stats::stddev([2.0, 4.0, 4.0, 4.0, 5.0, 5.0, 7.0, 9.0]), 0.0001);
    }

    public function testStddevOfSingleValueIsZero(): void
    {
        self::assertSame(0.0, Stats::stddev([42.0]));
    }

    public function testPercentileFiftyOnOddCountReturnsMedian(): void
    {
        self::assertSame(30.0, Stats::percentile([10.0, 20.0, 30.0, 40.0, 50.0], 50.0));
    }

    public function testPercentileZeroAndHundredReturnBounds(): void
    {
        $values = [10.0, 20.0, 30.0, 40.0, 50.0];

        self::assertSame(10.0, Stats::percentile($values, 0.0));
        self::assertSame(50.0, Stats::percentile($values, 100.0));
    }

    public function testPercentileInterpolatesBetweenRanks(): void
    {
        // 4 valeurs, rangs 0..3 ; p90 -> rang 2.7 -> interpolation entre index 2 et 3
        self::assertEqualsWithDelta(37.0, Stats::percentile([10.0, 20.0, 30.0, 40.0], 90.0), 0.0001);
    }

    public function testPercentileOfEmptyListIsZero(): void
    {
        self::assertSame(0.0, Stats::percentile([], 50.0));
    }

    /**
     * Le domaine de `percentile()` est numerique, pas seulement flottant :
     * `Report\TextReport` lui passe des masses salariales en **centimes**,
     * donc des entiers. `assertSame` contre un `float` verifie les deux
     * moities du contrat - la valeur, et le fait que le type de retour reste
     * `float` meme quand le rang tombe pile sur un element entier.
     */
    public function testPercentileAcceptsIntegersAndStillReturnsAFloat(): void
    {
        self::assertSame(30.0, Stats::percentile([10, 20, 30, 40, 50], 50.0));
        self::assertEqualsWithDelta(37.0, Stats::percentile([10, 20, 30, 40], 90.0), 0.0001);
    }

    public function testHistogramBucketsAndSortsByBucketStart(): void
    {
        $histogram = Stats::histogram([31, 29, 30, 39, 40, 22], bucketWidth: 10);

        self::assertSame([20 => 2, 30 => 3, 40 => 1], $histogram);
    }
}
