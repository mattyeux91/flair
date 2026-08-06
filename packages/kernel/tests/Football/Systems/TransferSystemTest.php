<?php

declare(strict_types=1);

namespace Flair\Kernel\Tests\Football\Systems;

use Flair\Kernel\Core\Ecs\WorldState;
use Flair\Kernel\Core\Pipeline\Pipeline;
use Flair\Kernel\Core\Ruleset\Balance;
use Flair\Kernel\Core\Ruleset\ContractBalance;
use Flair\Kernel\Core\Ruleset\MarketValueBalance;
use Flair\Kernel\Core\Ruleset\PerceptionBalance;
use Flair\Kernel\Core\Ruleset\PositionBalance;
use Flair\Kernel\Core\Ruleset\Ruleset;
use Flair\Kernel\Core\Ruleset\TransferBalance;
use Flair\Kernel\Core\Support\SimDate;
use Flair\Kernel\Football\Components\BoardPatience;
use Flair\Kernel\Football\Components\Club;
use Flair\Kernel\Football\Components\Contract;
use Flair\Kernel\Football\Components\Finances;
use Flair\Kernel\Football\Components\Negotiation;
use Flair\Kernel\Football\Components\Person;
use Flair\Kernel\Football\Components\PlayerMentalSkills;
use Flair\Kernel\Football\Components\PlayerPhysicalSkills;
use Flair\Kernel\Football\Components\PlayerPotentials;
use Flair\Kernel\Football\Components\PlayerTechnicalSkills;
use Flair\Kernel\Football\Components\Position;
use Flair\Kernel\Football\Events\TransferAgreed;
use Flair\Kernel\Football\Events\TransferNegotiationBroken;
use Flair\Kernel\Football\Events\TransferNegotiationOpened;
use Flair\Kernel\Football\Support\PositionModel;
use Flair\Kernel\Football\Systems\TransferSystem;
use PHPUnit\Framework\TestCase;

/**
 * Les joueurs de ces tests ont un profil **plat** (les seize attributs a la
 * meme valeur) : `PositionModel::bestPosition()` les classe alors toujours
 * gardien (premier de l'enum, egalites departagees par l'ordre de
 * declaration), et leur qualite vaut exactement cette valeur a n'importe quel
 * poste - ce qui rend `MarketValueModel::value()` predictible a la main. A
 * qualite de reference (50), age au pic et contrat loin de l'echeance, la
 * valorisation vaut exactement `MarketValueBalance::$baseValueCents`
 * (5 000 000 par defaut) - cf. `MarketValueModelTest`.
 *
 * `positionScarcityMin`/`Max` sont pousses a `1.0` dans chaque `TransferBalance`
 * de ce fichier : ca fixe `rarete_poste` a 1.0 sans avoir a faire coincider
 * exactement l'offre et la demande d'un monde miniature.
 */
final class TransferSystemTest extends TestCase
{
    private const int OPENING_DAY = 200;

    public function testNoNegotiationOpensWithoutAPositionGap(): void
    {
        $world = new WorldState();
        $buyer = $this->createClub($world);
        // Deux gardiens : la cible par defaut pour ce poste (4-4-2, 20 joueurs).
        $this->createFlatPlayer($world, $buyer, tick: self::OPENING_DAY);
        $this->createFlatPlayer($world, $buyer, tick: self::OPENING_DAY);

        $this->runTransfer($world, tick: self::OPENING_DAY);

        self::assertSame([], $this->opened($world));
    }

    public function testANegotiationOpensOnTheOpeningDayWhenAClubHasAGap(): void
    {
        $world = new WorldState();
        $buyer = $this->createClub($world);
        $seller = $this->createClub($world);
        $target = $this->createFlatPlayer($world, $seller, tick: self::OPENING_DAY);

        $this->runTransfer($world, tick: self::OPENING_DAY);

        $opened = $this->opened($world);
        self::assertCount(1, $opened);
        self::assertSame($buyer, $opened[0]->buyerClubId);
        self::assertSame($seller, $opened[0]->sellerClubId);
        self::assertSame($target, $opened[0]->playerId);
        self::assertSame(3_750_000, $opened[0]->openingOfferCents, '0.75 x 5 000 000, la valorisation a qualite/age/contrat de reference');
    }

    public function testNoNegotiationOpensOutsideTheOpeningDay(): void
    {
        $world = new WorldState();
        $this->createClub($world);
        $seller = $this->createClub($world);
        $this->createFlatPlayer($world, $seller, tick: self::OPENING_DAY);

        $this->runTransfer($world, tick: self::OPENING_DAY - 1);

        self::assertSame([], $this->opened($world));
    }

