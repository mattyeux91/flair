<?php

declare(strict_types=1);

namespace Flair\Kernel\Tests\Core\Support;

use Flair\Kernel\Core\Support\Hash;
use PHPUnit\Framework\TestCase;

final class HashTest extends TestCase
{
    public function testSameInputsProduceTheSameOutput(): void
    {
        self::assertSame(
            Hash::mix32(777, 10, 12345, 1),
            Hash::mix32(777, 10, 12345, 1),
        );
    }

    public function testChangingTheWorldSeedChangesTheOutput(): void
    {
        self::assertNotSame(
            Hash::mix32(1, 10, 12345, 1),
            Hash::mix32(2, 10, 12345, 1),
        );
    }

    public function testChangingTheTickChangesTheOutput(): void
    {
        self::assertNotSame(
            Hash::mix32(777, 1, 12345, 1),
            Hash::mix32(777, 2, 12345, 1),
        );
    }

    public function testChangingTheSystemIdHashChangesTheOutput(): void
    {
        self::assertNotSame(
            Hash::mix32(777, 10, 1, 1),
            Hash::mix32(777, 10, 2, 1),
        );
    }

    public function testChangingTheEntityIdChangesTheOutput(): void
    {
        self::assertNotSame(
            Hash::mix32(777, 10, 12345, 1),
            Hash::mix32(777, 10, 12345, 2),
        );
    }

    public function testTheOrderOfArgumentsMatters(): void
    {
        // (1,2,3,4) et (2,1,3,4) ne doivent pas se confondre : ce sont deux
        // flux differents (worldSeed=1,tick=2) vs (worldSeed=2,tick=1).
        self::assertNotSame(Hash::mix32(1, 2, 3, 4), Hash::mix32(2, 1, 3, 4));
    }

    public function testOutputsStayWithinUnsigned32BitRange(): void
    {
        for ($i = 0; $i < 10000; $i++) {
            $value = Hash::mix32($i, $i * 7, $i * 13, $i * 31);
            self::assertGreaterThanOrEqual(0, $value);
            self::assertLessThanOrEqual(0xFFFFFFFF, $value);
        }
    }

    /**
     * Vecteur de regression : contrairement au vecteur de Rng, celui-ci n'est
     * pas cross-verifie contre une implementation externe - Hash::mix32 n'est
     * pas un algorithme publie, seulement un usage documente (docs/13- §4.1)
     * de l'avalanche murmur3 deja eprouvee dans Rng::splitMix32. Sert de fil
     * de detente : toute modification (volontaire ou non) doit se voir ici.
     */
    public function testRegressionVector(): void
    {
        self::assertSame(2423077775, Hash::mix32(1, 2, 3, 4));
        self::assertSame(4070628201, Hash::mix32(777, 10, crc32('sysA'), 1));
        self::assertSame(222617050, Hash::mix32(777, 10, crc32('sysB'), 1));
    }
}
