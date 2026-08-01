<?php

declare(strict_types=1);

namespace Flair\Kernel\Football;

/**
 * Les attributs mentaux d'un joueur (docs/12-modele-du-monde.md §5). Portes
 * par tout joueur, gardien inclus.
 *
 * `command` (autorite/communication sur sa surface) vit ici plutot que dans
 * un composant "gardien" a part : c'est un attribut mental dans son
 * comportement de vieillissement (proche de `leadership`), meme s'il n'est
 * presentable qu'au poste de gardien - la categorisation suit le
 * comportement, pas le domaine metier.
 *
 * Readonly comme tout composant (12- §1/§2) : un systeme n'edite jamais en
 * place, il ecrit une nouvelle instance via ComponentStore::set().
 */
final readonly class PlayerMentalSkills
{
    public function __construct(
        public int $vision,
        public int $composure,
        public int $leadership,
        public int $discipline,
        public int $command,
    ) {
    }
}