    public function testAClubAlreadyEngagedAsBuyerDoesNotOpenASecondNegotiation(): void
    {
        $world = new WorldState();
        $buyer = $this->createClub($world);
        $seller = $this->createClub($world);
        $this->createFlatPlayer($world, $seller, tick: self::OPENING_DAY);

        $existing = $world->createEntity();
        $world->components(Negotiation::class)->set($existing, new Negotiation(
            $buyer,
            $seller,
            playerId: 999_999,
            round: 1,
            lastOfferCents: 500_000,
            reservePriceCents: 1_000_000,
            buyerCeilingCents: 2_000_000,
        ));

        $this->runTransfer($world, tick: self::OPENING_DAY, transfer: $this->neverBreaks());

        self::assertSame([], $this->opened($world), 'aucune nouvelle negociation ne doit s\'ouvrir');
        self::assertNotNull(
            $world->components(Negotiation::class)->get($existing),
            'la negociation existante doit survivre a l\'avancement de ce tick',
        );
    }

    public function testATargetAlreadyInAnOpenNegotiationIsNotTargetedAgain(): void
    {
        $world = new WorldState();
        $firstBuyer = $this->createClub($world);
        $this->createClub($world); // le second acheteur, non capture : seul son existence importe
        $seller = $this->createClub($world);
        $target = $this->createFlatPlayer($world, $seller, tick: self::OPENING_DAY);

        $existing = $world->createEntity();
        $world->components(Negotiation::class)->set($existing, new Negotiation(
            $firstBuyer,
            $seller,
            $target,
            round: 1,
            lastOfferCents: 500_000,
            reservePriceCents: 1_000_000,
            buyerCeilingCents: 2_000_000,
        ));

        $this->runTransfer($world, tick: self::OPENING_DAY, transfer: $this->neverBreaks());

        self::assertSame([], $this->opened($world), 'le second acheteur ne doit pas cibler un joueur deja en negociation');
    }

    public function testAnOfferAtOrAboveTheReservePriceIsAcceptedImmediately(): void
    {
        $world = new WorldState();
        $this->createClub($world);
        $seller = $this->createClub($world);
        $target = $this->createFlatPlayer($world, $seller, tick: self::OPENING_DAY);

        $transfer = new TransferBalance(positionScarcityMin: 1.0, positionScarcityMax: 1.0, openingOfferShare: 1.0);

        $this->runTransfer($world, tick: self::OPENING_DAY, transfer: $transfer);
        $negotiationId = $this->opened($world)[0]->negotiationId;

        $this->runTransfer($world, tick: self::OPENING_DAY + 1, transfer: $transfer);

        $agreed = $this->agreed($world);
        self::assertCount(1, $agreed);
        self::assertSame($negotiationId, $agreed[0]->negotiationId);
        self::assertSame($target, $agreed[0]->playerId);
        self::assertSame(1, $agreed[0]->round, 'une offre qui couvre deja la reserve ne doit pas attendre');
        self::assertSame(5_000_000, $agreed[0]->agreedPriceCents);
        self::assertNull($world->components(Negotiation::class)->get($negotiationId), 'un accord retire la negociation');
    }

    /**
     * Le risque ludique que ce point doit ecarter (docs/14- §5) : que la
     * negociation se resolve des le premier tour. Avec les defauts d'ouverture
     * (0.75) et de reserve (5 000 000), l'offre initiale (3 750 000) est sous
     * la reserve - `advance()` ne peut donc jamais accepter au tour 1. Les
     * ruptures aleatoires sont desactivees pour isoler cette garantie
     * structurelle de tout effet du hasard : la resolution qui suit (rupture
     * forcee a `maxRounds`, faute d'assez de concession pour combler l'ecart
     * en six tours) arrive quand meme a un tour strictement superieur a 1.
     */
    public function testNegotiationNeverResolvesOnTheFirstRound(): void
    {
        $world = new WorldState();
        $this->createClub($world);
        $seller = $this->createClub($world);
        $this->createFlatPlayer($world, $seller, tick: self::OPENING_DAY);

        $transfer = $this->neverBreaks();

        $this->runTransfer($world, tick: self::OPENING_DAY, transfer: $transfer);

        $resolved = [];

        for ($tick = self::OPENING_DAY + 1; $tick <= self::OPENING_DAY + 10 && $resolved === []; $tick++) {
            $this->runTransfer($world, tick: $tick, transfer: $transfer);
            $resolved = [...$this->agreed($world), ...$this->broken($world)];
        }

        self::assertNotSame([], $resolved, 'la negociation doit se resoudre dans la fenetre observee');
        self::assertGreaterThan(1, $resolved[0]->round, 'ne doit jamais se resoudre au premier tour');
    }

