<?php

declare(strict_types=1);

namespace Flair\Host;

/**
 * Ce qu'un appel a `AdvanceWorld` a produit. Un enum plutot qu'un booleen ou
 * une exception : « un autre processus tient le verrou » est un deroulement
 * **normal** d'un declenchement par cron, pas une erreur a journaliser en
 * rouge - alors que « ce monde n'existe pas » en est une.
 */
enum AdvanceOutcome: string
{
    /** Le monde a avance d'un tick, evenements et snapshot ecrits. */
    case Advanced = 'advanced';

    /** Un autre processus fait deja avancer ce monde. Rien n'a ete ecrit. */
    case Busy = 'busy';

    /** Aucun monde de cet identifiant, ou aucun snapshot pour le reprendre. */
    case Unknown = 'unknown';
}
