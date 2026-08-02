<?php

declare(strict_types=1);

namespace Flair\Kernel\Core\Ruleset;

/**
 * Leviers d'equilibrage du calendrier (docs/15- §4), lus uniquement par
 * Football\CalendarSystem : jour de generation de la saison, espacement des
 * journees.
 *
 * Meme limite que YouthIntakeBalance::$intakeDayOfYear : `SimDate` n'est
 * qu'un compteur de jours sans epoch (docs/13- §1), donc "jour de l'annee
 * simulee" reste `tick % 365`, pas une vraie date calendaire.
 */
final readonly class CalendarBalance
{
    public function __construct(
        /** Jour de l'annee simulee (`tick % 365`) ou une nouvelle saison est generee pour chaque `Competition`. */
        public int $seasonStartDayOfYear = 0,
        /** Delai (jours) entre la generation de la saison et le coup d'envoi de la premiere journee. */
        public int $firstMatchdayOffsetDays = 14,
        /** Espacement (jours) entre deux journees consecutives. */
        public int $matchdayIntervalDays = 7,
    ) {
    }
}
