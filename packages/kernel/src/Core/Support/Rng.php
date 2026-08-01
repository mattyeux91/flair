<?php

declare(strict_types=1);

namespace Flair\Kernel\Core\Support;

/**
 * PRNG 32 bits deterministe (xoshiro128**, Blackman & Vigna, domaine public).
 *
 * PHP fait basculer silencieusement un depassement d'int en float - sans
 * erreur ni avertissement - ce qui casserait le determinisme sans le
 * signaler. Toute multiplication 32x32 passe donc par {@see Math32::mul32()},
 * qui ne repose jamais sur le fait qu'un int PHP tienne sur 64 bits.
 *
 * Voir docs/13-moteur-de-simulation.md §4.3.
 */
final class Rng
{
    private const MASK = 0xFFFFFFFF;

    private int $s0;
    private int $s1;
    private int $s2;
    private int $s3;

    public function __construct(int $seed)
    {
        $state = $seed & self::MASK;

        $this->s0 = self::splitMix32($state);
        $this->s1 = self::splitMix32($state);
        $this->s2 = self::splitMix32($state);
        $this->s3 = self::splitMix32($state);

        if (($this->s0 | $this->s1 | $this->s2 | $this->s3) === 0) {
            // xoshiro128** est absorbant sur l'etat nul : une graine
            // pathologique (0) ne doit jamais y mener.
            $this->s0 = 1;
        }
    }

    /**
     * Un flux isole par (monde, tick, systeme, entite) - jamais un PRNG
     * global partage (docs/13- §4.1) : ajouter un systeme ou une entite ne
     * decale le tirage d'aucun autre flux.
     */
    public static function forStream(int $worldSeed, int $tick, string $systemId, int $entityId): self
    {
        return new self(Hash::mix32($worldSeed, $tick, crc32($systemId), $entityId));
    }

    public function nextUint32(): int
    {
        $result = Math32::mul32(self::rotl(Math32::mul32($this->s1, 5), 7), 9);

        $t = ($this->s1 << 9) & self::MASK;

        $this->s2 ^= $this->s0;
        $this->s3 ^= $this->s1;
        $this->s1 ^= $this->s2;
        $this->s0 ^= $this->s3;
        $this->s2 ^= $t;
        $this->s3 = self::rotl($this->s3, 11);

        return $result;
    }

    /** Deroule un etat initial bien distribue a partir d'une seule graine (finisseur MurmurHash3). */
    private static function splitMix32(int &$state): int
    {
        $state = ($state + 0x9E3779B9) & self::MASK;
        $z = $state;
        $z = Math32::mul32($z ^ ($z >> 16), 0x85EBCA6B);
        $z = Math32::mul32($z ^ ($z >> 13), 0xC2B2AE35);

        return ($z ^ ($z >> 16)) & self::MASK;
    }

    private static function rotl(int $x, int $k): int
    {
        return (($x << $k) | ($x >> (32 - $k))) & self::MASK;
    }
}
