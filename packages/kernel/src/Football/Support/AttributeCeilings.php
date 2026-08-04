<?php

declare(strict_types=1);

namespace Flair\Kernel\Football\Support;

/**
 * Le plafond de chacun des seize attributs d'un joueur, derive de son
 * `ceiling` scalaire et de son archetype par
 * `Football\Support\PositionModel::ceilings()`.
 *
 * **Derive, jamais stocke** : c'est une fonction pure de
 * `(ceiling, archetype, PositionBalance)`, recalculee a chaque tick par
 * `Football\PlayerDevelopmentSystem`. Le stocker ferait un dix-septieme etat a
 * garder coherent avec le `ceiling` pour zero gain - meme raisonnement que la
 * perception (docs/12- §4), qui n'est jamais stockee non plus.
 *
 * Un objet plat plutot que trois (physique/technique/mental) : la coupure en
 * trois composants suit le **comportement de vieillissement** (docs/12- §5),
 * qui ne concerne pas les plafonds. Son seul consommateur les lit attribut par
 * attribut.
 */
final readonly class AttributeCeilings
{
    public function __construct(
        // Physique
        public int $pace,
        public int $stamina,
        public int $strength,
        public int $reflexes,
        // Technique
        public int $technique,
        public int $passing,
        public int $finishing,
        public int $defending,
        public int $positioning,
        public int $handling,
        public int $distribution,
        // Mental
        public int $vision,
        public int $composure,
        public int $leadership,
        public int $discipline,
        public int $command,
    ) {
    }
}
