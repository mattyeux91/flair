<?php

declare(strict_types=1);

namespace Flair\Kernel\Tests\Football\Systems;

use Flair\Kernel\Core\Ecs\WorldState;
use Flair\Kernel\Core\Pipeline\Pipeline;
use Flair\Kernel\Core\Ruleset\Balance;
use Flair\Kernel\Core\Ruleset\ContractBalance;
use Flair\Kernel\Core\Ruleset\PerceptionBalance;
use Flair\Kernel\Core\Ruleset\Ruleset;
use Flair\Kernel\Core\Support\SimDate;
use Flair\Kernel\Football\Components\Club;
use Flair\Kernel\Football\Components\Contract;
use Flair\Kernel\Football\Components\Employment;
use Flair\Kernel\Football\Components\Finances;
use Flair\Kernel\Football\Components\Person;
use Flair\Kernel\Football\Components\PlayerMentalSkills;
use Flair\Kernel\Football\Components\PlayerPhysicalSkills;
use Flair\Kernel\Football\Components\PlayerTechnicalSkills;
use Flair\Kernel\Football\Components\Scout;
use Flair\Kernel\Football\Components\SeasonIncome;
use Flair\Kernel\Football\Components\SquadMembership;
use Flair\Kernel\Football\Events\ContractExpired;
use Flair\Kernel\Football\Events\ContractSigned;
use Flair\Kernel\Football\Support\WageModel;
use Flair\Kernel\Football\Systems\ContractSystem;
use PHPUnit\Framework\TestCase;

/**
 * `ContractSystem` ne modifie aucun composant : tout ce qu'il produit sort par
 * l'OutQueue. Ces tests lisent donc des Faits, jamais l'etat du monde -
 * l'application est la responsabilite de `SquadSystem`, teste separement.
 *
 * **La perception est desactivee par defaut dans `runRenewal()`**
 * (`baseErrorPoints: 0`), pour que les cas qui portent sur la decision - budget,
 * cible d'effectif, solvabilite, postes - la testent sans bruit. Les cas qui
 * portent sur la perception elle-meme l'activent explicitement.
 */
final class ContractSystemTest extends TestCase
{
    private const int RENEWAL_DAY = 180;

    /** Assez de joueurs pour que l'erreur moyenne d'un observateur soit lisible. */
    private const int COHORT_SIZE = 40;

    /**
     * 60 plutot que 50 : l'erreur reste alors a l'interieur des bornes du
     * multiplicateur de salaire (`wageMultiplierMin/Max`), donc les ecarts se
     * lisent sur le salaire sans etre ecrases par le clamp.
     */
    private const int COHORT_SKILL = 60;

    public function testNothingHappensOutsideTheRenewalDay(): void
    {
        $world = new WorldState();
        $club = $this->createClub($world);
        $this->createPlayer($world, $club, skill: 50, expiresOn: 1);

        $this->runRenewal($world, tick: self::RENEWAL_DAY - 1);

        self::assertSame([], $world->outQueue()->pending());
    }

    public function testAnExpiringContractIsRenewedAtTheMarketWage(): void
    {
        $world = new WorldState();
        $club = $this->createClub($world);
        $player = $this->createPlayer($world, $club, skill: 80, expiresOn: 10, wagePerWeekCents: 1);

        $this->runRenewal($world, tick: self::RENEWAL_DAY);

        $signed = $this->signed($world);
        self::assertCount(1, $signed);
        self::assertSame($player, $signed[0]->playerId);
        self::assertSame($club, $signed[0]->clubId);
        self::assertSame($club, $signed[0]->previousClubId, 'un renouvellement porte le meme club des deux cotes');
        self::assertSame(
            WageModel::perWeekCents(80, new ContractBalance(), 1.0),
            $signed[0]->wagePerWeekCents,
            'le renouvellement passe au prix du marche, pas au salaire precedent',
        );
        self::assertGreaterThanOrEqual(self::RENEWAL_DAY + 2 * 365, $signed[0]->expiresOnEpochDay);
    }

    public function testAContractStillRunningIsLeftAlone(): void
    {
        $world = new WorldState();
        $club = $this->createClub($world);
        $this->createPlayer($world, $club, skill: 50, expiresOn: self::RENEWAL_DAY + 1);

        $this->runRenewal($world, tick: self::RENEWAL_DAY);

        self::assertSame([], $world->outQueue()->pending());
    }

