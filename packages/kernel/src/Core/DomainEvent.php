<?php

declare(strict_types=1);

namespace Flair\Kernel\Core;

/**
 * Marqueur : "ceci est arrive". Passe, immuable, journalise dans l'event log
 * (docs/11-architecture-generale.md §3, docs/16-evenements-et-cascades.md §1).
 *
 * A ne jamais confondre avec DecisionRequest (une question) ni Intent (une
 * action a venir) - les trois messages ne se recouvrent jamais.
 */
interface DomainEvent
{
}
