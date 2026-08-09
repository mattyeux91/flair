<?php

declare(strict_types=1);

namespace Flair\Kernel\Tests\Football\Systems;

use Flair\Kernel\Football\Support\PositionModel;
use Flair\Kernel\Core\Ecs\WorldState;
use Flair\Kernel\Core\Pipeline\Pipeline;
use Flair\Kernel\Core\Ruleset\Balance;
use Flair\Kernel\Core\Ruleset\FinanceBalance;
use Flair\Kernel\Core\Ruleset\Ruleset;
use Flair\Kernel\Core\Support\SimDate;
use Flair\Kernel\Football\Components\Club;
use Flair\Kernel\Football\Components\Contract;
use Flair\Kernel\Football\Components\Facilities;
use Flair\Kernel\Football\Components\Finances;
use Flair\Kernel\Core\Ruleset\PositionBalance;
use Flair\Kernel\Football\Components\Person;
use Flair\Kernel\Football\Components\PlayerMentalSkills;
use Flair\Kernel\Football\Components\PlayerPhysicalSkills;
use Flair\Kernel\Football\Components\PlayerPotentials;
use Flair\Kernel\Football\Components\Position;
use Flair\Kernel\Football\Components\PlayerTechnicalSkills;
use Flair\Kernel\Football\Components\SeasonIncome;
use Flair\Kernel\Football\Components\StandingsEntry;
use Flair\Kernel\Football\Events\ClubInvestedInFacilities;
use Flair\Kernel\Football\Events\SeasonConcluded;
use Flair\Kernel\Football\Events\TransferAgreed;
use Flair\Kernel\Core\Ruleset\InflationBalance;
use Flair\Kernel\Football\Singletons\MarketInflation;
use Flair\Kernel\Football\Singletons\MonetaryMass;
use Flair\Kernel\Football\Systems\FinanceSystem;
use Flair\Kernel\Football\Systems\RetirementSystem;
use Flair\Kernel\Football\Systems\SquadSystem;
use PHPUnit\Framework\TestCase;

final class FinanceSystemTest extends TestCase
{
    /**
     * Echeance hors d'atteinte de toutes les fenetres de ce fichier : ces
     * tests portent sur les flux monetaires, jamais sur l'expiration des
     * contrats (`Football\ContractSystem`, teste ailleurs). Une echeance
     * neutre evite qu'un joueur disparaisse de l'effectif au milieu d'une
     * mesure de masse salariale.
     */
    private const int NEVER_EXPIRES = 1_000_000;

