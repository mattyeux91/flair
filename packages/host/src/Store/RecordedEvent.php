<?php

declare(strict_types=1);

namespace Flair\Host\Store;

use Flair\Kernel\Core\Messaging\DomainEvent;

/**
 * Un Fait tel qu'il a ete journalise : son objet, et ou il se place dans
 * l'histoire du monde.
 *
 * `(tick, seq)` est l'ordre total des Faits d'un monde (docs/13- §4.5), celui
 * de la cle primaire de `events`. Il vit ici et non sur le `DomainEvent` parce
 * que le noyau ne le connait pas : un Fait ne sait pas quand il a eu lieu, il
 * dit seulement ce qui est arrive. C'est le meme partage que
 * `Core\Snapshot\WorldSnapshot`, dont l'enveloppe porte le tick que le
 * `WorldState` ne porte pas.
 */
final readonly class RecordedEvent
{
    public function __construct(
        public int $tick,
        public int $seq,
        public DomainEvent $event,
    ) {
    }

    /** La saison a laquelle ce Fait appartient - 1 tick = 1 jour simule (docs/13- §1). */
    public function season(): int
    {
        return intdiv($this->tick, 365);
    }
}
