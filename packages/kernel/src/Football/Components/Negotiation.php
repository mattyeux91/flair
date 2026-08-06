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
    ) {
    }
}
