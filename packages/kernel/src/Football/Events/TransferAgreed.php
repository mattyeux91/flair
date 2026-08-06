<?php

declare(strict_types=1);

namespace Flair\Kernel\Football\Events;

use Flair\Kernel\Core\Messaging\DomainEvent;

/**
 * Un transfert est conclu : l'offre en cours a atteint ou depasse le prix de
 * reserve du vendeur (`Football\TransferSystem`, docs/17-marche-transferts.md
 * point 2).
 *
 * Aucun argent ne change encore de mains a ce point du chantier - le grand
 * livre est branche au point 4. `round` porte le nombre de tours ecoules,
 * c'est la grandeur que la verification de ce point mesure (la distribution
 * ne doit pas s'ecraser sur 1).
 */
final class TransferAgreed implements DomainEvent
{
    public function __construct(
        public int $negotiationId,
        public int $buyerClubId,
        public int $sellerClubId,
        public int $playerId,
        public int $round,
        public int $agreedPriceCents,
    ) {
    }
}
