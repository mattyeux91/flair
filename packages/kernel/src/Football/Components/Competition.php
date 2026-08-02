<?php

declare(strict_types=1);

namespace Flair\Kernel\Football\Components;

/**
 * Identite minimale d'une competition (docs/12- §3 : `Competition { format,
 * tier, countryId }`, catalogue complet hors scope - pas de `countryId`,
 * aucune entite Pays n'existe encore). Porte uniquement ce dont
 * `Football\CalendarSystem` a besoin pour exister comme entite distincte
 * des clubs qu'elle regroupe.
 *
 * Une seule instance en Phase 0 (docs/15- §4 : "1 pays, 1 division, 18
 * clubs") : aucun composant `CompetitionMembership` n'existe encore cote
 * club, donc `CalendarSystem` associe tous les clubs du monde a chaque
 * entite `Competition` qu'il trouve. A corriger le jour ou une deuxieme
 * division apparait - introduire plusieurs competitions aujourd'hui leur
 * ferait partager exactement les memes clubs.
 */
final readonly class Competition
{
    public function __construct(
        public string $name,
    ) {
    }
}
