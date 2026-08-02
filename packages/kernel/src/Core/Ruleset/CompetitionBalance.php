<?php

declare(strict_types=1);

namespace Flair\Kernel\Core\Ruleset;

/**
 * Leviers d'equilibrage du classement (docs/15- §4), lus uniquement par
 * Football\CompetitionSystem.
 */
final readonly class CompetitionBalance
{
    public function __construct(
        /** Points attribues au vainqueur d'un match. */
        public int $pointsForWin = 3,
        /** Points attribues a chaque equipe en cas de match nul. */
        public int $pointsForDraw = 1,
    ) {
    }
}
