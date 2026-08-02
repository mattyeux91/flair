<?php

declare(strict_types=1);

namespace Flair\Harness\Metrics;

/**
 * Sortie de CompetitiveBalance::analyze() - reponse operationnelle au "test
 * qui compte" de docs/14-algorithmes.md §7 (limite au volet sportif : le
 * Gini des revenus et l'inflation ne sont mesurables qu'a partir de la
 * Phase 2, une fois l'economie codee).
 */
final readonly class CompetitiveBalanceResult
{
    /**
     * @param array<string, int> $titlesByClub nom de club -> nombre de titres sur le run, inclut les clubs a 0 titre (univers = tous les clubs vus dans seasonHistory, pas seulement les vainqueurs)
     */
    public function __construct(
        public array $titlesByClub,
        public float $giniOfTitles,
        public ?float $topFiveTurnoverRate,
        public int $distinctChampions,
        public int $seasonsMeasured,
    ) {
    }
}
