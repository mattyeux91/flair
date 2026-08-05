<?php

declare(strict_types=1);

namespace Flair\Harness\Tests\Population;

use Flair\Harness\Population\PopulationFactory;
use Flair\Harness\Population\PopulationSpec;
use Flair\Harness\Population\StaffFactory;
use Flair\Kernel\Core\Ecs\WorldState;
use Flair\Kernel\Core\Support\Rng;
use Flair\Kernel\Football\Components\Contract;
use Flair\Kernel\Football\Components\Employment;
use Flair\Kernel\Football\Components\Person;
use Flair\Kernel\Football\Components\PlayerPhysicalSkills;
use Flair\Kernel\Football\Components\Scout;
use Flair\Kernel\Football\Components\SquadMembership;
use PHPUnit\Framework\TestCase;

final class StaffFactoryTest extends TestCase
{
    public function testEmploysExactlyOneScoutPerClub(): void
    {
        $world = new WorldState();
        $clubIds = [11, 22, 33];

        $scoutIds = (new StaffFactory())->create($world, new Rng(1), $clubIds, judgementMean: 50, judgementSpread: 25);

        self::assertCount(3, $scoutIds);
        self::assertSame(
            $clubIds,
            array_map(static fn (int $id): ?int => $world->components(Employment::class)->get($id)?->clubId, $scoutIds),
        );

        foreach ($scoutIds as $scoutId) {
            self::assertNotNull($world->components(Person::class)->get($scoutId), 'un scout est une personne avant d\'etre un role');
            self::assertNotNull($world->components(Scout::class)->get($scoutId));
        }
    }

    /**
     * L'invariant de docs/12- §4 : un scout n'est pas un membre d'effectif et ne
     * doit apparaitre dans aucun parcours qui itere l'effectif.
     */
    public function testAScoutIsNeverASquadMember(): void
    {
        $world = new WorldState();

        $scoutIds = (new StaffFactory())->create($world, new Rng(1), [11], judgementMean: 50, judgementSpread: 25);

        foreach ($scoutIds as $scoutId) {
            self::assertNull($world->components(SquadMembership::class)->get($scoutId));
            self::assertNull($world->components(Contract::class)->get($scoutId));
            self::assertNull($world->components(PlayerPhysicalSkills::class)->get($scoutId));
        }
    }

    public function testJudgementIsDispersedAroundTheMeanAndStaysOnTheAbsoluteScale(): void
    {
        $world = new WorldState();
        $clubIds = range(1, 60);

        $scoutIds = (new StaffFactory())->create($world, new Rng(7), $clubIds, judgementMean: 50, judgementSpread: 25);
        $judgements = array_map(static fn (int $id): int => $world->components(Scout::class)->get($id)->judgement ?? 0, $scoutIds);

        if ($judgements === []) {
            self::fail('aucun recruteur cree');
        }

        self::assertGreaterThanOrEqual(25, min($judgements));
        self::assertLessThanOrEqual(75, max($judgements));
        self::assertGreaterThan(min($judgements), max($judgements), 'une dispersion non nulle doit produire des recruteurs differents');
    }

    /** L'experience de controle du lot : un monde ou aucun club n'est mieux informe qu'un autre. */
    public function testZeroSpreadMakesEveryScoutIdentical(): void
    {
        $world = new WorldState();

        $scoutIds = (new StaffFactory())->create($world, new Rng(7), range(1, 10), judgementMean: 50, judgementSpread: 0);
        $judgements = array_map(static fn (int $id): int => $world->components(Scout::class)->get($id)->judgement ?? 0, $scoutIds);

        self::assertSame(array_fill(0, 10, 50), $judgements);
    }

    /**
     * Le staff est seme **apres** les joueurs, pour que les identifiants des
     * entites joueur - donc tous les flux RNG qui en derivent - restent ceux
     * d'avant l'arrivee du staff dans le monde.
     */
    public function testStaffIdentifiersComeAfterEveryPlayerIdentifier(): void
    {
        $world = new WorldState();
        $spec = new PopulationSpec(playerCount: 20, years: 1, seed: 1, clubCount: 4);

        $playerIds = (new PopulationFactory())->populate($world, $spec);

        $staffIds = $world->components(Scout::class)->entities();
        self::assertCount(4, $staffIds);

        if ($staffIds === [] || $playerIds === []) {
            self::fail('le monde doit contenir des joueurs et des recruteurs');
        }

        self::assertGreaterThan(max($playerIds), min($staffIds));
    }
}
