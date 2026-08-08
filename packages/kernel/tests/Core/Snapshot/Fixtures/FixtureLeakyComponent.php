<?php

declare(strict_types=1);

namespace Flair\Kernel\Tests\Core\Snapshot\Fixtures;

/**
 * Non conforme au contrat : une propriete privee, donc de l'etat que le codec
 * ecrirait amoindri sans rien signaler. C'est precisement le mode de panne que
 * SnapshotContract existe pour rendre bruyant.
 */
final class FixtureLeakyComponent
{
    public function __construct(public int $visible, private int $hidden = 7)
    {
    }

    public function hidden(): int
    {
        return $this->hidden;
    }
}
