<?php

declare(strict_types=1);

namespace Flair\Kernel\Tests\Core;

use Flair\Kernel\Core\Rng;
use PHPUnit\Framework\TestCase;

final class RngTest extends TestCase
{
    public function testSameSeedProducesTheSameSequence(): void
    {
        $a = new Rng(777);
        $b = new Rng(777);

        for ($i = 0; $i < 1000; $i++) {
            self::assertSame($a->nextUint32(), $b->nextUint32());
        }
    }

    public function testDifferentSeedsDiverge(): void
    {
        $a = new Rng(1);
        $b = new Rng(2);

        self::assertNotSame($a->nextUint32(), $b->nextUint32());
    }

    public function testOutputsStayWithinUnsigned32BitRangeAndNeverBecomeFloat(): void
    {
        $rng = new Rng(123456789);

        for ($i = 0; $i < 100000; $i++) {
            $value = $rng->nextUint32();
            self::assertIsInt($value);
            self::assertGreaterThanOrEqual(0, $value);
            self::assertLessThanOrEqual(0xFFFFFFFF, $value);
        }
    }

    public function testZeroSeedDoesNotCollapseToTheAbsorbingAllZeroState(): void
    {
        $rng = new Rng(0);

        self::assertNotSame(0, $rng->nextUint32());
    }

    /**
     * Vecteur de regression : toute modification (volontaire ou non) de
     * l'algorithme doit se voir ici. Valeurs figees apres verification
     * croisee contre une implementation Python independante (arithmetique
     * arbitraire, donc a l'abri du piege de depassement decrit en
     * docs/13-moteur-de-simulation.md §4.3/§4.9).
     */
    public function testRegressionVectorForSeed42(): void
    {
        $rng = new Rng(42);

        self::assertSame(
            [2837322924, 544945897, 479756282, 3500138142, 339756180],
            [
                $rng->nextUint32(),
                $rng->nextUint32(),
                $rng->nextUint32(),
                $rng->nextUint32(),
                $rng->nextUint32(),
            ],
        );
    }
}