    /**
     * Le tri par qualite decroissante est ce qui donne un sens a la cible
     * d'effectif : un club qui doit couper coupe par le bas.
     */
    public function testAClubOverItsTargetKeepsItsBestAndReleasesTheRest(): void
    {
        $world = new WorldState();
        $club = $this->createClub($world);
        $weak = $this->createPlayer($world, $club, skill: 30, expiresOn: 1);
        $strong = $this->createPlayer($world, $club, skill: 90, expiresOn: 1);

        $this->runRenewal($world, tick: self::RENEWAL_DAY, contract: new ContractBalance(targetSquadSize: 1));

        self::assertSame([$strong], array_map(static fn (ContractSigned $e): int => $e->playerId, $this->signed($world)));
        self::assertSame([$weak], array_map(static fn (ContractExpired $e): int => $e->playerId, $this->expired($world)));
    }

    /**
     * Le budget est une part du revenu de la saison ecoulee. Un club sans
     * `SeasonIncome` n'est pas contraint (premiere annee d'un monde) - c'est
     * pourquoi ce test en pose un explicitement.
     */
    public function testAClubRenewsOnlyWhatItsWageBudgetCovers(): void
    {
        $world = new WorldState();
        $contract = new ContractBalance(wageBudgetShare: 1.0);
        $oneSalary = WageModel::perWeekCents(50, $contract, 1.0) * 52;

        $club = $this->createClub($world, seasonIncomeCents: $oneSalary);
        $first = $this->createPlayer($world, $club, skill: 50, expiresOn: 1);
        $this->createPlayer($world, $club, skill: 50, expiresOn: 1);

        $this->runRenewal($world, tick: self::RENEWAL_DAY, contract: $contract);

        self::assertCount(1, $this->signed($world), 'le budget ne couvre qu\'un seul salaire');
        self::assertSame($first, $this->signed($world)[0]->playerId, 'a qualite egale, l\'EntityId le plus petit passe');
        self::assertCount(1, $this->expired($world));
    }

    public function testAClubWithADeficitSignsAPlayerLeftWithoutAClub(): void
    {
        $world = new WorldState();
        $poacher = $this->createClub($world);
        $unattached = $this->createUnattachedPlayer($world, skill: 70);

        $this->runRenewal($world, tick: self::RENEWAL_DAY);

        $signed = $this->signed($world);
        self::assertCount(1, $signed);
        self::assertSame($unattached, $signed[0]->playerId);
        self::assertSame($poacher, $signed[0]->clubId);
        self::assertNull($signed[0]->previousClubId, 'un joueur sans club n\'a pas de club precedent');
    }

    /**
     * La garde de solvabilite ne porte que sur les **arrivees** : un club dans
     * le rouge continue de prolonger les siens (cf. docblock du systeme).
     */
    public function testAClubInTheRedRenewsItsOwnPlayersButSignsNobody(): void
    {
        $world = new WorldState();
        $club = $this->createClub($world, balanceCents: -1);
        $own = $this->createPlayer($world, $club, skill: 50, expiresOn: 1);
        $this->createUnattachedPlayer($world, skill: 90);

        $this->runRenewal($world, tick: self::RENEWAL_DAY);

        self::assertSame([$own], array_map(static fn (ContractSigned $e): int => $e->playerId, $this->signed($world)));
    }

