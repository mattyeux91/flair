<?php

declare(strict_types=1);

namespace Flair\Kernel\Core\Snapshot;

use JsonException;

/**
 * La representation textuelle d'un snapshot. Pure manipulation de chaine :
 * aucune ecriture, aucun acces disque - le stockage reste l'affaire du Host,
 * seul le *format* appartient au noyau.
 *
 * JSON plutot que `serialize()` : un dump PHP lie le format aux noms de
 * classes et se relit mal a la main. Un snapshot doit rester inspectable et
 * migrable (docs/13- §6), et ce sera la valeur d'une colonne `jsonb`.
 *
 * `JSON_PRESERVE_ZERO_FRACTION` n'est pas un detail de confort : sans lui,
 * un `1.0` s'ecrit `1` et revient en `int`. Les flottants du monde
 * (Facilities::$quality, MarketInflation::$index, PlayerPotentials::$growthRate)
 * doivent revenir **au bit pres** - un bit perdu ne casse rien de visible, il
 * fait diverger le monde au tick suivant. La fidelite est verifiee par un
 * test sur des doubles adverses plutot que par une lecture de `ini_get()`,
 * qui serait un acces a l'environnement dans le noyau (docs/11- §1).
 */
final class JsonSnapshotFormat
{
    private const int ENCODE_FLAGS = JSON_PRESERVE_ZERO_FRACTION
        | JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
        | JSON_THROW_ON_ERROR;

    public static function toJson(WorldSnapshot $snapshot): string
    {
        try {
            return json_encode($snapshot->toArray(), self::ENCODE_FLAGS);
        } catch (JsonException $exception) {
            throw SnapshotFormatException::malformed('encodage JSON impossible - ' . $exception->getMessage());
        }
    }

    public static function fromJson(string $json): WorldSnapshot
    {
        try {
            $raw = json_decode($json, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw SnapshotFormatException::malformed('JSON illisible - ' . $exception->getMessage());
        }

        if (!is_array($raw)) {
            throw SnapshotFormatException::malformed('le JSON ne contient pas un objet');
        }

        /** @var array<string, mixed> $raw */
        return WorldSnapshot::fromArray($raw);
    }
}
