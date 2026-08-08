<?php

declare(strict_types=1);

namespace Flair\Worldgen\Tests;

use Flair\Kernel\Core\Ecs\WorldState;
use Flair\Kernel\Core\Support\Rng;
use Flair\Kernel\Football\Components\BoardPatience;
use Flair\Worldgen\ClubFactory;
use PHPUnit\Framework\TestCase;

final class ClubFactoryTest extends TestCase
{
    public function testDisperseBoardPatienceIsDispersedAroundTheMeanAndStaysOnTheAbsoluteScale(): void
    {
        $world = new WorldState();
        $clubIds = range(1, 60);

        (new ClubFactory())->disperseBoardPatience($world, new Rng(7), $clubIds, mean: 50, spread: 25);

        $levels = array_map(static fn (int $id): int => $world->components(BoardPatience::class)->get($id)->level ?? 0, $clubIds);

        self::assertGreaterThanOrEqual(25, min($levels));
        self::assertLessThanOrEqual(75, max($levels));
        self::assertGreaterThan(min($levels), max($levels), 'une dispersion non nulle doit produire des clubs differents');
    }

    /** L'experience de controle : un monde ou aucun club n'est ni plus ni moins patient qu'un autre. */
    public function testZeroSpreadMakesEveryClubIdentical(): void
    {
        $world = new WorldState();
        $clubIds = range(1, 10);

        (new ClubFactory())->disperseBoardPatience($world, new Rng(7), $clubIds, mean: 50, spread: 0);

        $levels = array_map(static fn (int $id): int => $world->components(BoardPatience::class)->get($id)->level ?? 0, $clubIds);

        self::assertSame(array_fill(0, 10, 50), $levels);
    }
}
