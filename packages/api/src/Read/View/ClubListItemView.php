<?php

declare(strict_types=1);

namespace Flair\Api\Read\View;

/** Un club dans la liste d'un monde : juste assez pour choisir lequel ouvrir. */
final readonly class ClubListItemView
{
    public function __construct(
        public int $id,
        public string $name,
        public int $squadSize,
        public int $balanceCents,
    ) {
    }
}
