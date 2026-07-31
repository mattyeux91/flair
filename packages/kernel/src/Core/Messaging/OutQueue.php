<?php

declare(strict_types=1);

namespace Flair\Kernel\Core\Messaging;

/**
 * Canal par defaut de communication entre systemes, entre les ticks
 * (docs/13-moteur-de-simulation.md §2 : "si tu hesites, c'est le canal 2").
 *
 * Une seule classe pour ce que la doc nomme "OutQueue" et "InQueue" : ce
 * n'est pas deux structures distinctes, c'est la meme file observee a deux
 * moments de son cycle de vie. Remplie pendant le tick N via emit() (cote
 * ecriture, "OutQueue"), puis drain() la vide et renvoie son contenu trie -
 * ce retour EST l'InQueue du tick N+1 (cote lecture), pas un type separe.
 *
 * Tri a la lecture : (systemIndex, entityId, seq), §4.5 - trois cles, pas
 * quatre comme le Scheduler : une OutQueue ne contient jamais que les
 * evenements du tick courant, il n'y a pas de dimension temporelle a trier.
 * Meme discipline que Scheduler/ComponentStore : tableau simple, tri
 * explicite a la lecture, jamais d'ordre implicite.
 */
final class OutQueue
{
    /** @var list<OutQueueEntry> */
    private array $entries = [];

    public function emit(DomainEvent $event, int $systemIndex, int $entityId, int $seq): void
    {
        $this->entries[] = new OutQueueEntry($event, $systemIndex, $entityId, $seq);
    }

    /**
     * Vide la file et renvoie son contenu, trie par (systemIndex, entityId,
     * seq). Le retour EST l'InQueue du tick suivant.
     *
     * @return list<DomainEvent>
     */
    public function drain(): array
    {
        $entries = $this->entries;
        $this->entries = [];

        usort(
            $entries,
            static fn (OutQueueEntry $a, OutQueueEntry $b): int => $a->systemIndex <=> $b->systemIndex
                ?: $a->entityId <=> $b->entityId
                ?: $a->seq <=> $b->seq,
        );

        return array_map(static fn (OutQueueEntry $entry): DomainEvent => $entry->event, $entries);
    }

    public function count(): int
    {
        return count($this->entries);
    }
}
