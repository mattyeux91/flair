<?php

declare(strict_types=1);

namespace Flair\Harness\Metrics;

/**
 * Sortie de EventGraphCollector::snapshot() - reponse partielle a
 * docs/16-evenements-et-cascades.md §6 : volume par type d'evenement/Fait sur
 * tout le run, et une serie annuelle de la taille du Scheduler (backlog
 * d'evenements dates non encore echus - le seul signal de croissance non
 * amortie disponible aujourd'hui, cf. docblock de EventGraphCollector pour
 * pourquoi l'OutQueue n'apporte rien de plus et pourquoi profondeur de
 * cascade / entites sur-modifiees ne sont pas mesurees ici).
 */
final readonly class EventGraphResult
{
    /**
     * @param array<class-string, int> $volumeByType classe d'evenement -> nombre d'occurrences sur tout le run
     * @param list<array{year: int, schedulerBacklog: int}> $schedulerBacklogByYear
     */
    public function __construct(
        public array $volumeByType,
        public int $totalEvents,
        public array $schedulerBacklogByYear,
    ) {
    }
}
