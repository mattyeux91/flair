<?php

declare(strict_types=1);

namespace Flair\Kernel\Football\Intents;

use Flair\Kernel\Football\Components\Contract;
use Flair\Kernel\Football\Components\Finances;
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
 * Quatre comportements ne sont plus imposes par le noyau mais choisis ici, et
 * une autre source a le droit d'en decider autrement :
 *
 * - considerer **tous** les postes en deficit a la fois, en ponderant l'urgence
 *   de chacun (un humain peut acheter un attaquant alors qu'il lui manque un
 *   gardien - ici le club le peut aussi, si l'attaquant est une assez bonne
 *   affaire) ;
 * - viser le meilleur rapport `qualite percue / prix estime` ;
 * - renoncer des que la contre-demande depasse le plafond fixe a l'ouverture ;
 * - ne jamais s'engager au-dela de ce qu'il a en caisse.
 *
 * ## Pourquoi le premier point a change (2026-08-08)
 *
 * Cette source ne visait auparavant que le **premier** poste sous-effectif
 * dans l'ordre de declaration de `Position`, gardien d'abord. Mesure a 6
 * graines x 20 ans : **0,5 % des transferts concernaient un attaquant** (1 sur
 * 197), contre 64 % de defenseurs, alors que les attaquants sont 20 % de la
 * population sous contrat.
 *
 * La cause n'etait pas l'ordre en lui-meme mais ce qu'il devient quand tout le
 * monde est en deficit partout : `SquadComposition::targets()` somme a 22 pour
 * un `targetSquadSize` de 20, et l'effectif reel tourne autour de 16,5. Un
 * club est donc court a **chaque** poste en permanence, « le premier poste en
 * deficit » n'est plus une priorite mais une constante - et comme un club
 * n'ouvre qu'une negociation par an (`TransferSystem::openNegotiations()`),
 * cette constante etait tout son marche.
 *
 * D'ou un classement par **ampleur relative** du deficit plutot que par ordre
 * de declaration. C'est aussi le motif deja tenu par la decision sœur,
 * `Football\ContractSystem::pick()`, qui filtre ses candidats sur « ce poste
 * est en deficit » sans jamais imposer d'ordre entre postes.
 */
final class NpcBuyerIntentSource implements BuyerIntentSource
{
    /** @param array<int, true> $targetedPlayers */
    public function openingBid(TransferMarketView $view, int $buyerClubId, array $targetedPlayers): ?BidForPlayer
    {
        $transfer = $view->ctx->ruleset()->balance->transfer;
        $needs = $this->needWeights(
            $view->squadByPosition[$buyerClubId] ?? [],
            $view->targets,
            $transfer->needWeightSpan,
        );

        if ($needs === []) {
            return null;
        }

        $target = $this->selectTarget($view, $buyerClubId, $needs, $targetedPlayers);

        if ($target === null) {
            return null;
        }

        [$playerId, $valuationCents] = $target;

        $opening = (int) round($valuationCents * $transfer->openingOfferShare);
        $ceiling = $this->affordableCeiling($view, $buyerClubId, (int) round($valuationCents * $transfer->buyerFlexMargin));

        // Un club qui ne peut deja pas payer son offre d'ouverture n'ouvre pas
        // une negociation qu'il ne peut pas conclure : il s'abstient cette
        // annee plutot que d'occuper une place et un joueur pour rien.
        if ($opening > $ceiling) {
            return null;
        }

        return new BidForPlayer($buyerClubId, $playerId, $opening, $ceiling);
    }

    /**
     * Le plafond, borne par ce que le club a reellement en caisse
     * (docs/17- point 4 : depuis que les indemnites se paient, un plafond tire
     * de la seule valorisation laisserait un club sans le sou acheter).
     *
     * C'est une **politique de PNJ, pas une regle du systeme** : rien dans
     * `Football\TransferSystem` n'interdit de se ruiner, et une source humaine
     * garde ce droit. Un club sans `Finances` n'est pas contraint - aucune
     * donnee ne justifie de lui refuser quoi que ce soit, meme choix que
     * `ContractBalance::$wageBudgetShare` face a un club sans `SeasonIncome`.
     */
    private function affordableCeiling(TransferMarketView $view, int $buyerClubId, int $ceiling): int
    {
        $finances = $view->ctx->read(Finances::class)->get($buyerClubId);

        if ($finances === null) {
            return $ceiling;
        }

        return min($ceiling, max(0, $finances->balanceCents));
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
     * Le poids d'urgence de chaque poste sous-effectif de ce club :
     * `1 + span x deficit/cible`, donc `1.0` a la marge et `1 + span` pour un
     * poste ou le club n'a personne. Les postes combles n'y figurent pas - un
     * tableau vide signifie que le club ne participe pas cette annee.
     *
     * Le deficit est rapporte a **la cible du poste** et non a un total : il
     * est plus grave de perdre un gardien sur deux qu'un defenseur sur huit,
     * et c'est precisement ce que l'ordre de declaration ne savait pas dire.
     *
     * @param array<string, int> $held
     * @param array<string, int> $targets
     * @return array<string, float> valeur de poste -> poids, postes combles exclus
     */
    private function needWeights(array $held, array $targets, float $span): array
    {
        $weights = [];

        foreach (Position::cases() as $position) {
            $target = $targets[$position->value] ?? 0;
            $deficit = $target - ($held[$position->value] ?? 0);

            if ($target <= 0 || $deficit <= 0) {
                continue;
            }

            $weights[$position->value] = 1.0 + $span * ($deficit / $target);
        }

        return $weights;
    }

    /**
     * La meilleure cible, tous postes en deficit confondus, au sens
     *
     *     score = (qualite percue / prix estime) x poids d'urgence du poste
     *
     * parmi les joueurs sous contrat ailleurs et non deja cibles - ou `null`.
     * Forme de docs/14- §3 : une base qui porte le phenomene, **un seul**
     * modificateur, borne par construction dans `[1, 1 + span]`.
     *
     * Les deux facteurs tirent volontairement en sens opposes : un poste rare
     * est **plus cher** (`rarete_poste` dans `MarketValueModel`, via
     * `TransferSystem::scarcityByPosition()`), donc son ratio est moins bon, et
     * le poids d'urgence est ce qui contrebalance.
     *
     * L'iteration suit `Contract::entities()` (EntityId croissant), donc a
     * egalite stricte le premier rencontre l'emporte sans depart supplementaire.
     *
     * @param array<string, float> $needs
     * @param array<int, true> $targetedPlayers
     * @return array{0: int, 1: int}|null [playerId, valorisation de l'acheteur]
     */
    private function selectTarget(
        TransferMarketView $view,
        int $buyerClubId,
        array $needs,
        array $targetedPlayers,
    ): ?array {
        $best = null;
        $bestScore = -1.0;

        foreach ($view->ctx->read(Contract::class)->entities() as $playerId) {
            $contract = $view->ctx->read(Contract::class)->get($playerId);

            if ($contract === null || $contract->clubId === $buyerClubId || isset($targetedPlayers[$playerId])) {
                continue;
            }

            $position = $this->positionOf($view, $playerId);

            if ($position === null || !isset($needs[$position->value])) {
                continue;
            }

            $quality = $view->perceivedQuality($buyerClubId, $playerId);
            $value = $view->valuation($buyerClubId, $playerId, $position, $buyerClubId);

            if ($quality === null || $value === null) {
                continue;
            }

            $score = ($quality / max(1, $value)) * $needs[$position->value];

            if ($score > $bestScore) {
                $bestScore = $score;
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
