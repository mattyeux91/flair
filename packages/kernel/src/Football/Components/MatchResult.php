<?php

declare(strict_types=1);

namespace Flair\Kernel\Football\Components;

/**
 * Le score d'un match joue, porte par l'entite `Fixture` correspondante.
 * Seul writer : `Football\MatchSystem`.
 *
 * Duplique volontairement les identifiants deja presents sur `Fixture`
 * (`competitionId`/`homeClubId`/`awayClubId`/`matchday`) - meme precedent
 * que `Football\Events\YouthPlayerPromoted` qui duplique `clubId` deja
 * present sur `SquadMembership` : un consommateur (un futur rapport de
 * saison, `Football\CompetitionSystem`) doit pouvoir lire ce composant seul,
 * sans recroiser `Fixture`.
 */
final readonly class MatchResult
{
    public function __construct(
        public int $competitionId,
        public int $homeClubId,
        public int $awayClubId,
        public int $matchday,
        public int $homeGoals,
        public int $awayGoals,
    ) {
    }
}
