<?php

declare(strict_types=1);

namespace Flair\Api\Read\View;

/**
 * Une saison d'un club : ou il a fini, ce qu'il a fait, qui est arrive et qui
 * est parti.
 *
 * ## Une saison conclue est citee, une saison en cours est comptee
 *
 * Pour une saison **conclue**, tout vient de `SeasonConcluded.finalTable` :
 * rang, points, bilan et buts sont ce que le monde a publie, personne ne les
 * recalcule. Pour la saison **en cours**, ce Fait n'existe pas encore, donc
 * le bilan est compte sur les `MatchPlayed` et les points appliques depuis les
 * baremes du `Ruleset` du monde.
 *
 * Les deux chemins doivent coincider tant qu'aucune regle n'attribue de points
 * hors d'un resultat de match, et c'est un test qui le tient
 * (`ClubHistoryReaderTest`) - pas une convention. Le jour ou un retrait de
 * points existera, le chemin « cite » restera juste et le chemin « compte »
 * deviendra faux : c'est pour cette raison que le premier a la priorite.
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
     * @param list<MovementView> $retirements
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
        /** Ceux qui ont raccroche sous ce maillot : un depart dont personne ne profite. */
        public array $retirements,
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
