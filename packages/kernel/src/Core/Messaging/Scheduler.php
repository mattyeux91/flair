<?php

declare(strict_types=1);

namespace Flair\Kernel\Core\Messaging;

/**
 * File d'evenements dates (docs/13-moteur-de-simulation.md §3).
 *
 * N'utilise jamais l'ordre d'une SplPriorityQueue : une file de priorite
 * departage mal les ex aequo, et c'est exactement la que le determinisme se
 * perd sans bruit (§3). Stockage en tableau simple, tri explicite par la cle
 * complete (tick, systemIndex, entityId, seq) au moment de la lecture - la
 * meme discipline que ComponentStore::entities().
 *
 * Ne calcule pas systemIndex/seq : ce sont des cles fournies par l'appelant
 * (le futur SystemContext), pas une responsabilite du Scheduler.
 */
final class Scheduler
{
    /** @var list<ScheduledEntry> */
    private array $entries = [];

    public function schedule(
        DomainEvent $event,
        int $atTick,
        int $systemIndex,
        int $entityId,
        int $seq,
    ): void {
        $this->entries[] = new ScheduledEntry($event, $atTick, $systemIndex, $entityId, $seq);
    }

    /**
     * Retire et renvoie, tries par (tick, systemIndex, entityId, seq), tous
     * les evenements dont l'echeance est atteinte (atTick <= $tick).
     *
     * @return list<DomainEvent>
     */
    public function drainDueBy(int $tick): array
    {
        $due = [];
        $remaining = [];

        foreach ($this->entries as $entry) {
            if ($entry->atTick <= $tick) {
                $due[] = $entry;
            } else {
                $remaining[] = $entry;
            }
        }

        $this->entries = $remaining;

        usort(
            $due,
            static fn (ScheduledEntry $a, ScheduledEntry $b): int => $a->atTick <=> $b->atTick
                ?: $a->systemIndex <=> $b->systemIndex
                ?: $a->entityId <=> $b->entityId
                ?: $a->seq <=> $b->seq,
        );

        return array_map(static fn (ScheduledEntry $entry): DomainEvent => $entry->event, $due);
    }

    public function count(): int
    {
        return count($this->entries);
    }

    /**
     * Les entrees en attente, avec leurs cles de tri. Sert la persistance
     * (Core\Snapshot\SnapshotCodec) : un evenement seulement planifie n'a
     * emis aucun Fait, donc l'event log ne le rattraperait pas - un snapshot
     * qui l'ignorerait le perdrait pour de bon (docs/13- §5).
     *
     * Rendu tel quel, sans tri : `drainDueBy()` est la seule lecture
     * ordonnee, et la restauration reconstruit la file par `schedule()` -
     * l'ordre de stockage n'a jamais d'effet observable.
     *
     * @return list<ScheduledEntry>
     */
    public function entries(): array
    {
        return $this->entries;
    }
}
