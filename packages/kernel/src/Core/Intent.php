<?php

declare(strict_types=1);

namespace Flair\Kernel\Core;

/**
 * Marqueur : "voici ce que je fais". Futur immediat, consomme une seule fois,
 * journalise dans l'intent log - jamais l'event log
 * (docs/11-architecture-generale.md §3, docs/16-evenements-et-cascades.md §1).
 *
 * Produit indifferemment par un agent humain ou PNJ, via IntentSource - c'est
 * ce qui les rend interchangeables du point de vue du noyau.
 */
interface Intent
{
}
