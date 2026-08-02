<?php

declare(strict_types=1);

namespace Flair\Kernel\Football\Events;

use Flair\Kernel\Core\Messaging\DomainEvent;

/**
 * Une nouvelle saison commence pour une competition : emis par
 * `Football\CalendarSystem` (canal 2, `ctx->emit()`) en meme temps qu'il
 * genere le calendrier de la saison. Seul consommateur a ce jour :
 * `Football\CompetitionSystem`, qui remet `Standings` a vide - pas besoin du
 * canal 1 ici, le premier match de la saison arrive de toute facon
 * plusieurs jours plus tard (`CalendarBalance::$firstMatchdayOffsetDays`).
 */
final class SeasonStarted implements DomainEvent
{
    public function __construct(
        public int $competitionId,
    ) {
    }
}
