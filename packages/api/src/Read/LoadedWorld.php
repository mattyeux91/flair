<?php

declare(strict_types=1);

namespace Flair\Api\Read;

use Flair\Host\Store\WorldRecord;
use Flair\Kernel\Core\Ecs\WorldState;

/**
 * Un monde decode, pret a etre lu : son identite en base, le tick auquel il en
 * est, et son etat complet.
 *
 * Le tick vient de l'**enveloppe** du snapshot et non du `WorldState` : le
 * noyau ne stocke pas le tick dans l'etat, il vit dans `TickContext`
 * (docs/13- §8, corrige au lot snapshot). `WorldRecord::$tick` n'est qu'une
 * commodite, ecrite dans la meme transaction.
 *
 * Cet objet n'est **jamais** ecrit. `WorldState::components()` rend un store
 * dont `set()`/`remove()` sont publics - c'est necessaire a `worldgen`, qui
 * n'est pas un systeme - mais du cote lecture, muter cet etat n'aurait aucun
 * effet en base et ne ferait que produire une page qui mentirait sur le monde.
 */
final readonly class LoadedWorld
{
    public function __construct(
        public WorldRecord $record,
        public int $tick,
        public WorldState $state,
    ) {
    }

    /** Saison en cours, comptee depuis le genesis (1 tick = 1 jour simule). */
    public function season(): int
    {
        return intdiv($this->tick, 365);
    }

    /** Jour de l'annee simulee - la seule notion de date du noyau (docs/13- §1). */
    public function dayOfYear(): int
    {
        return $this->tick % 365;
    }
}
