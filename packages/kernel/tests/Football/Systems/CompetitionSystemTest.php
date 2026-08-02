<?php

declare(strict_types=1);

namespace Flair\Kernel\Tests\Football\Systems;

use Flair\Kernel\Core\Ecs\WorldState;
use Flair\Kernel\Core\Pipeline\Pipeline;
use Flair\Kernel\Core\Ruleset\Balance;
use Flair\Kernel\Core\Ruleset\CompetitionBalance;
use Flair\Kernel\Core\Ruleset\Ruleset;
use Flair\Kernel\Football\Components\MatchResult;
use Flair\Kernel\Football\Components\Standings;
use Flair\Kernel\Football\Events\FixtureKickoff;
use Flair\Kernel\Football\Events\SeasonConcluded;
use Flair\Kernel\Football\Events\SeasonEnded;
use Flair\Kernel\Football\Events\SeasonStarted;
use Flair\Kernel\Football\Systems\CompetitionSystem;
use PHPUnit\Framework\TestCase;

final class CompetitionSystemTest extends TestCase
{
    private const WORLD_SEED = 20260802;
    private const COMPETITION_ID = 1;
    private const HOME_CLUB_ID = 10;
    private const AWAY_CLUB_ID = 20;

    public function testAHomeWinCreditsThreePointsToTheWinnerAndZeroToTheLoser(): void
    {
        $world = new WorldState();
        $this->scheduleKickoffWithResult($world, homeGoals: 2, awayGoals: 0, atTick: 5);

        $this->runTick($world, tick: 5, balance: new CompetitionBalance());

        $standings = $world->components(Standings::class)->get(self::COMPETITION_ID);
        self::assertNotNull($standings);

        $home = $standings->entries[self::HOME_CLUB_ID];
        $away = $standings->entries[self::AWAY_CLUB_ID];

        self::assertSame(3, $home->points);
        self::assertSame(1, $home->won);
        self::assertSame(0, $home->drawn);
        self::assertSame(0, $home->lost);
        self::assertSame(2, $home->goalsFor);
        self::assertSame(0, $home->goalsAgainst);

        self::assertSame(0, $away->points);
        self::assertSame(0, $away->won);
        self::assertSame(0, $away->drawn);
        self::assertSame(1, $away->lost);
        self::assertSame(0, $away->goalsFor);
        self::assertSame(2, $away->goalsAgainst);
    }

    public function testADrawCreditsOnePointToEachClub(): void
    {
        $world = new WorldState();
        $this->scheduleKickoffWithResult($world, homeGoals: 1, awayGoals: 1, atTick: 5);

        $this->runTick($world, tick: 5, balance: new CompetitionBalance());

        $standings = $world->components(Standings::class)->get(self::COMPETITION_ID);
        self::assertNotNull($standings);

        self::assertSame(1, $standings->entries[self::HOME_CLUB_ID]->points);
        self::assertSame(1, $standings->entries[self::AWAY_CLUB_ID]->points);
        self::assertSame(1, $standings->entries[self::HOME_CLUB_ID]->drawn);
        self::assertSame(1, $standings->entries[self::AWAY_CLUB_ID]->drawn);
    }

    public function testResultsAccumulateAcrossMatches(): void
    {
        $world = new WorldState();
        $this->scheduleKickoffWithResult($world, homeGoals: 1, awayGoals: 0, atTick: 5);
        $this->runTick($world, tick: 5, balance: new CompetitionBalance());

        $this->scheduleKickoffWithResult($world, homeGoals: 0, awayGoals: 3, atTick: 12);
        $this->runTick($world, tick: 12, balance: new CompetitionBalance());

        $standings = $world->components(Standings::class)->get(self::COMPETITION_ID);
        self::assertNotNull($standings);

        $home = $standings->entries[self::HOME_CLUB_ID];
        self::assertSame(2, $home->played);
        self::assertSame(1, $home->goalsFor);
        self::assertSame(3, $home->goalsAgainst);
        self::assertSame(3, $home->points);
    }

    public function testSeasonStartedResetsTheStandings(): void
    {
        $world = new WorldState();
        $this->scheduleKickoffWithResult($world, homeGoals: 2, awayGoals: 0, atTick: 5);
        $this->runTick($world, tick: 5, balance: new CompetitionBalance());

        self::assertNotNull($world->components(Standings::class)->get(self::COMPETITION_ID));

        $world->scheduler()->schedule(
            new SeasonStarted(self::COMPETITION_ID),
            atTick: 10,
            systemIndex: 0,
            entityId: self::COMPETITION_ID,
            seq: 0,
        );
        $this->runTick($world, tick: 10, balance: new CompetitionBalance());

        $standings = $world->components(Standings::class)->get(self::COMPETITION_ID);
        self::assertNotNull($standings);
        self::assertSame([], $standings->entries);
    }

