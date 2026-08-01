<?php

declare(strict_types=1);

namespace Flair\Kernel\Tests\Core\Support;

use Flair\Kernel\Core\Support\Rng;
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

    public function testOutputsStayWithinUnsigned32BitRange(): void
    {
        $rng = new Rng(123456789);

        for ($i = 0; $i < 100000; $i++) {
            $value = $rng->nextUint32();
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

    public function testForStreamProducesTheSameSequenceForTheSameStreamKey(): void
    {
        $a = Rng::forStream(worldSeed: 777, tick: 10, systemId: 'aging', entityId: 42);
        $b = Rng::forStream(worldSeed: 777, tick: 10, systemId: 'aging', entityId: 42);

        for ($i = 0; $i < 100; $i++) {
            self::assertSame($a->nextUint32(), $b->nextUint32());
        }
    }

    public function testForStreamDivergesWhenTheWorldSeedChanges(): void
    {
        $a = Rng::forStream(worldSeed: 1, tick: 10, systemId: 'aging', entityId: 42);
        $b = Rng::forStream(worldSeed: 2, tick: 10, systemId: 'aging', entityId: 42);

        self::assertNotSame($a->nextUint32(), $b->nextUint32());
    }

    public function testForStreamDivergesWhenTheTickChanges(): void
    {
        $a = Rng::forStream(worldSeed: 777, tick: 1, systemId: 'aging', entityId: 42);
        $b = Rng::forStream(worldSeed: 777, tick: 2, systemId: 'aging', entityId: 42);

        self::assertNotSame($a->nextUint32(), $b->nextUint32());
    }

    public function testForStreamDivergesWhenTheSystemIdChanges(): void
    {
        $a = Rng::forStream(worldSeed: 777, tick: 10, systemId: 'aging', entityId: 42);
        $b = Rng::forStream(worldSeed: 777, tick: 10, systemId: 'training', entityId: 42);

        self::assertNotSame($a->nextUint32(), $b->nextUint32());
    }

    public function testForStreamDivergesWhenTheEntityIdChanges(): void
    {
        $a = Rng::forStream(worldSeed: 777, tick: 10, systemId: 'aging', entityId: 1);
        $b = Rng::forStream(worldSeed: 777, tick: 10, systemId: 'aging', entityId: 2);

        self::assertNotSame($a->nextUint32(), $b->nextUint32());
    }

    /**
     * Vecteur de regression pour forStream() - memes reserves que celui de
     * Hash::mix32 (non cross-verifie, sert de fil de detente).
     */
    public function testForStreamRegressionVector(): void
    {
        $rng = Rng::forStream(worldSeed: 777, tick: 10, systemId: 'aging', entityId: 42);

        self::assertSame(
            [2433504188, 781999779, 3572523971, 2246616197, 2054322769],
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
