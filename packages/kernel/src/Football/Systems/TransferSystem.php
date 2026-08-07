<?php

declare(strict_types=1);

namespace Flair\Kernel\Football\Systems;

use Flair\Kernel\Core\Messaging\DomainEvent;
use Flair\Kernel\Core\Pipeline\System;
use Flair\Kernel\Core\Pipeline\SystemContext;
use Flair\Kernel\Core\Ruleset\TransferBalance;
use Flair\Kernel\Core\Support\Rng;
use Flair\Kernel\Core\Support\SimDate;
use Flair\Kernel\Football\Components\BoardPatience;
use Flair\Kernel\Football\Components\Club;
use Flair\Kernel\Football\Components\Contract;
use Flair\Kernel\Football\Components\Employment;
use Flair\Kernel\Football\Components\Finances;
use Flair\Kernel\Football\Components\Negotiation;
use Flair\Kernel\Football\Components\Person;
use Flair\Kernel\Football\Components\PlayerMentalSkills;
use Flair\Kernel\Football\Components\PlayerPhysicalSkills;
use Flair\Kernel\Football\Components\PlayerPotentials;
use Flair\Kernel\Football\Components\PlayerTechnicalSkills;
use Flair\Kernel\Football\Components\Position;
use Flair\Kernel\Football\Components\Scout;
use Flair\Kernel\Football\Components\SeasonIncome;
use Flair\Kernel\Football\Events\ContractSigned;
use Flair\Kernel\Football\Events\TransferAgreed;
use Flair\Kernel\Football\Events\TransferCounterDemanded;
use Flair\Kernel\Football\Events\TransferNegotiationBroken;
use Flair\Kernel\Football\Events\TransferNegotiationOpened;
use Flair\Kernel\Football\Intents\BidForPlayer;
use Flair\Kernel\Football\Intents\BuyerIntentSource;
use Flair\Kernel\Football\Intents\TransferMarketView;
use Flair\Kernel\Football\Support\PositionModel;
use Flair\Kernel\Football\Support\SquadComposition;
use Flair\Kernel\Football\Support\WageModel;

/**
 * Le marche des transferts (docs/14-algorithmes.md §5,
 * docs/17-marche-transferts.md) : des clubs qui negocient sur **plusieurs
 * tours**, avec memoire, plutot qu'une enchere qui se resout d'un coup -
 * « economiquement correct et ludiquement mort », exactement ce que `14-` §5
 * interdit.
 *
 * ## Ce systeme arbitre, il ne decide pas pour l'acheteur (point 3)
 *
 * Toutes les decisions du club acheteur - ouvrir ou non, pour qui, a quel
 * prix, et comment repondre a une contre-demande - sortent d'une
 * `Football\Intents\BuyerIntentSource`. Le PNJ n'est plus qu'une
 * implementation parmi d'autres possibles ; l'agent humain de la Phase 5 en
 * sera une autre, sans reecriture (docs/11- §3).
 *
 * Ce qui reste ici : le **vendeur** (prix de reserve, acceptation, rupture,
 * patience), les **regles du marche** (un acheteur a la fois, un joueur a la
 * fois, une fenetre d'ouverture, un plafond de tours), et la **validation** des
 * intentions recues - docs/11- §3 : « mises en file, validees, puis
 * consommees ». Une source demande, elle n'ordonne pas.
 *
 * ## Un tour, deux ticks - et pourtant le meme nombre de ticks qu'avant
 *
 * L'acheteur ne peut pas repondre dans le tick qui produit la contre-demande :
 * les Faits d'un tick ne sont visibles qu'au suivant (docs/13- §2), donc un
 * humain repondrait a une question qu'il n'a pas encore lue. La contre-demande
 * est donc **posee** au tick N (`Negotiation::$pendingCounterCents`) et la
 * reponse **lue** au tick N+1, ou le vendeur reevalue dans la foulee.
 *
 * Le compte n'a pas bouge pour autant : une negociation avancait deja d'un
 * tour par tick au point 2. Seul l'instant ou le nombre de l'acheteur est
 * decide se deplace, de la fin du tick N au debut du tick N+1 - le meme tick,
 * puisqu'une negociation n'est evaluee qu'une fois par tick.
 *
 * ## Premier etat multi-tick du noyau
 *
 * Une negociation vit sur une entite dediee (`Negotiation`), creee par ce
 * systeme puis `set()` a nouveau a chaque tick suivant tant qu'elle n'est pas
 * resolue - le meme composant dans `creates()` **et** `writes()` du meme
 * systeme autorise `set()` sur cette entite a n'importe quel tick, pas
 * seulement celui de sa creation
 * (`Core\Pipeline\SystemAccess::requiresCreatedEntity()`).
 *
 * ## Ce qui n'est toujours pas fait (limites assumees)
 *
 * Pas de reputation (aucun composant n'existe) - la richesse relative du club
 * en tient lieu. Pas d'agence independante du joueur/agent - l'etape 5 de
 * `14-` §5 est repliee dans le prix de reserve du vendeur. Pas d'enchere
 * concurrente - le premier club qui cible un joueur le verrouille pour
 * l'annee. Pas de fenetre a bornes - un seul jour d'ouverture fixe,
 * `TransferBalance::$maxRounds` garantit a lui seul la cloture.
 *
 * L'argent, lui, est reel depuis le point 4 : `TransferAgreed` est **execute**
 * par `Football\FinanceSystem` au tick suivant, et le joueur change de club
 * par le `ContractSigned` emis en meme temps (cf. `agree()`).
 */
