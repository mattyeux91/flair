<?php

declare(strict_types=1);

namespace Flair\Kernel\Football\Components;

/**
 * Point d'extension pour un futur systeme d'entrainement (docs/14- §2 :
 * modif = clamp(h(entrainement) x i(temps de jeu) x j(moral), 0.5, 2.0)).
 * Un seul champ : le produit deja compose et deja borne a [0.5, 2.0] par
 * qui que ce soit qui le produit - PlayerDevelopmentSystem ne recompose
 * rien, ne re-clamp rien, il consomme un multiplicateur pret a l'emploi
 * (docs/14- §3 - separer base et modificateurs, borner le produit).
 *
 * Absent aujourd'hui pour toute entite : aucun TrainingSystem n'existe
 * encore pour l'ecrire. PlayerDevelopmentSystem le lit avec un defaut
 * neutre (1.0) - ouvert a l'extension sans modification (OCP) : un futur
 * TrainingSystem composera en ecrivant ce composant plus tot dans le
 * pipeline, sans toucher PlayerDevelopmentSystem.
 */
final readonly class TrainingEffect
{
    public function __construct(
        public float $quality,
    ) {
    }
}
