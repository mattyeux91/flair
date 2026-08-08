<?php

declare(strict_types=1);

namespace Flair\Kernel\Tests\Core\Snapshot\Fixtures;

use Flair\Kernel\Core\Snapshot\SnapshotArrayOf;

/**
 * Un composant qui rassemble toutes les formes du contrat en un seul type :
 * scalaires, flottant, enum backed, DTO imbrique, map keyee d'objets, liste de
 * scalaires, et les deux nullables.
 */
final readonly class FixtureComponent
{
    /**
     * @param array<int, FixturePoint> $points keye, pas une liste
     * @param list<int> $numbers
     */
    public function __construct(
        public int $count,
        public float $ratio,
        public string $label,
        public bool $active,
        public FixtureColour $colour,
        public FixturePoint $origin,
        #[SnapshotArrayOf(FixturePoint::class)]
        public array $points,
        #[SnapshotArrayOf('int')]
        public array $numbers,
        public ?FixturePoint $optional = null,
        public ?int $maybe = null,
    ) {
    }
}
