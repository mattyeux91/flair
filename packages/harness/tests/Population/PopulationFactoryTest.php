<?php

declare(strict_types=1);

namespace Flair\Harness\Tests\Population;

use Flair\Harness\Population\PopulationFactory;
use Flair\Harness\Population\PopulationSpec;
use Flair\Kernel\Core\Ecs\WorldState;
use Flair\Kernel\Core\Ruleset\ContractBalance;
use Flair\Kernel\Core\Ruleset\YouthIntakeBalance;
use Flair\Kernel\Football\Components\Club;
use Flair\Kernel\Football\Components\Competition;
use Flair\Kernel\Football\Components\Contract;
use Flair\Kernel\Football\Components\Facilities;
use Flair\Kernel\Football\Components\Finances;
use Flair\Kernel\Football\Components\PlayerMentalSkills;
use Flair\Kernel\Football\Components\PlayerPhysicalSkills;
use Flair\Kernel\Football\Components\PlayerTechnicalSkills;
use Flair\Kernel\Football\Components\SquadMembership;
use Flair\Kernel\Football\Support\WageModel;
use PHPUnit\Framework\TestCase;

final class PopulationFactoryTest extends TestCase
{
    public function testCreatesTheRequestedNumberOfClubsWithFacilitiesAndAStartingBalance(): void
    {
        $world = new WorldState();
        $spec = new PopulationSpec(playerCount: 20, years: 1, seed: 1, clubCount: 4, facilitiesQuality: 1.3, startingBalanceCents: 7_500_000);

        (new PopulationFactory())->populate($world, $spec);

        $clubIds = $world->components(Club::class)->entities();
        self::assertCount(4, $clubIds);

        foreach ($clubIds as $clubId) {
            self::assertSame(1.3, $world->components(Facilities::class)->get($clubId)?->quality);
            self::assertSame(7_500_000, $world->components(Finances::class)->get($clubId)?->balanceCents);
        }
    }

    /**
     * Sans Contract, Football\FinanceSystem n'a aucun salaire a verser pour
     * ce joueur (cf. docblock Football\Components\Contract) - meme trou que
     * SquadMembership avant ce lot pour TrainingSystem/YouthIntakeSystem.
     *
     * Le salaire est compare a `Football\Support\WageModel` plutot qu'a une
     * constante : c'est la propriete qui compte (le genesis demarre au meme
     * prix que celui auquel `Football\ContractSystem` renouvellera), et une
     * valeur en dur casserait a chaque recalibrage de `ContractBalance`.
     */
    public function testAssignsAContractMatchingSquadMembershipToEveryClubbedPlayer(): void
    {
        $world = new WorldState();
        $spec = new PopulationSpec(playerCount: 9, years: 1, seed: 1, clubCount: 3);
        $talent = new YouthIntakeBalance();
        $contracts = new ContractBalance();

        $playerIds = (new PopulationFactory())->populate($world, $spec, atTick: 1, talent: $talent, contracts: $contracts);

        foreach ($playerIds as $playerId) {
            $membership = $world->components(SquadMembership::class)->get($playerId);
            $contract = $world->components(Contract::class)->get($playerId);

            self::assertNotNull($membership);
            self::assertNotNull($contract);
            self::assertSame($membership->clubId, $contract->clubId);

            $quality = WageModel::quality(
                $world->components(PlayerPhysicalSkills::class)->get($playerId),
                $world->components(PlayerTechnicalSkills::class)->get($playerId),
                $world->components(PlayerMentalSkills::class)->get($playerId),
            );
            self::assertSame(WageModel::perWeekCents($quality, $contracts), $contract->wagePerWeekCents);
        }
    }

    /**
     * Sans etalement, toute la population arriverait a terme la meme annee et
     * le monde entier changerait de club en bloc (cf. `PopulationFactory::employ()`).
     */
    public function testStaggersContractExpiryAcrossThePopulation(): void
    {
        $world = new WorldState();
        $spec = new PopulationSpec(playerCount: 60, years: 1, seed: 7, clubCount: 3);

        $playerIds = (new PopulationFactory())->populate($world, $spec, atTick: 1);

        $expiryYears = [];
        foreach ($playerIds as $playerId) {
            $contract = $world->components(Contract::class)->get($playerId);
            self::assertNotNull($contract);
            $expiryYears[intdiv($contract->expiresOn->epochDay, 365)] = true;
        }

        self::assertGreaterThan(1, \count($expiryYears), 'les echeances devraient couvrir plusieurs annees');
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
            self::assertNull($world->components(Contract::class)->get($playerId));
        }
    }

    /**
     * Sans elle, Football\CalendarSystem (qui lit Competition::class) n'a
     * aucun calendrier a generer meme si des clubs existent.
     */
    public function testCreatesACompetitionWhenClubsExist(): void
    {
        $world = new WorldState();
        $spec = new PopulationSpec(playerCount: 5, years: 1, seed: 1, clubCount: 3);

        (new PopulationFactory())->populate($world, $spec);

        self::assertCount(1, $world->components(Competition::class)->entities());
    }

    public function testDoesNotCreateACompetitionWithoutClubs(): void
    {
        $world = new WorldState();
        $spec = new PopulationSpec(playerCount: 5, years: 1, seed: 1, clubCount: 0);

        (new PopulationFactory())->populate($world, $spec);

        self::assertSame([], $world->components(Competition::class)->entities());
    }
}
