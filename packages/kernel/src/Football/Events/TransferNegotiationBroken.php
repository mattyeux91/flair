<?php

declare(strict_types=1);

namespace Flair\Kernel\Football\Events;

use Flair\Kernel\Core\Messaging\DomainEvent;

/**
 * Une negociation se rompt, sans accord (`Football\TransferSystem`,
 * docs/17-marche-transferts.md point 2) - par tirage de rupture, par
 * depassement du plafond de l'acheteur, ou de force au-dela de
 * `TransferBalance::$maxRounds`. Les trois causes partagent le meme Fait :
 * ce qui est racontable, c'est que le transfert n'a pas eu lieu, pas
 * pourquoi (docs/16- §2).
 */
final class TransferNegotiationBroken implements DomainEvent
{
    public function __construct(
        public int $negotiationId,
        public int $buyerClubId,
        public int $sellerClubId,
        public int $playerId,
        public int $round,
    ) {
    }
}
