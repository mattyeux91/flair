<?php

declare(strict_types=1);

namespace Flair\Kernel\Core\Snapshot;

use RuntimeException;

/**
 * Un snapshot ne peut pas etre ecrit ou relu : type inconnu du registre,
 * version de format ou de noyau incompatible, valeur hors du contrat
 * d'encodage (cf. ValueCodec).
 *
 * Toujours une exception, jamais une valeur par defaut silencieuse : une
 * donnee de monde qu'on ne sait pas relire est de l'etat perdu, et perdre de
 * l'etat sans bruit est exactement le mode de panne que ce lot existe pour
 * rendre impossible.
 */
final class SnapshotFormatException extends RuntimeException
{
    public static function unknownKey(string $key): self
    {
        return new self("Aucun type enregistre pour la cle de snapshot \"{$key}\".");
    }

    public static function unregisteredClass(string $class): self
    {
        return new self("La classe \"{$class}\" n'est enregistree dans aucun TypeRegistry.");
    }

    public static function duplicateKey(string $key): self
    {
        return new self("La cle de snapshot \"{$key}\" est declaree deux fois.");
    }

    public static function unsupportedValue(string $context, string $detail): self
    {
        return new self("Valeur non encodable pour {$context} : {$detail}.");
    }

    public static function incompatibleFormat(int $found, int $expected): self
    {
        return new self("Snapshot au format {$found}, ce noyau lit le format {$expected}.");
    }

    public static function incompatibleKernel(string $found, string $expected): self
    {
        return new self(
            "Snapshot ecrit par le noyau {$found}, ce noyau est en {$expected} : "
            . 'une migration explicite est necessaire (docs/13- §6).',
        );
    }

    public static function malformed(string $detail): self
    {
        return new self("Snapshot malforme : {$detail}.");
    }
}
