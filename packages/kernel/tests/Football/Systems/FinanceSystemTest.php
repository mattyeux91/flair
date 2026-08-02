<?php

declare(strict_types=1);

namespace Flair\Kernel\Tests\Football\Systems;

use Flair\Kernel\Core\Ecs\WorldState;
use Flair\Kernel\Core\Pipeline\Pipeline;
use Flair\Kernel\Core\Ruleset\Balance;
use Flair\Kernel\Core\Ruleset\FinanceBalance;
use Flair\Kernel\Core\Ruleset\Ruleset;
use Flair\Kernel\Core\Support\SimDate;
use Flair\Kernel\Football\Components\Club;
use Flair\Kernel\Football\Components\Contract;
use Flair\Kernel\Football\Components\Finances;
use Flair\Kernel\Football\Components\Person;
use Flair\Kernel\Football\Components\PlayerMentalSkills;
use Flair\Kernel\Football\Components\PlayerPhysicalSkills;
use Flair\Kernel\Football\Components\PlayerPotentials;
use Flair\Kernel\Football\Components\PlayerTechnicalSkills;
use Flair\Kernel\Football\Events\SeasonStarted;
use Flair\Kernel\Football\Singletons\MonetaryMass;
use Flair\Kernel\Football\Systems\FinanceSystem;
use Flair\Kernel\Football\Systems\RetirementSystem;
use PHPUnit\Framework\TestCase;

final class FinanceSystemTest extends TestCase
{
    public function testSeasonStartedCreditsEveryClubWithFinancesAndUpdatesMonetaryMass(): void
    {
        $world = new WorldState();
        $club = $this->createClub($world, balanceCents: 1_000_000);

        $world->scheduler()->schedule(
            new SeasonStarted(competitionId: 1),
            atTick: 5,
            systemIndex: 0,
            entityId: 1,
            seq: 0,
        );

        $pipeline = new Pipeline([new FinanceSystem()]);
        $pipeline->tick($world, tick: 5, worldSeed: 1, ruleset: $this->ruleset(), intents: []);

        $finances = $world->components(Finances::class)->get($club);
        self::assertNotNull($finances);
        self::assertSame(1_000_000 + 70_000_000, $finances->balanceCents);

        $mass = $world->singleton(MonetaryMass::class);
        self::assertNotNull($mass);
        self::assertSame(70_000_000, $mass->totalInjectionsCents);
        self::assertSame(0, $mass->totalSinksCents);
    }

    public function testWagesAreDeductedOnThePaymentDayAndUpdateMonetaryMass(): void
    {
        $world = new WorldState();
        $club = $this->createClub($world, balanceCents: 1_000_000);
        $this->createContractedPlayer($world, $club, wagePerWeekCents: 50_000);

        $pipeline = new Pipeline([new FinanceSystem()]);

        // wagePaymentDayOfWeek par defaut = 0 : tick 7 est un jour de paie
        // (7 % 7 === 0), tick 1 ne l'est pas.
        $pipeline->tick($world, tick: 1, worldSeed: 1, ruleset: $this->ruleset(), intents: []);
        $beforePaymentDay = $world->components(Finances::class)->get($club);
        self::assertNotNull($beforePaymentDay);
        self::assertSame(1_000_000, $beforePaymentDay->balanceCents);

        $pipeline->tick($world, tick: 7, worldSeed: 1, ruleset: $this->ruleset(), intents: []);

        $finances = $world->components(Finances::class)->get($club);
        self::assertNotNull($finances);
        self::assertSame(1_000_000 - 50_000, $finances->balanceCents);

        $mass = $world->singleton(MonetaryMass::class);
        self::assertNotNull($mass);
        self::assertSame(0, $mass->totalInjectionsCents);
        self::assertSame(50_000, $mass->totalSinksCents);
    }

