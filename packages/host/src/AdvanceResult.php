<?php

declare(strict_types=1);

namespace Flair\Host;

/**
 * Le compte rendu d'un tick : de quoi ecrire une ligne de log honnete et
 * mesurer le cout que docs/13- §7 annonce comme le vrai facteur limitant -
 * l'ecriture en base, pas le CPU du noyau.
 */
final readonly class AdvanceResult
{
    public function __construct(
        public AdvanceOutcome $outcome,
        public int $tick = 0,
        public int $events = 0,
        public float $simulationSeconds = 0.0,
        public float $persistenceSeconds = 0.0,
    ) {
    }

    public static function busy(): self
    {
        return new self(AdvanceOutcome::Busy);
    }

    public static function unknown(): self
    {
        return new self(AdvanceOutcome::Unknown);
    }
}