final class TransferSystem implements System
{
    public function __construct(private BuyerIntentSource $buyer)
    {
    }

    public function id(): string
    {
        return 'transfer';
    }

    /** @return list<class-string> */
    public function reads(): array
    {
        return [
            BoardPatience::class,
            Club::class,
            Contract::class,
            Employment::class,
            Finances::class,
            Negotiation::class,
            Person::class,
            PlayerMentalSkills::class,
            PlayerPhysicalSkills::class,
            PlayerPotentials::class,
            PlayerTechnicalSkills::class,
            Scout::class,
            SeasonIncome::class,
        ];
    }

    /** @return list<class-string> */
    public function writes(): array
    {
        return [Negotiation::class];
    }

    /** @return list<class-string> */
    public function removes(): array
    {
        return [Negotiation::class];
    }

    /** @return list<class-string> */
    public function creates(): array
    {
        return [Negotiation::class];
    }

    /** @return list<class-string> */
    public function subscribesTo(): array
    {
        return [];
    }

    public function handle(DomainEvent $event, SystemContext $ctx): void
    {
    }

    /**
     * L'avancement des negociations ouvertes precede l'ouverture des neuves,
     * pour qu'une negociation ouverte aujourd'hui ne soit jamais avancee le
     * jour meme.
     *
     * La vue est construite **une fois** et partagee par les deux passes :
     * aucune des deux ne deplace un joueur d'un club a un autre (le transfert
     * reel est le point 4), donc ses agregats de ligue restent vrais d'un bout
     * a l'autre du tick. Elle n'est construite que les jours ou quelque chose
     * se passe - une poignee par an - et jamais les ~356 autres.
     */
    public function update(SystemContext $ctx): void
    {
        $transfer = $ctx->ruleset()->balance->transfer;
        $openingDay = $ctx->tick % 365 === $transfer->negotiationOpeningDayOfYear;
        $negotiationIds = $ctx->read(Negotiation::class)->entities();

        if (!$openingDay && $negotiationIds === []) {
            return;
        }

        $view = $this->view($ctx, $transfer);

        foreach ($negotiationIds as $negotiationId) {
            $negotiation = $ctx->read(Negotiation::class)->get($negotiationId);

            if ($negotiation !== null) {
                $this->advance($ctx, $view, $negotiationId, $negotiation, $transfer);
            }
        }

        if ($openingDay) {
            $this->openNegotiations($ctx, $view, $transfer);
        }
    }

    private function view(SystemContext $ctx, TransferBalance $transfer): TransferMarketView
    {
        $clubIds = $ctx->read(Club::class)->entities();
        $squadByPosition = SquadComposition::byPosition($ctx);
        $targets = SquadComposition::targets($ctx->ruleset()->balance->position, $ctx->ruleset()->balance->contract);

        return new TransferMarketView(
            $ctx,
            new SimDate($ctx->tick),
            $this->observersByClub($ctx, $clubIds),
            $this->scarcityByPosition($clubIds, $squadByPosition, $targets, $transfer),
            $this->wealthByClub($ctx, $clubIds, $transfer),
            $squadByPosition,
            $targets,
        );
    }

