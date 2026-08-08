<?php

declare(strict_types=1);

namespace Flair\Api\Read;

use Flair\Host\Store\SnapshotStore;
use Flair\Host\Store\WorldRepository;
use Flair\Kernel\Core\Snapshot\SnapshotCodec;

/**
 * La porte d'entree de toute lecture : un `worldId` en entree, un monde decode
 * en sortie.
 *
 * ## Pourquoi il n'y a ni projection ni cache
 *
 * Mesure du lot 0 (monde de reference, 500 joueurs / 18 clubs, dix ans) :
 * **14 ms et 18 Mo** pour reconstituer le monde entier - 5,7 ms de lecture en
 * base et de `json_decode`, 8,3 ms de decodage en `WorldState`. A ce prix, une
 * table de projection serait de la denormalisation sans probleme a resoudre,
 * et un cache un invalidateur a maintenir pour gagner dix millisecondes.
 *
 * Le monde ne change d'ailleurs qu'une fois par heure en production (un tick =
 * un jour, declenche par cron) : le snapshot relu est le meme pendant 3 600
 * secondes. Si un jour le cout devient genant, c'est **la** qu'un cache par
 * tick se posera - un seul endroit, et une cle evidente.
 *
 * ## Aucun code de persistance neuf
 *
 * Tout vient de `flair/host` : `WorldRepository` pour l'identite,
 * `SnapshotStore::latest()` pour l'etat. En particulier le chargement passe
 * par `WorldSnapshot::fromArray()` (dans `SnapshotStore`), donc par les gardes
 * de version - un monde ecrit par un noyau que celui-ci ne sait plus lire
 * **leve**, au lieu de s'afficher a moitie faux.
 */
final readonly class WorldReader
{
    public function __construct(
        private WorldRepository $worlds,
        private SnapshotStore $snapshots,
        private SnapshotCodec $codec,
    ) {
    }

    /** `null` si le monde n'existe pas, ou n'a aucun snapshot pour le reconstituer. */
    public function load(string $worldId): ?LoadedWorld
    {
        $record = $this->worlds->find($worldId);

        if ($record === null) {
            return null;
        }

        $snapshot = $this->snapshots->latest($worldId);

        if ($snapshot === null) {
            return null;
        }

        return new LoadedWorld($record, $snapshot->tick, $snapshot->restore($this->codec));
    }
}
