<?php

declare(strict_types=1);

namespace Flair\Harness\Metrics;

/**
 * Fonctions statistiques pures sur des listes de nombres - aucune dependance
 * au noyau, testables sans WorldState/Pipeline.
 */
final class Stats
{
    /** @param list<float> $values */
    public static function mean(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }

        return array_sum($values) / \count($values);
    }

    /** @param list<float> $values */
    public static function stddev(array $values): float
    {
        $count = \count($values);
        if ($count < 2) {
            return 0.0;
        }

        $mean = self::mean($values);
        $squaredDeviations = array_map(static fn (float $value): float => ($value - $mean) ** 2, $values);

        return sqrt(array_sum($squaredDeviations) / $count);
    }

    /**
     * Percentile par interpolation lineaire entre les deux rangs encadrants
     * (methode "linear interpolation of the empirical CDF").
     *
     * @param list<float> $values
     * @param float $percentile 0-100
     */
    public static function percentile(array $values, float $percentile): float
    {
        if ($values === []) {
            return 0.0;
        }

        $sorted = $values;
        sort($sorted);

        $rank = ($percentile / 100.0) * (\count($sorted) - 1);
        $lowerIndex = (int) floor($rank);
        $upperIndex = (int) ceil($rank);

        if ($lowerIndex === $upperIndex) {
            return $sorted[$lowerIndex];
        }

        $fraction = $rank - $lowerIndex;

        return $sorted[$lowerIndex] + ($sorted[$upperIndex] - $sorted[$lowerIndex]) * $fraction;
    }

    /**
     * @param list<int> $values
     * @return array<int, int> debut de bucket -> effectif, trie par bucket croissant
     */
    public static function histogram(array $values, int $bucketWidth): array
    {
        $histogram = [];
        foreach ($values as $value) {
            $bucket = intdiv($value, $bucketWidth) * $bucketWidth;
            $histogram[$bucket] = ($histogram[$bucket] ?? 0) + 1;
        }

        ksort($histogram);

        return $histogram;
    }
}