    // --- Avancement -----------------------------------------------------

    /**
     * Un tour, dans l'ordre : la reponse de l'acheteur si on l'attendait, puis
     * l'evaluation du vendeur. Au plus un tirage RNG, celui de la rupture -
     * tout le reste est deterministe une fois la reponse connue.
     */
    private function advance(
        SystemContext $ctx,
        TransferMarketView $view,
        int $negotiationId,
        Negotiation $negotiation,
        TransferBalance $transfer,
    ): void {
        if ($negotiation->pendingCounterCents !== null) {
            $response = $this->buyer->respondToCounter(
                $view,
                $negotiationId,
                $negotiation,
                $negotiation->pendingCounterCents,
            );

            if ($response === null) {
                // Pas d'intention ce tick. Renoncement pour un PNJ (qui calcule
                // au lieu d'attendre), silence pour un humain - d'ou le delai.
                if ($ctx->tick - $negotiation->pendingSinceTick < $transfer->responseGraceTicks) {
                    return;
                }

                $this->abandon($ctx, $negotiationId, $negotiation);

                return;
            }

            $negotiation = new Negotiation(
                $negotiation->buyerClubId,
                $negotiation->sellerClubId,
                $negotiation->playerId,
                $negotiation->round + 1,
                $response->offerCents,
                $negotiation->reservePriceCents,
                $negotiation->buyerCeilingCents,
            );
        }

        if ($negotiation->lastOfferCents >= $negotiation->reservePriceCents) {
            $this->agree($ctx, $view, $negotiationId, $negotiation);

            return;
        }

        if ($negotiation->round > $transfer->maxRounds) {
            $this->abandon($ctx, $negotiationId, $negotiation);

            return;
        }

        $gap = $negotiation->reservePriceCents > 0
            ? ($negotiation->reservePriceCents - $negotiation->lastOfferCents) / $negotiation->reservePriceCents
            : 0.0;

        $breakProbability = max(0.0, min(1.0,
            ($transfer->breakBaseProbability
                + $transfer->breakRoundGrowth * ($negotiation->round - 1)
                + $transfer->breakGapWeight * $gap)
            * $this->patienceFactor($ctx, $negotiation->sellerClubId, $transfer),
        ));

        $rng = $ctx->rng($negotiationId);

        if ($this->unitInterval($rng) < $breakProbability) {
            $this->abandon($ctx, $negotiationId, $negotiation);

            return;
        }

        $counterCents = (int) round(
            $negotiation->lastOfferCents
            + $transfer->sellerConcessionShare * ($negotiation->reservePriceCents - $negotiation->lastOfferCents),
        );

        $ctx->emit(
            new TransferCounterDemanded($negotiationId, $negotiation->playerId, $negotiation->round, $counterCents),
            entityId: $negotiationId,
        );

        $ctx->write(Negotiation::class)->set($negotiationId, new Negotiation(
            $negotiation->buyerClubId,
            $negotiation->sellerClubId,
            $negotiation->playerId,
            $negotiation->round,
            $negotiation->lastOfferCents,
            $negotiation->reservePriceCents,
            $negotiation->buyerCeilingCents,
            pendingCounterCents: $counterCents,
            pendingSinceTick: $ctx->tick,
        ));
    }

    /**
     * Le facteur qui multiplie la probabilite de rupture d'un tour : `1.0` a
     * `patienceReference`, en-dessous de `1.0` pour un vendeur plus patient
     * que la reference, au-dessus pour un vendeur plus impatient. Un club
     * sans `BoardPatience` est lu comme exactement neutre.
     */
    private function patienceFactor(SystemContext $ctx, int $sellerClubId, TransferBalance $transfer): float
    {
        $patience = $ctx->read(BoardPatience::class)->get($sellerClubId);
        $level = $patience === null ? $transfer->patienceReference : $patience->level;

        if ($level <= 0) {
            return $transfer->patienceFactorMax;
        }

        return max(
            $transfer->patienceFactorMin,
            min($transfer->patienceFactorMax, $transfer->patienceReference / $level),
        );
    }

