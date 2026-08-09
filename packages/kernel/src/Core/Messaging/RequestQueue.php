<?php

declare(strict_types=1);

namespace Flair\Kernel\Core\Messaging;

/**
 * Les questions posees pendant un tick (docs/16- §1 : « DecisionRequest -
 * question transitoire, jamais journalisee »).
 *
 * ## Pourquoi ce n'est pas une OutQueue de plus
 *
 * Trois differences, et chacune tient a la nature d'une question :
 *
 * 1. **Elle ne vit pas dans le `WorldState`.** L'OutQueue y vit parce que ce
 *    qu'un tick emet doit etre traite au tick suivant meme si le processus
 *    meurt entre les deux (`Core\Snapshot\SnapshotCodec` la serialise). Une
 *    question n'a pas ce besoin : ce qui est durable, c'est l'**etat** qui la
 *    motive - une contre-demande en attente vit dans
 *    `Football\Components\Negotiation::$pendingCounterCents`, pas dans le
 *    message. Le message est la sonnette, pas la porte.
 * 2. **Elle n'est jamais journalisee.** Elle sort par `StepResult::$requests`,
 *    que `Host\AdvanceWorld` ne donne pas a l'event log - c'est tout l'objet
 *    de la distinction de docs/16- §1, et la raison d'etre de cette classe.
 * 3. **Aucun systeme ne la lit.** Une question va a un decideur *hors* du
 *    noyau ; un systeme qui repondrait a la question d'un autre systeme serait
 *    exactement l'appel direct que docs/13- §2 interdit.
 *
 * ## Le tri, lui, est le meme
 *
 * `(systemIndex, entityId, seq)`, comme l'OutQueue (docs/13- §4.5). Une
 * question transitoire reste une sortie du noyau : deux executions du meme
 * monde doivent poser les memes questions **dans le meme ordre**, sans quoi le
 * determinisme s'arreterait a la frontiere de ce qu'on regarde.
 */
final class RequestQueue
{
    /** @var list<RequestQueueEntry> */
    private array $entries = [];

    public function ask(DecisionRequest $request, int $systemIndex, int $entityId, int $seq): void
    {
        $this->entries[] = new RequestQueueEntry($request, $systemIndex, $entityId, $seq);
    }

    /** @return list<DecisionRequest> */
    public function pending(): array
    {
        $entries = $this->entries;

        usort(
            $entries,
            static fn (RequestQueueEntry $a, RequestQueueEntry $b): int => $a->systemIndex <=> $b->systemIndex
                ?: $a->entityId <=> $b->entityId
                ?: $a->seq <=> $b->seq,
        );

        return array_map(static fn (RequestQueueEntry $entry): DecisionRequest => $entry->request, $entries);
    }
}
