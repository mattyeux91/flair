<?php

declare(strict_types=1);

namespace Flair\Kernel\Football\Events;

use Flair\Kernel\Core\Messaging\DomainEvent;

/**
 * Un match a ete joue : un Fait, racontable par nature (docs/16- §2) et
 * matiere premiere du futur digest de retour d'absence (docs/14- §9). Emis
 * par `Football\MatchSystem` en plus - pas a la place - du composant
 * `MatchResult` qu'il ecrit : `MatchResult` sert la resolution du jour meme
 * (canal 1, lu par `Football\CompetitionSystem`), ce Fait sert tout ce qui
 * consomme le journal d'evenements au tick suivant (canal 2).
 */
final class MatchPlayed implements DomainEvent
{
    public function __construct(
        public int $fixtureId,
        public int $competitionId,
        public int $homeClubId,
        public int $awayClubId,
        public int $homeGoals,
        public int $awayGoals,
    ) {
    }
}
