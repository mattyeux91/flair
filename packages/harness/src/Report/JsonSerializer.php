<?php

declare(strict_types=1);

namespace Flair\Harness\Report;

use Flair\Harness\Metrics\AggregateResult;

/**
 * Rendu JSON d'un AggregateResult, consomme par public/app.js. Structure
 * volontairement plate (memes cles que AggregateResult) - aucune logique,
 * juste une conversion en tableau serialisable.
 */
final class JsonSerializer
{
    /**
     * @return array{
     *     curves: array<string, array<int, array{mean: float, p10: float, p50: float, p90: float, count: int}>>,
     *     deltaCurves: array<string, array<int, array{meanDelta: float, count: int}>>,
     *     chainedCurves: array<string, array<int, float>>,
     *     retirementAgeHistogram: array<int, int>,
     * }
     */
    public static function toArray(AggregateResult $result): array
    {
        return [
            'curves' => $result->curves,
            'deltaCurves' => $result->deltaCurves,
            'chainedCurves' => $result->chainedCurves,
            'retirementAgeHistogram' => $result->retirementAgeHistogram,
        ];
    }
}
