<?php

declare(strict_types=1);

namespace Flair\Kernel\Football\Events;

use Flair\Kernel\Core\Messaging\DomainEvent;

/**
 * Un club a consacre `cents` a ses installations : emis par
 * `Football\FinanceSystem` au moment ou il debite la somme, traite au tick
 * suivant par `Football\FacilitiesSystem` qui la convertit en qualite.
 *
 * ## Pourquoi un evenement et pas un composant
 *
 * Ce n'est pas un choix de style, c'est la seule option possible.
 * `Facilities` est lu par `Football\YouthIntakeSystem` et
 * `Football\TrainingSystem`, places **en tete** du pipeline : son writer doit
 * donc venir avant eux. `Finances` est ecrit par `FinanceSystem` : tout
 * lecteur doit venir apres lui. Aucun ordre de pipeline ne satisfait les deux
 * a la fois - `YouthIntakeSystem` doit rester en tete pour le canal 1 des
 * joueurs promus. Un systeme unique qui lirait l'argent et ecrirait les
 * installations est donc structurellement impossible, et le couplage passe
 * par le canal 2 (docs/13- §2). Meme mur, meme reponse, que
 * `Football\Events\SeasonConcluded`.
 *
 * Le partage tombe juste : la finance decide **combien d'argent**, les
 * installations decident **combien de qualite** cet argent achete. Aucun des
 * deux n'a besoin des leviers de `Ruleset` de l'autre.
 *
 * ## Un Fait, pas de la comptabilite de routine
 *
 * Contrairement aux salaires et au credit de saison que `FinanceSystem` ne
 * journalise pas, agrandir son centre de formation franchit un seuil
 * comportemental, engage le club durablement et se raconte (docs/16- §2).
 * Un par club et par saison au maximum - aucun risque de bruit.
 */
final class ClubInvestedInFacilities implements DomainEvent
{
    public function __construct(
        public int $clubId,
        public int $cents,
    ) {
    }
}
