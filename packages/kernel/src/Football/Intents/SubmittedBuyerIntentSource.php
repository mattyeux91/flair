<?php

declare(strict_types=1);

namespace Flair\Kernel\Football\Intents;

use Flair\Kernel\Football\Components\Negotiation;

/**
 * Les decisions d'achat viennent de la file d'intentions du tick
 * (`TickContext::$intents`), pas d'une IA : c'est le chemin qu'empruntera un
 * agent humain quand `host` existera (docs/13- §8, `$this->intentInbox->drain()`).
 *
 * **Premier consommateur reel de `TickContext::$intents`.** Le tuyau existait
 * depuis le debut, borde jusqu'a `SystemContext::intents()`, et son propre
 * docblock admettait n'avoir aucun lecteur (« exposes des maintenant meme si
 * aucun systeme du domaine football n'existe encore pour les lire »). Il en a
 * un.
 *
 * ## Pourquoi ca marche pour un humain
 *
 * Le vendeur contre-demande au tick N et le Fait `TransferCounterDemanded`
 * n'est visible qu'a la fin de ce tick (docs/13- §2). L'humain le lit, soumet
 * son intention, et `Football\TransferSystem` la trouve ici au tick N+1 - d'ou
 * le fait que la reponse de l'acheteur ait ete decalee d'un tick au point 3.
 * Sans ce decalage, cette classe ne pourrait rien fournir d'autre qu'une
 * decision prise avant d'avoir vu la question.
 *
 * Rendre `null` ne veut pas dire « je me retire » mais « rien n'est arrive ce
 * tick » : c'est `TransferBalance::$responseGraceTicks` qui borne l'attente.
 *
 * ## Ce qui n'est pas ici
 *
 * Pas de repli sur le PNJ quand la file est vide. docs/11- §3 en fait une
 * propriete (« si un joueur humain part, son agent peut etre repris par
 * l'IA »), mais rien ne peuple `TickContext::$intents` en production
 * aujourd'hui : ce composite est du cablage `host`, Phase 5.
 */
final class SubmittedBuyerIntentSource implements BuyerIntentSource
{
    /** @param array<int, true> $targetedPlayers */
    public function openingBid(TransferMarketView $view, int $buyerClubId, array $targetedPlayers): ?BidForPlayer
    {
        foreach ($view->ctx->intents() as $intent) {
            if ($intent instanceof BidForPlayer && $intent->buyerClubId === $buyerClubId) {
                return $intent;
            }
        }

        return null;
    }

    public function respondToCounter(
        TransferMarketView $view,
        int $negotiationId,
        Negotiation $negotiation,
        int $counterCents,
    ): ?RaiseTransferOffer {
        foreach ($view->ctx->intents() as $intent) {
            if ($intent instanceof RaiseTransferOffer && $intent->negotiationId === $negotiationId) {
                return $intent;
            }
        }

        return null;
    }
}
