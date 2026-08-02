<?php

declare(strict_types=1);

namespace Flair\Kernel\Tests\Football;

use Flair\Kernel\Core\Ecs\WorldState;
use Flair\Kernel\Core\Pipeline\Pipeline;
use Flair\Kernel\Core\Ruleset\Balance;
use Flair\Kernel\Core\Ruleset\CalendarBalance;
use Flair\Kernel\Core\Ruleset\Ruleset;
use Flair\Kernel\Football\Components\Club;
use Flair\Kernel\Football\Components\Competition;
use Flair\Kernel\Football\Components\Fixture;
use Flair\Kernel\Football\Components\MatchResult;
use Flair\Kernel\Football\Components\PlayerPhysicalSkills;
use Flair\Kernel\Football\Components\PlayerTechnicalSkills;
use Flair\Kernel\Football\Components\SquadMembership;
use Flair\Kernel\Football\Components\Standings;
use Flair\Kernel\Football\Systems\CalendarSystem;
use Flair\Kernel\Football\Systems\CompetitionSystem;
use Flair\Kernel\Football\Systems\MatchSystem;
use PHPUnit\Framework\TestCase;

/**
 * Pipeline complet (calendrier -> match -> classement) sur une saison
 * entiere : verifie que les trois systemes cooperent correctement a travers
 * le Scheduler/OutQueue, pas seulement isolement.
 */
final class SeasonIntegrationTest extends TestCase
{
    private const WORLD_SEED = 20260802;
    private const CLUB_COUNT = 8;

    public function testStandingsAfterAFullSeasonMatchTheSumOfMatchResults(): void
    {
        $world = new WorldState();
        $this->addCompetition($world, 'Ligue Test');

        for ($i = 0; $i < self::CLUB_COUNT; $i++) {
            $this->addClub($world, "Club {$i}", skill: 40 + $i * 5);
        }

        $pipeline = new Pipeline([
            new CalendarSystem(),
            new MatchSystem(),
            new CompetitionSystem(),
        ]);
        $ruleset = new Ruleset('test', balance: new Balance(calendar: new CalendarBalance()));

        // Assez de jours pour couvrir la generation (jour 0) et toutes les
        // journees (14 + (N-1)*2 journees espacees de 7 jours).
        $daysInSeason = 14 + (self::CLUB_COUNT - 1) * 2 * 7 + 7;

        for ($tick = 0; $tick <= $daysInSeason; $tick++) {
            $pipeline->tick($world, $tick, self::WORLD_SEED, $ruleset, []);
        }

        $expectedFixtures = self::CLUB_COUNT * (self::CLUB_COUNT - 1);
        $fixtureIds = $world->components(Fixture::class)->entities();
        self::assertCount($expectedFixtures, $fixtureIds);

        $results = $world->components(MatchResult::class);
        foreach ($fixtureIds as $fixtureId) {
            self::assertNotNull($results->get($fixtureId), "Le match {$fixtureId} aurait du etre joue d'ici la fin de la saison");
        }

        $competitionId = $world->components(Competition::class)->entities()[0];
        $standings = $world->components(Standings::class)->get($competitionId);
        self::assertNotNull($standings);

        /** @var array<int, array{played:int, points:int, goalsFor:int}> $expected */
        $expected = [];
        foreach ($fixtureIds as $fixtureId) {
            $result = $results->get($fixtureId);
            self::assertNotNull($result);

            $expected[$result->homeClubId] = $this->applyMatch($expected[$result->homeClubId] ?? $this->emptyStats(), $result->homeGoals, $result->awayGoals);
            $expected[$result->awayClubId] = $this->applyMatch($expected[$result->awayClubId] ?? $this->emptyStats(), $result->awayGoals, $result->homeGoals);
        }

        foreach ($expected as $clubId => $stats) {
            $entry = $standings->entries[$clubId] ?? null;
            self::assertNotNull($entry, "Le club {$clubId} devrait avoir une entree au classement");
            self::assertSame($stats['played'], $entry->played);
            self::assertSame($stats['points'], $entry->points);
            self::assertSame($stats['goalsFor'], $entry->goalsFor);
        }
    }

    /** @return array{played:int, points:int, goalsFor:int} */
    private function emptyStats(): array
    {
        return ['played' => 0, 'points' => 0, 'goalsFor' => 0];
    }

    /**
     * @param array{played:int, points:int, goalsFor:int} $stats
     * @return array{played:int, points:int, goalsFor:int}
     */
    private function applyMatch(array $stats, int $goalsFor, int $goalsAgainst): array
    {
        $points = match (true) {
            $goalsFor > $goalsAgainst => 3,
            $goalsFor === $goalsAgainst => 1,
            default => 0,
        };

        return [
            'played' => $stats['played'] + 1,
            'points' => $stats['points'] + $points,
            'goalsFor' => $stats['goalsFor'] + $goalsFor,
        ];
    }

    private function addCompetition(WorldState $world, string $name): int
    {
        $competition = $world->createEntity();
        $world->components(Competition::class)->set($competition, new Competition($name));

        return $competition;
    }

    private function addClub(WorldState $world, string $name, int $skill): int
    {
        $club = $world->createEntity();
        $world->components(Club::class)->set($club, new Club($name));

        $player = $world->createEntity();
        $world->components(SquadMembership::class)->set($player, new SquadMembership($club));
        $world->components(PlayerPhysicalSkills::class)->set($player, new PlayerPhysicalSkills($skill, $skill, $skill, $skill));
        $world->components(PlayerTechnicalSkills::class)->set($player, new PlayerTechnicalSkills($skill, $skill, $skill, $skill, $skill, $skill, $skill));

        return $club;
    }
}
