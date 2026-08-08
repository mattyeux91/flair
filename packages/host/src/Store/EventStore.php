<?php

declare(strict_types=1);

namespace Flair\Host\Store;

use Flair\Host\Database\Database;
use Flair\Kernel\Core\Messaging\DomainEvent;
use Flair\Kernel\Core\Snapshot\TypeRegistry;
use Flair\Kernel\Core\Snapshot\ValueCodec;

/**
 * L'event log : append-only, jamais modifie, jamais supprime (docs/13- §5 -
 * « l'event log est la verite du passe », docs/13- §6).
 *
 * `type` prend la **cle stable** du `TypeRegistry`, jamais un FQCN. C'est le
 * second consommateur reel du registre, celui qui justifiait de l'ecrire au
 * lot snapshot plutot que par anticipation : renommer une classe d'evenement
 * ne doit pas rendre illisible l'histoire deja journalisee.
 *
 * `seq` est la position de l'evenement dans le lot du tick. L'OutQueue rend
 * deja ses evenements dans un ordre total (systemIndex, entityId, seq -
 * docs/13- §4.5) ; il suffit donc de la conserver, pas de la recalculer.
 * Couple a `(world_id, tick)` en cle primaire, elle interdit qu'une commande
 * rejouee duplique l'histoire.
 */
final class EventStore
{
    public function __construct(
        private readonly Database $database,
        private readonly TypeRegistry $types,
    ) {
    }

    /**
     * @param list<DomainEvent> $events
     * @return int le nombre de Faits journalises
     */
    public function append(string $worldId, int $tick, array $events): int
    {
        if ($events === []) {
            return 0;
        }

        $codec = new ValueCodec();
        $now = date('c');
        $rows = [];

        foreach ($events as $seq => $event) {
            $rows[] = [
                'world_id' => $worldId,
                'tick' => $tick,
                'seq' => $seq,
                'type' => $this->types->keyFor($event),
                'payload' => json_encode($codec->encode($event), JSON_THROW_ON_ERROR),
                'recorded_at' => $now,
            ];
        }

        $this->database->connection()->table('events')->insert($rows);

        return count($rows);
    }

    public function countFor(string $worldId): int
    {
        return $this->database->connection()->table('events')->where('world_id', $worldId)->count();
    }

    /**
     * Les Faits d'un monde dans un intervalle de ticks, **rehydrates** en
     * objets, dans l'ordre de l'histoire.
     *
     * ## Le miroir exact de `append()`
     *
     * L'ecriture traduit une classe en cle stable par `TypeRegistry::keyFor()` ;
     * la lecture fait le chemin inverse par `classFor()` puis `ValueCodec` -
     * les memes deux outils, dans l'autre sens. C'est pour ca que cette methode
     * vit ici et non cote lecteur : personne d'autre n'a a savoir que
     * `events.type` porte une cle de registre plutot qu'un FQCN.
     *
     * ## Ce que ce package n'apprend **pas** au passage
     *
     * Rien sur le football. On rend les Faits d'un intervalle, un point c'est
     * tout : savoir que `MatchPlayed::$homeClubId` designe un club est une
     * connaissance de **lecture**, elle appartient a `flair/api`. Une methode
     * `forClub()` ici ferait entrer le domaine dans la couche de persistance,
     * et il faudrait la retoucher a chaque Fait ajoute au noyau.
     *
     * Bornes incluses. `tail()` reste ce qu'il est - une lecture de debug pour
     * le CLI, bornee par une limite, qui rend des chaines.
     *
     * @return list<RecordedEvent>
     */
    public function between(string $worldId, int $fromTick, int $toTick): array
    {
        $rows = $this->database->connection()->table('events')
            ->where('world_id', $worldId)
            ->where('tick', '>=', $fromTick)
            ->where('tick', '<=', $toTick)
            ->orderBy('tick')
            ->orderBy('seq')
            ->get();

        $codec = new ValueCodec();
        $recorded = [];

        foreach ($rows as $row) {
            $payload = json_decode(Row::string($row, 'payload'), associative: true);

            $recorded[] = new RecordedEvent(
                tick: Row::int($row, 'tick'),
                seq: Row::int($row, 'seq'),
                event: $this->rehydrate($codec, Row::string($row, 'type'), $payload),
            );
        }

        return $recorded;
    }

    private function rehydrate(ValueCodec $codec, string $type, mixed $payload): DomainEvent
    {
        $event = $codec->decode($this->types->classFor($type), $payload);

        // Le registre pourrait rendre la classe d'un composant si une cle
        // d'evenement etait un jour reutilisee ailleurs. `TypeRegistry`
        // l'interdit deja (cles uniques toutes familles confondues), mais un
        // event log est de l'etat de monde : on verifie plutot que de rendre
        // un objet dont l'appelant a promis le type.
        return $event instanceof DomainEvent
            ? $event
            : throw new UnexpectedEventType($type, $event::class);
    }

    /**
     * Les derniers Faits d'un monde, du plus recent au plus ancien - de quoi
     * regarder ce qui vient de se passer depuis la CLI. Les projections et le
     * digest de retour d'absence (docs/14- §9) sont Phase 4 ; ceci n'est pas
     * leur ebauche, juste une lecture de debug.
     *
     * @return list<array{tick: int, seq: int, type: string, payload: string}>
     */
    public function tail(string $worldId, int $limit = 20): array
    {
        $rows = $this->database->connection()->table('events')
            ->where('world_id', $worldId)
            ->orderByDesc('tick')
            ->orderByDesc('seq')
            ->limit($limit)
            ->get();

        $tail = [];
        foreach ($rows as $row) {
            $tail[] = [
                'tick' => Row::int($row, 'tick'),
                'seq' => Row::int($row, 'seq'),
                'type' => Row::string($row, 'type'),
                'payload' => Row::string($row, 'payload'),
            ];
        }

        return $tail;
    }
}
