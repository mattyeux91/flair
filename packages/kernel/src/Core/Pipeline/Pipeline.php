<?php

declare(strict_types=1);

namespace Flair\Kernel\Core\Pipeline;

use Flair\Kernel\Core\Ecs\WorldState;
use Flair\Kernel\Core\Messaging\OutQueue;
use Flair\Kernel\Core\Messaging\Scheduler;

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
 */
final class Pipeline
{
    /** @param list<System> $systems ordre declare, versionne avec le noyau (13- §2) */
    public function __construct(private array $systems)
    {
    }

    public function tick(WorldState $world, Scheduler $scheduler, OutQueue $outQueue, int $tick, int $worldSeed): void
    {
        $seq = new SeqCounter();

        $incoming = [...$scheduler->drainDueBy($tick), ...$outQueue->drain()];

        foreach ($this->systems as $index => $system) {
            $ctx = new SystemContext($tick, $index, $system->id(), $worldSeed, $world, $scheduler, $outQueue, $seq);

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
    }
}