    /**
     * Un joueur libere par son club puis repris ailleurs le meme jour n'emet
     * **que** `ContractSigned` : c'est ce qui evite a `SquadSystem` de recevoir
     * deux Faits contradictoires sur la meme entite (cf. docblock de
     * `ContractExpired`).
     */
    public function testAReleasedPlayerPickedUpElsewhereDoesNotAlsoExpire(): void
    {
        $world = new WorldState();
        $seller = $this->createClub($world);
        $buyer = $this->createClub($world);
        $player = $this->createPlayer($world, $seller, skill: 60, expiresOn: 1);

        $this->runRenewal($world, tick: self::RENEWAL_DAY, contract: new ContractBalance(targetSquadSize: 0));

        // `targetSquadSize: 0` empeche aussi le repreneur de signer : on
        // verifie ici le cas symetrique, ou le joueur reste sans club.
        self::assertSame([], $this->signed($world));
        self::assertSame([$player], array_map(static fn (ContractExpired $e): int => $e->playerId, $this->expired($world)));

        $world = new WorldState();
        $seller = $this->createClub($world);
        $buyer = $this->createClub($world);
        $player = $this->createPlayer($world, $seller, skill: 60, expiresOn: 1);
        // Le vendeur est plein d'un joueur sous contrat, le repreneur est vide.
        $this->createPlayer($world, $seller, skill: 99, expiresOn: 100_000);

        $this->runRenewal($world, tick: self::RENEWAL_DAY, contract: new ContractBalance(targetSquadSize: 1));

        $signed = $this->signed($world);
        self::assertCount(1, $signed);
        self::assertSame($player, $signed[0]->playerId);
        self::assertSame($buyer, $signed[0]->clubId);
        self::assertSame($seller, $signed[0]->previousClubId);
        self::assertSame([], $this->expired($world), 'un joueur repris n\'est pas aussi declare sans club');
    }

    /**
     * Un retraite du jour a perdu ses competences plus tot dans le tick mais
     * garde son `Contract` jusqu'a ce que `SquadSystem` traite `PlayerRetired`.
     * Le remettre sur le marche ferait signer un retraite.
     */
    public function testAPlayerWithoutSkillsIsNeitherRenewedNorPutOnTheMarket(): void
    {
        $world = new WorldState();
        $club = $this->createClub($world);
        $player = $world->createEntity();
        $world->components(Person::class)->set($player, new Person('Retraite', new SimDate(0)));
        $world->components(Contract::class)->set($player, new Contract($club, 50_000, new SimDate(1), new SimDate(1)));

        $this->runRenewal($world, tick: self::RENEWAL_DAY);

        self::assertSame([], $world->outQueue()->pending());
    }

    /**
     * L'interrupteur du lot vu du systeme : a erreur nulle, le club paie
     * exactement le prix de la qualite vraie - donc tout ce que les autres cas
     * de ce fichier mesurent reste mesure sans bruit.
     */
    public function testWithoutPerceptionErrorTheClubPaysTheTruePrice(): void
    {
        $world = new WorldState();
        $club = $this->createClub($world);
        $this->createScout($world, $club, judgement: 1);
        $this->createPlayer($world, $club, skill: self::COHORT_SKILL, expiresOn: 1);

        $this->runRenewal($world, tick: self::RENEWAL_DAY);

        self::assertSame(
            WageModel::perWeekCents(self::COHORT_SKILL, new ContractBalance(), 1.0),
            $this->signed($world)[0]->wagePerWeekCents,
        );
    }

    /**
     * Le coeur du lot : un club se trompe sur **son propre** joueur, et le
     * mauvais prix qu'il en tire est la sanction de son staff. Sans ce cas, la
     * bascule pourrait etre entierement en place sans rien changer a une seule
     * decision.
     */
    public function testAClubMispricesItsOwnPlayerWhenItsScoutIsPoor(): void
    {
        $world = new WorldState();
        $club = $this->createClub($world);
        $this->createScout($world, $club, judgement: 5);
        $this->createPlayer($world, $club, skill: self::COHORT_SKILL, expiresOn: 1);

        $this->runRenewal($world, tick: self::RENEWAL_DAY, perception: new PerceptionBalance());

        self::assertNotSame(
            WageModel::perWeekCents(self::COHORT_SKILL, new ContractBalance(), 1.0),
            $this->signed($world)[0]->wagePerWeekCents,
        );
    }

    public function testABetterScoutMispricesLess(): void
    {
        $poor = $this->mispricing(judgement: 10);
        $median = $this->mispricing(judgement: 50);
        $excellent = $this->mispricing(judgement: 95);

        self::assertGreaterThan($median, $poor);
        self::assertGreaterThan($excellent, $median);
    }

    /**
     * Un club sans staff est le **pire** observateur du monde, pas un
     * omniscient : c'est l'affirmation de conception que le lot vient corriger.
     */
    public function testAClubWithoutAnyScoutJudgesWorseThanOneWithAGoodScout(): void
    {
        self::assertGreaterThan(
            $this->mispricing(judgement: 95),
            $this->mispricing(judgement: null),
        );
    }

