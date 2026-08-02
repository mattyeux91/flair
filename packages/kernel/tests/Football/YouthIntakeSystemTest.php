<?php

declare(strict_types=1);

namespace Flair\Kernel\Tests\Football;

use Flair\Kernel\Core\Ecs\WorldState;
use Flair\Kernel\Core\Pipeline\Pipeline;
use Flair\Kernel\Core\Ruleset\Balance;
use Flair\Kernel\Core\Ruleset\Ruleset;
use Flair\Kernel\Core\Ruleset\YouthIntakeBalance;
use Flair\Kernel\Core\Support\SimDate;
use Flair\Kernel\Football\Components\Club;
use Flair\Kernel\Football\Components\Facilities;
use Flair\Kernel\Football\Components\Person;
use Flair\Kernel\Football\Components\PlayerPotentials;
use Flair\Kernel\Football\Components\SquadMembership;
use Flair\Kernel\Football\Events\YouthPlayerPromoted;
use Flair\Kernel\Football\Systems\YouthIntakeSystem;
use PHPUnit\Framework\TestCase;

final class YouthIntakeSystemTest extends TestCase
{
    private const WORLD_SEED = 20260801;

    public function testNoPlayerIsPromotedOutsideTheIntakeDay(): void
    {
        $world = new WorldState();
        $this->addClub($world, 'FC Test', 1.0);

        $this->runTick($world, tick: 12, balance: new YouthIntakeBalance(intakeDayOfYear: 180));

        self::assertSame([], $world->components(SquadMembership::class)->entities());
    }

    public function testPlayersArePromotedOnTheIntakeDay(): void
    {
        $world = new WorldState();
        $club = $this->addClub($world, 'FC Test', 1.0);

        $this->runTick($world, tick: 180, balance: new YouthIntakeBalance(intakeDayOfYear: 180, baseIntakePerClub: 2.0));

        $promoted = $world->components(SquadMembership::class)->entities();
        self::assertCount(2, $promoted);

        foreach ($promoted as $playerId) {
            self::assertSame($club, $world->components(SquadMembership::class)->get($playerId)?->clubId);
            self::assertNotNull($world->components(Person::class)->get($playerId));
            self::assertNotNull($world->components(PlayerPotentials::class)->get($playerId));
        }
    }

    public function testIntakeRecursEverySimulatedYear(): void
    {
        $world = new WorldState();
        $this->addClub($world, 'FC Test', 1.0);
        $balance = new YouthIntakeBalance(intakeDayOfYear: 10, baseIntakePerClub: 1.0);

        foreach ([10, 375, 740] as $tick) {
            $this->runTick($world, $tick, $balance);
        }

        self::assertCount(3, $world->components(SquadMembership::class)->entities());
    }

    public function testPromotedPlayersHaveTheConfiguredAge(): void
    {
        $world = new WorldState();
        $this->addClub($world, 'FC Test', 1.0);
        $tick = 180;

        $this->runTick($world, $tick, new YouthIntakeBalance(intakeDayOfYear: 180, intakeAgeYears: 17.0, baseIntakePerClub: 1.0));

        $playerId = $world->components(SquadMembership::class)->entities()[0];
        $person = $world->components(Person::class)->get($playerId);
        self::assertNotNull($person);

        $age = (new SimDate($tick))->yearsSince($person->birthDate);
        self::assertEqualsWithDelta(17.0, $age, 0.01);
    }

    /**
     * Les installations decident de la **part** du vivier qu'un club capte,
     * pas de sa taille : la comparaison doit donc se faire entre deux clubs
     * d'un meme monde. Deux mondes separes a qualite uniforme promouvraient
     * exactement autant l'un que l'autre, quelle que soit leur qualite - c'est
     * precisement la propriete verifiee par le test suivant.
     */
    public function testBetterFacilitiesCaptureALargerShareOfTheNationalIntake(): void
    {
        $world = new WorldState();
        $elite = $this->addClub($world, 'Centre Elite', 2.0);
        $modest = $this->addClub($world, 'Centre Modeste', 0.5);

        $balance = new YouthIntakeBalance(intakeDayOfYear: 0, baseIntakePerClub: 1.0);
        for ($year = 0; $year < 40; $year++) {
            $this->runTick($world, tick: $year * 365, balance: $balance);
        }

        self::assertGreaterThan($this->squadSize($world, $modest), $this->squadSize($world, $elite));
    }

    /**
     * **La propriete qui empeche le monde d'osciller** (cf. le docblock de
     * `YouthIntakeSystem::averageQuality()`). Le vivier national est de taille
     * fixe : ameliorer les installations de tout le monde redistribue les
     * promotions, ca n'en cree pas une de plus. Sans cette invariance, les
     * installations devenues dynamiques refermeraient une boucle de retour
     * retardee d'une carriere entiere, et la population cesserait d'etre
     * stationnaire - critere de sortie de la Phase 0.
     */
    public function testTheNationalIntakeDoesNotDependOnHowGoodTheFacilitiesAre(): void
    {
        $poor = $this->promotionsInAWorldWhereEveryClubHasQuality(0.5);
        $average = $this->promotionsInAWorldWhereEveryClubHasQuality(1.0);
        $rich = $this->promotionsInAWorldWhereEveryClubHasQuality(2.0);

        self::assertSame($average, $poor);
        self::assertSame($average, $rich);
        self::assertGreaterThan(0, $average);
    }

