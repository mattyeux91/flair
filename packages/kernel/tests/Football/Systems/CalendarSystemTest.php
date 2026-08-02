<?php

declare(strict_types=1);

namespace Flair\Kernel\Tests\Football\Systems;

use Flair\Kernel\Core\Ecs\WorldState;
use Flair\Kernel\Core\Pipeline\Pipeline;
use Flair\Kernel\Core\Ruleset\Balance;
use Flair\Kernel\Core\Ruleset\CalendarBalance;
use Flair\Kernel\Core\Ruleset\Ruleset;
use Flair\Kernel\Football\Components\Club;
use Flair\Kernel\Football\Components\Competition;
use Flair\Kernel\Football\Components\Fixture;
use Flair\Kernel\Football\Events\FixtureKickoff;
use Flair\Kernel\Football\Events\SeasonStarted;
use Flair\Kernel\Football\Systems\CalendarSystem;
use PHPUnit\Framework\TestCase;

final class CalendarSystemTest extends TestCase
{
    private const WORLD_SEED = 20260802;

    public function testGeneratesADoubleRoundRobinForAllClubs(): void
    {
        $world = new WorldState();
        $this->addCompetition($world, 'Ligue Test');
        $clubCount = 18;

        for ($i = 0; $i < $clubCount; $i++) {
            $this->addClub($world, "Club {$i}");
        }

        $this->runTick($world, tick: 0, balance: new CalendarBalance());

        $fixtures = $world->components(Fixture::class)->entities();
        self::assertCount($clubCount * ($clubCount - 1), $fixtures);
    }

    public function testEachClubPlaysEveryOtherClubOnceAtHomeAndOnceAway(): void
    {
        $world = new WorldState();
        $this->addCompetition($world, 'Ligue Test');
        $clubIds = [];

        for ($i = 0; $i < 6; $i++) {
            $clubIds[] = $this->addClub($world, "Club {$i}");
        }

        $this->runTick($world, tick: 0, balance: new CalendarBalance());

        /** @var array<int, array<int, int>> $homeCountByOpponent clubId -> opponentId -> count */
        $homeCountByOpponent = [];

        foreach ($world->components(Fixture::class)->entities() as $fixtureId) {
            $fixture = $world->components(Fixture::class)->get($fixtureId);
            self::assertNotNull($fixture);
            $homeCountByOpponent[$fixture->homeClubId][$fixture->awayClubId] = ($homeCountByOpponent[$fixture->homeClubId][$fixture->awayClubId] ?? 0) + 1;
        }

        foreach ($clubIds as $home) {
            $homeGames = 0;

            foreach ($clubIds as $away) {
                if ($home === $away) {
                    continue;
                }

                $homeWins = $homeCountByOpponent[$home][$away] ?? 0;
                self::assertSame(1, $homeWins, "Club {$home} doit affronter le club {$away} exactement une fois a domicile");
                $homeGames += $homeWins;
            }

            self::assertSame(count($clubIds) - 1, $homeGames);
        }
    }

    public function testSchedulesAFixtureKickoffForEachFixture(): void
    {
        $world = new WorldState();
        $this->addCompetition($world, 'Ligue Test');
        $this->addClub($world, 'A');
        $this->addClub($world, 'B');

        $balance = new CalendarBalance(seasonStartDayOfYear: 0, firstMatchdayOffsetDays: 14, matchdayIntervalDays: 7);
        $this->runTick($world, tick: 0, balance: $balance);

        // 2 clubs -> 1 journee aller (tick 14) + 1 journee retour (tick 21).
        $dueAtFirstMatchday = $world->scheduler()->drainDueBy(14);
        self::assertCount(1, $dueAtFirstMatchday);
        self::assertInstanceOf(FixtureKickoff::class, $dueAtFirstMatchday[0]);

        $dueAtSecondMatchday = $world->scheduler()->drainDueBy(21);
        self::assertCount(1, $dueAtSecondMatchday);
        self::assertInstanceOf(FixtureKickoff::class, $dueAtSecondMatchday[0]);
    }

    public function testRecursEverySimulatedYear(): void
    {
        $world = new WorldState();
        $this->addCompetition($world, 'Ligue Test');
        $this->addClub($world, 'A');
        $this->addClub($world, 'B');

        $balance = new CalendarBalance(seasonStartDayOfYear: 10);

        foreach ([10, 375, 740] as $tick) {
            $this->runTick($world, $tick, $balance);
        }

        // 2 clubs -> 2 fixtures par saison (1 aller + 1 retour), 3 saisons.
        self::assertCount(6, $world->components(Fixture::class)->entities());
    }

    public function testEmitsASeasonStartedFactPerCompetition(): void
    {
        $world = new WorldState();
        $competition = $this->addCompetition($world, 'Ligue Test');
        $this->addClub($world, 'A');
        $this->addClub($world, 'B');

        $this->runTick($world, tick: 0, balance: new CalendarBalance());

        $events = $world->outQueue()->pending();
        self::assertCount(1, $events);
        self::assertInstanceOf(SeasonStarted::class, $events[0]);
        self::assertSame($competition, $events[0]->competitionId);
    }

    public function testDoesNothingOutsideTheSeasonStartDay(): void
    {
        $world = new WorldState();
        $this->addCompetition($world, 'Ligue Test');
        $this->addClub($world, 'A');
        $this->addClub($world, 'B');

        $this->runTick($world, tick: 5, balance: new CalendarBalance(seasonStartDayOfYear: 0));

        self::assertSame([], $world->components(Fixture::class)->entities());
    }

    private function addCompetition(WorldState $world, string $name): int
    {
        $competition = $world->createEntity();
        $world->components(Competition::class)->set($competition, new Competition($name));

        return $competition;
    }

    private function addClub(WorldState $world, string $name): int
    {
        $club = $world->createEntity();
        $world->components(Club::class)->set($club, new Club($name));

        return $club;
    }

    private function runTick(WorldState $world, int $tick, CalendarBalance $balance): void
    {
        $pipeline = new Pipeline([new CalendarSystem()]);
        $ruleset = new Ruleset('test', balance: new Balance(calendar: $balance));

        $pipeline->tick($world, $tick, self::WORLD_SEED, $ruleset, []);
    }
}
