<?php

declare(strict_types=1);

namespace Flair\Harness\Metrics;

use Flair\Kernel\Core\Ecs\WorldState;
use Flair\Kernel\Core\Messaging\DomainEvent;

/**
 * Collecteur optionnel passe a Sampler::run() (docs/16-evenements-et-cascades.md
 * §6 : "une boucle non amortie ne se voit pas dans les metriques metier, elle
 * se voit ici"). Deux signaux, choisis pour ce qui est reellement mesurable
 * sans toucher le contrat du noyau :
 *
 * - **Volume par type d'evenement**, tally() appele apres chaque step() -
 *   une explosion du nombre d'occurrences d'un type donne, saison apres
 *   saison, est le premier symptome visible d'une cascade non amortie.
 * - **Backlog du Scheduler**, releve une fois par annee (meme granularite
 *   que Sampler::populationByYear) via recordQueueDepth() - c'est le seul
 *   signal de croissance non amortie qui a du sens ici. `OutQueue` se vide
 *   entierement a chaque tick (docs/13- §2 : un evenement emis via emit()
 *   n'est traite qu'au tick suivant, jamais accumule) - son count() juste
 *   apres un step() est donc structurellement egal au nombre d'evenements de
 *   CE tick, deja capture par le tally par type. Seul le Scheduler (echeances
 *   arbitraires, docs/13- §3) peut reellement grossir sans jamais se vider.
 *
 * **Limitations assumees, a lever seulement si le besoin se confirme :**
 * - **Profondeur de cascade** : non mesurable. Aucun evenement ne porte de
 *   lien causal (`causedBy`/`correlationId`/`depth`) - `StepResult.events`/
 *   `OutQueue::pending()` ne renvoient qu'une `list<DomainEvent>` nue. Ajouter
 *   ce lien toucherait `SystemContext::emit()`/`schedule()`, `OutQueueEntry`,
 *   `ScheduledEntry` et tous les sites d'emission existants - un changement
 *   de contrat kernel, pas une extension harness.
 * - **Entites sur-modifiees** : meme cause. Sans `entityId` sur l'evenement
 *   nu, la seule option serait d'extraire des ids au cas par cas selon le
 *   type d'evenement connu (comme le fait deja Sampler pour PlayerRetired/
 *   YouthPlayerPromoted) - faisable en extension future, pas fait ici.
 */
final class EventGraphCollector
{
    /** @var array<class-string, int> */
    private array $volumeByType = [];

    private int $totalEvents = 0;

    /** @var list<array{year: int, schedulerBacklog: int}> */
    private array $schedulerBacklogByYear = [];

    /** @param list<DomainEvent> $events */
    public function tally(array $events): void
    {
        foreach ($events as $event) {
            $type = $event::class;
            $this->volumeByType[$type] = ($this->volumeByType[$type] ?? 0) + 1;
            $this->totalEvents++;
        }
    }

    public function recordQueueDepth(int $year, WorldState $world): void
    {
        $this->schedulerBacklogByYear[] = [
            'year' => $year,
            'schedulerBacklog' => $world->scheduler()->count(),
        ];
    }

    public function snapshot(): EventGraphResult
    {
        return new EventGraphResult($this->volumeByType, $this->totalEvents, $this->schedulerBacklogByYear);
    }
}
