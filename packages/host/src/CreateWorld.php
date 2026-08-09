<?php

declare(strict_types=1);

namespace Flair\Host;

use Flair\Host\Database\Database;
use Flair\Host\Rules\RulesetForWorld;
use Flair\Host\Store\SnapshotStore;
use Flair\Host\Store\WorldRecord;
use Flair\Host\Store\WorldRepository;
use Flair\Kernel\Core\Ecs\WorldState;
use Flair\Kernel\Core\Snapshot\SnapshotCodec;
use Flair\Kernel\Core\Snapshot\WorldSnapshot;
use Flair\Kernel\Football\FootballTypes;
use Flair\Kernel\Kernel;
use Flair\Worldgen\WorldFactory;
use Flair\Worldgen\WorldSpec;
use RuntimeException;

/**
 * La genese d'un monde persistant : engendre l'etat initial via
 * `packages/worldgen`, l'ecrit en base au tick 0, et c'est tout.
 *
 * C'est la raison d'etre du lot precedent : `host` peut appeler `worldgen`,
 * pas `harness` (docs/11- §7). Un outil de mesure n'a pas a etre la source des
 * mondes de production.
 *
 * Le monde nait epingle a `(Kernel::VERSION, ruleset.version)` (docs/12- §6).
 * Aucun tick n'est joue ici : un monde neuf est un monde au tick 0, avec son
 * premier snapshot et un event log vide. `AdvanceWorld` fait le reste.
 *
 * ## Une **version** de regles, pas un `Ruleset`
 *
 * Ce constructeur prenait un `Ruleset` complet, et c'etait un piege : le
 * genesis n'en lisait que `->version`. `Worldgen\WorldFactory::populate()`
 * accepte bien des groupes de `Balance`, mais on ne lui en passe aucun - donc
 * un `Ruleset` porteur d'un `Balance` sur mesure aurait ete **silencieusement
 * ignore ici**, puis reconstruit aux defauts par `AdvanceWorld`. Le parametre
 * promettait un reglage qu'il n'appliquait nulle part.
 *
 * Il prend donc une chaine, et c'est `Rules\RulesetForWorld` - le site unique
 * qui traduit une version en regles - qui la valide. Un monde ne peut plus
 * naitre epingle a des regles que ce Host ne saura pas lui appliquer.
 */
final class CreateWorld
{
    private readonly SnapshotCodec $codec;

    public function __construct(
        private readonly Database $database,
        private readonly WorldRepository $worlds,
        private readonly SnapshotStore $snapshots,
        private readonly WorldFactory $genesis = new WorldFactory(),
    ) {
        $this->codec = new SnapshotCodec(FootballTypes::registry());
    }

    public function __invoke(
        string $worldId,
        WorldSpec $spec,
        string $rulesetVersion = RulesetForWorld::VERSION,
    ): WorldRecord {
        if ($this->worlds->exists($worldId)) {
            throw new RuntimeException("Le monde \"{$worldId}\" existe deja.");
        }

        // Avant toute ecriture, et avant meme d'engendrer le monde : un monde
        // qu'on ne saura pas avancer n'a pas a exister.
        RulesetForWorld::for($rulesetVersion);

        $state = new WorldState();

        // atTick: 1 - le genesis date les naissances et les contrats par
        // rapport au premier tick *joue*, pas au tick 0 ou rien ne s'est
        // encore passe. C'est la convention du harness depuis la Phase 0, et
        // la changer ici produirait des mondes subtilement differents des
        // mondes mesures.
        $this->genesis->populate($state, $spec, atTick: 1);

        $record = new WorldRecord(
            id: $worldId,
            seed: $spec->seed,
            kernelVersion: Kernel::VERSION,
            rulesetVersion: $rulesetVersion,
            tick: 0,
        );

        $this->database->connection()->transaction(function () use ($record, $state, $worldId, $spec, $rulesetVersion): void {
            $this->worlds->create($record);
            $this->snapshots->save(WorldSnapshot::capture(
                $this->codec,
                $state,
                $worldId,
                tick: 0,
                seed: $spec->seed,
                rulesetVersion: $rulesetVersion,
            ));
        });

        return $record;
    }
}
