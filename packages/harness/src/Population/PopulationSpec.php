<?php

declare(strict_types=1);

namespace Flair\Harness\Population;

/**
 * Parametres d'un run de harness, regroupes pour eviter que la liste de
 * parametres positionnels ne grossisse a chaque nouveau levier sur les
 * trois points d'appel qui en ont besoin (public/index.php,
 * bin/aggregate.php, Comparison\PairedSeedComparison).
 *
 * `clubCount`/`facilitiesQuality` pilotent uniquement la generation de
 * clubs synthetiques (Population\ClubFactory) - une qualite d'installations
 * uniforme sur tous les clubs, premier jet volontairement simple (pas de
 * variance entre clubs dans ce lot, cf. docblock ClubFactory).
 */
final readonly class PopulationSpec
{
    public function __construct(
        public int $playerCount,
        public int $years,
        public int $seed,
        public int $clubCount = 18,
        public float $facilitiesQuality = 1.0,
    ) {
    }
}
