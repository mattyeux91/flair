<?php

declare(strict_types=1);

namespace Flair\Kernel\Core\Snapshot;

use Flair\Kernel\Core\Ecs\EntityIdAllocator;
use Flair\Kernel\Core\Ecs\WorldState;
use Flair\Kernel\Core\Messaging\DomainEvent;
use Flair\Kernel\Core\Messaging\OutQueue;
use Flair\Kernel\Core\Messaging\Scheduler;

/**
 * Serialise et restaure l'integralite d'un WorldState (docs/13- §5).
 *
 * Pur et sans I/O : rend un tableau de donnees, n'ecrit nulle part. Le
 * stockage - Postgres, fichier, peu importe - est l'affaire du Host ; le
 * *format*, lui, appartient au noyau, parce que c'est le noyau qui definit la
 * forme de l'etat. Un composant qui change de forme casse alors le build du
 * noyau, pas le redemarrage d'un monde.
 *
 * Ce qui entre dans un snapshot, exhaustivement (cf. WorldState) :
 *
 * - le compteur d'entites - le perdre reattribuerait des EntityId deja
 *   utilises, ce qui casserait l'unicite promise par docs/12- §2 ;
 * - une colonne par type de composant present ;
 * - les singletons ;
 * - le Scheduler **et** l'OutQueue, avec leurs cles de tri. C'est la moitie
 *   qu'on oublie : un evenement seulement planifie n'a emis aucun Fait, donc
 *   un event log ne le rattraperait pas - il serait perdu pour de bon.
 *
 * Ce qui n'y entre pas : le tick et la graine (ils vivent dans TickContext,
 * pas dans l'etat - d'ou l'enveloppe WorldSnapshot), le Ruleset (le monde est
 * epingle a une version, docs/12- §6), SeqCounter et CreatedEntities (recrees
 * a chaque Pipeline::tick()).
 *
 * L'ordre d'ecriture est totalement determine - types tries par cle de
 * registre, entites par EntityId croissant via ComponentStore::entities() -
 * pour que deux snapshots du meme monde soient identiques octet pour octet.
 * Meme discipline que partout ailleurs (docs/12- §2, docs/13- §4.2) : jamais
 * l'ordre d'une map, jamais l'ordre d'insertion.
 */
