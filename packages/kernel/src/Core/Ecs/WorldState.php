<?php

declare(strict_types=1);

namespace Flair\Kernel\Core\Ecs;

/**
 * Le monde : agrege l'allocation d'entites, un ComponentStore par type de
 * composant, et les singletons (docs/12-modele-du-monde.md §2-§3 bis).
 *
 * N'expose volontairement aucune methode "toutes les entites du monde" :
 * une requete se fait toujours par intersection de colonnes (§2), jamais par
 * balayage global.
 */
final class WorldState
{
    /** @var array<class-string, ComponentStore<object>> */
    private array $componentStores = [];

    /** @var array<class-string, object> */
    private array $singletons = [];

    public function __construct(private EntityIdAllocator $entityIds = new EntityIdAllocator())
    {
    }

    public function createEntity(): int
    {
        return $this->entityIds->allocate();
    }

    /**
     * @template T of object
     * @param class-string<T> $componentType
     * @return ComponentStore<T>
     */
    public function components(string $componentType): ComponentStore
    {
        /** @var ComponentStore<T> */
        return $this->componentStores[$componentType] ??= new ComponentStore();
    }

    /** @param object $value */
    public function setSingleton(object $value): void
    {
        $this->singletons[$value::class] = $value;
    }

    /**
     * @template T of object
     * @param class-string<T> $type
     * @return T|null
     */
    public function singleton(string $type): ?object
    {
        /** @var T|null */
        return $this->singletons[$type] ?? null;
    }
}
