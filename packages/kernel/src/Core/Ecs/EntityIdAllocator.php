<?php

declare(strict_types=1);

namespace Flair\Kernel\Core\Ecs;

/**
 * Distribue des EntityId : des entiers opaques, stables, jamais reutilises
 * (docs/12-modele-du-monde.md §2). Un simple compteur monotone suffit : rien
 * n'est jamais "libere", donc aucune collision n'est possible par construction.
 *
 * 0 n'est jamais alloue : reserve comme valeur sentinelle "aucune entite".
 */
final class EntityIdAllocator
{
    public function __construct(private int $next = 1)
    {
    }

    public function allocate(): int
    {
        return $this->next++;
    }

    /**
     * Le prochain identifiant, sans le consommer : la seule facon de faire
     * survivre le compteur a un redemarrage (Core\Snapshot\SnapshotCodec).
     * La restauration passe par le constructeur.
     */
    public function next(): int
    {
        return $this->next;
    }
}
