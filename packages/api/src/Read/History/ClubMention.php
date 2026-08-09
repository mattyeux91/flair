<?php

declare(strict_types=1);

namespace Flair\Api\Read\History;

/** Un club nomme par un Fait, et a quel titre. */
final readonly class ClubMention
{
    public function __construct(
        public int $clubId,
        public ClubRole $role,
    ) {
    }
}