    public function testANegotiationBreaksWhenTheCounterExceedsTheBuyerCeiling(): void
    {
        $world = new WorldState();
        $this->createClub($world);
        $seller = $this->createClub($world);
        $this->createFlatPlayer($world, $seller, tick: self::OPENING_DAY);

        $transfer = new TransferBalance(
            positionScarcityMin: 1.0,
            positionScarcityMax: 1.0,
            breakBaseProbability: 0.0,
            breakRoundGrowth: 0.0,
            breakGapWeight: 0.0,
            openingOfferShare: 0.5,
            buyerFlexMargin: 0.6,
            sellerConcessionShare: 0.9,
        );

        $this->runTransfer($world, tick: self::OPENING_DAY, transfer: $transfer);
        $negotiationId = $this->opened($world)[0]->negotiationId;

        $this->runTransfer($world, tick: self::OPENING_DAY + 1, transfer: $transfer);

        $broken = $this->broken($world);
        self::assertCount(1, $broken);
        self::assertSame($negotiationId, $broken[0]->negotiationId);
        self::assertSame(1, $broken[0]->round);
        self::assertNull($world->components(Negotiation::class)->get($negotiationId));
    }

    public function testANegotiationIsForciblyBrokenAtMaxRounds(): void
    {
        $world = new WorldState();
        $this->createClub($world);
        $seller = $this->createClub($world);
        $this->createFlatPlayer($world, $seller, tick: self::OPENING_DAY);

        $transfer = new TransferBalance(
            positionScarcityMin: 1.0,
            positionScarcityMax: 1.0,
            breakBaseProbability: 0.0,
            breakRoundGrowth: 0.0,
            breakGapWeight: 0.0,
            openingOfferShare: 0.1,
            buyerFlexMargin: 10.0,
            sellerConcessionShare: 0.01,
            buyerConcessionShare: 0.01,
            maxRounds: 1,
        );

        $this->runTransfer($world, tick: self::OPENING_DAY, transfer: $transfer);
        $negotiationId = $this->opened($world)[0]->negotiationId;

        $this->runTransfer($world, tick: self::OPENING_DAY + 1, transfer: $transfer); // round 1 -> round 2
        $this->runTransfer($world, tick: self::OPENING_DAY + 2, transfer: $transfer); // round 2 > maxRounds(1)

        $broken = $this->broken($world);
        self::assertCount(1, $broken);
        self::assertSame($negotiationId, $broken[0]->negotiationId);
        self::assertSame(2, $broken[0]->round);
        self::assertNull($world->components(Negotiation::class)->get($negotiationId));
    }

    /**
     * Statistique, meme methode que `ContractSystemTest::mispricing()` : un
     * padding d'entites avant chaque essai varie l'`EntityId` de la
     * negociation, donc son flux RNG (`$ctx->rng($negotiationId)`). Toutes
     * choses egales par ailleurs (meme ecart offre/reserve, memes coefficients
     * globaux), seule la patience du vendeur change entre les deux groupes.
     */
    public function testLowerSellerPatienceIncreasesTheBreakRate(): void
    {
        $impatient = $this->breakCount(patienceLevel: 10);
        $patient = $this->breakCount(patienceLevel: 100);

        self::assertGreaterThan(
            $patient,
            $impatient,
            'un vendeur peu patient (niveau 10) doit rompre plus souvent qu\'un vendeur tres patient (niveau 100)',
        );
    }

    /** Sans `BoardPatience`, le comportement doit rester celui d'avant ce point : facteur neutre (1.0). */
    public function testASellerWithoutBoardPatienceBehavesAsAtTheReferenceLevel(): void
    {
        self::assertSame($this->breakCount(patienceLevel: 50), $this->breakCount(patienceLevel: null));
    }

    private const int PATIENCE_TRIALS = 200;

    private function breakCount(?int $patienceLevel): int
    {
        $broken = 0;

        for ($trial = 0; $trial < self::PATIENCE_TRIALS; $trial++) {
            $world = new WorldState();

            for ($padding = 0; $padding < $trial; $padding++) {
                $world->createEntity();
            }

            $buyer = $this->createClub($world);
            $seller = $this->createClub($world);

            if ($patienceLevel !== null) {
                $world->components(BoardPatience::class)->set($seller, new BoardPatience($patienceLevel));
            }

            $negotiationId = $world->createEntity();
            $world->components(Negotiation::class)->set($negotiationId, new Negotiation(
                $buyer,
                $seller,
                playerId: 999_999,
                round: 1,
                lastOfferCents: 3_750_000,
                reservePriceCents: 5_000_000,
                buyerCeilingCents: 10_000_000,
            ));

            // Hors du jour d'ouverture : seul l'avancement de cette negociation joue.
            $this->runTransfer($world, tick: self::OPENING_DAY - 1);

            if ($this->broken($world) !== []) {
                $broken++;
            }
        }

        return $broken;
    }

    public function testDeterminismGivenTheSameSeed(): void
    {
        $first = $this->runToResolution(seed: 7);
        $second = $this->runToResolution(seed: 7);

        self::assertSame($first, $second);
        self::assertNotSame([], $first);
    }

