<?php

declare(strict_types=1);

namespace Flair\Host\Tests;

use Flair\Host\Database\Database;
use Flair\Host\Database\Schema;
use Flair\Host\Store\EventStore;
use Flair\Host\Store\SnapshotStore;
use Flair\Host\Store\WorldRepository;
use Flair\Kernel\Football\FootballTypes;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Socle des tests de `host` : une **vraie** base PostgreSQL, jamais un double.
 *
 * Ce package n'a pour ainsi dire pas de logique propre - ce qu'il apporte est
 * *ce que la base garantit* : une transaction atomique et un verrou advisory.
 * Un double en memoire ne testerait que le double, et laisserait passer
 * exactement les bugs que ce lot existe pour exclure.
 *
 * La suite se **skippe** proprement si aucune base n'est joignable, plutot que
 * d'echouer : un `docker compose up -d db` manquant est une machine mal
 * preparee, pas une regression du code. En CI, ce sera un service Postgres.
 */
abstract class DatabaseTestCase extends TestCase
{
    protected Database $database;
    protected WorldRepository $worlds;
    protected SnapshotStore $snapshots;
    protected EventStore $events;

    /** @var list<string> mondes crees par le test, effaces a la fin */
    private array $created = [];

    protected function setUp(): void
    {
        $this->database = Database::fromEnvironment();

        try {
            $this->database->connection()->select('select 1');
        } catch (Throwable $error) {
            self::markTestSkipped(
                'Aucune base PostgreSQL joignable (docker compose up -d db) : ' . $error->getMessage(),
            );
        }

        (new Schema($this->database))->install();

        $this->worlds = new WorldRepository($this->database);
        $this->snapshots = new SnapshotStore($this->database);
        $this->events = new EventStore($this->database, FootballTypes::registry());
    }

    protected function tearDown(): void
    {
        foreach ($this->created as $worldId) {
            $this->forget($worldId);
        }

        $this->created = [];
    }

    /**
     * Un identifiant unique par test : les tests partagent une base et
     * doivent pouvoir tourner sans s'annuler l'un l'autre.
     */
    protected function newWorldId(string $hint): string
    {
        $worldId = sprintf('test-%s-%s', $hint, bin2hex(random_bytes(4)));
        $this->created[] = $worldId;

        return $worldId;
    }

    protected function forget(string $worldId): void
    {
        $connection = $this->database->connection();

        foreach (['events', 'snapshots', 'worlds'] as $table) {
            $column = $table === 'worlds' ? 'id' : 'world_id';
            $connection->table($table)->where($column, $worldId)->delete();
        }
    }
}