    /**
     * L'`observationCount` de docs/12- §4, porte par l'anciennete : le meme
     * staff se trompe moins sur un joueur qu'il voit depuis dix ans que sur un
     * joueur arrive hier.
     */
    public function testTenureAtTheClubReducesTheError(): void
    {
        $newcomers = $this->mispricing(judgement: 50, tenureYears: 0);
        $veterans = $this->mispricing(judgement: 50, tenureYears: 10);

        self::assertGreaterThan($veterans, $newcomers);
    }

    /**
     * Un joueur sans club n'est observe par personne : le club qui l'evalue en
     * est reduit a son seul jugement, donc se trompe plus que sur un joueur
     * maison de meme niveau, avec le meme staff.
     */
    public function testAPlayerFromOutsideIsJudgedLessWellThanAnEqualPlayerAtHome(): void
    {
        $perception = new PerceptionBalance();
        $trueWage = WageModel::perWeekCents(self::COHORT_SKILL, new ContractBalance(), 1.0);

        $atHome = 0;
        $fromOutside = 0;

        for ($i = 0; $i < self::COHORT_SIZE; $i++) {
            $world = new WorldState();
            // Bourrage **avant** les entites utiles : le bruit depend des
            // identifiants, donc c'est ce decalage qui fait de chaque tour un
            // tirage different plutot que le meme tirage repete.
            for ($padding = 0; $padding < $i; $padding++) {
                $world->createEntity();
            }

            $club = $this->createClub($world);
            $this->createScout($world, $club, judgement: 50);
            $this->createPlayer($world, $club, skill: self::COHORT_SKILL, expiresOn: 1, signedOn: self::RENEWAL_DAY - 4 * 365);
            $this->createUnattachedPlayer($world, self::COHORT_SKILL);

            $this->runRenewal($world, tick: self::RENEWAL_DAY, perception: $perception);

            foreach ($this->signed($world) as $event) {
                $error = abs($event->wagePerWeekCents - $trueWage);

                if ($event->previousClubId === null) {
                    $fromOutside += $error;

                    continue;
                }

                $atHome += $error;
            }
        }

        self::assertGreaterThan(0, $atHome, 'les deux joueurs doivent bien avoir ete signes');
        self::assertGreaterThan($atHome, $fromOutside);
    }

    /**
     * L'erreur totale de prix d'un club sur une cohorte de joueurs de meme
     * niveau vrai - la mesure la plus directe de "ce staff se trompe de
     * combien".
     *
     * Un monde par joueur plutot qu'une cohorte dans un seul monde : le bruit
     * depend de l'`EntityId`, et des entites successives dans un meme monde
     * donneraient des tirages correles a leur rang.
     */
    private function mispricing(?int $judgement, int $tenureYears = 0): int
    {
        $perception = new PerceptionBalance();
        $trueWage = WageModel::perWeekCents(self::COHORT_SKILL, new ContractBalance(), 1.0);
        $total = 0;

        for ($i = 0; $i < self::COHORT_SIZE; $i++) {
            $world = new WorldState();

            for ($padding = 0; $padding < $i; $padding++) {
                $world->createEntity();
            }

            $club = $this->createClub($world);

            if ($judgement !== null) {
                $this->createScout($world, $club, $judgement);
            }

            $this->createPlayer(
                $world,
                $club,
                skill: self::COHORT_SKILL,
                expiresOn: 1,
                signedOn: self::RENEWAL_DAY - $tenureYears * 365,
            );

            $this->runRenewal($world, tick: self::RENEWAL_DAY, perception: $perception);
            $total += abs($this->signed($world)[0]->wagePerWeekCents - $trueWage);
        }

        return $total;
    }

    /**
     * Le vivier n'est plus filtre sur la qualite vraie (il n'a plus d'ordre
     * unique) : c'est desormais l'estimation impossible qui tient une entite
     * incomplete hors du marche. Meme protection, autre endroit - d'ou ce cas.
     */
    public function testAnIncompletePlayerIsNeverSignedFromThePool(): void
    {
        $world = new WorldState();
        $this->createClub($world);
        $halfPlayer = $world->createEntity();
        $world->components(Person::class)->set($halfPlayer, new Person('Sans technique', new SimDate(0)));
        $world->components(PlayerPhysicalSkills::class)->set($halfPlayer, new PlayerPhysicalSkills(60, 60, 60, 60));

        $this->runRenewal($world, tick: self::RENEWAL_DAY);

        self::assertSame([], $this->signed($world));
    }