    /**
     * La conclusion, et **deux Faits pour deux conséquences distinctes**
     * (docs/17- point 4) : `TransferAgreed` porte l'indemnite, que
     * `Football\FinanceSystem` deplace au tick suivant ; `ContractSigned`
     * porte l'engagement, que `Football\SquadSystem` applique au meme tick
     * suivant. Ce systeme n'ecrit ni `Finances` ni `Contract` - il n'en est
     * proprietaire d'aucun, et chaque consequence suit son writer.
     *
     * Pas de `TransferCompleted` : un troisieme Fait ne franchirait aucun
     * seuil que ces deux-la ne franchissent pas (docs/16- §2), il n'ajouterait
     * que du bruit sur un chemin que ce projet surveille.
     *
     * **Le joueur signe un nouveau contrat, il n'herite pas de l'ancien** :
     * salaire au prix du marche tel que **l'acheteur** le percoit, duree tiree
     * comme a un renouvellement. Consequence voulue : `SquadSystem` remet
     * `signedOn` au tick de l'application, donc l'anciennete - et avec elle
     * l'`observationYears` du nouveau club - repart de zero. Un club vient
     * d'acheter quelqu'un qu'il n'a jamais eu sous les yeux, et il le juge
     * comme tel l'annee suivante.
     *
     * Le flux RNG est `rng($playerId)`, jamais `rng($negotiationId)` : c'est
     * celui dont `Football\ContractSystem` tire deja la duree d'un contrat, et
     * une entite distincte de la negociation, donc aucune collision avec le
     * tirage de rupture.
     */
    private function agree(SystemContext $ctx, TransferMarketView $view, int $negotiationId, Negotiation $negotiation): void
    {
        $ctx->emit(new TransferAgreed(
            $negotiationId,
            $negotiation->buyerClubId,
            $negotiation->sellerClubId,
            $negotiation->playerId,
            $negotiation->round,
            $negotiation->lastOfferCents,
        ), entityId: $negotiationId);

        $contract = $ctx->ruleset()->balance->contract;
        $quality = $view->perceivedQuality($negotiation->buyerClubId, $negotiation->playerId) ?? 0;

        $ctx->emit(new ContractSigned(
            $negotiation->playerId,
            $negotiation->buyerClubId,
            $negotiation->sellerClubId,
            WageModel::perWeekCents($quality, $contract),
            $ctx->tick + WageModel::contractDurationYears($ctx->rng($negotiation->playerId), $contract) * 365,
        ), entityId: $negotiation->playerId);

        $ctx->write(Negotiation::class)->remove($negotiationId);
    }

    private function abandon(SystemContext $ctx, int $negotiationId, Negotiation $negotiation): void
    {
        $ctx->emit(new TransferNegotiationBroken(
            $negotiationId,
            $negotiation->buyerClubId,
            $negotiation->sellerClubId,
            $negotiation->playerId,
            $negotiation->round,
        ), entityId: $negotiationId);

        $ctx->write(Negotiation::class)->remove($negotiationId);
    }

    // --- Ouverture --------------------------------------------------------

    private function openNegotiations(SystemContext $ctx, TransferMarketView $view, TransferBalance $transfer): void
    {
        $engagedBuyers = $this->engagedBuyerClubIds($ctx);
        $targetedPlayers = $this->targetedPlayerIds($ctx);

        foreach ($ctx->read(Club::class)->entities() as $buyerClubId) {
            if (isset($engagedBuyers[$buyerClubId])) {
                continue;
            }

            $bid = $this->buyer->openingBid($view, $buyerClubId, $targetedPlayers);

            if ($bid === null) {
                continue;
            }

            $admitted = $this->admit($ctx, $bid, $buyerClubId, $targetedPlayers);

            if ($admitted === null) {
                continue;
            }

            [$position, $sellerClubId] = $admitted;

            $negotiationId = $ctx->createEntity();
            $ctx->write(Negotiation::class)->set($negotiationId, new Negotiation(
                $buyerClubId,
                $sellerClubId,
                $bid->playerId,
                round: 1,
                lastOfferCents: $bid->offerCents,
                reservePriceCents: $this->reservePrice($view, $sellerClubId, $buyerClubId, $bid->playerId, $position, $transfer),
                buyerCeilingCents: $bid->ceilingCents,
            ));
            $ctx->emit(
                new TransferNegotiationOpened($negotiationId, $buyerClubId, $sellerClubId, $bid->playerId, $bid->offerCents),
                entityId: $negotiationId,
            );

            $engagedBuyers[$buyerClubId] = true;
            $targetedPlayers[$bid->playerId] = true;
        }
    }

