<?php

declare(strict_types=1);

namespace Flair\Kernel\Tests\Core\Snapshot\Fixtures;

/** DTO imbrique : le cas de `SimDate` dans `Contract`. */
final readonly class FixturePoint
{
    public function __construct(public int $x, public int $y)
    {
    }
}
