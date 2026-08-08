<?php

declare(strict_types=1);

namespace Flair\Api\Read\View;

/**
 * Une saison d'un club : ou il a fini, ce qu'il a fait, qui est arrive et qui
 * est parti.
 *
 * ## Le rang est un Fait, les points sont un calcul
 *
 * `$rank` vient de `SeasonConcluded.finalRanking` - c'est le monde qui l'a dit,
 * il est autoritaire. `$points` est **recalcule** depuis les `MatchPlayed` et
 * les baremes du `Ruleset` du monde, parce que l'event log ne porte pas les
 * points finaux. Un test exige que le recalcul de la saison en cours egale le
 * `Standings` du snapshot ; le jour ou `CompetitionSystem` changerait sa facon
 * d'attribuer les points, c'est ce test qui rougirait, pas cette page qui
 * mentirait en silence.
 *
 * `$rank` est `null` pour une saison **en cours** : elle n'a pas encore ete
 * conclue, donc personne n'a de classement final. Ce n'est pas une donnee
 * manquante, c'est l'etat du monde.
 */
final readonly class SeasonBlockView
{
    /**
     * @param list<MovementView> $arrivals
     * @param list<MovementView> $departures
     * @param list<MovementView> $youthPromoted
     * @param list<MovementView> $renewals
     * @param list<LoggedFactView> $log
     */
    public function __construct(
        public int $season,
        public int $fromTick,
        public int $toTick,
        public ?int $rank,
        public ?int $clubsRanked,
        public int $played,
        public int $won,
        public int $drawn,
        public int $lost,
        public int $goalsFor,
        public int $goalsAgainst,
        public int $points,
        public array $arrivals,
        public array $departures,
        public array $youthPromoted,
        /** Contrats prolonges au meme club - ni une arrivee ni un depart. */
        public array $renewals,
        public int $facilitiesInvestedCents,
        public int $transferSpendCents,
        public int $transferIncomeCents,
        public array $log,
    ) {
    }

    public function goalDifference(): int
    {
        return $this->goalsFor - $this->goalsAgainst;
    }

    /** Une saison sans match jouee est une saison ou le club n'a fait que du mercato. */
    public function hasPlayed(): bool
    {
        return $this->played > 0;
    }
}
