<?php

declare(strict_types=1);

namespace Flair\Kernel\Core\Support;

/**
 * Derive une graine 32 bits unique pour un flux (monde, tick, systeme,
 * entite), pour que chaque systeme et chaque entite ait sa propre sequence
 * aleatoire, isolee et stable (docs/13-moteur-de-simulation.md §4.1).
 *
 * Signature calquee sur l'exemple documente : `systemIdHash` est deja
 * `crc32($systemId)` cote appelant (voir Rng::forStream), pas une chaine -
 * Hash reste une fonction pure sur des entiers.
 *
 * Implementation : XOR-fold sequentiel des 4 valeurs, avec un avalanche
 * murmur3 (memes constantes que Rng::splitMix32) entre chaque etape. Chaque
 * operation passe par Math32::mul32 pour eviter le piege du depassement
 * int -> float de PHP (docs/13- §4.3).
 */
final class Hash
{
    private const MASK = 0xFFFFFFFF;

    public static function mix32(int $worldSeed, int $tick, int $systemIdHash, int $entityId): int
    {
        return self::mixAll($worldSeed, $tick, $systemIdHash, $entityId);
    }

    /**
     * Le meme repliement, sur un nombre quelconque de valeurs - pour les
     * derivations qui ne sont pas un flux RNG et n'ont donc pas la forme
     * (monde, tick, systeme, entite) : la perception d'un joueur par un scout
     * se derive de (monde, observateur, sujet, nb d'observations), sans tick
     * (docs/12- §4 : l'erreur d'un observateur est un biais stable, pas un
     * bruit re-tire a chaque lecture).
     *
     * `mix32()` en est le cas a quatre valeurs, exactement : l'etat part de 0
     * et absorbe les valeurs en sequence, donc la forme variadique ne change
     * aucune valeur deja produite.
     */
    public static function mixAll(int ...$values): int
    {
        $state = 0;

        foreach ($values as $value) {
            $state = self::combine($state, $value);
        }

        return $state;
    }

    private static function combine(int $state, int $value): int
    {
        return self::avalanche(($state ^ $value) & self::MASK);
    }

    /** Finisseur MurmurHash3, deja utilise dans Rng::splitMix32. */
    private static function avalanche(int $z): int
    {
        $z = Math32::mul32($z ^ ($z >> 16), 0x85EBCA6B);
        $z = Math32::mul32($z ^ ($z >> 13), 0xC2B2AE35);

        return ($z ^ ($z >> 16)) & self::MASK;
    }
}