final readonly class SnapshotCodec
{
    public function __construct(private TypeRegistry $types)
    {
    }

    /** @return array<string, mixed> */
    public function encode(WorldState $world): array
    {
        $values = new ValueCodec();

        return [
            'nextEntityId' => $world->nextEntityId(),
            'components' => $this->encodeComponents($world, $values),
            'singletons' => $this->encodeSingletons($world, $values),
            'scheduler' => $this->encodeScheduler($world, $values),
            'outQueue' => $this->encodeOutQueue($world, $values),
        ];
    }

    /** @param array<string, mixed> $state */
    public function decode(array $state): WorldState
    {
        $values = new ValueCodec();

        $scheduler = new Scheduler();
        foreach (self::listAt($state, 'scheduler') as $entry) {
            $scheduler->schedule(
                $this->decodeEvent($entry, $values),
                self::intAt($entry, 'atTick'),
                self::intAt($entry, 'systemIndex'),
                self::intAt($entry, 'entityId'),
                self::intAt($entry, 'seq'),
            );
        }

        $outQueue = new OutQueue();
        foreach (self::listAt($state, 'outQueue') as $entry) {
            $outQueue->emit(
                $this->decodeEvent($entry, $values),
                self::intAt($entry, 'systemIndex'),
                self::intAt($entry, 'entityId'),
                self::intAt($entry, 'seq'),
            );
        }

        $world = new WorldState(
            new EntityIdAllocator(self::intAt($state, 'nextEntityId')),
            $scheduler,
            $outQueue,
        );

        foreach (self::mapAt($state, 'components') as $key => $column) {
            $class = $this->types->classFor($key);
            $store = $world->components($class);

            if (!is_array($column)) {
                throw SnapshotFormatException::malformed("la colonne \"{$key}\" n'est pas un tableau");
            }

            foreach ($column as $entityId => $raw) {
                if (!is_int($entityId)) {
                    throw SnapshotFormatException::malformed("EntityId non entier dans la colonne \"{$key}\"");
                }

                $store->set($entityId, $values->decode($class, $raw));
            }
        }

        foreach (self::mapAt($state, 'singletons') as $key => $raw) {
            $world->setSingleton($values->decode($this->types->classFor($key), $raw));
        }

        return $world;
    }

    /** @return array<string, array<int, mixed>> */
    private function encodeComponents(WorldState $world, ValueCodec $values): array
    {
        $present = $world->componentTypes();
        foreach ($present as $class) {
            if (!$this->types->knows($class)) {
                throw SnapshotFormatException::unregisteredClass($class);
            }
        }

        $components = [];
        foreach ($this->types->componentClasses() as $class) {
            if (!in_array($class, $present, true)) {
                continue;
            }

            $store = $world->components($class);
            $column = [];
            foreach ($store->entities() as $entityId) {
                $component = $store->get($entityId);
                if ($component === null) {
                    continue;
                }

                $column[$entityId] = $values->encode($component);
            }

            if ($column !== []) {
                $components[$this->types->keyFor($class)] = $column;
            }
        }

        return $components;
    }

    /** @return array<string, mixed> */
    private function encodeSingletons(WorldState $world, ValueCodec $values): array
    {
        $singletons = [];
        foreach ($world->singletonInstances() as $singleton) {
            $singletons[$this->types->keyFor($singleton)] = $values->encode($singleton);
        }

        ksort($singletons);

        return $singletons;
    }

    /** @return list<array<string, mixed>> */
    private function encodeScheduler(WorldState $world, ValueCodec $values): array
    {
        $entries = [];
        foreach ($world->scheduler()->entries() as $entry) {
            $entries[] = [
                'atTick' => $entry->atTick,
                'systemIndex' => $entry->systemIndex,
                'entityId' => $entry->entityId,
                'seq' => $entry->seq,
                'event' => $this->encodeEvent($entry->event, $values),
            ];
        }

        return $entries;
    }

    /** @return list<array<string, mixed>> */
    private function encodeOutQueue(WorldState $world, ValueCodec $values): array
    {
        $entries = [];
        foreach ($world->outQueue()->entries() as $entry) {
            $entries[] = [
                'systemIndex' => $entry->systemIndex,
                'entityId' => $entry->entityId,
                'seq' => $entry->seq,
                'event' => $this->encodeEvent($entry->event, $values),
            ];
        }

        return $entries;
    }

    /** @return array{type: string, data: mixed} */
    private function encodeEvent(DomainEvent $event, ValueCodec $values): array
    {
        return [
            'type' => $this->types->keyFor($event),
            'data' => $values->encode($event),
        ];
    }

    /** @param array<string, mixed> $entry */
    private function decodeEvent(array $entry, ValueCodec $values): DomainEvent
    {
        $envelope = $entry['event'] ?? null;
        if (!is_array($envelope) || !isset($envelope['type']) || !is_string($envelope['type'])) {
            throw SnapshotFormatException::malformed('entree de file sans evenement type');
        }

        $event = $values->decode($this->types->classFor($envelope['type']), $envelope['data'] ?? null);

        return $event instanceof DomainEvent
            ? $event
            : throw SnapshotFormatException::malformed("\"{$envelope['type']}\" n'est pas un DomainEvent");
    }

    /**
     * @param array<string, mixed> $state
     * @return list<array<string, mixed>>
     */
    private static function listAt(array $state, string $key): array
    {
        $value = $state[$key] ?? [];
        if (!is_array($value)) {
            throw SnapshotFormatException::malformed("\"{$key}\" n'est pas une liste");
        }

        $entries = [];
        foreach ($value as $entry) {
            if (!is_array($entry)) {
                throw SnapshotFormatException::malformed("entree non structuree dans \"{$key}\"");
            }

            /** @var array<string, mixed> $entry */
            $entries[] = $entry;
        }

        return $entries;
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private static function mapAt(array $state, string $key): array
    {
        $value = $state[$key] ?? [];
        if (!is_array($value)) {
            throw SnapshotFormatException::malformed("\"{$key}\" n'est pas une table");
        }

        /** @var array<string, mixed> */
        return $value;
    }

    /** @param array<string, mixed> $entry */
    private static function intAt(array $entry, string $key): int
    {
        $value = $entry[$key] ?? null;

        return is_int($value)
            ? $value
            : throw SnapshotFormatException::malformed("\"{$key}\" absent ou non entier");
    }
}
