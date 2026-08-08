<?php

declare(strict_types=1);

namespace Flair\Api\Tests;

use Flair\Api\Tests\Support\WorldFixture;
use Flair\Host\Database\Database;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

/**
 * Socle des tests **HTTP** : l'application Laravel plus une vraie base
 * PostgreSQL.
 *
 * A ne pas confondre avec `ReadTestCase`, qui ne boote rien. La difference
 * n'est pas cosmetique : ceci sert a tester des routes, des contrôleurs et le
 * rendu, qui n'existent pas sans application. Tout ce qui **lit un monde** doit
 * au contraire passer par `ReadTestCase`, precisement pour prouver que la
 * couche de lecture n'a pas besoin de ce qui est monte ici.
 *
 * La connexion vient du **conteneur**, donc de `config/flair.php`, donc du
 * `.env` - contrairement a `ReadTestCase` qui la prend dans l'environnement.
 * C'est le comportement de production, et c'est ce qu'on veut eprouver ici.
 *
 * Meme doctrine que `Host\Tests\DatabaseTestCase` : une **vraie** base, jamais
 * un double. Ce paquet ne contient a peu pres aucune logique propre, il lit un
 * monde - un double ne testerait que le double, et laisserait passer ce qui
 * compte, a savoir qu'un snapshot ecrit par le Host se relit et se presente
 * correctement. On ne peut pas heriter de la classe de `host` : son
 * `autoload-dev` ne sort pas de son paquet.
 */
abstract class TestCase extends BaseTestCase
{
    protected WorldFixture $world;

    public function createApplication(): Application
    {
        /** @var Application $app */
        $app = require __DIR__ . '/../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->world = new WorldFixture($this->app->make(Database::class));

        $error = $this->world->reachable();

        if ($error !== null) {
            self::markTestSkipped("Aucune base PostgreSQL joignable (docker compose up -d db) : {$error}");
        }

        $this->world->installSchema();
    }

    protected function tearDown(): void
    {
        $this->world->forgetAll();

        parent::tearDown();
    }
}
