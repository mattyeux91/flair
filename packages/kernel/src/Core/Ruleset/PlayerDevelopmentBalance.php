<?php

declare(strict_types=1);

namespace Flair\Kernel\Core\Ruleset;

/**
 * Leviers d'equilibrage de la progression/declin des competences
 * (docs/14-algorithmes.md §2), lus uniquement par
 * Football\PlayerDevelopmentSystem : forme de g(age) (seuil de
 * progression maximale, plateau, pente de declin), et un multiplicateur
 * de declin post-pic par categorie de competences - le physique decline
 * plus vite que le mental une fois son pic depasse. L'age de pic lui-meme
 * n'est **pas** ici : il est individuel et distinct par categorie, dans
 * `PlayerPotentials` (`*PeakAge`) - ce fichier ne porte que la pente,
 * jamais le moment.
 *
 * Premier jet qualitatif (cf. docblock de PlayerDevelopmentSystem), a
 * calibrer via le harness d'equilibrage (Phase 1) - cette classe existe
 * pour que ce calibrage n'implique jamais de toucher au code du systeme,
 * seulement au Ruleset. Les trois multiplicateurs de declin respectent
 * deja un ordre qualitatif (physique decline plus vite que technique,
 * lui-meme plus vite que mental), mais ne sont pas equilibres.
 */
final readonly class PlayerDevelopmentBalance
{
    public function __construct(
        /** Age (annees) en dessous duquel g(age) est maximal (1.0) - progression la plus rapide possible vers le plafond. */
        public float $growthPrimeAgeThreshold = 23.0,
        /** Valeur de g(age) entre `growthPrimeAgeThreshold` et le pic de la categorie - progression ralentie mais toujours positive. */
        public float $growthPlateauFactor = 0.3,
        /** Pente du declin post-pic : g(age) = -declineRatePerYear × (age - peakAge) une fois le pic de la categorie depasse. */
        public float $declineRatePerYear = 1.0,
        /** Multiplicateur de `PlayerPotentials::$fragility` dans le declin post-pic de `PlayerPhysicalSkills` - la categorie qui s'erode le plus vite avec l'age. */
        public float $physicalDeclineMultiplier = 2.0,
        /** Multiplicateur de `PlayerPotentials::$fragility` dans le declin post-pic de `PlayerTechnicalSkills`. */
        public float $technicalDeclineMultiplier = 1.0,
        /** Multiplicateur de `PlayerPotentials::$fragility` dans le declin post-pic de `PlayerMentalSkills` - la categorie qui resiste le mieux a l'age. */
        public float $mentalDeclineMultiplier = 0.5,
    ) {
    }
}
