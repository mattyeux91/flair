<?php

declare(strict_types=1);

/**
 * La base du monde, telle que `flair/host` l'attend.
 *
 * Volontairement **pas** dans `config/database.php` : cette connexion n'est pas
 * celle de Laravel. Elle est passee a `Flair\Host\Database\DatabaseConfig`, qui
 * amorce son propre gestionnaire `illuminate/database` (mode Capsule, sans
 * facades). L'application n'ouvre donc qu'une connexion vers les tables du
 * monde, et personne n'est tente d'ecrire de l'Eloquent sur `events`.
 *
 * On lit ici par `env()`, jamais par `getenv()` : `Host\DatabaseConfig::
 * fromEnvironment()` utilise `getenv()`, ce qui est l'idiome juste pour le CLI
 * du Host mais ne voit pas forcement le `.env` que Laravel charge dans son
 * propre depot. Le provider construit donc `DatabaseConfig` explicitement, avec
 * ses cinq valeurs nommees, et `fromEnvironment()` reste au CLI.
 */
return [
    'db' => [
        'host' => env('FLAIR_DB_HOST', '127.0.0.1'),
        // Pas de `(int)` ici : `env()` rend du `mixed`, et un cast
        // transformerait silencieusement une valeur absurde en 0 - donc une
        // connexion sur le port 0 au lieu d'une erreur lisible. La conversion
        // verifiee vit dans `App\Providers\AppServiceProvider`, comme
        // `Host\Store\Row::int()` le fait pour les `bigint` que PDO rend en
        // texte.
        'port' => env('FLAIR_DB_PORT', 54329),
        'database' => env('FLAIR_DB_NAME', 'flair'),
        'username' => env('FLAIR_DB_USER', 'flair'),
        'password' => env('FLAIR_DB_PASSWORD', 'flair'),
    ],
];
