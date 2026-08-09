<?php

declare(strict_types=1);

namespace Flair\Api\Tests\Support;

use Flair\Host\AdvanceWorld;
use Flair\Host\CreateWorld;
use Flair\Host\Database\Database;
use Flair\Host\Database\Schema;
use Flair\Host\Store\EventStore;
use Flair\Host\Store\SnapshotStore;
use Flair\Host\Store\WorldRepository;
use Flair\Host\WorldLock;
use Flair\Kernel\Football\FootballTypes;
use Flair\Worldgen\WorldSpec;

/**
 * De quoi semer un monde jetable en base, et le reprendre a la fin.
 *
 * Extrait parce qu'il a **deux consommateurs reels** - `Tests\TestCase`, qui
 * boote Laravel pour les tests HTTP, et `Tests\ReadTestCase`, qui ne le boote
 * pas. C'est le critere d'extraction du projet, satisfait sans etre force : sans
 * ces deux bases, ce code serait reste dans l'unique classe qui en avait besoin.
 *
 * Ne connait rien de Laravel, pour la meme raison que `Flair\Api\Read\` : c'est
 * ce qui lui permet de servir les deux.
 */
final class WorldFixture
{
    public readonly WorldRepository $worlds;
    public readonly SnapshotStore $snapshots;
    public readonly EventStore $events;

    /** @var list<string> */
    private array $created = [];

    public function __construct(public readonly Database $database)
    {
        $this->worlds = new WorldRepository($database);
        $this->snapshots = new SnapshotStore($database);
        $this->events = new EventStore($database, FootballTypes::registry());
    }

    public function reachable(): ?string
    {
        try {
            $this->database->connection()->select('select 1');
        } catch (\Throwable $error) {
            return $error->getMessage();
        }

        return null;
    }

    public function installSchema(): void
    {
        (new Schema($this->database))->install();
    }

    /**
     * Un monde neuf, sous un identifiant unique : les tests partagent une base
     * et doivent pouvoir tourner sans s'annuler l'un l'autre.
     *
     * 60 joueurs / 4 clubs, comme `Host\Tests\PersistedWorldMatchesMemoryTest` :
     * quinze joueurs par club, assez pour composer un onze reel sans saturer
     * les effectifs.
     */
    public function create(string $hint, int $players = 60, int $clubs = 4, int $seed = 42): string
    {
        $worldId = sprintf('test-api-%s-%s', $hint, bin2hex(random_bytes(4)));
        $this->created[] = $worldId;

        (new CreateWorld($this->database, $this->worlds, $this->snapshots))(
            $worldId,
            new WorldSpec(playerCount: $players, seed: $seed, clubCount: $clubs),
        );

        return $worldId;
    }

    public function advance(string $worldId, int $ticks): void
    {
        $advance = new AdvanceWorld(
            $this->database,
            $this->worlds,
            $this->events,
            $this->snapshots,
            new WorldLock($this->database),
        );

        for ($i = 0; $i < $ticks; $i++) {
            $advance($worldId);
        }
    }

    public function forgetAll(): void
    {
        $connection = $this->database->connection();

        foreach ($this->created as $worldId) {
            foreach (['events', 'snapshots', 'worlds'] as $table) {
                $connection->table($table)->where($table === 'worlds' ? 'id' : 'world_id', $worldId)->delete();
            }
        }

        $this->created = [];
    }
}
