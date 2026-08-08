<?php

declare(strict_types=1);

namespace Flair\Host\Database;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * Le schema, en un seul endroit et applique par `install()`.
 *
 * Volontairement **pas** un systeme de migrations versionnees : il n'existe
 * aucun monde en production, donc aucune base a faire evoluer sans la casser.
 * Poser un migrateur maintenant serait une brique par anticipation, et le
 * projet a une regle explicite contre ca. Le jour ou un monde vivant devra
 * survivre a un changement de schema, ce sera une migration explicite - la
 * meme discipline que docs/13- §6 impose deja aux versions de noyau.
 *
 * ## Les trois tables
 *
 * - **`worlds`** — l'identite d'un monde : sa graine, le couple
 *   `(kernelVersion, rulesetVersion)` auquel il est epingle (docs/12- §6), et
 *   le tick ou il en est. Ce `tick` est **une projection de commodite**, pas
 *   la verite : la verite est le tick du dernier snapshot, ecrit dans la meme
 *   transaction.
 * - **`events`** — l'event log, append-only (docs/13- §5). `type` prend les
 *   cles stables de `Core\Snapshot\TypeRegistry`, jamais un FQCN : renommer
 *   une classe ne doit pas rendre l'histoire du monde illisible. C'est le
 *   second consommateur reel du registre, celui qui justifiait de l'ecrire.
 * - **`snapshots`** — l'etat serialise, un par tick, en `jsonb`.
 *
 * ## `jsonb` pour les Faits, `json` pour les snapshots - et la raison est
 * concrete
 *
 * `events.payload` est en **`jsonb`** : les projections de la Phase 4 devront
 * l'interroger (`payload->>'clubId'`) et l'indexer, ce que `json` ne permet
 * pas.
 *
 * `snapshots.state` est en **`json`**, et c'est un choix corrige apres mesure.
 * `jsonb` ne stocke pas le texte recu, il stocke une forme normalisee : **les
 * cles d'objet sont reordonnees** (par longueur puis par octets) et les
 * doublons ecartes. Un etat relu depuis `jsonb` n'est donc plus identique,
 * octet pour octet, a ce que le noyau avait produit - alors que
 * `Core\Snapshot\SnapshotCodec` garantit precisement cette stabilite. La
 * relecture reste **correcte** (le decodage cherche ses cles par nom, jamais
 * par position), mais la propriete se perdait en silence a la frontiere de la
 * base, et un test de parite octet-a-octet devenait impossible a ecrire.
 * `json` conserve le texte tel quel ; on n'y perd rien, personne n'interroge
 * l'interieur d'un snapshot - il se charge en entier ou pas du tout.
 */
final class Schema
{
    public function __construct(private readonly Database $database)
    {
    }

    public function install(): void
    {
        $schema = $this->builder();

        if (!$schema->hasTable('worlds')) {
            $schema->create('worlds', static function (Blueprint $table): void {
                $table->string('id')->primary();
                $table->bigInteger('seed');
                $table->string('kernel_version');
                $table->string('ruleset_version');
                $table->bigInteger('tick')->default(0);
                $table->timestampTz('created_at');
                $table->timestampTz('updated_at');
            });
        }

        if (!$schema->hasTable('events')) {
            $schema->create('events', static function (Blueprint $table): void {
                $table->string('world_id');
                $table->bigInteger('tick');
                $table->integer('seq');
                $table->string('type');
                $table->jsonb('payload');
                $table->timestampTz('recorded_at');

                // (world_id, tick, seq) est l'ordre total des Faits d'un monde
                // (docs/13- §4.5). En faire la cle primaire donne l'index de
                // lecture et interdit le doublon d'un meme evenement - une
                // commande rejouee ne peut pas dupliquer l'histoire.
                $table->primary(['world_id', 'tick', 'seq']);
                $table->index(['world_id', 'type']);
            });
        }

        if (!$schema->hasTable('snapshots')) {
            $schema->create('snapshots', static function (Blueprint $table): void {
                $table->string('world_id');
                $table->bigInteger('tick');
                $table->integer('format');
                $table->string('kernel_version');
                $table->string('ruleset_version');
                $table->bigInteger('seed');
                $table->json('state');
                $table->timestampTz('written_at');

                $table->primary(['world_id', 'tick']);
            });
        }
    }

    public function drop(): void
    {
        $schema = $this->builder();

        foreach (['events', 'snapshots', 'worlds'] as $table) {
            $schema->dropIfExists($table);
        }
    }

    private function builder(): Builder
    {
        return $this->database->connection()->getSchemaBuilder();
    }
}