    /** @return list<array{string, int, int}> */
    private function runToResolution(int $seed): array
    {
        $world = new WorldState();
        $this->createClub($world);
        $seller = $this->createClub($world);
        $this->createFlatPlayer($world, $seller, tick: self::OPENING_DAY);

        $transfer = new TransferBalance(positionScarcityMin: 1.0, positionScarcityMax: 1.0);

        $this->runTransfer($world, tick: self::OPENING_DAY, seed: $seed, transfer: $transfer);

        $trace = [];

        for ($tick = self::OPENING_DAY + 1; $tick <= self::OPENING_DAY + 10 && $trace === []; $tick++) {
            $this->runTransfer($world, tick: $tick, seed: $seed, transfer: $transfer);

            foreach ($this->agreed($world) as $event) {
                $trace[] = ['agreed', $event->round, $event->agreedPriceCents];
            }

            foreach ($this->broken($world) as $event) {
                $trace[] = ['broken', $event->round, 0];
            }
        }

        return $trace;
    }

    private function neverBreaks(): TransferBalance
    {
        return new TransferBalance(
            positionScarcityMin: 1.0,
            positionScarcityMax: 1.0,
            breakBaseProbability: 0.0,
            breakRoundGrowth: 0.0,
            breakGapWeight: 0.0,
        );
    }

    private function runTransfer(
        WorldState $world,
        int $tick,
        int $seed = 1,
        ?TransferBalance $transfer = null,
    ): void {
        $ruleset = new Ruleset('test', new Balance(
            contract: new ContractBalance(),
            position: new PositionBalance(),
            perception: new PerceptionBalance(baseErrorPoints: 0.0),
            market: new MarketValueBalance(),
            transfer: $transfer ?? new TransferBalance(positionScarcityMin: 1.0, positionScarcityMax: 1.0),
        ));

        (new Pipeline([new TransferSystem()]))->tick($world, tick: $tick, worldSeed: $seed, ruleset: $ruleset, intents: []);
    }

    /** @return list<TransferNegotiationOpened> */
    private function opened(WorldState $world): array
    {
        return array_values(array_filter(
            $world->outQueue()->pending(),
            static fn (object $event): bool => $event instanceof TransferNegotiationOpened,
        ));
    }

    /** @return list<TransferAgreed> */
    private function agreed(WorldState $world): array
    {
        return array_values(array_filter(
            $world->outQueue()->pending(),
            static fn (object $event): bool => $event instanceof TransferAgreed,
        ));
    }

    /** @return list<TransferNegotiationBroken> */
    private function broken(WorldState $world): array
    {
        return array_values(array_filter(
            $world->outQueue()->pending(),
            static fn (object $event): bool => $event instanceof TransferNegotiationBroken,
        ));
    }

    private function createClub(WorldState $world, int $balanceCents = 100_000_000): int
    {
        $club = $world->createEntity();
        $world->components(Club::class)->set($club, new Club('Club Test'));
        $world->components(Finances::class)->set($club, new Finances($balanceCents));

        return $club;
    }

    /**
     * Un joueur au profil plat (seize attributs a 50) : toujours classe
     * gardien par `PositionModel::bestPosition()` (egalites -> premier de
     * l'enum), et note exactement 50 - la qualite de reference - a n'importe
     * quel poste. Age au pic (27 ans), contrat loin de l'echeance : la
     * valorisation vaut exactement `MarketValueBalance::$baseValueCents`.
     */
    private function createFlatPlayer(WorldState $world, int $clubId, int $tick, int $skill = 50): int
    {
        $peakAge = 27;
        $player = $world->createEntity();

        $world->components(Person::class)->set($player, new Person('Joueur Test', new SimDate($tick - $peakAge * 365)));
        $world->components(PlayerPhysicalSkills::class)->set($player, new PlayerPhysicalSkills($skill, $skill, $skill, $skill));
        $world->components(PlayerTechnicalSkills::class)->set($player, new PlayerTechnicalSkills($skill, $skill, $skill, $skill, $skill, $skill, $skill));
        $world->components(PlayerMentalSkills::class)->set($player, new PlayerMentalSkills($skill, $skill, $skill, $skill, $skill));
        $world->components(PlayerPotentials::class)->set($player, new PlayerPotentials(
            ceiling: 100,
            archetype: Position::Goalkeeper,
            ceilings: PositionModel::ceilings(100, Position::Goalkeeper, [], new PositionBalance()),
            physicalPeakAge: $peakAge,
            technicalPeakAge: $peakAge,
            mentalPeakAge: $peakAge,
            growthRate: 1.0,
            fragility: 1.0,
        ));
        $world->components(Contract::class)->set($player, new Contract($clubId, 1, new SimDate($tick + 1_000), new SimDate(0)));

        return $player;
    }
}
