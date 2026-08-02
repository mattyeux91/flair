<?php

declare(strict_types=1);

namespace Flair\Kernel\Football\Events;

use Flair\Kernel\Core\Messaging\DomainEvent;

/**
 * Echeance planifiee par `Football\CalendarSystem` via `ctx->schedule()`
 * (docs/13- §3, propagation differee) : le jour du coup d'envoi d'un match
 * programme dans le calendrier.
 *
 * Self-suffisant, comme tous les evenements du domaine football deja
 * ecrits : porte tous les identifiants necessaires a `Football\MatchSystem`
 * et `Football\CompetitionSystem`, qui y reagissent tous les deux (dans cet
 * ordre, `MatchSystem` etant declare plus tot dans le pipeline) sans avoir
 * besoin de relire le composant `Fixture`.
 */
final class FixtureKickoff implements DomainEvent
{
    public function __construct(
        public int $fixtureId,
        public int $competitionId,
        public int $homeClubId,
        public int $awayClubId,
        public int $matchday,
    ) {
    }
}