    public function testSeasonConcludedCreditsEveryClubWithFinancesAndUpdatesMonetaryMass(): void
    {
        $world = new WorldState();
        $club = $this->createClub($world, balanceCents: 1_000_000);

        $this->concludeSeason($world, ranking: [$club], atTick: 5);

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

    /**
     * Le defaut `meritShare = 0.0` doit reproduire *exactement* le
     * comportement plat d'avant la repartition, division entiere comprise -
     * c'est ce qui garantit qu'introduire ce levier n'a pas deplace le monde
     * par defaut, et donc que les mesures des Phases 0/1 restent valides.
     */
    public function testTheDefaultMeritShareGivesEveryClubTheSameIncomeWhateverItsRank(): void
    {
        $world = new WorldState();
        $clubs = $this->createClubs($world, count: 4);

        $this->concludeSeason($world, ranking: array_reverse($clubs), atTick: 5);
        $this->runFinanceTick($world, tick: 5, finance: new FinanceBalance());

        foreach ($clubs as $club) {
            self::assertSame(70_000_000, $world->components(SeasonIncome::class)->get($club)?->cents);
        }
    }

    public function testMeritShareRewardsTheTopOfTheTableAtTheExpenseOfTheBottom(): void
    {
        $world = new WorldState();
        [$first, $second, $third] = $this->createClubs($world, count: 3);

        // Classement volontairement inverse de l'ordre des identifiants : ce
        // qui decide de la part est le rang, jamais l'ordre d'iteration du
        // ComponentStore.
        $this->concludeSeason($world, ranking: [$third, $second, $first], atTick: 5);
        $this->runFinanceTick($world, tick: 5, finance: new FinanceBalance(meritShare: 1.0));

        // pot = 70M x 3 = 210M, meritPool = 210M, poids 3/2/1 sur un total de 6.
        self::assertSame(105_000_000, $world->components(SeasonIncome::class)->get($third)?->cents);
        self::assertSame(70_000_000, $world->components(SeasonIncome::class)->get($second)?->cents);
        self::assertSame(35_000_000, $world->components(SeasonIncome::class)->get($first)?->cents);
    }

    /**
     * Premiere saison d'un monde : aucun match joue, donc aucun classement.
     * Le fallback doit rester une part egale, jamais une exception ni un
     * club privilegie par son identifiant.
     */
    public function testAnEmptyRankingFallsBackToAnEqualShare(): void
    {
        $world = new WorldState();
        $clubs = $this->createClubs($world, count: 4);

        $this->concludeSeason($world, ranking: [], atTick: 5);
        $this->runFinanceTick($world, tick: 5, finance: new FinanceBalance(meritShare: 1.0));

        $incomes = array_map(
            fn (int $club): ?int => $world->components(SeasonIncome::class)->get($club)?->cents,
            $clubs,
        );

        self::assertSame([70_000_000, 70_000_000, 70_000_000, 70_000_000], $incomes);
    }

    /**
     * L'enveloppe est un **plafond** : les restes de division entiere ne
     * sont pas injectes. Ce que `MonetaryMass` comptabilise doit etre la
     * somme des credits reels, jamais le pot theorique - sans quoi
     * `Harness\Tests\Regression\MonetaryConservationTest` verrait diverger la
     * masse monetaire et le solde des clubs.
     */
    public function testTheIntegerRemainderIsNotInjectedAndMonetaryMassTracksWhatWasActuallyCredited(): void
    {
        $world = new WorldState();
        $clubs = $this->createClubs($world, count: 4, balanceCents: 0);

        $this->concludeSeason($world, ranking: $clubs, atTick: 5);
        $this->runFinanceTick($world, tick: 5, finance: new FinanceBalance(
            clubIncomePerSeasonCents: 100,
            meritShare: 0.33,
        ));

        // pot = 400, meritPool = round(132) = 132, equalPool = 268 (67/club),
        // poids 4/3/2/1 sur un total de 10 -> 52/39/26/13.
        $incomes = array_map(
            fn (int $club): int => $world->components(SeasonIncome::class)->get($club)->cents ?? 0,
            $clubs,
        );
        self::assertSame([119, 106, 93, 80], $incomes);

        $credited = array_sum($incomes);
        self::assertSame(398, $credited);
        self::assertLessThan(400, $credited, 'le pot est un plafond, pas une quantite a epuiser');

        $balances = array_map(
            fn (int $club): int => $world->components(Finances::class)->get($club)->balanceCents ?? 0,
            $clubs,
        );
        self::assertSame($credited, array_sum($balances));
        self::assertSame($credited, $world->singleton(MonetaryMass::class)?->totalInjectionsCents);
    }

    /**
     * Une valeur hors bornes rendrait `equalPool` negatif, donc le dernier du
     * classement debiteur d'un revenu. Clampe plutot que rejete : le noyau
     * doit tourner 1 000 saisons sans surveillance.
     */
    public function testAMeritShareAboveOneIsClampedRatherThanProducingNegativeIncome(): void
    {
        $world = new WorldState();
        $clubs = $this->createClubs($world, count: 3);

        $this->concludeSeason($world, ranking: $clubs, atTick: 5);
        $this->runFinanceTick($world, tick: 5, finance: new FinanceBalance(meritShare: 2.5));

        foreach ($clubs as $club) {
            self::assertGreaterThan(0, $world->components(SeasonIncome::class)->get($club)?->cents);
        }

        self::assertSame(35_000_000, $world->components(SeasonIncome::class)->get($clubs[2])?->cents);
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

        $this->concludeSeason($world, ranking: [$clubWithoutFinances], atTick: 7);

        $pipeline = new Pipeline([new FinanceSystem()]);
        $pipeline->tick($world, tick: 7, worldSeed: 1, ruleset: $this->ruleset(), intents: []);

        self::assertNull($world->components(Finances::class)->get($clubWithoutFinances));
    }

    public function testARetiredPlayerStopsBeingPaidWages(): void
    {
        $world = new WorldState();
        $club = $this->createClub($world, balanceCents: 100_000_000);
        $player = $this->createPlayerNearingRetirement($world, $club, wagePerWeekCents: 50_000);

        // `SquadSystem` est indispensable ici : depuis qu'il possede la
        // relation d'emploi, c'est lui qui retire `Contract` en reagissant a
        // `PlayerRetired`, au tick suivant la retraite.
        $pipeline = new Pipeline([new SquadSystem(), new RetirementSystem(), new FinanceSystem()]);
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

    /**
     * Le coeur du point 4 (docs/17-) : une indemnite est un **mouvement
     * interne**, ni injection ni puits. La somme des soldes ne bouge pas, et
     * `MonetaryMass` non plus - c'est cette double assertion que casserait un
     * bookkeeping ajoute « pour la symetrie ».
     */
    public function testATransferMovesTheFeeBetweenClubsWithoutChangingTheMonetaryMass(): void
    {
        $world = new WorldState();
        $buyer = $this->createClub($world, 10_000_000);
        $seller = $this->createClub($world, 3_000_000);
        $world->setSingleton(new MonetaryMass(777, 333));

        $this->agreeTransfer($world, $buyer, $seller, feeCents: 4_000_000, atTick: 10);
        $this->runFinanceTick($world, tick: 10, finance: new FinanceBalance());

        self::assertSame(6_000_000, $this->balanceOf($world, $buyer));
        self::assertSame(7_000_000, $this->balanceOf($world, $seller));

        $mass = $world->singleton(MonetaryMass::class);
        self::assertNotNull($mass);
        self::assertSame(777, $mass->totalInjectionsCents, 'une indemnite n\'est pas une injection');
        self::assertSame(333, $mass->totalSinksCents, 'une indemnite n\'est pas un puits');
    }

    /**
     * Atomique ou nul : debiter sans pouvoir crediter detruirait de la monnaie
     * et casserait l'invariant de conservation.
     */
    public function testATransferWhoseSellerHasNoFinancesMovesNothingAtAll(): void
    {
        $world = new WorldState();
        $buyer = $this->createClub($world, 10_000_000);
        $vanished = $world->createEntity();

        $this->agreeTransfer($world, $buyer, $vanished, feeCents: 4_000_000, atTick: 10);
        $this->runFinanceTick($world, tick: 10, finance: new FinanceBalance());

        self::assertSame(10_000_000, $this->balanceOf($world, $buyer));
    }

    public function testSeveralTransfersInTheSameTickAreAllSettled(): void
    {
        $world = new WorldState();
        $first = $this->createClub($world, 10_000_000);
        $second = $this->createClub($world, 10_000_000);
        $third = $this->createClub($world, 10_000_000);

        $this->agreeTransfer($world, $first, $second, feeCents: 1_000_000, atTick: 10);
        $this->agreeTransfer($world, $second, $third, feeCents: 2_500_000, atTick: 10);
        $this->runFinanceTick($world, tick: 10, finance: new FinanceBalance());

        self::assertSame(9_000_000, $this->balanceOf($world, $first));
        self::assertSame(8_500_000, $this->balanceOf($world, $second), '+1 000 000 recu, -2 500 000 paye');
        self::assertSame(12_500_000, $this->balanceOf($world, $third));
    }

    /**
     * A cible zero - le defaut - toute la machinerie d'inflation est un no-op :
     * l'indice reste a 1, l'enveloppe vaut exactement ce qu'elle valait avant
     * le point 5, et le terme d'anticipation est nul.
     */
    public function testInflationIsAStrictNoOpAtTheDefaultTarget(): void
    {
        $world = new WorldState();
        $club = $this->createClub($world, balanceCents: 0);

        $this->concludeSeason($world, ranking: [$club], atTick: 5);
        $this->runFinanceTick($world, tick: 5, finance: new FinanceBalance(clubIncomePerSeasonCents: 70_000_000));

        self::assertSame(70_000_000, $this->balanceOf($world, $club));

        $inflation = $world->singleton(MarketInflation::class);
        self::assertNotNull($inflation);
        self::assertSame(1.0, $inflation->index);
        self::assertSame(0.0, $inflation->annualRate);
    }

    /**
     * L'indice avance de la cible a chaque saison achevee, et **tout** ce qui
     * est nominal le suit - l'enveloppe comme l'entretien. C'est ce qui en fait
     * un changement d'unite monetaire (docs/14- §5) et non une distorsion de
     * prix relatifs.
     */
    public function testTheIndexAdvancesByTheTargetAndCarriesEveryNominalAmount(): void
    {
        $world = new WorldState();
        $club = $this->createClub($world, balanceCents: 0);
        $world->components(Facilities::class)->set($club, new Facilities(1.0));

        $ruleset = new Balance(
            finance: new FinanceBalance(
                clubIncomePerSeasonCents: 70_000_000,
                facilityUpkeepPerQualityPointCents: 10_000_000,
                facilityInvestmentReserveCents: PHP_INT_MAX,
            ),
            inflation: new InflationBalance(marketInflationTarget: 0.10),
        );

        // Saison 1 : indice encore a 1, donc les montants nominaux.
        $this->concludeSeason($world, ranking: [$club], atTick: 5);
        (new Pipeline([new FinanceSystem()]))->tick($world, tick: 5, worldSeed: 1, ruleset: new Ruleset('test', $ruleset), intents: []);

        self::assertSame(70_000_000 - 10_000_000, $this->balanceOf($world, $club));
        self::assertSame(1.10, $world->singleton(MarketInflation::class)?->index);

        // Saison 2 : tout est porte a 1,10 - l'enveloppe et l'entretien.
        $this->concludeSeason($world, ranking: [$club], atTick: 12);
        (new Pipeline([new FinanceSystem()]))->tick($world, tick: 12, worldSeed: 1, ruleset: new Ruleset('test', $ruleset), intents: []);

        // 60 000 000 + 77 000 000 - 11 000 000, plus l'anticipation
        // (10 % de la masse de 60 000 000 relevee a la saison precedente).
        self::assertSame(60_000_000 + 77_000_000 + 6_000_000 - 11_000_000, $this->balanceOf($world, $club));
    }

    private function balanceOf(WorldState $world, int $clubId): int
    {
        return $world->components(Finances::class)->get($clubId)->balanceCents ?? 0;
    }

    private function agreeTransfer(WorldState $world, int $buyerClubId, int $sellerClubId, int $feeCents, int $atTick): void
    {
        $world->scheduler()->schedule(
            new TransferAgreed(
                negotiationId: 900 + $buyerClubId,
                buyerClubId: $buyerClubId,
                sellerClubId: $sellerClubId,
                playerId: 500,
                round: 2,
                agreedPriceCents: $feeCents,
            ),
            atTick: $atTick,
            systemIndex: 0,
            entityId: 900 + $buyerClubId,
            seq: 0,
        );
    }

    /**
     * Place un `SeasonConcluded` dans le Scheduler pour qu'il soit draine au
     * tick voulu - le Pipeline calcule son lot d'evenements entrants avant
     * qu'aucun systeme ne tourne, donc emettre depuis le test ne suffirait
     * pas.
     *
     * Prend le classement sous sa forme la plus lisible - une liste de
     * `clubId` - et le porte a la forme du Fait. Aucun test de ce fichier ne
     * regarde les points d'une saison : `FinanceSystem` ne pondere que par le
     * rang, et c'est precisement ce que `SeasonConcluded::ranking()` sert.
     *
     * @param list<int> $ranking
     */
    private function concludeSeason(WorldState $world, array $ranking, int $atTick): void
    {
        $world->scheduler()->schedule(
            new SeasonConcluded(
                competitionId: 1,
                finalTable: array_map(
                    static fn (int $clubId): StandingsEntry => new StandingsEntry($clubId),
                    $ranking,
                ),
            ),
            atTick: $atTick,
            systemIndex: 0,
            entityId: 1,
            seq: 0,
        );
    }

    private function runFinanceTick(WorldState $world, int $tick, FinanceBalance $finance): void
    {
        (new Pipeline([new FinanceSystem()]))->tick(
            $world,
            tick: $tick,
            worldSeed: 1,
            ruleset: new Ruleset('test', new Balance(finance: $finance)),
            intents: [],
        );
    }

    /** @return list<int> identifiants croissants */
    private function createClubs(WorldState $world, int $count, int $balanceCents = 0): array
    {
        $clubs = [];
        for ($i = 0; $i < $count; $i++) {
            $clubs[] = $this->createClub($world, $balanceCents);
        }

        return $clubs;
    }

    /**
     * L'entretien croit avec le **carre** de la qualite, pas lineairement
     * (`FinanceBalance::$facilityUpkeepPerQualityPointCents`) : a
     * `quality = 1.0`, un club paie exactement le tarif de base (point
     * neutre partage avec l'ancienne version lineaire) ; a `quality = 2.0`
     * (le maximum), il en paie quatre fois plus, pas deux.
     */
    public function testUpkeepGrowsWithTheSquareOfFacilityQualityAndIsCountedAsASink(): void
    {
        $world = new WorldState();
        $modest = $this->createClub($world, balanceCents: 0);
        $lavish = $this->createClub($world, balanceCents: 0);
        $world->components(Facilities::class)->set($modest, new Facilities(1.0));
        $world->components(Facilities::class)->set($lavish, new Facilities(2.0));

        // Reserve inatteignable : on isole l'entretien de l'investissement.
        $this->concludeSeason($world, ranking: [$modest, $lavish], atTick: 5);
        $this->runFinanceTick($world, tick: 5, finance: new FinanceBalance(
            facilityUpkeepPerQualityPointCents: 10_000_000,
            facilityInvestmentReserveCents: PHP_INT_MAX,
        ));

        self::assertSame(70_000_000 - 10_000_000, $world->components(Finances::class)->get($modest)?->balanceCents);
        self::assertSame(70_000_000 - 40_000_000, $world->components(Finances::class)->get($lavish)?->balanceCents);

        self::assertSame(50_000_000, $world->singleton(MonetaryMass::class)?->totalSinksCents);
    }

    public function testAClubInvestsItsSurplusAboveTheReserveAndEmitsTheFact(): void
    {
        $world = new WorldState();
        $club = $this->createClub($world, balanceCents: 0);
        $world->components(Facilities::class)->set($club, new Facilities(1.0));

        $this->concludeSeason($world, ranking: [$club], atTick: 5);
        $this->runFinanceTick($world, tick: 5, finance: new FinanceBalance(
            facilityUpkeepPerQualityPointCents: 0,
            facilityInvestmentReserveCents: 50_000_000,
            facilityInvestmentMaxPerSeasonCents: 40_000_000,
        ));

        // 70M de revenu, 50M de reserve -> 20M investis, sous le plafond.
        self::assertSame(50_000_000, $world->components(Finances::class)->get($club)?->balanceCents);
        self::assertSame(20_000_000, $world->singleton(MonetaryMass::class)?->totalSinksCents);

        $emitted = $this->investmentsEmitted($world);
        self::assertCount(1, $emitted);
        self::assertSame($club, $emitted[0]->clubId);
        self::assertSame(20_000_000, $emitted[0]->cents);
    }

    public function testInvestmentIsCappedPerSeason(): void
    {
        $world = new WorldState();
        $club = $this->createClub($world, balanceCents: 500_000_000);
        $world->components(Facilities::class)->set($club, new Facilities(1.0));

        $this->concludeSeason($world, ranking: [$club], atTick: 5);
        $this->runFinanceTick($world, tick: 5, finance: new FinanceBalance(
            facilityUpkeepPerQualityPointCents: 0,
            facilityInvestmentReserveCents: 50_000_000,
            facilityInvestmentMaxPerSeasonCents: 40_000_000,
        ));

        self::assertSame(40_000_000, $this->investmentsEmitted($world)[0]->cents ?? null);
    }

    public function testAClubBelowItsReserveDoesNotInvest(): void
    {
        $world = new WorldState();
        $club = $this->createClub($world, balanceCents: 0);
        $world->components(Facilities::class)->set($club, new Facilities(1.0));

        $this->concludeSeason($world, ranking: [$club], atTick: 5);
        $this->runFinanceTick($world, tick: 5, finance: new FinanceBalance(
            facilityUpkeepPerQualityPointCents: 0,
            facilityInvestmentReserveCents: 200_000_000,
        ));

        self::assertSame([], $this->investmentsEmitted($world));
        self::assertSame(70_000_000, $world->components(Finances::class)->get($club)?->balanceCents);
    }

    /**
     * Sans ce garde-fou, l'argent d'un club deja au plafond disparaitrait sans
     * contrepartie : `Football\FacilitiesSystem` clamperait la qualite en
     * silence et le club aurait paye pour rien.
     */
    public function testAClubAtMaximumQualityDoesNotBurnMoneyOnFacilities(): void
    {
        $world = new WorldState();
        $club = $this->createClub($world, balanceCents: 500_000_000);
        $world->components(Facilities::class)->set($club, new Facilities(Facilities::MAX_QUALITY));

        $this->concludeSeason($world, ranking: [$club], atTick: 5);
        $this->runFinanceTick($world, tick: 5, finance: new FinanceBalance(
            facilityUpkeepPerQualityPointCents: 0,
            facilityInvestmentReserveCents: 50_000_000,
        ));

        self::assertSame([], $this->investmentsEmitted($world));
        self::assertSame(500_000_000 + 70_000_000, $world->components(Finances::class)->get($club)?->balanceCents);
    }

    public function testAClubWithoutFacilitiesPaysNoUpkeepAndInvestsNothing(): void
    {
        $world = new WorldState();
        $club = $this->createClub($world, balanceCents: 500_000_000);

        $this->concludeSeason($world, ranking: [$club], atTick: 5);
        $this->runFinanceTick($world, tick: 5, finance: new FinanceBalance());

        self::assertSame([], $this->investmentsEmitted($world));
        self::assertSame(0, $world->singleton(MonetaryMass::class)?->totalSinksCents);
    }

    /** @return list<ClubInvestedInFacilities> */
    private function investmentsEmitted(WorldState $world): array
    {
        return array_values(array_filter(
            $world->outQueue()->pending(),
            static fn (object $event): bool => $event instanceof ClubInvestedInFacilities,
        ));
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
        $world->components(Contract::class)->set($player, new Contract($clubId, $wagePerWeekCents, new SimDate(self::NEVER_EXPIRES), new SimDate(1)));

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
            archetype: Position::Midfielder,
            ceilings: PositionModel::ceilings(90, Position::Midfielder, [], new PositionBalance()),
            physicalPeakAge: 27,
            technicalPeakAge: 27,
            mentalPeakAge: 27,
            growthRate: 0.3,
            fragility: 0.8,
        ));
        $world->components(Contract::class)->set($player, new Contract($clubId, $wagePerWeekCents, new SimDate(self::NEVER_EXPIRES), new SimDate(1)));

        return $player;
    }

    private function ruleset(): Ruleset
    {
        return new Ruleset('test', new Balance(finance: new FinanceBalance()));
    }
}
