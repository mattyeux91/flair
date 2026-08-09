<?php

declare(strict_types=1);

namespace App\Providers;

use Flair\Host\Database\Database;
use Flair\Host\Database\DatabaseConfig;
use Flair\Host\Store\EventStore;
use Flair\Host\Store\SnapshotStore;
use Flair\Host\Store\WorldRepository;
use Flair\Kernel\Core\Snapshot\SnapshotCodec;
use Flair\Kernel\Football\FootballTypes;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

/**
 * Le seul endroit ou Laravel et `flair/host` se touchent.
 *
 * ## Pourquoi `DatabaseConfig` est construit ici, et pas par `fromEnvironment()`
 *
 * `Host\Database\DatabaseConfig::fromEnvironment()` lit par `getenv()`, ce qui
 * est l'idiome juste pour le CLI du Host - c'est meme precisement la couche
 * dont ces I/O sont le role (docs/11- §1). Mais Laravel charge son `.env` dans
 * son **propre** depot de configuration, et `getenv()` ne le voit pas
 * forcement selon la facon dont l'adaptateur `putenv` de `vlucas/phpdotenv`
 * est configure. Dependre de ce recouvrement serait dependre d'un detail
 * d'implementation d'une dependance transitive.
 *
 * Les cinq valeurs sont donc lues dans `config/flair.php` et passees nommees.
 * `fromEnvironment()` reste au CLI, ou il a raison.
 *
 * ## Une seule connexion, et pas de facade
 *
 * `host` amorce son propre gestionnaire `illuminate/database` en mode Capsule,
 * sans `setAsGlobal()`. On enregistre cet objet en singleton du conteneur et on
 * le passe : l'application n'ouvre **pas** une seconde connexion Laravel vers
 * les memes tables, et personne n'est tente d'ecrire de l'Eloquent sur
 * `events`. C'est aussi pourquoi `config/database.php` reste inutilise, et
 * pourquoi cette application n'a aucune migration a elle.
 */
final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // La configuration est amorcee avant l'enregistrement des providers :
        // on peut donc la lire tout de suite et fermer sur des valeurs deja
        // verifiees, plutot que de refaire le controle a chaque resolution.
        $database = new DatabaseConfig(
            host: $this->text('flair.db.host'),
            port: $this->number('flair.db.port'),
            database: $this->text('flair.db.database'),
            username: $this->text('flair.db.username'),
            password: $this->text('flair.db.password'),
        );

        $this->app->singleton(Database::class, static fn (): Database => new Database($database));

        $this->app->singleton(
            SnapshotCodec::class,
            static fn (): SnapshotCodec => new SnapshotCodec(FootballTypes::registry()),
        );

        $this->app->singleton(
            WorldRepository::class,
            static fn (Application $app): WorldRepository => new WorldRepository($app->make(Database::class)),
        );

        $this->app->singleton(
            SnapshotStore::class,
            static fn (Application $app): SnapshotStore => new SnapshotStore($app->make(Database::class)),
        );

        $this->app->singleton(
            EventStore::class,
            static fn (Application $app): EventStore => new EventStore($app->make(Database::class), FootballTypes::registry()),
        );
    }

    /**
     * Verifier plutot que caster : `config()` rend du `mixed`, et un
     * `(string)` transformerait silencieusement `null` en chaine vide - donc
     * une connexion vers un hote vide au lieu d'une erreur lisible. Meme
     * discipline que `Host\Store\Row` sur les lignes du query builder.
     */
    private function text(string $key): string
    {
        $value = config($key);

        return is_string($value)
            ? $value
            : throw new InvalidArgumentException("Configuration `{$key}` : chaine attendue, " . get_debug_type($value) . ' trouve.');
    }

    /**
     * Accepte aussi une chaine de chiffres : un `.env` ne porte que du texte,
     * donc `FLAIR_DB_PORT=54329` arrive en `string`. Meme tolerance, pour la
     * meme raison, que `Host\Store\Row::int()` sur les `bigint` que PDO rend en
     * texte - et meme refus de tout le reste.
     */
    private function number(string $key): int
    {
        $value = config($key);

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
            return (int) $value;
        }

        throw new InvalidArgumentException("Configuration `{$key}` : entier attendu, " . get_debug_type($value) . ' trouve.');
    }
}
