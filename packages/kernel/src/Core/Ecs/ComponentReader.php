<?php

declare(strict_types=1);

namespace Flair\Kernel\Core\Ecs;

/**
 * Vue lecture seule d'une colonne de composants.
 *
 * Existe pour que `Core\Pipeline\SystemContext::read()` puisse rendre un
 * handle qui n'a physiquement pas de `set()`/`remove()` : la faute
 * "j'ecris un composant que je n'ai pas declare en writes()" devient une
 * erreur d'analyse statique (PHPStan niveau max est obligatoire sur
 * `kernel`), pas seulement une exception au premier tick.
 *
 * **Pas la moitie lecture d'une paire.** Son vis-a-vis en ecriture est
 * `Core\Pipeline\GuardedComponentWriter`, et il vit ailleurs a dessein :
 * celui-ci est une *capacite* de l'ECS ("ce qu'une colonne sait faire"),
 * l'autre est un *garde* portant les droits d'un systeme sur un tick. Voir
 * le docblock de `GuardedComponentWriter` pour le detail.
 *
 * @template T
 */
interface ComponentReader
{
    /** @return T|null */
    public function get(int $entity): mixed;

    /**
     * @return list<int>
     *
     * Toujours trie par EntityId croissant (docs/12- §2, docs/13- §4.2).
     */
    public function entities(): array;
}
