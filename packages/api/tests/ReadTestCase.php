<?php

declare(strict_types=1);

namespace Flair\Api\Tests;

use Flair\Api\Read\LoadedWorld;
use Flair\Api\Read\WorldReader;
use Flair\Api\Tests\Support\WorldFixture;
use Flair\Host\Database\Database;
use Flair\Kernel\Core\Snapshot\SnapshotCodec;
use Flair\Kernel\Football\FootballTypes;
use PHPUnit\Framework\TestCase as PhpUnitTestCase;

/**
 * Socle des tests de la couche de lecture - **sans booter Laravel**, et c'est
 * tout l'interet.
 *
 * Un test qui demarre une application HTTP pour verifier qu'un effectif est
 * trie par note teste deux choses au lieu d'une : si un provider casse, ce
 * test rougit alors qu'il n'a rien a dire sur les providers. Etendre
 * `PHPUnit\Framework\TestCase` au lieu de celui de Laravel **prouve** que
 * `Flair\Api\Read\` n'a besoin d'aucun framework, la ou
 * `Tests\Architecture\ReadLayerStaysFrameworkFreeTest` le verifie a la lecture
 * des imports. Les deux ensemble font que la frontiere `src/` vs `app/` est une
 * garantie et non une convention.
 *
 * ## La connexion vient de l'environnement, pas du `.env` de Laravel
 *
 * `DatabaseConfig::fromEnvironment()` lit par `getenv()`, avec les defauts du
 * `docker-compose.yml`. Sans application, il n'y a personne pour charger le
 * `.env` - c'est exactement la situation de la suite de `flair/host`, qui
 * fonctionne ainsi depuis la Phase 3.
 *
 * > ⚠️ Consequence a connaitre : si la base n'est pas sur les reglages par
 * > defaut, ces tests exigent de vrais `FLAIR_DB_*` **exportes dans
 * > l'environnement**, la ou les tests HTTP se contentent du `.env`. Les tests
 * > HTTP (`Tests\TestCase`) prennent leur connexion dans le conteneur, donc
 * > bien du `.env`.
 */
abstract class ReadTestCase extends PhpUnitTestCase
{
    protected WorldFixture $world;

    protected function setUp(): void
    {
        $this->world = new WorldFixture(Database::fromEnvironment());

        $error = $this->world->reachable();

        if ($error !== null) {
            self::markTestSkipped("Aucune base PostgreSQL joignable (docker compose up -d db) : {$error}");
        }

        $this->world->installSchema();
    }

    protected function tearDown(): void
    {
        $this->world->forgetAll();
    }

    protected function read(string $worldId): LoadedWorld
    {
        $world = (new WorldReader(
            $this->world->worlds,
            $this->world->snapshots,
            new SnapshotCodec(FootballTypes::registry()),
        ))->load($worldId);

        self::assertNotNull($world, "Le monde \"{$worldId}\" n'a pas pu etre reconstitue.");

        return $world;
    }
}
