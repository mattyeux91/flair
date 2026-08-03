<?php

declare(strict_types=1);

namespace Flair\Kernel\Core\Pipeline;

use LogicException;

/**
 * Un systeme a touche un composant qu'il n'a pas declare (docs/13- §2).
 *
 * `LogicException` et pas une exception metier : ce n'est jamais une
 * situation de monde, c'est un bug de declaration. Le message nomme la
 * declaration a ajouter, parce que c'est toujours la correction attendue -
 * ajouter la declaration manquante, jamais contourner le controle.
 *
 * Attention : ajouter une declaration n'est pas anodin. `reads()`/`writes()`
 * /`removes()` alimentent les invariants de `Football\PipelineInvariantsTest`
 * (un seul writer par composant, aucune lecture d'un composant ecrit plus
 * loin). Une declaration ajoutee a la legere peut donc faire echouer un
 * invariant - c'est le but.
 */
final class UndeclaredAccessException extends LogicException
{
    public static function read(string $systemId, string $componentType): self
    {
        return new self(sprintf(
            'Le systeme "%s" lit %s sans l\'avoir declare : ajoute-le a reads().',
            $systemId,
            $componentType,
        ));
    }

    public static function write(string $systemId, string $componentType): self
    {
        return new self(sprintf(
            'Le systeme "%s" mute %s sans l\'avoir declare : ajoute-le a writes(), creates() ou removes().',
            $systemId,
            $componentType,
        ));
    }

    public static function set(string $systemId, string $componentType): self
    {
        return new self(sprintf(
            'Le systeme "%s" ecrit %s mais ne l\'a declare qu\'en removes() : ajoute-le a writes().',
            $systemId,
            $componentType,
        ));
    }

    public static function remove(string $systemId, string $componentType): self
    {
        return new self(sprintf(
            'Le systeme "%s" retire %s sans l\'avoir declare : ajoute-le a removes().',
            $systemId,
            $componentType,
        ));
    }

    public static function setOnForeignEntity(string $systemId, string $componentType, int $entity): self
    {
        return new self(sprintf(
            'Le systeme "%s" a declare %s en creates() seulement, mais l\'ecrit sur l\'entite %d '
            . 'qu\'il n\'a pas creee dans ce tick. creates() ne couvre que les entites issues de '
            . 'createEntity() : pour muter une entite preexistante, il faut writes() - ce qui '
            . 'entre en conflit avec son writer actuel (docs/13- §2).',
            $systemId,
            $componentType,
            $entity,
        ));
    }
}
