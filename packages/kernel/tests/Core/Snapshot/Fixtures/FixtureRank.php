<?php

declare(strict_types=1);

namespace Flair\Kernel\Tests\Core\Snapshot\Fixtures;

/** Enum backed sur des entiers, l'autre moitie du cas enum. */
enum FixtureRank: int
{
    case First = 1;
    case Second = 2;
}
