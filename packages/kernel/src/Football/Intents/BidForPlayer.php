<?php

declare(strict_types=1);

namespace Flair\Kernel\Football\Intents;

use Flair\Kernel\Core\Messaging\Intent;

/**
 * « Ce club ouvre une negociation pour ce joueur, a ce prix » - la premiere
 * intention concrete du monde (docs/11- §3, docs/17-marche-transferts.md
 * point 3). Produite indifferemment par un PNJ ou un humain, via
 * `BuyerIntentSource`.
 *
 * Ne porte **pas** le poste vise. Le poste sert au calcul du vendeur (rarete,
 * profondeur d'effectif) et `Football\TransferSystem` le redérive lui-meme des
 * competences du joueur : une intention d'acheteur ne dicte jamais une entree
 * du calcul d'en face. Elle ne porte pas non plus le club vendeur, deduit du
 * `Contract` du joueur.
 *
 * `ceilingCents` est le plafond que l'acheteur s'accorde pour toute la
 * negociation. C'est une variable de decision **privee** : elle est recopiee
 * dans `Football\Components\Negotiation` et n'apparait dans aucun Fait. Une
 * source humaine a le droit de l'ignorer et de decider tour par tour.
 */
final readonly class BidForPlayer implements Intent
{
    public function __construct(
        public int $buyerClubId,
        public int $playerId,
        public int $offerCents,
        public int $ceilingCents,
    ) {
    }
}
