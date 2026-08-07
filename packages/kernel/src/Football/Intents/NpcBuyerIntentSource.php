<?php

declare(strict_types=1);

namespace Flair\Kernel\Football\Intents;

use Flair\Kernel\Football\Components\Contract;
use Flair\Kernel\Football\Components\Negotiation;
use Flair\Kernel\Football\Components\PlayerMentalSkills;
use Flair\Kernel\Football\Components\PlayerPhysicalSkills;
use Flair\Kernel\Football\Components\PlayerTechnicalSkills;
use Flair\Kernel\Football\Components\Position;
use Flair\Kernel\Football\Support\PositionModel;

/**
 * Le cerveau d'achat des clubs PNJ : les etapes 1 a 3 et 6 (cote acheteur) de
 * docs/14- §5. C'est la logique ecrite au point 2 du chantier, sortie telle
 * quelle de `Football\TransferSystem` - elle n'y etait pas mauvaise, elle y
 * etait **cablee en dur**, donc irremplacable.
 *
 * Aucun tirage aleatoire : cette source est entierement deterministe, tout le
 * hasard de la negociation est cote vendeur (la rupture, dans le systeme).
 *
 * ## Ce qui est desormais de la politique, pas de la regle
 *
 * Trois comportements ne sont plus imposes par le noyau mais choisis ici, et
 * une autre source a le droit d'en decider autrement :
 *
 * - n'acheter qu'au **premier poste sous-effectif** (un humain peut acheter un
 *   attaquant alors qu'il lui manque un gardien) ;
 * - viser le meilleur rapport `qualite percue / prix estime` ;
 * - renoncer des que la contre-demande depasse le plafond fixe a l'ouverture.
 */
final class NpcBuyerIntentSource implements BuyerIntentSource
{
    /** @param array<int, true> $targetedPlayers */
    public function openingBid(TransferMarketView $view, int $buyerClubId, array $targetedPlayers): ?BidForPlayer
    {
        $needed = $this->neededPosition($view->squadByPosition[$buyerClubId] ?? [], $view->targets);

        if ($needed === null) {
            return null;
        }

        $target = $this->selectTarget($view, $buyerClubId, $needed, $targetedPlayers);

        if ($target === null) {
            return null;
        }

        [$playerId, $valuationCents] = $target;
        $transfer = $view->ctx->ruleset()->balance->transfer;

        return new BidForPlayer(
            $buyerClubId,
            $playerId,
            (int) round($valuationCents * $transfer->openingOfferShare),
            (int) round($valuationCents * $transfer->buyerFlexMargin),
        );
    }

    public function respondToCounter(
        TransferMarketView $view,
        int $negotiationId,
        Negotiation $negotiation,
        int $counterCents,
    ): ?RaiseTransferOffer {
        if ($counterCents > $negotiation->buyerCeilingCents) {
            return null;
        }

        $transfer = $view->ctx->ruleset()->balance->transfer;

        return new RaiseTransferOffer($negotiationId, (int) round(
            $negotiation->lastOfferCents
            + $transfer->buyerConcessionShare * ($counterCents - $negotiation->lastOfferCents),
        ));
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
     * @param array<int, true> $targetedPlayers
     * @return array{0: int, 1: int}|null [playerId, valorisation de l'acheteur]
     */
    private function selectTarget(
        TransferMarketView $view,
        int $buyerClubId,
        Position $needed,
        array $targetedPlayers,
    ): ?array {
        $best = null;
        $bestRatio = -1.0;

        foreach ($view->ctx->read(Contract::class)->entities() as $playerId) {
            $contract = $view->ctx->read(Contract::class)->get($playerId);

            if ($contract === null || $contract->clubId === $buyerClubId || isset($targetedPlayers[$playerId])) {
                continue;
            }

            if ($this->positionOf($view, $playerId) !== $needed) {
                continue;
            }

            $quality = $view->perceivedQuality($buyerClubId, $playerId);
            $value = $view->valuation($buyerClubId, $playerId, $needed, $buyerClubId);

            if ($quality === null || $value === null) {
                continue;
            }

            $ratio = $quality / max(1, $value);

            if ($ratio > $bestRatio) {
                $bestRatio = $ratio;
                $best = [$playerId, $value];
            }
        }

        return $best;
    }

    private function positionOf(TransferMarketView $view, int $playerId): ?Position
    {
        $physical = $view->ctx->read(PlayerPhysicalSkills::class)->get($playerId);
        $technical = $view->ctx->read(PlayerTechnicalSkills::class)->get($playerId);
        $mental = $view->ctx->read(PlayerMentalSkills::class)->get($playerId);

        if ($physical === null || $technical === null || $mental === null) {
            return null;
        }

        return PositionModel::bestPosition($physical, $technical, $mental);
    }
}
