<?php

declare(strict_types=1);

namespace Flair\Kernel\Football\Intents;

use Flair\Kernel\Core\Messaging\Intent;

/**
 * « Je releve mon offre a ce montant » - la reponse de l'acheteur a une
 * contre-demande du vendeur (docs/17-marche-transferts.md point 3).
 *
 * Il n'existe volontairement **pas** d'intention de retrait : ne rien produire
 * dit deja la meme chose, et `TransferBalance::$responseGraceTicks` borne
 * l'attente. Deux moyens de dire une seule chose, c'est un moyen de trop.
 */
final readonly class RaiseTransferOffer implements Intent
{
    public function __construct(
        public int $negotiationId,
        public int $offerCents,
    ) {
    }
}
