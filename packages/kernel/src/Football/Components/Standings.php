<?php

declare(strict_types=1);

namespace Flair\Kernel\Football\Components;

use Flair\Kernel\Core\Snapshot\SnapshotArrayOf;

/**
 * Le classement d'une competition, porte par l'entite competition
 * elle-meme (docs/12- §3 : `Competition` porte `Standings`). Seul writer :
 * `Football\CompetitionSystem`.
 *
 * `entries` est peuple paresseusement, keye par `clubId` : un club n'y
 * apparait qu'apres avoir joue au moins un match. Evite a
 * `CompetitionSystem` de devoir connaitre la liste complete des clubs
 * d'une competition (pas de lecture de `Club::class` necessaire) - il lui
 * suffit de lire/ecrire les deux entrees concernees par chaque
 * `MatchResult`.
 */
final readonly class Standings
{
    /** @param array<int, StandingsEntry> $entries keye par clubId */
    public function __construct(
        #[SnapshotArrayOf(StandingsEntry::class)]
        public array $entries = [],
    ) {
    }
}
