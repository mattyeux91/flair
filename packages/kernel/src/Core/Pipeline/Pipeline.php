<?php

declare(strict_types=1);

namespace Flair\Kernel\Core\Pipeline;

use Flair\Kernel\Core\Ecs\WorldState;
use Flair\Kernel\Core\Messaging\DecisionRequest;
use Flair\Kernel\Core\Messaging\Intent;
use Flair\Kernel\Core\Messaging\RequestQueue;
use Flair\Kernel\Core\Ruleset\Ruleset;

/**
 * Execute un tick sur une liste de systemes declaree, dans l'ordre
 * (docs/13-moteur-de-simulation.md §2). L'ordre est une donnee d'architecture,
 * versionnee avec le noyau.
 *
 * Le lot d'evenements traite (`$incoming`) est calcule une seule fois, avant
 * qu'aucun systeme ne s'execute. Tout ce qu'un systeme emet pendant le tick
 * part dans OutQueue/Scheduler, jamais dans `$incoming` - c'est ce qui rend
 * la boucle infinie intra-tick structurellement impossible (docs/16- §3).
 *
 * Scheduler et OutQueue restent deux lots distincts, simplement concatenes :
 * chacun est deja ordonne par sa propre regle (drainDueBy/drain), il n'y a
 * pas de cle de tri unifiee documentee entre les deux sources.
 *
 * Scheduler/OutQueue ne sont plus des parametres : ils vivent desormais dans
 * WorldState (docs/11- §1 - step() ne prend que WorldState + TickContext,
 * rien d'autre ne pourrait les faire survivre d'un appel a l'autre).
 */
final class Pipeline
{
    /** @var list<SystemAccess> declarations indexees, dans l'ordre de $systems */
    private array $access;

    /** @param list<System> $systems ordre declare, versionne avec le noyau (13- §2) */
    public function __construct(private array $systems)
    {
        $this->access = array_map(
            static fn (System $system): SystemAccess => SystemAccess::of($system),
            $systems,
        );
    }

    /**
     * Les questions du tick sont rendues, les Faits ne le sont pas : ceux-ci
     * restent dans l'OutQueue du monde, qui leur survit d'un tick a l'autre.
     * Une `RequestQueue` nait et meurt avec le tick (voir son docblock), donc
     * elle n'a nulle part ou aller sinon ici.
     *
     * @param list<Intent> $intents
     * @return list<DecisionRequest> les questions posees pendant ce tick
     */
    public function tick(WorldState $world, int $tick, int $worldSeed, Ruleset $ruleset, array $intents): array
    {
        $scheduler = $world->scheduler();
        $outQueue = $world->outQueue();
        $requests = new RequestQueue();
        $seq = new SeqCounter();

        $incoming = [...$scheduler->drainDueBy($tick), ...$outQueue->drain()];

        foreach ($this->systems as $index => $system) {
            $ctx = new SystemContext(
                $tick,
                $index,
                $this->access[$index],
                $worldSeed,
                $ruleset,
                $intents,
                $world,
                $scheduler,
                $outQueue,
                $requests,
                $seq,
            );

            foreach ($incoming as $event) {
                foreach ($system->subscribesTo() as $type) {
                    if ($event instanceof $type) {
                        $system->handle($event, $ctx);
                        continue 2;
                    }
                }
            }

            $system->update($ctx);
        }

        return $requests->pending();
    }
}
