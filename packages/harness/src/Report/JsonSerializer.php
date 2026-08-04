<?php

declare(strict_types=1);

namespace Flair\Harness\Report;

use Flair\Harness\Metrics\AggregateResult;
use Flair\Harness\Metrics\CompetitiveBalance;

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
     *     retirementAgeHistogram: array<int, int>,
     *     populationByYear: array<int, int>,
     *     finalAgeHistogram: array<int, int>,
     *     goalsPerMatchHistogram: array<int, int>,
     *     matchResultDistribution: array{homeWin: int, draw: int, awayWin: int},
     *     scorelineFrequency: array<string, int>,
     *     seasonHistory: list<array{season: int, standings: list<array{clubId: int, clubName: string, played: int, won: int, drawn: int, lost: int, goalsFor: int, goalsAgainst: int, points: int}>, matches: list<array{matchday: int, homeClub: string, awayClub: string, homeGoals: int, awayGoals: int}>}>,
     *     cumulativeIncomeByClub: array<string, int>,
     *     finalFacilitiesByClub: array<string, float>,
     *     competitiveBalance: array{titlesByClub: array<string, int>, giniOfTitles: float, giniOfRevenues: float, topFiveTurnoverRate: float|null, distinctChampions: int, seasonsMeasured: int},
     * }
     */
    public static function toArray(AggregateResult $result): array
    {
        return [
            'curves' => $result->curves,
            'retirementAgeHistogram' => $result->retirementAgeHistogram,
            'populationByYear' => $result->populationByYear,
            'finalAgeHistogram' => $result->finalAgeHistogram,
            'goalsPerMatchHistogram' => $result->goalsPerMatchHistogram,
            'matchResultDistribution' => $result->matchResultDistribution,
            'scorelineFrequency' => $result->scorelineFrequency,
            'seasonHistory' => $result->seasonHistory,
            'cumulativeIncomeByClub' => $result->cumulativeIncomeByClub,
            'finalFacilitiesByClub' => $result->finalFacilitiesByClub,
            'competitiveBalance' => CompetitiveBalance::toArray(CompetitiveBalance::analyze($result->seasonHistory, $result->cumulativeIncomeByClub)),
        ];
    }
}
