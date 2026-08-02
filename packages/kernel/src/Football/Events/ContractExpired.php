<?php

declare(strict_types=1);

namespace Flair\Kernel\Football\Events;

use Flair\Kernel\Core\Messaging\DomainEvent;

/**
 * Un joueur se retrouve sans club : emis par `Football\ContractSystem` quand
 * un contrat arrive a terme et que **personne** ne l'a repris dans la meme
 * passe, applique au tick suivant par `Football\SquadSystem` qui retire
 * `Contract` et `SquadMembership`.
 *
 * ## Emis seulement pour les invendus
 *
 * Un joueur libere par son club puis signe ailleurs le meme jour n'emet que
 * `ContractSigned` : ce Fait-ci ne sort que pour ceux que personne n'a
 * voulus. Deux raisons, dans cet ordre :
 *
 * 1. `SquadSystem` n'a alors jamais deux Faits contradictoires a appliquer
 *    sur la meme entite au meme tick. L'ordre de traitement de l'OutQueue est
 *    total et deterministe (docs/13- §4.5), donc le resultat serait
 *    reproductible - mais il dependrait d'un detail d'ordonnancement plutot
 *    que d'une intention, et le premier lecteur du journal y verrait un
 *    joueur licencie puis reembauche qui ne s'est jamais produit.
 * 2. Le volume tombe a ce qu'il raconte reellement.
 *
 * Le Fait porte le club **quitte** : c'est l'information narrative (qui a
 * laisse partir qui), et le joueur n'en a plus aucun apres coup.
 *
 * Un joueur qui prend sa retraite n'emet pas ce Fait : `PlayerRetired` dit
 * deja tout, et `SquadSystem` s'en sert pour le meme nettoyage.
 */
final class ContractExpired implements DomainEvent
{
    public function __construct(
        public int $playerId,
        public int $clubId,
    ) {
    }
}
