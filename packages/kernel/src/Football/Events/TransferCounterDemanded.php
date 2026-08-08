<?php

declare(strict_types=1);

namespace Flair\Kernel\Football\Events;

use Flair\Kernel\Core\Messaging\DomainEvent;

/**
 * Le club vendeur repond a l'offre en cours par une contre-demande plutot que
 * d'accepter ou de rompre (`Football\TransferSystem`,
 * docs/17-marche-transferts.md point 2).
 *
 * ## Les deux clubs, comme les trois autres Faits du marche
 *
 * `TransferNegotiationOpened`, `TransferAgreed` et `TransferNegotiationBroken`
 * les portent ; celui-ci ne portait que la negociation, ce qui le rendait
 * inattribuable a un club une fois en base - le composant `Negotiation` qui
 * aurait permis la jointure n'existe plus une fois l'affaire close (docs/16-
 * §2 : un Fait porte de quoi l'attribuer a ses sujets).
 *
 * ## Ce qu'il ne dira jamais
 *
 * `counterCents` est public : c'est ce que le vendeur dit a voix haute. Le
 * prix de reserve et le plafond de l'acheteur restent des variables de
 * decision **privees**, jamais exposees dans un Fait - meme logique que la
 * verite cachee (docs/12- §4). Un Fait est ce que le monde a vu, pas ce que
 * les parties pensaient.
 */
final class TransferCounterDemanded implements DomainEvent
{
    public function __construct(
        public int $negotiationId,
        public int $playerId,
        public int $round,
        public int $counterCents,
        public int $buyerClubId,
        public int $sellerClubId,
    ) {
    }
}
