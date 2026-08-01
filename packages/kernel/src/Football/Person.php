<?php

declare(strict_types=1);

namespace Flair\Kernel\Football;

use Flair\Kernel\Core\Support\SimDate;

/**
 * L'identite d'une entite, independante de son ou ses roles (docs/12-
 * modele-du-monde.md §3 - un "joueur" n'est que le nom informel d'une
 * entite qui porte PlayerPotentials et ses composants de competences en
 * plus de Person). Persiste a travers les changements de role : un joueur
 * qui devient entraineur garde son Person.
 *
 * Minimal a dessein : `nationalities`/`homeCityId` du catalogue (12- §3)
 * rejoindront ce composant quand un systeme les lira reellement.
 */
final readonly class Person
{
    public function __construct(
        public string $name,
        public SimDate $birthDate,
    ) {
    }
}
