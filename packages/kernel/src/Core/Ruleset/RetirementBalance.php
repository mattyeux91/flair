<?php

declare(strict_types=1);

namespace Flair\Kernel\Core\Ruleset;

/**
 * Leviers d'equilibrage de la retraite (docs/14-algorithmes.md §2), lus
 * uniquement par Football\RetirementSystem : age d'eligibilite, poids de
 * l'age/la fragilite dans la probabilite annuelle de retraite.
 *
 * Premier jet qualitatif (cf. docblock de RetirementSystem), a calibrer
 * via le harness d'equilibrage (Phase 1) - cette classe existe pour que ce
 * calibrage n'implique jamais de toucher au code du systeme, seulement au
 * Ruleset.
 */
final readonly class RetirementBalance
{
    public function __construct(
        /** Age (annees) a partir duquel un tirage de retraite a lieu chaque tick ; en dessous, aucun risque. */
        public float $retirementEligibleAge = 29.0,
        /** Poids des annees passees au-dela de `retirementEligibleAge` dans la probabilite annuelle de retraite (`yearsPastEligible * retirementAgeWeight`). */
        public float $retirementAgeWeight = 0.15,
        /** Poids de `PlayerPotentials::$fragility` (0-1) dans la probabilite annuelle de retraite (`fragility * retirementFragilityWeight`). */
        public float $retirementFragilityWeight = 0.15,
    ) {
    }
}
