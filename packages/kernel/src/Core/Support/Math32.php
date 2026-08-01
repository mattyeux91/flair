<?php

declare(strict_types=1);

namespace Flair\Kernel\Core\Support;

/**
 * Arithmetique 32 bits masquee, partagee par Rng et Hash (docs/13- §4.3).
 *
 * PHP fait basculer silencieusement un depassement d'int en float - sans
 * erreur ni avertissement. Toute multiplication 32x32 passe par mul32(), qui
 * ne repose jamais sur le fait qu'un int PHP tienne sur 64 bits.
 */
final class Math32
{
    private const MASK = 0xFFFFFFFF;

    /**
     * Multiplication 32x32 -> 32 bits basse, par blocs de 16 bits.
     *
     * Une multiplication directe (a * b) & self::MASK ne suffit pas : le
     * produit intermediaire de deux operandes proches de 0xFFFFFFFF depasse
     * PHP_INT_MAX sur une machine 64 bits, et bascule en float *avant* que
     * le masque ne s'applique.
     */
    public static function mul32(int $a, int $b): int
    {
        $aLo = $a & 0xFFFF;
        $aHi = ($a >> 16) & 0xFFFF;
        $bLo = $b & 0xFFFF;
        $bHi = ($b >> 16) & 0xFFFF;

        $low = $aLo * $bLo;
        $mid = ($aLo * $bHi + $aHi * $bLo) & 0xFFFF;

        return ($low + ($mid << 16)) & self::MASK;
    }
}
