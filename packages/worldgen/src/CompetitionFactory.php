<?php

declare(strict_types=1);

namespace Flair\Worldgen;

use Flair\Kernel\Core\Ecs\WorldState;
use Flair\Kernel\Football\Components\Competition;

/**
 * Cree l'entite competition d'un monde - sans elle,
 * Football\CalendarSystem (qui lit Competition::class) n'a aucun calendrier
 * a generer, meme quand des clubs existent. Une seule instance : le noyau
 * n'a qu'une seule division en Phase 0 (docs/15- §4), et Football\CalendarSystem
 * associe deja tous les clubs du monde a chaque Competition trouvee (pas de
 * CompetitionMembership).
 */
final class CompetitionFactory
{
    public function create(WorldState $world, string $name = 'Championnat synthetique'): int
    {
        $competition = $world->createEntity();
        $world->components(Competition::class)->set($competition, new Competition($name));

        return $competition;
    }
}
