<?php

declare(strict_types=1);

namespace Flair\Kernel\Football\Components;

/**
 * Un match programme dans le calendrier (docs/15- §4 : "calendrier = entites
 * `Fixture` programmees dans le Scheduler"). Cree une fois par
 * `Football\CalendarSystem` (`creates()`) au moment de la generation du
 * calendrier de la saison, jamais modifie ensuite - le resultat vit dans
 * `MatchResult`, un composant distinct sur la meme entite, seul writer
 * `Football\MatchSystem`. Separer les deux evite qu'un systeme qui ne veut
 * qu'ajouter un score doive connaitre la forme complete de `Fixture`.
 *
 * `matchday` est un index de journee (0-indexe) sur l'ensemble de la saison
 * (aller + retour), pas juste sur une manche - utile pour trier/afficher un
 * calendrier sans recalculer la position depuis `atTick`.
 */
final readonly class Fixture
{
    public function __construct(
        public int $competitionId,
        public int $homeClubId,
        public int $awayClubId,
        public int $matchday,
    ) {
    }
}
