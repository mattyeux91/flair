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
