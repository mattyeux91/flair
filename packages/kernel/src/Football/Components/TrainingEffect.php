<?php

declare(strict_types=1);

namespace Flair\Kernel\Football\Components;

/**
 * La qualite d'environnement d'entrainement d'un joueur - `h(entrainement)`
 * uniquement (docs/14- §2 : modif = clamp(h × i(temps de jeu) × j(moral),
 * 0.5, 2.0)), **pas** le produit complet. Ecrit par `Football\TrainingSystem`
 * (installations du club) et lu par `Football\PlayerDevelopmentSystem` avec
 * un defaut neutre (1.0) quand absent (joueur sans club).
 *
 * `i`(temps de jeu) et `j`(moral) seront, le jour ou `MatchSystem` et un
 * composant `Morale` existeront, des composants-facteurs **separes**
 * (`PlayingTimeEffect`, `MoraleEffect` par ex.) - jamais fusionnes ici : un
 * seul producteur par composant (docs/13- §2). `PlayerDevelopmentSystem`
 * composera alors plusieurs facteurs au lieu d'un seul ; pas de
 * generalisation de cette composition tant qu'un seul facteur existe
 * (YAGNI).
 *
 * Un seul champ : deja borne `[0.5, 2.0]` par son producteur
 * (`TrainingSystem`) - `PlayerDevelopmentSystem` ne recompose rien, ne
 * re-clamp rien, il consomme un multiplicateur pret a l'emploi (docs/14- §3
 * - separer base et modificateurs, borner le produit).
 */
final readonly class TrainingEffect
{
    public function __construct(
        public float $quality,
    ) {
    }
}
