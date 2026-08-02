<?php

declare(strict_types=1);

namespace Flair\Harness\Metrics;

/**
 * Sortie de CompetitiveBalance::analyze() - reponse operationnelle au "test
 * qui compte" de docs/14-algorithmes.md §7. Trois de ses quatre metriques
 * sont couvertes ; l'inflation reste hors de portee tant qu'aucun prix
 * n'existe dans le monde (ni valorisation de joueur, ni marche).
 */
final readonly class CompetitiveBalanceResult
{
    /**
     * @param array<string, int> $titlesByClub nom de club -> nombre de titres sur le run, inclut les clubs a 0 titre (univers = tous les clubs vus dans seasonHistory, pas seulement les vainqueurs)
     * @param float $giniOfRevenues inegalite des revenus cumules entre clubs (0 = tous le meme revenu, 1 = un seul club encaisse tout)
     */
    public function __construct(
        public array $titlesByClub,
        public float $giniOfTitles,
        public ?float $topFiveTurnoverRate,
        public int $distinctChampions,
        public int $seasonsMeasured,
        public float $giniOfRevenues = 0.0,
    ) {
    }
}
