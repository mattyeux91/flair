<?php

declare(strict_types=1);

namespace Flair\Kernel\Football\Events;

use Flair\Kernel\Core\Messaging\DomainEvent;

/**
 * Un joueur s'engage : emis par `Football\ContractSystem` le jour du
 * renouvellement annuel, applique au tick suivant par
 * `Football\SquadSystem` qui ecrit `Contract` et `SquadMembership`.
 *
 * Le vocabulaire evite volontairement "agent libre", pourtant le terme
 * footballistique consacre : dans ce projet "agent" designe deja
 * l'intermediaire qui represente les joueurs (docs/14- §5, `Contract::$agentId`
 * au catalogue), c'est-a-dire le role incarne par le joueur humain. Un joueur
 * sans contrat est donc dit **sans club**, jamais "agent libre".
 *
 * Couvre les deux cas d'un seul type : `previousClubId === $clubId` est un
 * renouvellement, `previousClubId === null` l'embauche d'un joueur sans club, et
 * toute autre valeur un transfert libre entre clubs. Deux types distincts
 * couteraient au consommateur une double souscription pour la meme ecriture,
 * alors que l'information qui les separe tient dans un champ.
 *
 * ## Pourquoi un evenement et pas une ecriture directe
 *
 * Meme mur que `ClubInvestedInFacilities`, pour les memes raisons
 * structurelles. Decider un renouvellement demande de lire les competences du
 * joueur (`Football\PlayerDevelopmentSystem` les ecrit, `RetirementSystem`
 * les retire) et `Finances` (`FinanceSystem` l'ecrit) : le decideur doit donc
 * venir **apres** eux. Ecrire `SquadMembership` impose de venir **avant**
 * `TrainingSystem` et `MatchSystem`, qui le lisent. Aucun ordre de pipeline
 * ne satisfait les deux - le couplage passe donc par le canal 2 (docs/13-
 * §2), decider tard et appliquer tot.
 *
 * ## Un Fait a part entiere
 *
 * Ce n'est pas de la comptabilite de routine comme un versement de salaire :
 * un contrat engage le club pour des annees, il est racontable, et un
 * changement de club est exactement ce qu'un fil d'actualite de mercato
 * raconte (docs/16- §2). Le volume reste celui des contrats arrivant a terme,
 * soit environ un tiers de l'effectif du monde par an - trois ordres de
 * grandeur sous le piege des "3 millions d'evenements de bruit par saison"
 * (docs/15- §5).
 *
 * `expiresOnEpochDay` plutot qu'un `SimDate` : le payload d'un Fait est
 * destine a etre journalise en `jsonb` (docs/13- §5), donc reste scalaire.
 */
final class ContractSigned implements DomainEvent
{
    public function __construct(
        public int $playerId,
        public int $clubId,
        public ?int $previousClubId,
        public int $wagePerWeekCents,
        public int $expiresOnEpochDay,
    ) {
    }
}
