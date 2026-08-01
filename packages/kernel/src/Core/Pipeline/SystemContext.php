<?php

declare(strict_types=1);

namespace Flair\Kernel\Core\Pipeline;

use Flair\Kernel\Core\Ecs\ComponentStore;
use Flair\Kernel\Core\Ecs\WorldState;
use Flair\Kernel\Core\Messaging\DomainEvent;
use Flair\Kernel\Core\Messaging\Intent;
use Flair\Kernel\Core\Messaging\OutQueue;
use Flair\Kernel\Core\Messaging\Scheduler;
use Flair\Kernel\Core\Ruleset;
use Flair\Kernel\Core\Support\Rng;

/**
 * Facade unique par laquelle un System accede a tout : lecture/ecriture du
 * monde, planification, emission (docs/13-moteur-de-simulation.md §2 :
 * handle(DomainEvent $event, SystemContext $ctx), update(SystemContext $ctx)).
 *
 * `systemIndex` et `seq` ne sont jamais exposes : connus a la construction,
 * necessaires uniquement au Scheduler/OutQueue sous-jacents. Un System n'a
 * jamais besoin de les lire lui-meme.
 *
 * `systemId` (distinct de `systemIndex`) sert uniquement a deriver le flux
 * RNG du systeme (docs/13- §4.1) : contrairement a l'index, il ne bouge pas
 * si le pipeline est reordonne.
 *
 * `ruleset`/`intents` viennent du `TickContext` (11- §1) : exposes des
 * maintenant meme si aucun systeme du domaine football n'existe encore pour
 * les lire, comme `rng()` avant le premier systeme concret.
 *
 * N'applique pas les declarations reads()/writes() de System : ce controle
 * porte sur l'ensemble des systemes du pipeline, pas sur un contexte isole
 * (voir le plan associe a cette classe).
 */
final readonly class SystemContext
{
    /** @param list<Intent> $intents */
    public function __construct(
        public int $tick,
        private int $systemIndex,
        private string $systemId,
        private int $worldSeed,
        private Ruleset $ruleset,
        private array $intents,
        private WorldState $world,
        private Scheduler $scheduler,
        private OutQueue $outQueue,
        private SeqCounter $seq,
    ) {
    }

    /**
     * @template T of object
     * @param class-string<T> $componentType
     * @return ComponentStore<T>
     */
    public function components(string $componentType): ComponentStore
    {
        return $this->world->components($componentType);
    }

    public function createEntity(): int
    {
        return $this->world->createEntity();
    }

    /**
     * @template T of object
     * @param class-string<T> $type
     * @return T|null
     */
    public function singleton(string $type): ?object
    {
        return $this->world->singleton($type);
    }

    public function setSingleton(object $value): void
    {
        $this->world->setSingleton($value);
    }

    public function schedule(DomainEvent $event, int $atTick, int $entityId): void
    {
        $this->scheduler->schedule($event, $atTick, $this->systemIndex, $entityId, $this->seq->next());
    }

    public function emit(DomainEvent $event, int $entityId): void
    {
        $this->outQueue->emit($event, $this->systemIndex, $entityId, $this->seq->next());
    }

    /**
     * Flux RNG isole pour cette entite, ce systeme, ce tick et ce monde
     * (docs/13- §4.1). Jamais un PRNG global partage : deux appels avec le
     * meme (worldSeed, tick, systemId, entityId) renvoient toujours la meme
     * sequence.
     */
    public function rng(int $entityId): Rng
    {
        return Rng::forStream($this->worldSeed, $this->tick, $this->systemId, $entityId);
    }

    public function ruleset(): Ruleset
    {
        return $this->ruleset;
    }

    /** @return list<Intent> */
    public function intents(): array
    {
        return $this->intents;
    }
}
