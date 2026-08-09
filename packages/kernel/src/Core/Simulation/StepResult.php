<?php

declare(strict_types=1);

namespace Flair\Kernel\Core\Simulation;

use Flair\Kernel\Core\Ecs\WorldState;
use Flair\Kernel\Core\Messaging\DecisionRequest;
use Flair\Kernel\Core\Messaging\DomainEvent;

/**
 * La sortie d'un tick (docs/11-architecture-generale.md §1) : le WorldState
 * (mute en place, cf. Simulation::step()), les Faits emis pendant ce tick, et
 * les questions posees pendant ce tick.
 *
 * **Deux listes, deux destins, et c'est tout l'objet de docs/16- §1.**
 * `$events` est ce que le Host journalise (`eventStore->append()`) : le passe,
 * garde pour toujours. `$requests` est ce qu'il **ne journalise pas** : des
 * questions transitoires, adressees a un decideur hors du noyau, qui se
 * resolvent ou expirent. Les melanger revient a garder pour l'eternite des
 * questions dont la reponse a ete donnee le lendemain - c'est ce que faisait
 * le marche des transferts jusqu'au 2026-08-09.
 */
final readonly class StepResult
{
    /**
     * @param list<DomainEvent> $events
     * @param list<DecisionRequest> $requests
     */
    public function __construct(
        public WorldState $state,
        public array $events,
        public array $requests = [],
    ) {
    }
}
