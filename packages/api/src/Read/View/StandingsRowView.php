<?php

declare(strict_types=1);

namespace Flair\Api\Read\View;

/**
 * Une ligne de classement. Copie plate de `Football\Components\StandingsEntry`,
 * augmentee du rang et du nom du club - les deux seules choses que le composant
 * ne porte pas, parce qu'il n'a pas a les porter (le rang est un tri, le nom
 * vit sur `Club`).
 */
final readonly class StandingsRowView
{
    public function __construct(
        public int $rank,
        public int $clubId,
        public string $clubName,
        public int $played,
        public int $won,
        public int $drawn,
        public int $lost,
        public int $goalsFor,
        public int $goalsAgainst,
        public int $points,
    ) {
    }

    public function goalDifference(): int
    {
        return $this->goalsFor - $this->goalsAgainst;
    }
}
