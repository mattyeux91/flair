<?php

declare(strict_types=1);

namespace Flair\Harness\Metrics;

/**
 * Post-traitement pur sur AggregateResult::$seasonHistory (deja peuple par
 * Sampler, standings deja tries par WorldInspector::standingsSnapshot) -
 * aucune modification de Sampler necessaire, le champion d'une saison est
 * simplement standings[0].
 *
 * Repond au "test qui compte" de docs/14-algorithmes.md §7 : Gini des titres
 * (0 = egalite parfaite entre tous les clubs, 1 = un seul club rafle tout),
 * taux de rotation du top 5 (0 = memes 5 clubs chaque saison, 1 = renouvellement
 * total d'une saison a l'autre) et Gini des revenus.
 *
 * Le Gini des revenus ne se derive pas de `seasonHistory` : il se calcule sur
 * les revenus cumules que Sampler releve dans
 * `Football\Components\SeasonIncome`, passes en second argument. Il vaut donc
 * 0.0 quand l'appelant ne les fournit pas - une repartition parfaitement
 * egale, ce qui est exactement le monde par defaut
 * (`FinanceBalance::$meritShare = 0.0`).
 *
 * L'inflation, quatrieme metrique du meme test, n'est **pas** mesuree ici, et
 * la raison a change depuis le lot du marche : ce n'est plus qu'aucun prix
 * n'existe (il en existe depuis docs/17- point 1), c'est que l'inflation de ce
 * monde est une **decision** et non une observation - l'indice avance de
 * `marketInflationTarget` par construction (docs/17- point 5). Elle est donc
 * lisible directement dans `Football\Singletons\MarketInflation`, et ce qui
 * merite d'etre teste est ailleurs : `Harness\Tests\Regression\InflationRegressionTest`.
 */
final class CompetitiveBalance
{
    /**
     * @param list<array{season: int, standings: list<array{clubId: int, clubName: string, played: int, won: int, drawn: int, lost: int, goalsFor: int, goalsAgainst: int, points: int}>, matches: list<array{matchday: int, homeClub: string, awayClub: string, homeGoals: int, awayGoals: int}>}> $seasonHistory
     * @param array<string, int> $cumulativeIncomeByClub nom de club -> revenus de saison cumules sur le run (AggregateResult::$cumulativeIncomeByClub)
     */
    public static function analyze(array $seasonHistory, array $cumulativeIncomeByClub = []): CompetitiveBalanceResult
    {
        /** @var array<string, true> $universe */
        $universe = [];
        foreach ($seasonHistory as $season) {
            foreach ($season['standings'] as $row) {
                $universe[$row['clubName']] = true;
            }
        }

        /** @var array<string, int> $titlesByClub */
        $titlesByClub = array_fill_keys(array_keys($universe), 0);
        foreach ($seasonHistory as $season) {
            $champion = $season['standings'][0]['clubName'] ?? null;
            if ($champion !== null) {
                $titlesByClub[$champion]++;
            }
        }
        ksort($titlesByClub);

        $distinctChampions = \count(array_filter($titlesByClub, static fn (int $titles): bool => $titles > 0));

        return new CompetitiveBalanceResult(
            titlesByClub: $titlesByClub,
            giniOfTitles: self::gini(array_values($titlesByClub)),
            topFiveTurnoverRate: self::topFiveTurnoverRate($seasonHistory),
            distinctChampions: $distinctChampions,
            seasonsMeasured: \count($seasonHistory),
            giniOfRevenues: self::gini(array_values($cumulativeIncomeByClub)),
        );
    }

    /**
     * @return array{titlesByClub: array<string, int>, giniOfTitles: float, giniOfRevenues: float, topFiveTurnoverRate: float|null, distinctChampions: int, seasonsMeasured: int}
     */
    public static function toArray(CompetitiveBalanceResult $result): array
    {
        return [
            'titlesByClub' => $result->titlesByClub,
            'giniOfTitles' => $result->giniOfTitles,
            'giniOfRevenues' => $result->giniOfRevenues,
            'topFiveTurnoverRate' => $result->topFiveTurnoverRate,
            'distinctChampions' => $result->distinctChampions,
            'seasonsMeasured' => $result->seasonsMeasured,
        ];
    }

    /**
     * Coefficient de Gini standard : (Sum_i Sum_j |xi - xj|) / (2 n Sum xi).
     * 0.0 si la somme des valeurs est nulle (aucun titre distribue - le cas
     * ne devrait pas se produire des qu'au moins une saison est mesuree, mais
     * une liste vide ne doit pas produire une division par zero).
     *
     * @param list<int> $values
     */
    public static function gini(array $values): float
    {
        $n = \count($values);
        $total = array_sum($values);
        if ($n === 0 || $total <= 0) {
            return 0.0;
        }

        $sumAbsoluteDifferences = 0;
        foreach ($values as $vi) {
            foreach ($values as $vj) {
                $sumAbsoluteDifferences += abs($vi - $vj);
            }
        }

        return $sumAbsoluteDifferences / (2 * $n * $total);
    }

    /**
     * Moyenne, saison a saison, de la proportion du top 5 qui change par
     * rapport a la saison precedente. `null` si moins de deux saisons sont
     * mesurables (rotation non definie sur un point unique).
     *
     * @param list<array{season: int, standings: list<array{clubId: int, clubName: string, played: int, won: int, drawn: int, lost: int, goalsFor: int, goalsAgainst: int, points: int}>, matches: list<array{matchday: int, homeClub: string, awayClub: string, homeGoals: int, awayGoals: int}>}> $seasonHistory
     */
    private static function topFiveTurnoverRate(array $seasonHistory): ?float
    {
        if (\count($seasonHistory) < 2) {
            return null;
        }

        /** @var list<float> $rates */
        $rates = [];
        /** @var list<string>|null $previousTopFive */
        $previousTopFive = null;

        foreach ($seasonHistory as $season) {
            $topFive = array_slice(
                array_map(static fn (array $row): string => $row['clubName'], $season['standings']),
                0,
                5,
            );

            if ($previousTopFive !== null) {
                $denominator = min(5, \count($topFive));
                $newcomers = array_diff($topFive, $previousTopFive);
                $rates[] = $denominator > 0 ? \count($newcomers) / $denominator : 0.0;
            }

            $previousTopFive = $topFive;
        }

        return $rates === [] ? null : array_sum($rates) / \count($rates);
    }
}
