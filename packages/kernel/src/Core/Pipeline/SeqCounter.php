<?php

declare(strict_types=1);

namespace Flair\Kernel\Core\Pipeline;

/**
 * Compteur monotone d'emission, une instance par tick, partagee entre tous
 * les SystemContext de ce tick (docs/13-moteur-de-simulation.md §3 :
 * "seq est un compteur monotone par tick, attribue a l'emission").
 *
 * Volontairement distinct d'EntityIdAllocator malgre le mecanisme identique :
 * ce sont deux concepts sans rapport (identite d'entite vs ordre d'emission),
 * les melanger pour economiser quelques lignes serait trompeur.
 */
final class SeqCounter
{
    private int $next = 0;

    public function next(): int
    {
        return $this->next++;
    }
}
