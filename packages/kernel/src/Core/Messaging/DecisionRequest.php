<?php

declare(strict_types=1);

namespace Flair\Kernel\Core\Messaging;

/**
 * Marqueur : "quelqu'un doit trancher". Transitoire, jamais journalise - se
 * resout ou expire (docs/11-architecture-generale.md §3,
 * docs/16-evenements-et-cascades.md §1).
 *
 * Convention documentee, non imposee par le type-checker : toute
 * implementation doit porter une echeance (ex. `expiresAtTick`), sans quoi
 * les questions sans reponse s'accumulent indefiniment dans un monde qui
 * tourne sans personne. Pas de methode ici pour rester coherent avec le
 * style "propriete publique readonly, zero getter" utilise partout ailleurs.
 */
interface DecisionRequest
{
}
