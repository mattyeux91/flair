<?php

declare(strict_types=1);

namespace Flair\Kernel\Football\Generation;

/**
 * Le score d'un match tire par `PoissonMatchEngine`, pas encore ecrit dans
 * le monde - meme role que `PlayerBlueprint` pour `PlayerFactory`.
 */
final readonly class MatchScore
{
    public function __construct(
        public int $homeGoals,
        public int $awayGoals,
    ) {
    }
}
