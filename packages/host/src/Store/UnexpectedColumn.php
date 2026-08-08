<?php

declare(strict_types=1);

namespace Flair\Host\Store;

use RuntimeException;

/**
 * Une colonne ne contient pas ce que le code attend : schema derive, migration
 * manquante, ou requete qui ne selectionne pas ce qu'elle croit.
 *
 * Une exception plutot qu'une valeur par defaut, pour la meme raison que dans
 * `Core\Snapshot\SnapshotFormatException` : une donnee de monde qu'on ne sait
 * pas lire est de l'etat perdu, et le perdre sans bruit est le seul
 * comportement inacceptable.
 */
final class UnexpectedColumn extends RuntimeException
{
    public function __construct(string $column, string $expected, string $found)
    {
        parent::__construct("La colonne \"{$column}\" devrait etre un(e) {$expected}, {$found} trouve.");
    }
}
