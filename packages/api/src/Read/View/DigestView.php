<?php

declare(strict_types=1);

namespace Flair\Api\Read\View;

/**
 * Le digest de retour d'absence d'un club, cadre sur une fenetre de N jours
 * (docs/14- §9, seconde moitie du critere de sortie de la Phase 4).
 *
 * ## Ce que ce DTO n'est pas, et pourquoi
 *
 * Le vrai digest se cale sur « depuis ta derniere connexion ». Il n'y a **ni
 * utilisateur, ni session, ni derniere connexion** dans ce projet - ca
 * appartient au client de jeu, Phase 5. `$fromTick`/`$toTick` sont la reduction
 * honnete : les N derniers jours du monde, et rien de plus.
 *
 * ## `$factsByType` est un livrable, pas une statistique de debug
 *
 * docs/14- §9 dit que le digest est **le meilleur controle qualite des seuils
 * d'emission** : s'il est illisible, ce sont les seuils qui sont mal regles.
 * Encore faut-il pouvoir le constater. La ventilation par type met le
 * « 60 % de `MatchPlayed` » sur la page elle-meme, remis a jour a chaque
 * lecture, au lieu de le laisser dormir dans un document ou plus personne ne le
 * recalcule - le mode de panne exact que la dette D5 a coute.
 */
final readonly class DigestView
{
    /**
     * @param list<DigestEntryView> $highlights faits marquants du club, du plus pertinent au moins
     * @param list<DigestEntryView> $world faits marquants ne nommant pas ce club
     * @param array<string, int> $factsByType cle stable du type -> nombre de Faits dans la fenetre
     */
    public function __construct(
        public string $worldId,
        public int $clubId,
        public string $clubName,
        public int $tick,
        public int $fromTick,
        public int $toTick,
        public int $days,
        public DigestSummaryView $summary,
        public array $highlights,
        public array $world,
        public int $factsRead,
        public int $factsAboutClub,
        public array $factsByType,
    ) {
    }
}
