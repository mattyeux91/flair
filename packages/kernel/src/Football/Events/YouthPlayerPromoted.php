<?php

declare(strict_types=1);

namespace Flair\Kernel\Football\Events;

use Flair\Kernel\Core\Messaging\DomainEvent;

/**
 * Un jeune integre l'effectif professionnel d'un club : un Fait, par
 * racontabilite (docs/16- §2 - "le jeune X sort du centre") et parce qu'il
 * marque l'entree d'une entite dans la population, exactement symetrique de
 * `PlayerRetired` qui en marque la sortie. Ce sont les deux seuls Faits du
 * cycle de vie d'un joueur a ce jour.
 *
 * Emis une fois par joueur promu, jamais agrege par club : un consommateur
 * qui veut la promotion entiere d'un club regroupe lui-meme sur `clubId`.
 * Agreger ici couterait un seuil d'emission arbitraire ("a partir de
 * combien de jeunes est-ce racontable ?") pour une information que le
 * detail contient deja.
 */
final class YouthPlayerPromoted implements DomainEvent
{
    public function __construct(
        public int $playerId,
        public int $clubId,
    ) {
    }
}
