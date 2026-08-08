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

        return self::sorted($entries);
    }

    /**
     * Lecture non destructive, meme tri que drain() : ce qui a ete emis
     * *pendant* le tick courant, sans vider la file. Sert a StepResult
     * (Core/Simulation/Simulation.php) - l'OutQueue a ete videe en tout
     * debut de Pipeline::tick() pour calculer l'InQueue, donc tout ce
     * qu'elle contient a la fin du tick vient des emit() de ce tick.
     *
     * @return list<DomainEvent>
     */
    public function pending(): array
    {
        return self::sorted($this->entries);
    }

    public function count(): int
    {
        return count($this->entries);
    }

    /**
     * Les entrees en attente, avec leurs cles de tri - ce que `pending()` ne
     * peut pas rendre, puisqu'il jette (systemIndex, entityId, seq) pour ne
     * garder que les evenements. Sert la persistance
     * (Core\Snapshot\SnapshotCodec) : ce que le tick N a emis doit etre
     * traite au tick N+1 meme si le processus meurt entre les deux.
     *
     * @return list<OutQueueEntry>
     */
    public function entries(): array
    {
        return $this->entries;
    }

    /**
     * @param list<OutQueueEntry> $entries
     * @return list<DomainEvent>
     */
    private static function sorted(array $entries): array
    {
        usort(
            $entries,
            static fn (OutQueueEntry $a, OutQueueEntry $b): int => $a->systemIndex <=> $b->systemIndex
                ?: $a->entityId <=> $b->entityId
                ?: $a->seq <=> $b->seq,
        );

        return array_map(static fn (OutQueueEntry $entry): DomainEvent => $entry->event, $entries);
    }
}
