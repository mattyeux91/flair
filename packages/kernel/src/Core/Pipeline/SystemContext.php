<?php

declare(strict_types=1);

namespace Flair\Kernel\Core\Pipeline;

use Flair\Kernel\Core\Ecs\ComponentReader;
use Flair\Kernel\Core\Ecs\WorldState;
use Flair\Kernel\Core\Messaging\DomainEvent;
use Flair\Kernel\Core\Messaging\Intent;
use Flair\Kernel\Core\Messaging\OutQueue;
use Flair\Kernel\Core\Messaging\Scheduler;
use Flair\Kernel\Core\Ruleset\Ruleset;
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
 * `SystemAccess::$systemId` (distinct de `systemIndex`) sert uniquement a
 * deriver le flux RNG du systeme (docs/13- §4.1) : contrairement a l'index,
 * il ne bouge pas si le pipeline est reordonne.
 *
 * `ruleset`/`intents` viennent du `TickContext` (11- §1) : exposes des
 * maintenant meme si aucun systeme du domaine football n'existe encore pour
 * les lire, comme `rng()` avant le premier systeme concret.
 *
 * **Oppose les declarations du systeme** (`reads`/`writes`/`creates`/
 * `removes`, docs/13- §2). C'est la raison d'etre du couple `read()`/
 * `write()` : `Football\PipelineInvariantsTest` ne compare que des
 * declarations entre elles, il ne peut structurellement pas voir un acces
 * non declare. Sans garde ici, les declarations resteraient des
 * commentaires - et l'ordre du pipeline, qui se deduit d'elles, serait
 * deduit d'un mensonge possible.
 *
 * Le controle porte sur `SystemContext`, jamais sur l'ECS : `WorldState`
 * garde un acces libre, parce que worldgen et le harness ecrivent le monde
 * initial sans etre des systemes.
 */
final readonly class SystemContext
{
    /** @param list<Intent> $intents */
    public function __construct(
        public int $tick,
        private int $systemIndex,
        private SystemAccess $access,
        private int $worldSeed,
        private Ruleset $ruleset,
        private array $intents,
        private WorldState $world,
        private Scheduler $scheduler,
        private OutQueue $outQueue,
        private SeqCounter $seq,
        private CreatedEntities $created = new CreatedEntities(),
    ) {
    }

    /**
     * Vue lecture seule, exigeant `$componentType` dans `reads()`.
     *
     * @template T of object
     * @param class-string<T> $componentType
     * @return ComponentReader<T>
     */
    public function read(string $componentType): ComponentReader
    {
        if (!$this->access->mayRead($componentType)) {
            throw UndeclaredAccessException::read($this->access->systemId, $componentType);
        }

        return $this->world->components($componentType);
    }

    /**
     * Handle de mutation, exigeant `$componentType` dans `writes()`,
     * `creates()` ou `removes()`. Lequel des trois autorise quoi est
     * tranche par le handle lui-meme (GuardedComponentWriter), au plus pres
     * de l'appel fautif.
     *
     * @template T of object
     * @param class-string<T> $componentType
     * @return GuardedComponentWriter<T>
     */
    public function write(string $componentType): GuardedComponentWriter
    {
        if (!$this->access->maySet($componentType) && !$this->access->mayRemove($componentType)) {
            throw UndeclaredAccessException::write($this->access->systemId, $componentType);
        }

        return new GuardedComponentWriter(
            $this->world->components($componentType),
            $this->access,
            $this->created,
            $componentType,
        );
    }

    /**
     * Retient l'entite creee : c'est ce qui rend `creates()` verifiable
     * plutot que declaratif (voir CreatedEntities).
     */
    public function createEntity(): int
    {
        $entity = $this->world->createEntity();
        $this->created->add($entity);

        return $entity;
    }

    /**
     * Les singletons passent par les memes declarations que les composants :
     * `MonetaryMass` figure deja dans les `reads()`/`writes()` de
     * `Football\FinanceSystem` (docs/12- §3 bis).
     *
     * @template T of object
     * @param class-string<T> $type
     * @return T|null
     */
    public function singleton(string $type): ?object
    {
        if (!$this->access->mayRead($type)) {
            throw UndeclaredAccessException::read($this->access->systemId, $type);
        }

        return $this->world->singleton($type);
    }

    public function setSingleton(object $value): void
    {
        if (!$this->access->maySet($value::class)) {
            throw UndeclaredAccessException::write($this->access->systemId, $value::class);
        }

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
        return Rng::forStream($this->worldSeed, $this->tick, $this->access->systemId, $entityId);
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
