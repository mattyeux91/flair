<?php

declare(strict_types=1);

namespace Flair\Harness\Tests\Population;

use Flair\Harness\Population\PopulationFactory;
use Flair\Harness\Population\PopulationSpec;
use Flair\Kernel\Core\Ecs\WorldState;
use Flair\Kernel\Football\Components\Club;
use Flair\Kernel\Football\Components\Facilities;
use Flair\Kernel\Football\Components\SquadMembership;
use PHPUnit\Framework\TestCase;

final class PopulationFactoryTest extends TestCase
{
    public function testCreatesTheRequestedNumberOfClubsWithFacilities(): void
    {
        $world = new WorldState();
        $spec = new PopulationSpec(playerCount: 20, years: 1, seed: 1, clubCount: 4, facilitiesQuality: 1.3);

        (new PopulationFactory())->populate($world, $spec);

        $clubIds = $world->components(Club::class)->entities();
        self::assertCount(4, $clubIds);

        foreach ($clubIds as $clubId) {
            self::assertSame(1.3, $world->components(Facilities::class)->get($clubId)?->quality);
        }
    }

    /**
     * Sans SquadMembership, TrainingSystem n'a rien a lire et
     * YouthIntakeSystem n'a aucun club ou promouvoir (cf. docblock
     * ClubFactory) - c'est precisement le trou que ce lot corrige.
     */
    public function testDistributesPlayersAcrossClubsRoundRobin(): void
    {
        $world = new WorldState();
        $spec = new PopulationSpec(playerCount: 9, years: 1, seed: 1, clubCount: 3, facilitiesQuality: 1.0);

        $playerIds = (new PopulationFactory())->populate($world, $spec);

        self::assertCount(9, $playerIds);

        $countByClub = [];
        foreach ($playerIds as $playerId) {
            $clubId = $world->components(SquadMembership::class)->get($playerId)?->clubId;
            self::assertNotNull($clubId);
            $countByClub[$clubId] = ($countByClub[$clubId] ?? 0) + 1;
        }

        self::assertCount(3, $countByClub);
        foreach ($countByClub as $count) {
            self::assertSame(3, $count);
        }
    }

    public function testZeroClubsLeavesPlayersWithoutSquadMembership(): void
    {
        $world = new WorldState();
        $spec = new PopulationSpec(playerCount: 5, years: 1, seed: 1, clubCount: 0);

        $playerIds = (new PopulationFactory())->populate($world, $spec);

        self::assertSame([], $world->components(Club::class)->entities());
        foreach ($playerIds as $playerId) {
            self::assertNull($world->components(SquadMembership::class)->get($playerId));
        }
    }
}
