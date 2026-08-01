<?php

declare(strict_types=1);

namespace Flair\Kernel\Football;

/**
 * Les attributs techniques d'un joueur (docs/12-modele-du-monde.md §5).
 * Portes par tout joueur, gardien inclus - un gardien releve aussi de champ
 * apres une exclusion ou une blessure du titulaire, et joue alors avec ces
 * attributs.
 *
 * `handling`/`distribution` (le geste du gardien : captation, relance) vivent
 * ici plutot que dans un composant "gardien" a part : ce sont des attributs
 * techniques dans leur comportement de vieillissement (le geste ne se perd
 * pas comme l'explosivite), meme s'ils ne sont presentables qu'au poste de
 * gardien - la categorisation suit le comportement, pas le domaine metier.
 *
 * Readonly comme tout composant (12- §1/§2) : un systeme n'edite jamais en
 * place, il ecrit une nouvelle instance via ComponentStore::set().
 */
final readonly class PlayerTechnicalSkills
{
    public function __construct(
        public int $technique,
        public int $passing,
        public int $finishing,
        public int $defending,
        public int $positioning,
        public int $handling,
        public int $distribution,
    ) {
    }
}
