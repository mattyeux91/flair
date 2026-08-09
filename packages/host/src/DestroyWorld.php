<?php

declare(strict_types=1);

namespace Flair\Host;

use Flair\Host\Database\Database;

/**
 * Efface un monde : son event log, ses snapshots, sa ligne.
 *
 * ## Pourquoi ca existe, et pourquoi c'est un cas d'exploitation
 *
 * Le format des Faits n'est pas versionne (`Database\Schema` l'assume : aucun
 * monde en production, donc rien a migrer), et `Core\Snapshot\ValueCodec` est
 * **strict dans les deux sens** - un champ absent leve, un champ inconnu
 * aussi. Ajouter un champ a un Fait rend donc illisible tout Fait deja en
 * base. La reponse, tant qu'aucun monde ne compte, est de refaire le monde ;
 * la seule alternative etait une incantation SQL recopiee dans un README.
 *
 * ⚠️ **Irreversible, et sans confirmation.** L'appelant est responsable de
 * demander l'accord : `bin/host.php destroy` exige `--force` pour cette
 * raison. Le jour ou un monde comptera, ce n'est pas cette classe qu'il
 * faudra durcir, c'est une migration de payload qu'il faudra ecrire.
 *
 * ## Une transaction, dans l'ordre inverse des dependances
 *
 * `events` et `snapshots` d'abord, `worlds` en dernier : ni cle etrangere ni
 * `ON DELETE CASCADE` dans `Schema`, donc l'ordre est ici une discipline, pas
 * une contrainte du moteur. Une interruption au milieu ne doit pas laisser des
 * Faits orphelins derriere une ligne de monde disparue.
 */
final readonly class DestroyWorld
{
    public function __construct(private Database $database)
    {
    }

    /** @return array{events: int, snapshots: int, worlds: int} lignes effacees par table */
    public function __invoke(string $worldId): array
    {
        /** @var array{events: int, snapshots: int, worlds: int} */
        return $this->database->connection()->transaction(function () use ($worldId): array {
            $connection = $this->database->connection();

            return [
                'events' => $connection->table('events')->where('world_id', $worldId)->delete(),
                'snapshots' => $connection->table('snapshots')->where('world_id', $worldId)->delete(),
                'worlds' => $connection->table('worlds')->where('id', $worldId)->delete(),
            ];
        });
    }
}
