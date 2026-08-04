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
 * `startingBalanceCents` suit le meme principe (Phase 2) : un solde initial
 * uniforme, seede par ClubFactory, pas un levier de Ruleset - c'est un
 * parametre de generation du monde, pas un levier d'equilibrage du jeu.
 */
final readonly class PopulationSpec
{
    public function __construct(
        public int $playerCount,
        public int $years,
        public int $seed,
        public int $clubCount = 18,
        public float $facilitiesQuality = 1.0,
        public int $startingBalanceCents = 10_000_000,
    ) {
    }
}
