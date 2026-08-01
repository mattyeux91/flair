<?php

declare(strict_types=1);

namespace Flair\Kernel\Football;

/**
 * Les attributs d'un joueur de champ (docs/12-modele-du-monde.md §5) : peu
 * et orthogonaux, chacun consomme par une decision de jeu future (moteur de
 * match). La variante gardien (reflexes/handling/distribution/command) est
 * differee - rien ne la consomme encore.
 *
 * Readonly comme tout composant (12- §1/§2) : un systeme n'edite jamais en
 * place, il ecrit une nouvelle instance via ComponentStore::set().
 */
final readonly class PlayerSkills
{
    public function __construct(
        public int $technique,
        public int $passing,
        public int $finishing,
        public int $pace,
        public int $stamina,
        public int $strength,
        public int $defending,
        public int $positioning,
        public int $vision,
        public int $composure,
        public int $leadership,
        public int $discipline,
    ) {
    }
}
