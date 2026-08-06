<?php

declare(strict_types=1);

namespace Flair\Kernel\Football\Events;

use Flair\Kernel\Core\Messaging\DomainEvent;

/**
 * Un club ouvre une negociation pour un joueur sous contrat ailleurs
 * (`Football\TransferSystem`, docs/17-marche-transferts.md point 2).
 *
 * Ni `reservePriceCents` ni `buyerCeilingCents` : ce sont des variables de
 * decision privees a chaque partie, jamais journalisees (cf. le docblock de
 * `Football\Components\Negotiation`).
 */
final class TransferNegotiationOpened implements DomainEvent
{
    public function __construct(
        public int $negotiationId,
        public int $buyerClubId,
        public int $sellerClubId,
        public int $playerId,
        public int $openingOfferCents,
    ) {
    }
}