    private function promotionsInAWorldWhereEveryClubHasQuality(float $quality): int
    {
        $world = new WorldState();
        for ($i = 0; $i < 6; $i++) {
            $this->addClub($world, "Club {$i}", $quality);
        }

        $balance = new YouthIntakeBalance(intakeDayOfYear: 0, baseIntakePerClub: 1.5);
        for ($year = 0; $year < 20; $year++) {
            $this->runTick($world, tick: $year * 365, balance: $balance);
        }

        return \count($world->components(PlayerPotentials::class)->entities());
    }

    private function squadSize(WorldState $world, int $clubId): int
    {
        $size = 0;
        foreach ($world->components(SquadMembership::class)->entities() as $playerId) {
            if ($world->components(SquadMembership::class)->get($playerId)?->clubId === $clubId) {
                $size++;
            }
        }

        return $size;
    }

    /**
     * Le coeur de la calibration : l'arrondi stochastique doit conserver
     * l'esperance. Avec 1,2 attendu par club et par saison, un round() sec
     * donnerait exactement 1,0 - et `baseIntakePerClub` n'aurait plus aucun
     * effet entre 0,5 et 1,5.
     */
    public function testCohortSizeKeepsTheConfiguredExpectationOnAverage(): void
    {
        $years = 200;
        $promoted = $this->promotionsOverYears(quality: 1.0, years: $years, base: 1.2);

        self::assertEqualsWithDelta(1.2, $promoted / $years, 0.15);
    }

    public function testPromotionEmitsAFact(): void
    {
        $world = new WorldState();
        $club = $this->addClub($world, 'FC Test', 1.0);

        $this->runTick($world, tick: 180, balance: new YouthIntakeBalance(intakeDayOfYear: 180, baseIntakePerClub: 1.0));

        $events = $world->outQueue()->pending();
        self::assertCount(1, $events);

        $event = $events[0];
        self::assertInstanceOf(YouthPlayerPromoted::class, $event);
        self::assertSame($club, $event->clubId);
    }

    public function testIntakeIsDeterministicForAGivenSeed(): void
    {
        $signature = function (): string {
            $world = new WorldState();
            $this->addClub($world, 'FC Test', 1.4);
            $this->runTick($world, tick: 180, balance: new YouthIntakeBalance(intakeDayOfYear: 180, baseIntakePerClub: 3.0));

            $parts = [];
            foreach ($world->components(PlayerPotentials::class)->entities() as $playerId) {
                $potential = $world->components(PlayerPotentials::class)->get($playerId);
                self::assertNotNull($potential);
                $parts[] = sprintf('%d:%d:%.6f', $playerId, $potential->ceiling, $potential->growthRate);
            }

            return implode('|', $parts);
        };

        self::assertSame($signature(), $signature());
        self::assertNotSame('', $signature());
    }

    /**
     * La loi de talent est asymetrique a droite (docs/12- §7) : la majorite
     * des joueurs reste dans la moitie basse de la fourchette de `ceiling`,
     * les tres bons sont rares. Un tirage uniforme donnerait ~50 % au-dessus
     * de la mediane de la fourchette et ferait echouer ce test.
     */
    public function testTalentIsSkewedTowardsOrdinaryPlayers(): void
    {
        $balance = new YouthIntakeBalance(intakeDayOfYear: 0, baseIntakePerClub: 4.0, ceilingMin: 0, ceilingMax: 100);
        $world = new WorldState();

        for ($i = 0; $i < 30; $i++) {
            $this->addClub($world, "Club {$i}", 1.0);
        }

        $this->runTick($world, tick: 0, balance: $balance);

        $ceilings = [];
        foreach ($world->components(PlayerPotentials::class)->entities() as $playerId) {
            $potential = $world->components(PlayerPotentials::class)->get($playerId);
            self::assertNotNull($potential);
            $ceilings[] = $potential->ceiling;
        }

        self::assertGreaterThan(50, count($ceilings));

        $aboveMidpoint = count(array_filter($ceilings, static fn (int $ceiling): bool => $ceiling > 50));
        self::assertLessThan(0.25 * count($ceilings), $aboveMidpoint);
    }

    private function promotionsOverYears(float $quality, int $years, float $base = 1.0): int
    {
        $world = new WorldState();
        $this->addClub($world, 'FC Test', $quality);
        $balance = new YouthIntakeBalance(intakeDayOfYear: 0, baseIntakePerClub: $base);

        for ($year = 0; $year < $years; $year++) {
            $this->runTick($world, $year * 365, $balance);
        }

        return count($world->components(SquadMembership::class)->entities());
    }

    private function addClub(WorldState $world, string $name, float $quality): int
    {
        $club = $world->createEntity();
        $world->components(Club::class)->set($club, new Club($name));
        $world->components(Facilities::class)->set($club, new Facilities($quality));

        return $club;
    }

    private function runTick(WorldState $world, int $tick, YouthIntakeBalance $balance): void
    {
        $pipeline = new Pipeline([new YouthIntakeSystem()]);
        $ruleset = new Ruleset('test', balance: new Balance(youthIntake: $balance));

        $pipeline->tick($world, $tick, self::WORLD_SEED, $ruleset, []);
    }
}
