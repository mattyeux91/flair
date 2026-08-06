<?php

declare(strict_types=1);

namespace Flair\Kernel\Football\Systems;

use Flair\Kernel\Core\Messaging\DomainEvent;
use Flair\Kernel\Core\Pipeline\System;
use Flair\Kernel\Core\Pipeline\SystemContext;
use Flair\Kernel\Core\Ruleset\MarketValueBalance;
use Flair\Kernel\Core\Ruleset\PerceptionBalance;
use Flair\Kernel\Core\Ruleset\TransferBalance;
use Flair\Kernel\Core\Support\Rng;
use Flair\Kernel\Core\Support\SimDate;
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
use Flair\Kernel\Football\Events\TransferAgreed;
use Flair\Kernel\Football\Events\TransferCounterDemanded;
use Flair\Kernel\Football\Events\TransferNegotiationBroken;
use Flair\Kernel\Football\Events\TransferNegotiationOpened;
use Flair\Kernel\Football\Support\MarketValueModel;
use Flair\Kernel\Football\Support\PerceptionModel;
use Flair\Kernel\Football\Support\PositionModel;
use Flair\Kernel\Football\Support\SquadComposition;
use Flair\Kernel\Football\Support\WageModel;

/**
 * Le marche des transferts, point 2 du chantier (docs/14-algorithmes.md §5,
 * docs/17-marche-transferts.md) : des clubs qui negocient sur **plusieurs
 * tours**, avec memoire, plutot qu'une enchere qui se resout d'un coup -
 * « economiquement correct et ludiquement mort », exactement ce que `14-` §5
 * interdit.
 *
 * ## Premier etat multi-tick du noyau
 *
 * Jusqu'ici chaque systeme traite un tick en un seul passage. Une negociation
 * vit sur une entite dediee (`Negotiation`), creee par ce systeme puis
 * `set()` a nouveau a chaque tick suivant tant qu'elle n'est pas resolue - le
 * meme composant dans `creates()` **et** `writes()` du meme systeme autorise
 * `set()` sur cette entite a n'importe quel tick, pas seulement celui de sa
 * creation (`Core\Pipeline\SystemAccess::requiresCreatedEntity()`). Aucun
 * nouveau mecanisme de scheduler : l'OutQueue existant suffit, un Fait par
 * etape.
 *
 * « Memoire des tours precedents » est satisfaite par l'etat persistant
 * lui-meme (`round` + `lastOfferCents`), pas par un historique rejoue : les
 * Faits emis a chaque tour sont deja la matiere narrative en aval.
 *
 * ## Ce que ce point ne fait pas (limites assumees)
 *
 * Pas de reputation (aucun composant n'existe) - la richesse relative du
 * club en tient lieu, seul proxy disponible. Pas d'agence independante du
 * joueur/agent - l'etape 5 de `14-` §5 est repliee dans le prix de reserve du
 * vendeur (detresse financiere + profondeur d'effectif). Pas d'enchere
 * concurrente - le premier club qui cible un joueur le verrouille pour
 * l'annee. Pas de fenetre a bornes - un seul jour d'ouverture fixe,
 * `TransferBalance::$maxRounds` garantit a lui seul la cloture de toute
 * negociation. Pas d'argent reel - `TransferAgreed` est emis, le grand livre
 * se branche au point 4.
 *
 * ## Deux passes par tick
 *
 * D'abord l'**avancement** de toute negociation ouverte (chaque tick), puis,
 * le jour fixe de l'annee, l'**ouverture** de negociations neuves - dans cet
 * ordre pour qu'une negociation ouverte aujourd'hui ne soit jamais avancee le
 * jour meme de son ouverture (elle n'existe pas encore quand `entities()` est
 * lu pour l'avancement).
 */
final class TransferSystem implements System
{
    public function id(): string
    {
        return 'transfer';
    }

