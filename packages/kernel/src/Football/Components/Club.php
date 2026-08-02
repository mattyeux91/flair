<?php

declare(strict_types=1);

namespace Flair\Kernel\Football\Components;

/**
 * Identite minimale d'un club (docs/12- §3, catalogue complet hors scope :
 * pas de `cityId` - aucune entite Ville n'existe encore -, pas de
 * `Finances`/`Squad`/`Reputation`/`FanBase`/`BoardExpectations`). Porte
 * uniquement ce dont `TrainingSystem` a besoin pour exister comme entite
 * distincte du joueur.
 */
final readonly class Club
{
    public function __construct(
        public string $name,
    ) {
    }
}
