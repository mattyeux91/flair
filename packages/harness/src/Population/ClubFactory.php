<?php

declare(strict_types=1);

namespace Flair\Harness\Population;

use Flair\Kernel\Core\Ecs\WorldState;
use Flair\Kernel\Football\Components\Club;
use Flair\Kernel\Football\Components\Facilities;

/**
 * Cree des clubs synthetiques pour le harness - sans clubs (`Club` +
 * `Facilities`), ni `Football\TrainingSystem` (aucun `SquadMembership` a
 * lire) ni `Football\YouthIntakeSystem` (aucune entite ou promouvoir, cf.
 * `Football\YouthIntakeSystem::update`, qui itere `Club::class`) ne peuvent
 * produire le moindre effet - deux systemes entiers du pipeline restaient
 * incalibrables via le harness.
 *
 * Qualite d'installations uniforme sur tous les clubs plutot qu'une
 * variance tiree : le harness agrege sur la population entiere, pas par
 * club, donc une variance inter-clubs n'ajouterait aucun signal a ce qui
 * est mesure aujourd'hui - juste du bruit. A revisiter si un indicateur par
 * club apparait.
 */
final class ClubFactory
{
    /** @return list<int> identifiants des entites club creees */
    public function create(WorldState $world, int $count, float $facilitiesQuality): array
    {
        $clubIds = [];

        for ($i = 1; $i <= $count; $i++) {
            $entity = $world->createEntity();
            $world->components(Club::class)->set($entity, new Club("Club synthetique {$i}"));
            $world->components(Facilities::class)->set($entity, new Facilities($facilitiesQuality));
            $clubIds[] = $entity;
        }

        return $clubIds;
    }
}
