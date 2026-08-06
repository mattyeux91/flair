<?php

declare(strict_types=1);

namespace Flair\Kernel\Core\Ruleset;

/**
 * Leviers de la negociation multi-tours du marche des transferts (docs/14-
 * algorithmes.md §5, docs/17-marche-transferts.md point 2), traduits par
 * `Football\TransferSystem` a qui ce groupe est passe en entier.
 *
 * Contrairement a `MarketValueBalance` (point 1 du meme chantier, simple
 * passe-plat le temps qu'un appelant reel existe), ce groupe est **reellement
 * surchargeable** des sa creation (`Harness\Comparison\RulesetOverride`) :
 * la verification meme de ce point - la distribution du nombre de tours ne
 * doit pas s'ecraser sur 1 - est une campagne a graines appariees qui balaie
 * ces coefficients.
 */
final readonly class TransferBalance
{
    public function __construct(
        /**
         * Le jour fixe, chaque annee, ou de nouvelles negociations peuvent
         * s'ouvrir. Distinct de `ContractBalance::$renewalDayOfYear` (180)
         * pour que le mercato et l'ouverture du marche ne se disputent pas le
         * meme jour.
         */
        public int $negotiationOpeningDayOfYear = 200,
        /**
         * Le garde-fou de terminaison : au-dela de ce nombre de tours, une
         * negociation non resolue est rompue de force. Sans lui rien ne
         * garantirait qu'une negociation se termine un jour.
         */
        public int $maxRounds = 6,
        /** L'offre initiale, en part de la valorisation propre de l'acheteur. */
        public float $openingOfferShare = 0.75,
        /** Le plafond de l'acheteur = sa valorisation propre x cette marge. */
        public float $buyerFlexMargin = 1.15,
        /** La contre-demande du vendeur avance de cette part vers la derniere offre. */
        public float $sellerConcessionShare = 0.5,
        /** L'acheteur avance de cette part vers la contre-demande du vendeur. */
        public float $buyerConcessionShare = 0.5,
        /** Probabilite de rupture par tour, a ecart nul entre l'offre et la reserve. */
        public float $breakBaseProbability = 0.05,
        /** Ajout a la probabilite de rupture par tour ecoule (impatience). */
        public float $breakRoundGrowth = 0.05,
        /** Ajout a la probabilite de rupture, proportionnel a l'ecart offre/reserve restant. */
        public float $breakGapWeight = 0.3,
        /** Remise sur la reserve du vendeur quand son solde est negatif. */
        public float $financialDistressWeight = 0.3,
        /** Echelle de normalisation du decouvert (centimes) pour `financialDistressWeight`. */
        public int $financialDistressScaleCents = 5_000_000,
        /** Remise sur la reserve par joueur en surplus du poste chez le vendeur. */
        public float $squadDepthDiscountPerSurplusPlayer = 0.05,
        /** Plancher de la remise de profondeur d'effectif. */
        public float $squadDepthDiscountFloor = 0.6,
        /** Bornes de `rarete_poste` (demande/offre a l'echelle de la ligue). */
        public float $positionScarcityMin = 0.5,
        public float $positionScarcityMax = 2.0,
        /** Bornes de la richesse relative d'un club (son revenu / la moyenne de la ligue). */
        public float $buyerWealthMin = 0.5,
        public float $buyerWealthMax = 2.0,
        /**
         * Le niveau de patience (`Football\Components\BoardPatience`) pour
         * lequel le facteur de patience vaut exactement 1.0 - meme ancrage
         * que `ContractBalance::$referenceQuality`. Aussi la valeur lue pour
         * un club sans ce composant.
         */
        public int $patienceReference = 50,
        /**
         * Bornes du facteur qui multiplie la probabilite de rupture d'un
         * tour (docs/17-marche-transferts.md point 2 reouvert) : un club deux
         * fois plus patient que la reference voit sa probabilite de rupture
         * divisee par deux, borne par ce plancher.
         */
        public float $patienceFactorMin = 0.5,
        public float $patienceFactorMax = 2.0,
    ) {
    }
}
