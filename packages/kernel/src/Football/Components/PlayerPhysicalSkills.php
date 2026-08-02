<?php

declare(strict_types=1);

namespace Flair\Kernel\Football\Components;

/**
 * Les attributs physiques d'un joueur (docs/12-modele-du-monde.md §5).
 * Portes par tout joueur, gardien inclus - un gardien court, saute, dure.
 *
 * `reflexes` (temps de reaction/explosivite) vit ici plutot que dans un
 * composant "gardien" a part : c'est un attribut physique dans son
 * comportement de vieillissement, meme s'il n'est presentable qu'au poste
 * de gardien - la categorisation suit le comportement (physique/technique/
 * mental), pas le domaine metier.
 *
 * Readonly comme tout composant (12- §1/§2) : un systeme n'edite jamais en
 * place, il ecrit une nouvelle instance via ComponentStore::set().
 */
final readonly class PlayerPhysicalSkills
{
    public function __construct(
        public int $pace,
        public int $stamina,
        public int $strength,
        public int $reflexes,
    ) {
    }
}