    public function testSeasonEndedEmitsTheFinalRankingWithoutWipingTheTable(): void
    {
        $world = new WorldState();
        // 30 marque 4 buts et en prend 1, 10 en marque 2 et en prend 0 : le
        // second gagne au nombre de points, pas a la difference de buts.
        $this->playMatch($world, home: 10, away: 20, homeGoals: 2, awayGoals: 0, atTick: 5);
        $this->playMatch($world, home: 30, away: 20, homeGoals: 4, awayGoals: 1, atTick: 6);
        $this->playMatch($world, home: 30, away: 10, homeGoals: 0, awayGoals: 0, atTick: 7);

        $concluded = $this->endSeason($world, atTick: 10);

        self::assertSame(self::COMPETITION_ID, $concluded->competitionId);
        // 30 : 4 pts, +3 ; 10 : 4 pts, +2 ; 20 : 0 pt.
        self::assertSame([30, 10, 20], $concluded->finalRanking);

        // La table survit a la fin de saison : Harness\Metrics\Sampler l'y
        // lit pour son historique, et seul SeasonStarted la remet a zero.
        self::assertCount(3, $world->components(Standings::class)->get(self::COMPETITION_ID)->entries ?? []);
    }

    /**
     * `Standings::$entries` est keye par clubId et peuple paresseusement :
     * son ordre d'iteration est un ordre d'insertion, interdit comme source
     * d'ordre. Le depart final par clubId croissant rend le comparateur
     * total, donc le classement independant de cet ordre.
     */
    public function testPerfectlyTiedClubsAreRankedByAscendingClubId(): void
    {
        $world = new WorldState();
        // 20 joue (et gagne) avant 10 : il entre en premier dans la table.
        $this->playMatch($world, home: 20, away: 30, homeGoals: 1, awayGoals: 0, atTick: 5);
        $this->playMatch($world, home: 10, away: 40, homeGoals: 1, awayGoals: 0, atTick: 6);

        $concluded = $this->endSeason($world, atTick: 10);

        self::assertSame([10, 20, 30, 40], $concluded->finalRanking);
    }

    public function testAConcludedSeasonWithoutAnyMatchCarriesAnEmptyRanking(): void
    {
        $world = new WorldState();

        $concluded = $this->endSeason($world, atTick: 10);

        self::assertSame([], $concluded->finalRanking);
    }

    /**
     * Fait tourner le tick de fin de saison et renvoie le `SeasonConcluded`
     * emis, en verifiant qu'il y en a exactement un.
     */
    private function endSeason(WorldState $world, int $atTick): SeasonConcluded
    {
        $world->scheduler()->schedule(
            new SeasonEnded(self::COMPETITION_ID),
            atTick: $atTick,
            systemIndex: 0,
            entityId: self::COMPETITION_ID,
            seq: 0,
        );
        $this->runTick($world, tick: $atTick, balance: new CompetitionBalance());

        $emitted = array_values(array_filter(
            $world->outQueue()->pending(),
            static fn (object $event): bool => $event instanceof SeasonConcluded,
        ));

        self::assertCount(1, $emitted);

        return $emitted[0];
    }

    private function playMatch(WorldState $world, int $home, int $away, int $homeGoals, int $awayGoals, int $atTick): void
    {
        $fixture = $world->createEntity();
        $world->components(MatchResult::class)->set($fixture, new MatchResult(
            self::COMPETITION_ID,
            $home,
            $away,
            0,
            $homeGoals,
            $awayGoals,
        ));
        $world->scheduler()->schedule(
            new FixtureKickoff($fixture, self::COMPETITION_ID, $home, $away, 0),
            atTick: $atTick,
            systemIndex: 0,
            entityId: $fixture,
            seq: 0,
        );
        $this->runTick($world, tick: $atTick, balance: new CompetitionBalance());
    }

    private function scheduleKickoffWithResult(WorldState $world, int $homeGoals, int $awayGoals, int $atTick): int
    {
        $fixture = $world->createEntity();
        $world->components(MatchResult::class)->set($fixture, new MatchResult(
            self::COMPETITION_ID,
            self::HOME_CLUB_ID,
            self::AWAY_CLUB_ID,
            0,
            $homeGoals,
            $awayGoals,
        ));
        $world->scheduler()->schedule(
            new FixtureKickoff($fixture, self::COMPETITION_ID, self::HOME_CLUB_ID, self::AWAY_CLUB_ID, 0),
            atTick: $atTick,
            systemIndex: 0,
            entityId: $fixture,
            seq: 0,
        );

        return $fixture;
    }

    private function runTick(WorldState $world, int $tick, CompetitionBalance $balance): void
    {
        $pipeline = new Pipeline([new CompetitionSystem()]);
        $ruleset = new Ruleset('test', balance: new Balance(competition: $balance));

        $pipeline->tick($world, $tick, self::WORLD_SEED, $ruleset, []);
    }
}