    /** @return list<class-string> */
    public function reads(): array
    {
        return [
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

    public function update(SystemContext $ctx): void
    {
        $transfer = $ctx->ruleset()->balance->transfer;

        $this->advanceNegotiations($ctx, $transfer);

        if ($ctx->tick % 365 === $transfer->negotiationOpeningDayOfYear) {
            $this->openNegotiations($ctx, $transfer);
        }
    }

    // --- Avancement -----------------------------------------------------

    private function advanceNegotiations(SystemContext $ctx, TransferBalance $transfer): void
    {
        foreach ($ctx->read(Negotiation::class)->entities() as $negotiationId) {
            $negotiation = $ctx->read(Negotiation::class)->get($negotiationId);

            if ($negotiation !== null) {
                $this->advance($ctx, $negotiationId, $negotiation, $transfer);
            }
        }
    }

    /**
     * Un tour = un tick, au plus deux tirages RNG, dans cet ordre fixe : la
     * rupture, puis rien d'autre - la suite est deterministe une fois la
     * contre-demande connue.
     */
    private function advance(SystemContext $ctx, int $negotiationId, Negotiation $negotiation, TransferBalance $transfer): void
    {
        if ($negotiation->lastOfferCents >= $negotiation->reservePriceCents) {
            $this->agree($ctx, $negotiationId, $negotiation);

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
            $transfer->breakBaseProbability
            + $transfer->breakRoundGrowth * ($negotiation->round - 1)
            + $transfer->breakGapWeight * $gap,
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

        if ($counterCents > $negotiation->buyerCeilingCents) {
            $this->abandon($ctx, $negotiationId, $negotiation);

            return;
        }

        $nextOfferCents = (int) round(
            $negotiation->lastOfferCents + $transfer->buyerConcessionShare * ($counterCents - $negotiation->lastOfferCents),
        );

        $ctx->write(Negotiation::class)->set($negotiationId, new Negotiation(
            $negotiation->buyerClubId,
            $negotiation->sellerClubId,
            $negotiation->playerId,
            $negotiation->round + 1,
            $nextOfferCents,
            $negotiation->reservePriceCents,
            $negotiation->buyerCeilingCents,
        ));
    }

    private function agree(SystemContext $ctx, int $negotiationId, Negotiation $negotiation): void
    {
        $ctx->emit(new TransferAgreed(
            $negotiationId,
            $negotiation->buyerClubId,
            $negotiation->sellerClubId,
            $negotiation->playerId,
            $negotiation->round,
            $negotiation->lastOfferCents,
        ), entityId: $negotiationId);

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

    private function openNegotiations(SystemContext $ctx, TransferBalance $transfer): void
    {
        $clubIds = $ctx->read(Club::class)->entities();

        if ($clubIds === []) {
            return;
        }

        $observers = $this->observersByClub($ctx, $clubIds);
        $positions = $ctx->ruleset()->balance->position;
        $contract = $ctx->ruleset()->balance->contract;
        $perception = $ctx->ruleset()->balance->perception;
        $market = $ctx->ruleset()->balance->market;

        $squadByPosition = SquadComposition::byPosition($ctx);
        $targets = SquadComposition::targets($positions, $contract);
        $scarcity = $this->scarcityByPosition($clubIds, $squadByPosition, $targets, $transfer);
        $wealth = $this->wealthByClub($ctx, $clubIds, $transfer);
        $engagedBuyers = $this->engagedBuyerClubIds($ctx);
        $targetedPlayers = $this->targetedPlayerIds($ctx);
        $now = new SimDate($ctx->tick);

        foreach ($clubIds as $buyerClubId) {
            if (isset($engagedBuyers[$buyerClubId])) {
                continue;
            }

            $needed = $this->neededPosition($squadByPosition[$buyerClubId] ?? [], $targets);

            if ($needed === null) {
                continue;
            }

            $target = $this->selectTarget(
                $ctx,
                $buyerClubId,
                $needed,
                $observers[$buyerClubId],
                $perception,
                $market,
                $scarcity,
                $wealth,
                $now,
                $targetedPlayers,
            );

            if ($target === null) {
                continue;
            }

            [$playerId, $sellerClubId, $buyerValuationCents] = $target;

            $openingOfferCents = (int) round($buyerValuationCents * $transfer->openingOfferShare);
            $buyerCeilingCents = (int) round($buyerValuationCents * $transfer->buyerFlexMargin);
            $reservePriceCents = $this->reservePrice(
                $ctx,
                $sellerClubId,
                $buyerClubId,
                $playerId,
                $needed,
                $observers[$sellerClubId],
                $perception,
                $market,
                $scarcity,
                $wealth,
                $now,
                $squadByPosition,
                $targets,
                $transfer,
            );

            $negotiationId = $ctx->createEntity();
            $ctx->write(Negotiation::class)->set($negotiationId, new Negotiation(
                $buyerClubId,
                $sellerClubId,
                $playerId,
                round: 1,
                lastOfferCents: $openingOfferCents,
                reservePriceCents: $reservePriceCents,
                buyerCeilingCents: $buyerCeilingCents,
            ));
            $ctx->emit(
                new TransferNegotiationOpened($negotiationId, $buyerClubId, $sellerClubId, $playerId, $openingOfferCents),
                entityId: $negotiationId,
            );

            $engagedBuyers[$buyerClubId] = true;
            $targetedPlayers[$playerId] = true;
        }
    }

    /**
     * Le premier poste sous-effectif de ce club, ordre de declaration de
     * `Position` (gardien d'abord) - meme regle que
     * `YouthIntakeSystem::neededArchetype()`. `null` si l'effectif comble deja
     * chaque cible : le club ne participe pas cette annee.
     *
     * @param array<string, int> $held
     * @param array<string, int> $targets
     */
    private function neededPosition(array $held, array $targets): ?Position
    {
        foreach (Position::cases() as $position) {
            if (($held[$position->value] ?? 0) < ($targets[$position->value] ?? 0)) {
                return $position;
            }
        }

        return null;
    }

    /**
     * Le meilleur joueur, au sens `qualite percue / prix estime`, parmi ceux
     * sous contrat ailleurs a ce poste et non deja cibles - ou `null`.
     * L'iteration suit `Contract::entities()` (EntityId croissant), donc a
     * egalite stricte le premier rencontre l'emporte sans depart supplementaire.
     *
     * @param array{id: int, judgement: int} $buyerObserver
     * @param array<string, float> $scarcity
     * @param array<int, float> $wealth
     * @param array<int, true> $targetedPlayers
     * @return array{0: int, 1: int, 2: int}|null [playerId, sellerClubId, valorisation acheteur]
     */
    private function selectTarget(
        SystemContext $ctx,
        int $buyerClubId,
        Position $needed,
        array $buyerObserver,
        PerceptionBalance $perception,
        MarketValueBalance $market,
        array $scarcity,
        array $wealth,
        SimDate $now,
        array $targetedPlayers,
    ): ?array {
        $best = null;
        $bestRatio = -1.0;

        foreach ($ctx->read(Contract::class)->entities() as $playerId) {
            $contract = $ctx->read(Contract::class)->get($playerId);

            if ($contract === null || $contract->clubId === $buyerClubId || isset($targetedPlayers[$playerId])) {
                continue;
            }

            $physical = $ctx->read(PlayerPhysicalSkills::class)->get($playerId);
            $technical = $ctx->read(PlayerTechnicalSkills::class)->get($playerId);
            $mental = $ctx->read(PlayerMentalSkills::class)->get($playerId);
            $potentials = $ctx->read(PlayerPotentials::class)->get($playerId);
            $person = $ctx->read(Person::class)->get($playerId);

            if ($physical === null || $technical === null || $mental === null || $potentials === null || $person === null) {
                continue;
            }

            if (PositionModel::bestPosition($physical, $technical, $mental) !== $needed) {
                continue;
            }

            $quality = $this->perceived($ctx, $buyerObserver, $playerId, $perception);

            if ($quality === null) {
                continue;
            }

            $value = MarketValueModel::value(
                $quality,
                $now->yearsSince($person->birthDate),
                $potentials,
                $now,
                $contract->expiresOn,
                $scarcity[$needed->value] ?? 1.0,
                $wealth[$buyerClubId] ?? 1.0,
                1.0,
                $market,
            );

            $ratio = $quality / max(1, $value);

            if ($ratio > $bestRatio) {
                $bestRatio = $ratio;
                $best = [$playerId, $contract->clubId, $value];
            }
        }

        return $best;
    }

    /**
     * Le seuil du vendeur : sa propre valorisation du joueur (a la richesse de
     * **l'acheteur reel** - seul proxy disponible pour la reputation de
     * l'acheteur, absente du noyau), remisee par la detresse financiere du
     * vendeur et la profondeur de son effectif a ce poste (docs/14- §5, etape
     * 4).
     *
     * @param array{id: int, judgement: int} $sellerObserver
     * @param array<string, float> $scarcity
     * @param array<int, float> $wealth
     * @param array<int, array<string, int>> $squadByPosition
     * @param array<string, int> $targets
     */
    private function reservePrice(
        SystemContext $ctx,
        int $sellerClubId,
        int $buyerClubId,
        int $playerId,
        Position $position,
        array $sellerObserver,
        PerceptionBalance $perception,
        MarketValueBalance $market,
        array $scarcity,
        array $wealth,
        SimDate $now,
        array $squadByPosition,
        array $targets,
        TransferBalance $transfer,
    ): int {
        $potentials = $ctx->read(PlayerPotentials::class)->get($playerId);
        $person = $ctx->read(Person::class)->get($playerId);
        $contract = $ctx->read(Contract::class)->get($playerId);

        if ($potentials === null || $person === null || $contract === null) {
            return 0;
        }

        $quality = $this->perceived($ctx, $sellerObserver, $playerId, $perception) ?? 0;

        $valuation = MarketValueModel::value(
            $quality,
            $now->yearsSince($person->birthDate),
            $potentials,
            $now,
            $contract->expiresOn,
            $scarcity[$position->value] ?? 1.0,
            $wealth[$buyerClubId] ?? 1.0,
            1.0,
            $market,
        );

        $finances = $ctx->read(Finances::class)->get($sellerClubId);
        $distress = $finances !== null && $transfer->financialDistressScaleCents > 0
            ? max(0.0, min(1.0, -$finances->balanceCents / $transfer->financialDistressScaleCents))
            : 0.0;
        $distressMultiplier = max(0.0, 1.0 - $transfer->financialDistressWeight * $distress);

        $surplus = max(0, ($squadByPosition[$sellerClubId][$position->value] ?? 0) - ($targets[$position->value] ?? 0));
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

    // --- Perception (meme motif que Football\ContractSystem::perceived()) --

    /**
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

    /** @param array{id: int, judgement: int} $observer */
    private function perceived(SystemContext $ctx, array $observer, int $playerId, PerceptionBalance $perception): ?int
    {
        $physical = $ctx->read(PlayerPhysicalSkills::class)->get($playerId);
        $technical = $ctx->read(PlayerTechnicalSkills::class)->get($playerId);
        $mental = $ctx->read(PlayerMentalSkills::class)->get($playerId);

        if ($physical === null || $technical === null || $mental === null) {
            return null;
        }

        $observations = $this->observationYears($ctx, $observer['id'], $playerId);

        return PerceptionModel::estimate(
            WageModel::quality($physical, $technical, $mental),
            $ctx->stableHash($observer['id'], $playerId, $observations),
            $observations,
            $observer['judgement'],
            $perception,
        );
    }

    private function observationYears(SystemContext $ctx, int $observerId, int $playerId): int
    {
        $clubId = $ctx->read(Employment::class)->get($observerId)->clubId ?? $observerId;
        $contract = $ctx->read(Contract::class)->get($playerId);

        if ($contract === null || $contract->clubId !== $clubId) {
            return 0;
        }

        return max(0, (int) (($ctx->tick - $contract->signedOn->epochDay) / 365));
    }

    private function unitInterval(Rng $rng): float
    {
        return $rng->nextUint32() / 0xFFFFFFFF;
    }
}
