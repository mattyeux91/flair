<?php

declare(strict_types=1);

namespace Flair\Kernel\Core;

/**
 * Une entree de Scheduler : un DomainEvent a une echeance, avec les cles de
 * tri qui garantissent l'ordre total documente en docs/13- §3/§4.7 :
 * (tick, systemIndex, entityId, seq).
 */
final readonly class ScheduledEntry
{
    public function __construct(
        public DomainEvent $event,
        public int $atTick,
        public int $systemIndex,
        public int $entityId,
        public int $seq,
    ) {
    }
}
