<?php

declare(strict_types=1);

namespace Flair\Kernel\Football\Components;

/**
 * Une negociation de transfert en cours, sur une entite dediee creee par
 * `Football\TransferSystem` (docs/17-marche-transferts.md point 2). Premier
 * composant du noyau qui porte un etat **multi-tick** : `TransferSystem` le
 * cree a l'ouverture, puis le `set()` a nouveau a chaque tick suivant tant
 * qu'aucune resolution n'est intervenue (meme composant dans `creates()` et
 * `writes()` du meme systeme, docs/13- §2).
 *
 * « Memoire des tours precedents » (docs/14- §5) est satisfaite par cet etat
 * persistant lui-meme (`round` + `lastOfferCents`), pas par un historique
 * rejoue : les Faits emis a chaque tour sont deja la matiere narrative en
 * aval.
 *
 * `reservePriceCents`/`buyerCeilingCents` sont des variables de decision
 * privees a chaque partie, figees a l'ouverture : jamais exposees dans un
 * Fait (meme logique que la verite cachee, docs/12- §4), elles disparaissent
 * quand la negociation se resout (`removes()`).
 *
 * ## Machine a etats (docs/17-marche-transferts.md point 3)
 *
 * Deux etats seulement, distingues par `pendingCounterCents` :
 *
 * - **`null`** - la balle est au vendeur. Au prochain tick il accepte
 *   `lastOfferCents`, rompt, ou contre-demande.
 * - **non `null`** - la balle est a l'acheteur, qui doit repondre a cette
 *   contre-demande. `pendingSinceTick` date l'attente : au-dela de
 *   `TransferBalance::$responseGraceTicks` sans intention, la negociation
 *   s'eteint.
 *
 * L'attente existe parce qu'un acheteur peut etre **humain** : il voit le Fait
 * `TransferCounterDemanded` a la fin du tick qui l'a emis (docs/13- §2) et ne
 * peut donc repondre qu'au tick suivant. Une source PNJ, elle, repond dans le
 * tick meme ou elle voit la contre-demande - d'ou un delai de grace a `0` par
 * defaut, strictement sans effet sur un monde 100 % PNJ.
 */
final readonly class Negotiation
{
    public function __construct(
        public int $buyerClubId,
        public int $sellerClubId,
        public int $playerId,
        public int $round,
        public int $lastOfferCents,
        public int $reservePriceCents,
        public int $buyerCeilingCents,
        public ?int $pendingCounterCents = null,
        public int $pendingSinceTick = 0,
    ) {
    }
}
