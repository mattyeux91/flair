<?php

declare(strict_types=1);

namespace Flair\Api\Format;

/**
 * Les montants du monde en euros lisibles.
 *
 * Le noyau ne connait que des **centimes entiers** (`Finances::$balanceCents`,
 * `Contract::$wagePerWeekCents`...) : c'est ce qui rend l'invariant monetaire
 * exact au centime, et il n'a jamais a devenir un flottant. La conversion
 * n'appartient donc qu'a l'affichage, et elle vit ici plutot que dans un DTO -
 * un `ClubSheetView` qui porterait des chaines formatees ne serait plus
 * serialisable en JSON pour un client qui veut recalculer.
 */
final class Money
{
    /** Ex. `-1 234 567 890` centimes -> `-12 345 678,90 €`. */
    public static function euros(int $cents): string
    {
        return number_format($cents / 100, 2, ',', "\u{202f}") . "\u{a0}€";
    }

    /** Sans les centimes, pour les gros montants ou ils sont du bruit. */
    public static function roundEuros(int $cents): string
    {
        return number_format((int) round($cents / 100), 0, ',', "\u{202f}") . "\u{a0}€";
    }
}
