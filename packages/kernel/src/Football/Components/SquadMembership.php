<?php

declare(strict_types=1);

namespace Flair\Kernel\Football\Components;

/**
 * Affectation d'un joueur a un club : vit sur l'entite **joueur**, pointe
 * vers l'entite club (`$clubId`). Pas de composant `Squad` reciproque
 * (liste de joueurs cote club) dans ce lot - pour ce que `TrainingSystem`
 * a besoin de faire (par joueur, retrouver son club), ce seul pointeur
 * suffit et evite une coherence bidirectionnelle a maintenir.
 */
final readonly class SquadMembership
{
    public function __construct(
        public int $clubId,
    ) {
    }
}
