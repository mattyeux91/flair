<?php

declare(strict_types=1);

namespace Flair\Api\Read\View;

/**
 * Un monde dans l'index. Lu depuis la table `worlds` et le compte de
 * `events` - **sans** decoder de snapshot : lister dix mondes ne doit pas
 * couter dix fois les 14 ms d'un decodage complet.
 */
final readonly class WorldListItemView
{
    public function __construct(
        public string $id,
        public int $tick,
        public int $season,
        public int $seed,
        public string $kernelVersion,
        public string $rulesetVersion,
        public int $eventCount,
    ) {
    }
}
