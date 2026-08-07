<?php

declare(strict_types=1);

namespace Flair\Kernel\Football\Events;

use Flair\Kernel\Core\Messaging\DomainEvent;

/**
 * Un transfert est conclu : l'offre en cours a atteint ou depasse le prix de
 * reserve du vendeur (`Football\TransferSystem`, docs/17-marche-transferts.md
 * points 2 et 4).
 *
 * ## Ce Fait est executoire, pas seulement narratif
 *
 * Depuis le point 4, `Football\FinanceSystem` le consomme au tick suivant et
 * en fait un mouvement d'argent reel : l'acheteur debite, le vendeur credite
 * du meme montant. C'est le **seul** mouvement monetaire du monde qui ne soit
 * ni une injection ni un puits, donc celui qui rend
 * `Harness\Tests\Regression\MonetaryConservationTest` non trivial pour la
 * premiere fois.
 *
 * Il ne porte pas le mouvement du joueur : `Football\TransferSystem` emet en
 * meme temps un `ContractSigned`, applique par `Football\SquadSystem`. Deux
 * consequences, deux Faits, chacun vers le proprietaire de son composant - il
 * n'existe volontairement pas de troisieme Fait `TransferCompleted` qui
 * repeterait ce que ces deux-la disent deja (docs/16- §2).
 *
 * `round` porte le nombre de tours ecoules, la grandeur que la verification du
 * point 2 mesure (la distribution ne doit pas s'ecraser sur 1).
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
