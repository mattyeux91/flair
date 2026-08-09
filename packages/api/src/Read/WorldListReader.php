<?php

declare(strict_types=1);

namespace Flair\Api\Read;

use Flair\Api\Read\View\WorldListItemView;
use Flair\Host\Store\EventStore;
use Flair\Host\Store\WorldRepository;

/**
 * L'index des mondes.
 *
 * **Aucun snapshot n'est decode ici**, et c'est le seul point a retenir : un
 * decodage coute 14 ms et 18 Mo (mesure du lot 0), donc lister dix mondes en
 * couterait 140 ms et 180 Mo pour n'afficher que ce que la table `worlds`
 * porte deja. Le tick de `worlds` est une commodite ecrite dans la meme
 * transaction que le snapshot - ici, c'est precisement l'usage pour lequel
 * elle existe.
 */
final readonly class WorldListReader
{
    public function __construct(
        private WorldRepository $worlds,
        private EventStore $events,
    ) {
    }

    /** @return list<WorldListItemView> */
    public function read(): array
    {
        $items = [];
        foreach ($this->worlds->all() as $record) {
            $items[] = new WorldListItemView(
                id: $record->id,
                tick: $record->tick,
                season: intdiv($record->tick, 365),
                seed: $record->seed,
                kernelVersion: $record->kernelVersion,
                rulesetVersion: $record->rulesetVersion,
                eventCount: $this->events->countFor($record->id),
            );
        }

        return $items;
    }
}
