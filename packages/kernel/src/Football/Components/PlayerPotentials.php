<?php

declare(strict_types=1);

namespace Flair\Kernel\Football\Components;

/**
 * Le potentiel d'un joueur : une trajectoire, pas un plafond dur
 * (docs/14-algorithmes.md §2). `ceiling` est une asymptote souple - la
 * progression en approche sans jamais la heurter net.
 *
 * `ceiling`/`growthRate`/`fragility` restent partages par les trois
 * categories de competences (`PlayerPhysicalSkills`/`PlayerTechnicalSkills`/
 * `PlayerMentalSkills`) - aucun systeme n'a besoin de les distinguer par
 * categorie a ce jour. L'age de pic, en revanche, est individuel **et**
 * distinct par categorie (`*PeakAge`) : c'est un fait de football etabli
 * que le physique culmine avant le mental, a niveau de talent egal, et
 * `AgingSystem` en a un usage reel (pente de declin post-pic differente par
 * categorie, `Ruleset\AgingBalance`). Simplification restante, assumee :
 * un seul `ceiling`/`growthRate`/`fragility` pour les trois - a corriger
 * si un systeme en a besoin (cf. docblock d'AgingSystem).
 */
final readonly class PlayerPotentials
{
    public function __construct(
        public int $ceiling,
        public int $physicalPeakAge,
        public int $technicalPeakAge,
        public int $mentalPeakAge,
        public float $growthRate,
        public float $fragility,
    ) {
    }
}
