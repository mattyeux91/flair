<?php

declare(strict_types=1);

namespace Flair\Kernel\Core\Pipeline;

/**
 * Les entites rendues par `SystemContext::createEntity()` pour un systeme
 * et un tick donnes.
 *
 * Sert uniquement a rendre `System::creates()` verifiable. Cette declaration
 * dit "set() sur une entite creee par ce systeme dans ce tick" ; sans ce
 * registre, la moitie "creee par ce systeme dans ce tick" reste une
 * convention sur l'honneur - or c'est elle qui autorise deux systemes a
 * poser le meme composant sans violer l'invariant du writer unique
 * (`YouthIntakeSystem` cree `Contract`, dont `SquadSystem` est le writer).
 *
 * La portee tombe juste sans effort : `Pipeline::tick()` construit un
 * `SystemContext` par systeme et par tick, donc la duree de vie de cet objet
 * *est* "ce systeme, ce tick".
 *
 * Mutable a dessein, dans un `SystemContext` pourtant `readonly` : `readonly`
 * interdit de reaffecter la propriete, pas de muter l'objet reference. Meme
 * schema que Scheduler/OutQueue/SeqCounter, deja detenus par le contexte.
 */
final class CreatedEntities
{
    /** @var array<int, true> */
    private array $ids = [];

    public function add(int $entity): void
    {
        $this->ids[$entity] = true;
    }

    public function contains(int $entity): bool
    {
        return isset($this->ids[$entity]);
    }
}