    public function testAllocationIsStableForAGivenSeed(): void
    {
        $first = $this->allocationWithSeed(7);
        $second = $this->allocationWithSeed(7);

        self::assertSame($first, $second);
        self::assertNotSame([], $first);
    }

    /** @return list<array{int, int}> paires (joueur, club) signees */
    private function allocationWithSeed(int $seed): array
    {
        $world = new WorldState();
        $this->createClub($world);
        $this->createClub($world);
        $this->createClub($world);
        for ($i = 0; $i < 6; $i++) {
            $this->createUnattachedPlayer($world, skill: 40 + $i);
        }

        $this->runRenewal($world, tick: self::RENEWAL_DAY, seed: $seed, contract: new ContractBalance(targetSquadSize: 2));

        return array_map(
            static fn (ContractSigned $e): array => [$e->playerId, $e->clubId],
            $this->signed($world),
        );
    }

    private function runRenewal(
        WorldState $world,
        int $tick,
        int $seed = 1,
        ?ContractBalance $contract = null,
        ?PerceptionBalance $perception = null,
    ): void {
        $ruleset = new Ruleset('test', new Balance(
            contract: $contract ?? new ContractBalance(),
            perception: $perception ?? new PerceptionBalance(baseErrorPoints: 0.0),
        ));

        (new Pipeline([new ContractSystem()]))->tick($world, tick: $tick, worldSeed: $seed, ruleset: $ruleset, intents: []);
    }

    /** @return list<ContractSigned> */
    private function signed(WorldState $world): array
    {
        return array_values(array_filter(
            $world->outQueue()->pending(),
            static fn (object $event): bool => $event instanceof ContractSigned,
        ));
    }

    /** @return list<ContractExpired> */
    private function expired(WorldState $world): array
    {
        return array_values(array_filter(
            $world->outQueue()->pending(),
            static fn (object $event): bool => $event instanceof ContractExpired,
        ));
    }

    private function createClub(WorldState $world, int $balanceCents = 100_000_000, ?int $seasonIncomeCents = null): int
    {
        $club = $world->createEntity();
        $world->components(Club::class)->set($club, new Club('Club Test'));
        $world->components(Finances::class)->set($club, new Finances($balanceCents));

        if ($seasonIncomeCents !== null) {
            $world->components(SeasonIncome::class)->set($club, new SeasonIncome($seasonIncomeCents));
        }

        return $club;
    }

    private function createPlayer(
        WorldState $world,
        int $clubId,
        int $skill,
        int $expiresOn,
        int $wagePerWeekCents = 50_000,
        int $signedOn = self::RENEWAL_DAY,
    ): int {
        $player = $this->createUnattachedPlayer($world, $skill);
        $world->components(SquadMembership::class)->set($player, new SquadMembership($clubId));
        $world->components(Contract::class)->set($player, new Contract($clubId, $wagePerWeekCents, new SimDate($expiresOn), new SimDate($signedOn)));

        return $player;
    }

    /** Une personne employee comme scout par ce club - jamais un membre d'effectif. */
    private function createScout(WorldState $world, int $clubId, int $judgement): int
    {
        $person = $world->createEntity();
        $world->components(Person::class)->set($person, new Person('Scout Test', new SimDate(0)));
        $world->components(Employment::class)->set($person, new Employment($clubId));
        $world->components(Scout::class)->set($person, new Scout($judgement));

        return $person;
    }

    private function createUnattachedPlayer(WorldState $world, int $skill): int
    {
        $player = $world->createEntity();
        $world->components(Person::class)->set($player, new Person('Joueur Test', new SimDate(0)));
        $world->components(PlayerPhysicalSkills::class)->set($player, new PlayerPhysicalSkills($skill, $skill, $skill, $skill));
        $world->components(PlayerTechnicalSkills::class)->set($player, new PlayerTechnicalSkills($skill, $skill, $skill, $skill, $skill, $skill, $skill));
        $world->components(PlayerMentalSkills::class)->set($player, new PlayerMentalSkills($skill, $skill, $skill, $skill, $skill));

        return $player;
    }
}