    /**
     * La validation d'une intention d'achat, quelle qu'en soit la source
     * (docs/11- §3). Une source PNJ respecte deja ces regles par construction ;
     * une intention soumise de l'exterieur, non - et c'est ici, pas dans la
     * source, que le monde se protege.
     *
     * Le poste est **derive** des competences du joueur et jamais lu dans
     * l'intention : il entre dans le calcul du vendeur (rarete, profondeur
     * d'effectif), et un acheteur n'a pas a en fixer les entrees.
     *
     * @param array<int, true> $targetedPlayers
     * @return array{0: Position, 1: int}|null [poste derive, club vendeur], ou `null` si l'intention est rejetee
     */
    private function admit(SystemContext $ctx, BidForPlayer $bid, int $buyerClubId, array $targetedPlayers): ?array
    {
        if ($bid->buyerClubId !== $buyerClubId || isset($targetedPlayers[$bid->playerId])) {
            return null;
        }

        $contract = $ctx->read(Contract::class)->get($bid->playerId);

        if ($contract === null || $contract->clubId === $buyerClubId) {
            return null;
        }

        $physical = $ctx->read(PlayerPhysicalSkills::class)->get($bid->playerId);
        $technical = $ctx->read(PlayerTechnicalSkills::class)->get($bid->playerId);
        $mental = $ctx->read(PlayerMentalSkills::class)->get($bid->playerId);

        // `Person`/`PlayerPotentials` conditionnent la valorisation du vendeur :
        // sans eux le prix de reserve tomberait a zero et le transfert serait
        // gratuit.
        if ($physical === null || $technical === null || $mental === null) {
            return null;
        }

        if ($ctx->read(Person::class)->get($bid->playerId) === null
            || $ctx->read(PlayerPotentials::class)->get($bid->playerId) === null) {
            return null;
        }

        return [PositionModel::bestPosition($physical, $technical, $mental), $contract->clubId];
    }

    /**
     * Le seuil du vendeur : sa propre valorisation du joueur (a la richesse de
     * **l'acheteur reel** - seul proxy disponible pour la reputation de
     * l'acheteur, absente du noyau), remisee par la detresse financiere du
     * vendeur et la profondeur de son effectif a ce poste (docs/14- §5, etape
     * 4).
     */
    private function reservePrice(
        TransferMarketView $view,
        int $sellerClubId,
        int $buyerClubId,
        int $playerId,
        Position $position,
        TransferBalance $transfer,
    ): int {
        $valuation = $view->valuation($sellerClubId, $playerId, $position, $buyerClubId);

        if ($valuation === null) {
            return 0;
        }

        $finances = $view->ctx->read(Finances::class)->get($sellerClubId);
        $distress = $finances !== null && $transfer->financialDistressScaleCents > 0
            ? max(0.0, min(1.0, -$finances->balanceCents / $transfer->financialDistressScaleCents))
            : 0.0;
        $distressMultiplier = max(0.0, 1.0 - $transfer->financialDistressWeight * $distress);

        $surplus = max(0, ($view->squadByPosition[$sellerClubId][$position->value] ?? 0) - ($view->targets[$position->value] ?? 0));
        $depthMultiplier = max(
            $transfer->squadDepthDiscountFloor,
            1.0 - $transfer->squadDepthDiscountPerSurplusPlayer * $surplus,
        );

        return max(0, (int) round($valuation * $distressMultiplier * $depthMultiplier));
    }

