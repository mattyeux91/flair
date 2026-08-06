<?php

declare(strict_types=1);

namespace Flair\Kernel\Football\Events;

use Flair\Kernel\Core\Messaging\DomainEvent;

/**
 * Le club vendeur repond a l'offre en cours par une contre-demande plutot que
 * d'accepter ou de rompre (`Football\TransferSystem`,
 * docs/17-marche-transferts.md point 2).
 */
final class TransferCounterDemanded implements DomainEvent
{
    public function __construct(
        public int $negotiationId,
        public int $playerId,
        public int $round,
        public int $counterCents,
    ) {
    }
}
