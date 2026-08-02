<?php

declare(strict_types=1);

namespace Flair\Kernel\Tests\Football\Systems;

use Flair\Kernel\Core\Ecs\WorldState;
use Flair\Kernel\Core\Pipeline\Pipeline;
use Flair\Kernel\Core\Ruleset\Balance;
use Flair\Kernel\Core\Ruleset\MatchBalance;
use Flair\Kernel\Core\Ruleset\Ruleset;
use Flair\Kernel\Football\Components\Club;
use Flair\Kernel\Football\Components\MatchResult;
use Flair\Kernel\Football\Components\PlayerPhysicalSkills;
use Flair\Kernel\Football\Components\PlayerTechnicalSkills;
use Flair\Kernel\Football\Components\SquadMembership;
use Flair\Kernel\Football\Events\FixtureKickoff;
use Flair\Kernel\Football\Events\MatchPlayed;
use Flair\Kernel\Football\Systems\MatchSystem;
use PHPUnit\Framework\TestCase;

final class MatchSystemTest extends TestCase
{
    private const WORLD_SEED = 20260802;
    private const COMPETITION_ID = 999;

    public function testAFixtureKickoffProducesAConsistentMatchResultAndFact(): void
    {
        $world = new WorldState();
        $home = $this->addClubWithSkill($world, 50);
        $away = $this->addClubWithSkill($world, 50);
        $fixture = $this->scheduleKickoff($world, $home, $away, matchday: 0, atTick: 10);

        $this->runTick($world, tick: 10, balance: new MatchBalance());

        $result = $world->components(MatchResult::class)->get($fixture);
        self::assertNotNull($result);
        self::assertSame(self::COMPETITION_ID, $result->competitionId);
        self::assertSame($home, $result->homeClubId);
        self::assertSame($away, $result->awayClubId);
        self::assertGreaterThanOrEqual(0, $result->homeGoals);
        self::assertGreaterThanOrEqual(0, $result->awayGoals);

        $events = $world->outQueue()->pending();
        self::assertCount(1, $events);
        $event = $events[0];
        self::assertInstanceOf(MatchPlayed::class, $event);
        self::assertSame($result->homeGoals, $event->homeGoals);
        self::assertSame($result->awayGoals, $event->awayGoals);
    }

    public function testIsDeterministicForAGivenSeed(): void
    {
        $signature = function (): string {
            $world = new WorldState();
            $home = $this->addClubWithSkill($world, 60);
            $away = $this->addClubWithSkill($world, 45);
            $fixture = $this->scheduleKickoff($world, $home, $away, matchday: 0, atTick: 10);

            $this->runTick($world, tick: 10, balance: new MatchBalance());

            $result = $world->components(MatchResult::class)->get($fixture);
            self::assertNotNull($result);

            return "{$result->homeGoals}:{$result->awayGoals}";
        };

        self::assertSame($signature(), $signature());
    }

    public function testAStrongerSquadScoresMoreOnAverage(): void
    {
        $world = new WorldState();
        $strong = $this->addClubWithSkill($world, 80);
        $weak = $this->addClubWithSkill($world, 30);

        $strongGoals = 0;
        $weakGoals = 0;
        $matches = 300;

        for ($matchday = 0; $matchday < $matches; $matchday++) {
            $fixture = $this->scheduleKickoff($world, $strong, $weak, $matchday, atTick: 10);
            $this->runTick($world, tick: 10, balance: new MatchBalance(homeAdvantage: 0.0));

            $result = $world->components(MatchResult::class)->get($fixture);
            self::assertNotNull($result);
            $strongGoals += $result->homeGoals;
            $weakGoals += $result->awayGoals;
        }

        self::assertGreaterThan($weakGoals, $strongGoals);
    }

    public function testAClubWithoutAnySquadReceivesANeutralRating(): void
    {
        $world = new WorldState();
        $home = $world->createEntity();
        $world->components(Club::class)->set($home, new Club('Sans effectif'));
        $away = $this->addClubWithSkill($world, 50);
        $fixture = $this->scheduleKickoff($world, $home, $away, matchday: 0, atTick: 10);

        $this->runTick($world, tick: 10, balance: new MatchBalance());

        self::assertNotNull($world->components(MatchResult::class)->get($fixture));
    }

    private function addClubWithSkill(WorldState $world, int $skill): int
    {
        $club = $world->createEntity();
        $world->components(Club::class)->set($club, new Club("Club {$skill}"));

        $player = $world->createEntity();
        $world->components(SquadMembership::class)->set($player, new SquadMembership($club));
        $world->components(PlayerPhysicalSkills::class)->set($player, new PlayerPhysicalSkills($skill, $skill, $skill, $skill));
        $world->components(PlayerTechnicalSkills::class)->set($player, new PlayerTechnicalSkills($skill, $skill, $skill, $skill, $skill, $skill, $skill));

        return $club;
    }

    private function scheduleKickoff(WorldState $world, int $home, int $away, int $matchday, int $atTick): int
    {
        $fixture = $world->createEntity();
        $world->scheduler()->schedule(
            new FixtureKickoff($fixture, self::COMPETITION_ID, $home, $away, $matchday),
            atTick: $atTick,
            systemIndex: 0,
            entityId: $fixture,
            seq: 0,
        );

        return $fixture;
    }

    private function runTick(WorldState $world, int $tick, MatchBalance $balance): void
    {
        $pipeline = new Pipeline([new MatchSystem()]);
        $ruleset = new Ruleset('test', balance: new Balance(match: $balance));

        $pipeline->tick($world, $tick, self::WORLD_SEED, $ruleset, []);
    }
}