    /**
     * `rarete_poste`, a l'echelle de la ligue : demande (les cibles de chaque
     * club, sommees) sur offre (l'effectif reellement a ce poste, tous clubs
     * confondus). `SquadComposition::targets()` ne varie pas par club, d'ou la
     * multiplication par le nombre de clubs plutot qu'une somme.
     *
     * @param list<int> $clubIds
     * @param array<int, array<string, int>> $squadByPosition
     * @param array<string, int> $targets
     * @return array<string, float>
     */
    private function scarcityByPosition(array $clubIds, array $squadByPosition, array $targets, TransferBalance $transfer): array
    {
        $supply = array_fill_keys(array_map(static fn (Position $p): string => $p->value, Position::cases()), 0);

        foreach ($squadByPosition as $heldByPosition) {
            foreach ($heldByPosition as $positionValue => $count) {
                $supply[$positionValue] = ($supply[$positionValue] ?? 0) + $count;
            }
        }

        $demandPerClub = \count($clubIds);
        $scarcity = [];

        foreach (Position::cases() as $position) {
            $demand = ($targets[$position->value] ?? 0) * $demandPerClub;
            $scarcity[$position->value] = max(
                $transfer->positionScarcityMin,
                min($transfer->positionScarcityMax, $demand / max(1, $supply[$position->value])),
            );
        }

        return $scarcity;
    }

    /**
     * La richesse d'un club relative a la ligue : son revenu de la saison
     * ecoulee sur la moyenne de la ligue. Seul proxy disponible pour la
     * « reputation de l'acheteur » de docs/14- §5, absente du noyau.
     *
     * @param list<int> $clubIds
     * @return array<int, float>
     */
    private function wealthByClub(SystemContext $ctx, array $clubIds, TransferBalance $transfer): array
    {
        $incomes = [];
        $total = 0;

        foreach ($clubIds as $clubId) {
            $income = $ctx->read(SeasonIncome::class)->get($clubId);
            $incomes[$clubId] = $income === null ? 0 : $income->cents;
            $total += $incomes[$clubId];
        }

        $mean = $clubIds !== [] ? $total / \count($clubIds) : 0;
        $wealth = [];

        foreach ($clubIds as $clubId) {
            $wealth[$clubId] = $mean > 0
                ? max($transfer->buyerWealthMin, min($transfer->buyerWealthMax, $incomes[$clubId] / $mean))
                : 1.0;
        }

        return $wealth;
    }

    /** @return array<int, true> clubId -> present, deja engage comme acheteur */
    private function engagedBuyerClubIds(SystemContext $ctx): array
    {
        $engaged = [];

        foreach ($ctx->read(Negotiation::class)->entities() as $negotiationId) {
            $negotiation = $ctx->read(Negotiation::class)->get($negotiationId);

            if ($negotiation !== null) {
                $engaged[$negotiation->buyerClubId] = true;
            }
        }

        return $engaged;
    }

    /** @return array<int, true> playerId -> present, deja cible par une negociation ouverte */
    private function targetedPlayerIds(SystemContext $ctx): array
    {
        $targeted = [];

        foreach ($ctx->read(Negotiation::class)->entities() as $negotiationId) {
            $negotiation = $ctx->read(Negotiation::class)->get($negotiationId);

            if ($negotiation !== null) {
                $targeted[$negotiation->playerId] = true;
            }
        }

        return $targeted;
    }

    /**
     * Le meilleur oeil de chaque club : son recruteur le mieux note, ou le
     * club lui-meme - le **pire** observateur possible, jamais un omniscient
     * (docs/17- point « perception/scouting »).
     *
     * @param list<int> $clubIds
     * @return array<int, array{id: int, judgement: int}>
     */
    private function observersByClub(SystemContext $ctx, array $clubIds): array
    {
        $unstaffed = $ctx->ruleset()->balance->perception->unstaffedJudgement;
        $observers = [];

        foreach ($clubIds as $clubId) {
            $observers[$clubId] = ['id' => $clubId, 'judgement' => $unstaffed];
        }

        foreach ($ctx->read(Scout::class)->entities() as $personId) {
            $scout = $ctx->read(Scout::class)->get($personId);
            $employment = $ctx->read(Employment::class)->get($personId);

            if ($scout === null || $employment === null || !isset($observers[$employment->clubId])) {
                continue;
            }

            $incumbent = $observers[$employment->clubId];

            if ($incumbent['id'] === $employment->clubId || $scout->judgement > $incumbent['judgement']) {
                $observers[$employment->clubId] = ['id' => $personId, 'judgement' => $scout->judgement];
            }
        }

        return $observers;
    }

    private function unitInterval(Rng $rng): float
    {
        return $rng->nextUint32() / 0xFFFFFFFF;
    }
}
