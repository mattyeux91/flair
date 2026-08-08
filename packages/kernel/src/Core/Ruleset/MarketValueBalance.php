<?php

declare(strict_types=1);

namespace Flair\Kernel\Core\Ruleset;

/**
 * Leviers de la valorisation d'un joueur (docs/14-algorithmes.md §5, forme
 * bornee de §3), traduits par `Football\Support\MarketValueModel` a qui ce
 * groupe est passe en entier.
 *
 * **Ce groupe dit ce qu'un joueur vaut en monnaie, jamais qui l'observe**
 * (`perception`) **ni combien il coute en salaire** (`contract`) - trois
 * leviers distincts qu'aucun systeme ne doit avoir a lire ensemble (meme
 * coupure que documentee sur `Balance`).
 */
final readonly class MarketValueBalance
{
    public function __construct(
        /**
         * La valeur, en centimes, d'un joueur exactement a `referenceQuality`,
         * a son pic d'age, sans rarete de poste ni richesse d'acheteur, a
         * contrat plein (le point neutre de toute la formule).
         */
        public int $baseValueCents = 5_000_000,
        /** Le meme ancrage que `ContractBalance::$referenceQuality` : le joueur median. */
        public int $referenceQuality = 50,
        public float $valueMultiplierMin = 0.1,
        public float $valueMultiplierMax = 5.0,
        /**
         * Demi-largeur, en annees, de la rampe de prime jeunesse avant le pic.
         * Au-dela de `pic - youthWindowYears`, la prime plafonne a
         * `youthPremiumCeiling` plutot que de continuer a monter.
         */
        public float $youthWindowYears = 6.0,
        public float $youthPremiumCeiling = 1.5,
        /** Perte de multiplicateur par annee au-dela du pic. */
        public float $agingDeclinePerYear = 0.15,
        public float $agingFloorMultiplier = 0.1,
        /** Bornes du clamp sur `rarete_poste x richesse_acheteur` (docs/14- §5). */
        public float $modifierMin = 0.4,
        public float $modifierMax = 2.5,
        /**
         * Duree restante, en annees, au-dela de laquelle `facteur_contrat`
         * vaut 1.0 - en-deca, le joueur perd de la valeur jusqu'a
         * `contractFloorMultiplier` a l'echeance (docs/14- §5 : un joueur a
         * six mois du terme s'effondre).
         */
        public float $contractFullValueYears = 1.5,
        public float $contractFloorMultiplier = 0.05,
    ) {
    }
}
