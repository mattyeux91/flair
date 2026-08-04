<?php

declare(strict_types=1);

namespace Flair\Harness\Tests\Metrics;

use Flair\Harness\Metrics\CompetitiveBalance;
use PHPUnit\Framework\TestCase;

final class CompetitiveBalanceTest extends TestCase
{
    public function testGiniOfPerfectEqualityIsZero(): void
    {
        self::assertSame(0.0, CompetitiveBalance::gini([3, 3, 3, 3]));
    }

    public function testGiniOfTotalMonopolyApproachesOne(): void
    {
        // 4 clubs, un seul rafle les 10 titres : Gini = (n-1)/n = 0.75
        self::assertEqualsWithDelta(0.75, CompetitiveBalance::gini([10, 0, 0, 0]), 0.0001);
    }

    public function testGiniOfEmptyListIsZero(): void
    {
        self::assertSame(0.0, CompetitiveBalance::gini([]));
    }

    public function testGiniOfAllZeroTitlesIsZero(): void
    {
        self::assertSame(0.0, CompetitiveBalance::gini([0, 0, 0]));
    }

    public function testAnalyzeCountsTitlesIncludingClubsWithZero(): void
    {
        $result = CompetitiveBalance::analyze([
            $this->season(1, ['A', 'B', 'C']),
            $this->season(2, ['A', 'B', 'C']),
            $this->season(3, ['B', 'A', 'C']),
        ]);

        self::assertSame(['A' => 2, 'B' => 1, 'C' => 0], $result->titlesByClub);
        self::assertSame(2, $result->distinctChampions);
        self::assertSame(3, $result->seasonsMeasured);
    }

    public function testTopFiveTurnoverIsZeroWhenSameTopFiveEverySeason(): void
    {
        $result = CompetitiveBalance::analyze([
            $this->season(1, ['A', 'B', 'C', 'D', 'E', 'F']),
            $this->season(2, ['A', 'B', 'C', 'D', 'E', 'F']),
            $this->season(3, ['A', 'B', 'C', 'D', 'E', 'F']),
        ]);

        self::assertSame(0.0, $result->topFiveTurnoverRate);
    }

    public function testTopFiveTurnoverIsFullWhenTopFiveIsFullyRenewed(): void
    {
        $result = CompetitiveBalance::analyze([
            $this->season(1, ['A', 'B', 'C', 'D', 'E', 'K']),
            $this->season(2, ['F', 'G', 'H', 'I', 'J', 'K']),
        ]);

        self::assertSame(1.0, $result->topFiveTurnoverRate);
    }

    public function testTopFiveTurnoverIsNullWithFewerThanTwoSeasons(): void
    {
        $result = CompetitiveBalance::analyze([
            $this->season(1, ['A', 'B', 'C']),
        ]);

        self::assertNull($result->topFiveTurnoverRate);
    }

    public function testAnalyzeOfEmptyHistoryIsAllZeroesAndNullTurnover(): void
    {
        $result = CompetitiveBalance::analyze([]);

        self::assertSame([], $result->titlesByClub);
        self::assertSame(0.0, $result->giniOfTitles);
        self::assertSame(0.0, $result->giniOfRevenues);
        self::assertNull($result->topFiveTurnoverRate);
        self::assertSame(0, $result->distinctChampions);
        self::assertSame(0, $result->seasonsMeasured);
    }

    public function testGiniOfRevenuesIsZeroWhenEveryClubEarnsTheSame(): void
    {
        $result = CompetitiveBalance::analyze(
            [$this->season(1, ['A', 'B', 'C'])],
            ['A' => 70_000_000, 'B' => 70_000_000, 'C' => 70_000_000],
        );

        self::assertSame(0.0, $result->giniOfRevenues);
    }

    public function testGiniOfRevenuesRisesWithTheSpreadBetweenClubs(): void
    {
        $history = [$this->season(1, ['A', 'B', 'C'])];

        $mild = CompetitiveBalance::analyze($history, ['A' => 80, 'B' => 70, 'C' => 60]);
        $steep = CompetitiveBalance::analyze($history, ['A' => 150, 'B' => 45, 'C' => 15]);

        self::assertGreaterThan(0.0, $mild->giniOfRevenues);
        self::assertGreaterThan($mild->giniOfRevenues, $steep->giniOfRevenues);
    }

    /**
     * Le Gini des revenus ne se derive pas de `seasonHistory` : un appelant
     * qui ne fournit pas les revenus cumules doit obtenir 0.0 (repartition
     * parfaitement egale), pas une erreur ni une valeur inventee a partir du
     * classement.
     */
    public function testGiniOfRevenuesDefaultsToZeroWhenIncomesAreNotProvided(): void
    {
        $result = CompetitiveBalance::analyze([$this->season(1, ['A', 'B', 'C'])]);

        self::assertSame(0.0, $result->giniOfRevenues);
    }

    /**
     * @param list<string> $clubNamesInStandingsOrder standings[0] = champion
     * @return array{season: int, standings: list<array{clubId: int, clubName: string, played: int, won: int, drawn: int, lost: int, goalsFor: int, goalsAgainst: int, points: int}>, matches: list<array{matchday: int, homeClub: string, awayClub: string, homeGoals: int, awayGoals: int}>}
     */
    private function season(int $number, array $clubNamesInStandingsOrder): array
    {
        $standings = [];
        foreach ($clubNamesInStandingsOrder as $index => $clubName) {
            $points = \count($clubNamesInStandingsOrder) - $index;
            $standings[] = [
                'clubId' => $index + 1,
                'clubName' => $clubName,
                'played' => 10,
                'won' => 0,
                'drawn' => 0,
                'lost' => 0,
                'goalsFor' => 0,
                'goalsAgainst' => 0,
                'points' => $points,
            ];
        }

        return ['season' => $number, 'standings' => $standings, 'matches' => []];
    }
}
