<?php

declare(strict_types=1);

namespace Flair\Kernel\Core\Pipeline;

use Flair\Kernel\Core\Ecs\ComponentReader;
use Flair\Kernel\Core\Ecs\WorldState;
use Flair\Kernel\Core\Messaging\DecisionRequest;
use Flair\Kernel\Core\Messaging\DomainEvent;
use Flair\Kernel\Core\Messaging\Intent;
use Flair\Kernel\Core\Messaging\OutQueue;
use Flair\Kernel\Core\Messaging\RequestQueue;
use Flair\Kernel\Core\Messaging\Scheduler;
use Flair\Kernel\Core\Ruleset\Ruleset;
use Flair\Kernel\Core\Support\Hash;
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
        private RequestQueue $requests,
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
     * Pose une question a un decideur **hors du noyau** (docs/16- §1) : elle
     * sort par `Core\Simulation\StepResult::$requests`, n'entre ni dans
     * l'OutQueue ni dans l'event log, et aucun systeme ne la lira.
     *
     * Le jumeau d'`emit()`, et la difference tient en une phrase : `emit()`
     * dit ce qui **a eu lieu**, `ask()` demande ce qu'on **doit faire**.
     * Confondre les deux est precisement ce que docs/16- §1 interdit, et c'est
     * ce qui avait mis `Football\Requests\TransferCounterOffered` dans un
     * journal permanent alors qu'il attend une reponse.
     *
     * Ce qui rend la question survivable a un crash n'est pas ce message mais
     * l'**etat** qui la motive : voir le docblock de
     * `Core\Messaging\RequestQueue`.
     */
    public function ask(DecisionRequest $request, int $entityId): void
    {
        $this->requests->ask($request, $this->systemIndex, $entityId, $this->seq->next());
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

    /**
     * Derivation deterministe **invariante par tick**, pour les grandeurs qui
     * doivent rester stables d'une annee sur l'autre : l'erreur d'un
     * observateur sur un joueur est un biais, pas un bruit blanc re-tire a
     * chaque lecture (docs/12- §4).
     *
     * Ce n'est pas un flux RNG : aucune sequence, aucun etat, une valeur par
     * jeu d'arguments. `rng()` reste le seul chemin vers de l'aleatoire
     * sequentiel, et `$worldSeed` reste prive - un systeme ne peut pas se
     * fabriquer un PRNG global a partir d'ici.
     *
     * Volontairement **sans `systemId`**, contrairement a `rng()` : deux
     * systemes doivent obtenir la meme valeur pour les memes arguments, sans
     * quoi la valorisation d'un joueur par le marche percevrait ce joueur
     * autrement que le systeme de contrats, le meme jour, dans le meme monde.
     */
    public function stableHash(int ...$values): int
    {
        return Hash::mixAll($this->worldSeed, ...$values);
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
