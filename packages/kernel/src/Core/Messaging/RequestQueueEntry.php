<?php

declare(strict_types=1);

namespace Flair\Kernel\Core\Messaging;

/**
 * Une entree de RequestQueue : une question posee pendant le tick courant,
 * avec les memes cles de tri que l'OutQueue (docs/13- §4.5) -
 * (systemIndex, entityId, seq).
 */
final readonly class RequestQueueEntry
{
    public function __construct(
        public DecisionRequest $request,
        public int $systemIndex,
        public int $entityId,
        public int $seq,
    ) {
    }
}
