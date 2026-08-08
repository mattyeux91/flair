<?php

declare(strict_types=1);

namespace Flair\Host\Database;

use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Connection;

/**
 * Amorce `illuminate/database` en mode autonome (Capsule), sans application
 * Laravel autour.
 *
 * Pourquoi les composants et non le skeleton `laravel/laravel` : le Host de
 * docs/13- §8 est une commande CLI qui avance le monde d'un tick puis sort.
 * Un skeleton HTTP complet n'aurait ici aucun consommateur - `api` et `admin`
 * sont des packages **distincts** dans le graphe de docs/11- §7, et c'est la
 * qu'une application Laravel entiere aura un sens (Phase 4). Ce qu'on importe
 * ici travaille vraiment : gestionnaire de connexion, constructeur de schema,
 * transactions.
 *
 * Pas de `setAsGlobal()` ni de facades : la connexion est un objet qu'on passe
 * (DIP, docs/11- §8), pas un etat global qu'on invoque. Un test peut donc en
 * monter une seconde sans polluer la premiere.
 */
final class Database
{
    private readonly Manager $capsule;

    public function __construct(DatabaseConfig $config = new DatabaseConfig())
    {
        $this->capsule = new Manager();
        $this->capsule->addConnection($config->toArray());
    }

    public static function fromEnvironment(): self
    {
        return new self(DatabaseConfig::fromEnvironment());
    }

    public function connection(): Connection
    {
        /** @var Connection */
        return $this->capsule->getConnection();
    }
}
