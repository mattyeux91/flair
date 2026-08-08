<?php

declare(strict_types=1);

namespace Flair\Host;

use Flair\Host\Database\Database;
use Flair\Host\Rules\RulesetForWorld;
use Flair\Host\Store\EventStore;
use Flair\Host\Store\SnapshotStore;
use Flair\Host\Store\WorldRepository;
use Flair\Kernel\Core\Simulation\Simulation;
use Flair\Kernel\Core\Simulation\TickContext;
use Flair\Kernel\Core\Snapshot\SnapshotCodec;
use Flair\Kernel\Core\Snapshot\WorldSnapshot;
use Flair\Kernel\Football\FootballPipeline;
use Flair\Kernel\Football\FootballTypes;

/**
 * La boucle du Host (docs/13- §8) : **avancer un monde d'un tick, puis
 * sortir**. Pas de demon, pas de boucle infinie - c'est le grain naturel de
 * PHP, et il suffit largement a `1 tick = 1 jour` declenche par cron.
 *
 * ## Tout tient dans une seule transaction, et c'est le coeur du lot
 *
 * Verrou, lecture du snapshot, ecriture des Faits, ecriture du nouveau
 * snapshot : un seul bloc atomique. C'est cette atomicite, et elle seule, qui
 * rend vrai le critere de sortie de la Phase 3 - « tuer le processus au
 * hasard, le relancer, et le monde reprend sans incoherence ». Tuer le
 * processus a n'importe quel instant laisse la base soit **avant** le tick,
 * soit **apres**, jamais au milieu : PostgreSQL annule une transaction non
 * validee, et le verrou `xact` tombe avec elle.
 *
 * Un snapshot en avance ou en retard d'un tick sur l'event log rendrait
 * l'histoire du monde fausse, sans rien casser de visible. C'est le mode de
 * panne que cette structure exclut par construction plutot que par vigilance.
 *
 * ## Le tick n'est pas dans l'etat
 *
 * `$snapshot->tick + 1`, jamais `$state->tick + 1` : le tick vit dans
 * l'enveloppe (`Core\Snapshot\WorldSnapshot`), parce qu'il vit dans
 * `TickContext` et non dans le `WorldState`. Le pseudo-code de docs/13- §8 se
 * trompait sur ce point, corrige au lot snapshot.
 *
 * ## Ce qui manque encore, et qui est assume
 *
 * - **Aucune intention n'est consommee** : `TickContext::$intents` recoit un
 *   tableau vide. L'inbox d'intentions du Host (docs/13- §8) est Phase 5, et
 *   `Football\Intents\SubmittedBuyerIntentSource` l'attend deja.
 * - **Aucune projection** : docs/15- §4 les place en Phase 4. Le jour venu,
 *   elles s'appliqueront **dans cette meme transaction**, sinon un client
 *   verra un monde incoherent apres un crash.
 * - **Aucune diffusion SSE** : Phase 4 egalement, et elle se fera **hors**
 *   transaction - publier avant le commit annoncerait un tick qui pourrait
 *   encore etre annule.
 *
 * ## Le `Ruleset` d'un monde ne se devine plus
 *
 * Ce systeme faisait `new Ruleset($world->rulesetVersion)`, ce qui rendait les
 * defauts du noyau **quelle que soit** la version epinglee : un monde epingle
 * a d'autres regles aurait tourne selon celles-la sans que rien ne le dise.
 * `Rules\RulesetForWorld` leve maintenant pour toute version qu'il ne sait pas
 * reconstruire - le monde refuse d'avancer au lieu d'avancer faux. Le package
 * `ruleset` (docs/12- §6) n'aura qu'un seul site a rebrancher.
 */
final class AdvanceWorld
{
    private readonly Simulation $simulation;
    private readonly SnapshotCodec $codec;

    public function __construct(
        private readonly Database $database,
        private readonly WorldRepository $worlds,
        private readonly EventStore $events,
        private readonly SnapshotStore $snapshots,
        private readonly WorldLock $lock,
        private readonly int $snapshotRetention = SnapshotStore::DEFAULT_RETENTION,
    ) {
        $this->simulation = new Simulation(FootballPipeline::build());
        $this->codec = new SnapshotCodec(FootballTypes::registry());
    }

    public function __invoke(string $worldId): AdvanceResult
    {
        /** @var AdvanceResult */
        return $this->database->connection()->transaction(function () use ($worldId): AdvanceResult {
            // Le verrou d'abord : inutile de deserialiser un etat de plusieurs
            // centaines de kilo-octets pour decouvrir ensuite qu'un autre
            // processus tient deja ce monde.
            if (!$this->lock->tryAcquire($worldId)) {
                return AdvanceResult::busy();
            }

            $world = $this->worlds->find($worldId);
            $snapshot = $world === null ? null : $this->snapshots->latest($worldId);

            if ($world === null || $snapshot === null) {
                return AdvanceResult::unknown();
            }

            $tick = $snapshot->tick + 1;

            $startedSimulation = microtime(true);
            $state = $snapshot->restore($this->codec);
            $result = $this->simulation->step($state, new TickContext(
                tick: $tick,
                seed: $world->seed,
                intents: [],
                ruleset: RulesetForWorld::for($world->rulesetVersion),
            ));
            $simulationSeconds = microtime(true) - $startedSimulation;

            $startedPersistence = microtime(true);
            $written = $this->events->append($worldId, $tick, $result->events);
            $this->snapshots->save(WorldSnapshot::capture(
                $this->codec,
                $result->state,
                $worldId,
                $tick,
                $world->seed,
                $world->rulesetVersion,
            ));
            $this->snapshots->prune($worldId, $this->snapshotRetention);
            $this->worlds->recordTick($worldId, $tick);
            $persistenceSeconds = microtime(true) - $startedPersistence;

            return new AdvanceResult(
                outcome: AdvanceOutcome::Advanced,
                tick: $tick,
                events: $written,
                simulationSeconds: $simulationSeconds,
                persistenceSeconds: $persistenceSeconds,
            );
        });
    }
}
