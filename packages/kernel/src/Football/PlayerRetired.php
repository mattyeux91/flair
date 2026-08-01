<?php

declare(strict_types=1);

namespace Flair\Kernel\Football;

use Flair\Kernel\Core\Messaging\DomainEvent;

/**
 * Un joueur raccroche : irreversible, donc un Fait (docs/16- §2 - la
 * retraite est l'exemple cite d'evenement qui franchit le test de
 * pertinence par irreversibilite). Seul evenement emis par AgingSystem : la
 * derive quotidienne des attributs ne franchit aucun seuil decisionnel.
 */
final class PlayerRetired implements DomainEvent
{
    public function __construct(
        public int $playerId,
        public int $ageYears,
    ) {
    }
}
