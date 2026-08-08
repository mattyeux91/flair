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
        /**
         * Ce qui a reellement quitte la caisse du club, a l'unite monetaire du
         * jour. C'est ce que `Football\FinanceSystem` compte comme puits, et
         * ce qu'un journal d'evenements doit enregistrer : un Fait ne ment pas
         * sur l'argent depense.
         */
        public int $cents,
        /**
         * Le meme montant ramene a l'unite de **reference**, c'est-a-dire
         * divise par `Football\Singletons\MarketInflation::$index`. Seul
         * `Football\FacilitiesSystem` s'en sert, pour convertir en qualite :
         * un club ne doit pas batir plus vite parce que la monnaie a change
         * d'unite (docs/17- point 5).
         *
         * Deux champs plutot qu'un converti a la lecture parce que
         * `FacilitiesSystem` **ne peut pas** lire l'indice : il ecrit
         * `Facilities`, que `FinanceSystem` lit, donc l'arete existe deja dans
         * ce sens et l'inverse ferait un cycle que
         * `Core\Pipeline\SystemGraph` leverait au montage. Le meme mur
         * structurel que celui qui a impose ce Fait en premier lieu, un cran
         * plus loin.
         */
        public int $referenceCents,
    ) {
    }
}