    public function testAClubWithoutFinancesIsSkippedWithoutError(): void
    {
        $world = new WorldState();
        $clubWithoutFinances = $world->createEntity();
        $world->components(Club::class)->set($clubWithoutFinances, new Club('Sans tresorerie'));
        $this->createContractedPlayer($world, $clubWithoutFinances, wagePerWeekCents: 50_000);

        $world->scheduler()->schedule(
            new SeasonStarted(competitionId: 1),
            atTick: 7,
            systemIndex: 0,
            entityId: 1,
            seq: 0,
        );

        $pipeline = new Pipeline([new FinanceSystem()]);
        $pipeline->tick($world, tick: 7, worldSeed: 1, ruleset: $this->ruleset(), intents: []);

        self::assertNull($world->components(Finances::class)->get($clubWithoutFinances));
    }

    public function testARetiredPlayerStopsBeingPaidWages(): void
    {
        $world = new WorldState();
        $club = $this->createClub($world, balanceCents: 100_000_000);
        $player = $this->createPlayerNearingRetirement($world, $club, wagePerWeekCents: 50_000);

        $pipeline = new Pipeline([new RetirementSystem(), new FinanceSystem()]);
        $ruleset = $this->ruleset();

        $retiredAtTick = null;
        $balanceAtRetirement = null;

        for ($tick = 1; $tick <= 2000; $tick++) {
            $pipeline->tick($world, tick: $tick, worldSeed: 1, ruleset: $ruleset, intents: []);

            if ($retiredAtTick === null && $world->components(Contract::class)->get($player) === null) {
                $retiredAtTick = $tick;
                $balanceAtRetirement = $world->components(Finances::class)->get($club)?->balanceCents;
            }

            // Trois jours de paie supplementaires (7*3=21 ticks) apres la
            // retraite : assez pour prouver qu'aucun salaire n'est plus verse.
            if ($retiredAtTick !== null && $tick >= $retiredAtTick + 21) {
                break;
            }
        }

        self::assertNotNull($retiredAtTick, 'le joueur devrait avoir pris sa retraite dans la fenetre de test');
        self::assertNotNull($balanceAtRetirement);

        $balanceAfter = $world->components(Finances::class)->get($club)?->balanceCents;
        self::assertSame($balanceAtRetirement, $balanceAfter);
    }

    private function createClub(WorldState $world, int $balanceCents): int
    {
        $club = $world->createEntity();
        $world->components(Club::class)->set($club, new Club('Club Test'));
        $world->components(Finances::class)->set($club, new Finances($balanceCents));

        return $club;
    }

    private function createContractedPlayer(WorldState $world, int $clubId, int $wagePerWeekCents): int
    {
        $player = $world->createEntity();
        $world->components(Person::class)->set($player, new Person('Joueur Test', new SimDate(0)));
        $world->components(Contract::class)->set($player, new Contract($clubId, $wagePerWeekCents));

        return $player;
    }

    private function createPlayerNearingRetirement(WorldState $world, int $clubId, int $wagePerWeekCents): int
    {
        $player = $world->createEntity();
        $birthDay = (int) round(1 - 36.0 * 365);

        $world->components(Person::class)->set($player, new Person('Joueur Test', new SimDate($birthDay)));
        $world->components(PlayerPhysicalSkills::class)->set($player, new PlayerPhysicalSkills(60, 60, 60, 60));
        $world->components(PlayerTechnicalSkills::class)->set($player, new PlayerTechnicalSkills(60, 60, 60, 60, 60, 60, 60));
        $world->components(PlayerMentalSkills::class)->set($player, new PlayerMentalSkills(60, 60, 60, 60, 60));
        $world->components(PlayerPotentials::class)->set($player, new PlayerPotentials(
            ceiling: 90,
            physicalPeakAge: 27,
            technicalPeakAge: 27,
            mentalPeakAge: 27,
            growthRate: 0.3,
            fragility: 0.8,
        ));
        $world->components(Contract::class)->set($player, new Contract($clubId, $wagePerWeekCents));

        return $player;
    }

    private function ruleset(): Ruleset
    {
        return new Ruleset('test', new Balance(finance: new FinanceBalance()));
    }
}
