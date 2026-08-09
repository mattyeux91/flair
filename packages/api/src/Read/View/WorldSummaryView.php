<?php

declare(strict_types=1);

namespace Flair\Api\Read\View;

/**
 * Un monde vu de haut : ou il en est, sa competition, et ses deux singletons
 * monetaires.
 *
 * `$monetaryInjectionsCents` / `$monetarySinksCents` viennent du singleton
 * `MonetaryMass` : leur difference est la masse en circulation, et c'est
 * l'invariant que `Harness\Tests\Regression\MonetaryConservationTest` surveille.
 * L'afficher ici n'est pas de la curiosite - c'est la premiere fois qu'on peut
 * le regarder sans lancer un run.
 */
final readonly class WorldSummaryView
{
    /**
     * @param list<StandingsRowView> $standings
     * @param list<ClubListItemView> $clubs
     */
    public function __construct(
        public string $id,
        public int $tick,
        public int $season,
        public int $dayOfYear,
        public int $seed,
        public string $kernelVersion,
        public string $rulesetVersion,
        public ?string $competitionName,
        public array $standings,
        public array $clubs,
        public int $playerCount,
        public int $contractedPlayerCount,
        public int $monetaryInjectionsCents,
        public int $monetarySinksCents,
        /** Indice de politique monetaire, 1,0 = neutre (`MarketInflation`, lot 3 de la Phase 2). */
        public float $inflationIndex,
        public float $inflationAnnualRate,
    ) {
    }

    public function moneyInCirculationCents(): int
    {
        return $this->monetaryInjectionsCents - $this->monetarySinksCents;
    }
}
