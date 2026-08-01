<?php

declare(strict_types=1);

namespace Flair\Kernel\Core\Messaging;

/**
 * Une entree d'OutQueue : un DomainEvent emis pendant le tick courant, avec
 * les cles de tri qui garantissent l'ordre total documente en
 * docs/13-moteur-de-simulation.md §4.5 : (systemIndex, entityId, seq).
 */
final readonly class OutQueueEntry
{
    public function __construct(
        public DomainEvent $event,
        public int $systemIndex,
        public int $entityId,
        public int $seq,
    ) {
    }
}
