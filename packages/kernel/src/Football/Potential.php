<?php

declare(strict_types=1);

namespace Flair\Kernel\Football;

/**
 * Le potentiel d'un joueur : une trajectoire, pas un plafond dur
 * (docs/14-algorithmes.md §2). `ceiling` est une asymptote souple - la
 * progression en approche sans jamais la heurter net.
 */
final readonly class Potential
{
    public function __construct(
        public int $ceiling,
        public int $peakAge,
        public float $growthRate,
        public float $fragility,
    ) {
    }
}
