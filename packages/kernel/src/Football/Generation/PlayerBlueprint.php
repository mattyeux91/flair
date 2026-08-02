<?php

declare(strict_types=1);

namespace Flair\Kernel\Football\Generation;

use Flair\Kernel\Football\Components\Person;
use Flair\Kernel\Football\Components\PlayerMentalSkills;
use Flair\Kernel\Football\Components\PlayerPhysicalSkills;
use Flair\Kernel\Football\Components\PlayerPotentials;
use Flair\Kernel\Football\Components\PlayerTechnicalSkills;

/**
 * Le jeu de composants d'un joueur neuf, tire mais pas encore ecrit dans le
 * monde.
 *
 * Exister comme valeur intermediaire est deliberate : `PlayerFactory` reste
 * une fonction pure de (Rng, parametres) vers des donnees, sans acces au
 * monde. C'est l'appelant qui ecrit - `YouthIntakeSystem` via
 * `SystemContext`, le harness via `WorldState`, un futur `worldgen` par ce
 * qu'il voudra. Ces trois appelants n'ont pas de type d'acces commun (ni
 * interface partagee entre SystemContext et WorldState) : une factory qui
 * ecrirait elle-meme devrait en choisir un et exclure les autres.
 */
final readonly class PlayerBlueprint
{
    public function __construct(
        public Person $person,
        public PlayerPotentials $potentials,
        public PlayerPhysicalSkills $physical,
        public PlayerTechnicalSkills $technical,
        public PlayerMentalSkills $mental,
    ) {
    }
}
