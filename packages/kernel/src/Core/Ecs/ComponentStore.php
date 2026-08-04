<?php

declare(strict_types=1);

namespace Flair\Kernel\Core\Ecs;

/**
 * Stockage d'un type de composant : une colonne indexee par entite.
 *
 * Les composants sont des donnees plates en lecture seule (docs/12- §1-§2) :
 * un systeme n'edite jamais un composant en place, il en ecrit un nouveau
 * via set().
 *
 * Acces complet, sans controle de declaration : c'est le stockage brut, et
 * worldgen/harness l'ecrivent legitimement via WorldState (ils ne sont pas
 * des systemes). Un System, lui, n'y touche jamais directement - il passe
 * par SystemContext::read()/write(), qui opposent ses declarations
 * (docs/13- §2).
 *
 * @template T
 * @implements ComponentReader<T>
 */
final class ComponentStore implements ComponentReader
{
    /** @var array<int, T> */
    private array $components = [];

    /** @param T $component */
    public function set(int $entity, mixed $component): void
    {
        $this->components[$entity] = $component;
    }

    /** @return T|null */
    public function get(int $entity): mixed
    {
        return $this->components[$entity] ?? null;
    }

    public function remove(int $entity): void
    {
        unset($this->components[$entity]);
    }

    /**
     * @return list<int>
     *
     * Toujours trie par EntityId croissant : ne jamais renvoyer l'ordre
     * d'insertion, c'est la source n°1 de non-reproductibilite silencieuse
     * (docs/12- §2, docs/13- §4.2).
     */
    public function entities(): array
    {
        $ids = array_keys($this->components);
        sort($ids);

        return $ids;
    }
}
