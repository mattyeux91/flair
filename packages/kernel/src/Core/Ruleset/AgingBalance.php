<?php

declare(strict_types=1);

namespace Flair\Kernel\Core\Ruleset;

/**
 * Leviers d'equilibrage du vieillissement (docs/14-algorithmes.md §2), lus
 * par Football\AgingSystem : age d'eligibilite a la retraite, poids de
 * l'age/la fragilite dans la probabilite annuelle de retraite, forme de
 * g(age) (seuil de progression maximale, plateau, pente de declin), et
 * poids de la fragilite dans le declin post-pic.
 *
 * Premier jet qualitatif (cf. docblock d'AgingSystem), a calibrer via le
 * harness d'equilibrage (Phase 1) - cette classe existe pour que ce
 * calibrage n'implique jamais de toucher au code du systeme, seulement au
 * Ruleset.
 */
final readonly class AgingBalance
{
    public function __construct(
        /** Age (annees) a partir duquel un tirage de retraite a lieu chaque tick ; en dessous, aucun risque. */
        public float $retirementEligibleAge = 29.0,
        /** Poids des annees passees au-dela de `retirementEligibleAge` dans la probabilite annuelle de retraite (`yearsPastEligible * retirementAgeWeight`). */
        public float $retirementAgeWeight = 0.15,
        /** Poids de `Potential::$fragility` (0-1) dans la probabilite annuelle de retraite (`fragility * retirementFragilityWeight`). */
        public float $retirementFragilityWeight = 0.15,
        /** Age (annees) en dessous duquel g(age) est maximal (1.0) - progression la plus rapide possible vers le plafond. */
        public float $growthPrimeAgeThreshold = 23.0,
        /** Valeur de g(age) entre `growthPrimeAgeThreshold` et `peakAge` du joueur - progression ralentie mais toujours positive. */
        public float $growthPlateauFactor = 0.3,
        /** Pente du declin post-pic : g(age) = -declineRatePerYear × (age - peakAge) une fois `peakAge` depasse. */
        public float $declineRatePerYear = 0.1,
        /** Multiplicateur de `Potential::$fragility` dans l'amplitude du declin post-pic - plus un joueur est fragile, plus vite ses attributs regressent apres le pic. */
        public float $fragilityDeclineMultiplier = 2.0,
    ) {
    }
}
