<?php

declare(strict_types=1);

namespace Flair\Host\Database;

/**
 * La connexion a la base, lue dans l'environnement.
 *
 * `getenv()` est interdit **dans le noyau** (docs/11- §1) parce qu'il en
 * romprait la purete ; `host` est precisement la couche dont le role est de
 * faire ces I/O-la. La frontiere passe ici, pas ailleurs.
 *
 * Les valeurs par defaut sont celles du `docker-compose.yml` a la racine du
 * repo : un `docker compose up -d db` suffit a rendre le monde executable,
 * sans fichier de configuration a ecrire.
 */
final readonly class DatabaseConfig
{
    public function __construct(
        public string $host = '127.0.0.1',
        public int $port = 54329,
        public string $database = 'flair',
        public string $username = 'flair',
        public string $password = 'flair',
    ) {
    }

    public static function fromEnvironment(): self
    {
        $defaults = new self();

        return new self(
            host: self::env('FLAIR_DB_HOST') ?? $defaults->host,
            port: (int) (self::env('FLAIR_DB_PORT') ?? $defaults->port),
            database: self::env('FLAIR_DB_NAME') ?? $defaults->database,
            username: self::env('FLAIR_DB_USER') ?? $defaults->username,
            password: self::env('FLAIR_DB_PASSWORD') ?? $defaults->password,
        );
    }

    /** @return array<string, mixed> la forme attendue par Illuminate\Database */
    public function toArray(): array
    {
        return [
            'driver' => 'pgsql',
            'host' => $this->host,
            'port' => $this->port,
            'database' => $this->database,
            'username' => $this->username,
            'password' => $this->password,
            'charset' => 'utf8',
            'prefix' => '',
            'schema' => 'public',
        ];
    }

    private static function env(string $name): ?string
    {
        $value = getenv($name);

        return $value === false || $value === '' ? null : $value;
    }
}
