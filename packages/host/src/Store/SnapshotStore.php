<?php

declare(strict_types=1);

namespace Flair\Host\Store;

use Flair\Host\Database\Database;
use Flair\Kernel\Core\Snapshot\SnapshotFormatException;
use Flair\Kernel\Core\Snapshot\WorldSnapshot;

/**
 * Les snapshots d'un monde, un par tick (docs/13- §5, revise au lot snapshot :
 * plus de rejeu du delta).
 *
 * ## Pourquoi le chargement passe par `WorldSnapshot::fromArray()`
 *
 * On pourrait reconstruire l'enveloppe directement depuis les colonnes. On ne
 * le fait pas : `fromArray()` porte les gardes de version - format inconnu ou
 * `kernelVersion` different **levent**, au lieu de laisser un monde repartir
 * sur un etat que ce noyau ne sait plus lire. Contourner ces gardes pour
 * economiser un tableau intermediaire serait echanger la seule protection
 * contre un rejeu deguise (docs/13- §6) contre rien.
 *
 * ## La retention
 *
 * Un snapshot par tick et rien qui les efface ferait croitre la base sans fin
 * (0,38 Mo par tick au monde de reference). `prune()` ne garde que les N
 * derniers, dans la meme transaction que l'ecriture. N > 1 n'est pas
 * necessaire a la correction - la transaction garantit deja qu'un tick
 * echoue en bloc ou reussit en bloc - mais garder quelques ticks derriere soi
 * rend inspectable un bug qui ne se voit qu'apres coup.
 */
final class SnapshotStore
{
    public const int DEFAULT_RETENTION = 3;

    public function __construct(private readonly Database $database)
    {
    }

    public function save(WorldSnapshot $snapshot): void
    {
        $this->database->connection()->table('snapshots')->insert([
            'world_id' => $snapshot->worldId,
            'tick' => $snapshot->tick,
            'format' => $snapshot->format,
            'kernel_version' => $snapshot->kernelVersion,
            'ruleset_version' => $snapshot->rulesetVersion,
            'seed' => $snapshot->seed,
            'state' => json_encode($snapshot->state, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
            'written_at' => date('c'),
        ]);
    }

    public function latest(string $worldId): ?WorldSnapshot
    {
        $row = $this->database->connection()->table('snapshots')
            ->where('world_id', $worldId)
            ->orderByDesc('tick')
            ->first();

        if ($row === null) {
            return null;
        }

        $state = json_decode(Row::string($row, 'state'), associative: true);
        if (!is_array($state)) {
            throw SnapshotFormatException::malformed("etat illisible pour le monde \"{$worldId}\"");
        }

        /** @var array<string, mixed> $state */
        return WorldSnapshot::fromArray([
            'format' => Row::int($row, 'format'),
            'kernelVersion' => Row::string($row, 'kernel_version'),
            'rulesetVersion' => Row::string($row, 'ruleset_version'),
            'worldId' => Row::string($row, 'world_id'),
            'tick' => Row::int($row, 'tick'),
            'seed' => Row::int($row, 'seed'),
            'state' => $state,
        ]);
    }

    /** Ne garde que les `$retention` snapshots les plus recents. */
    public function prune(string $worldId, int $retention = self::DEFAULT_RETENTION): int
    {
        $keep = array_map(
            Row::toInt(...),
            $this->database->connection()->table('snapshots')
                ->where('world_id', $worldId)
                ->orderByDesc('tick')
                ->limit(max(1, $retention))
                ->pluck('tick')
                ->all(),
        );

        if ($keep === []) {
            return 0;
        }

        return $this->database->connection()->table('snapshots')
            ->where('world_id', $worldId)
            ->whereNotIn('tick', $keep)
            ->delete();
    }
}
