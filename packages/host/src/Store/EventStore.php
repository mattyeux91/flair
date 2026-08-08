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
