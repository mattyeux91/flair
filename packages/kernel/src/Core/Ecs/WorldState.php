<?php

declare(strict_types=1);

namespace Flair\Kernel\Core\Ecs;

use Flair\Kernel\Core\Messaging\OutQueue;
use Flair\Kernel\Core\Messaging\Scheduler;

/**
 * Le monde : agrege l'allocation d'entites, un ComponentStore par type de
 * composant, les singletons (docs/12-modele-du-monde.md §2-§3 bis), et les
 * files inter-ticks (Scheduler/OutQueue, 13- §2/§3).
 *
 * Ces deux dernieres rejoignent WorldState precisement parce que step()
 * (11- §1) ne prend que WorldState + TickContext : rien d'autre ne
 * pourrait les faire survivre d'un appel a l'autre. C'est aussi ce qui
 * ferme un trou de durabilite reel - un evenement seulement planifie
 * (schedule()) n'emet aucun Fait tant qu'il n'est pas declenche, donc un
 * snapshot qui ignorerait le Scheduler le perdrait silencieusement a un
 * redemarrage du Host.
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

    public function __construct(
        private EntityIdAllocator $entityIds = new EntityIdAllocator(),
        private Scheduler $scheduler = new Scheduler(),
        private OutQueue $outQueue = new OutQueue(),
    ) {
    }

    public function scheduler(): Scheduler
    {
        return $this->scheduler;
    }

    public function outQueue(): OutQueue
    {
        return $this->outQueue;
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
