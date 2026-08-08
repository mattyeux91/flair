<?php

declare(strict_types=1);

namespace Flair\Harness\Web;

/**
 * Lecture verifiee des entrees HTTP.
 *
 * Les superglobales et un `json_decode()` rendent du `mixed`, et c'est
 * exactement pour ne pas avoir a s'en occuper que `public/index.php` etait
 * reste hors PHPStan - ce qui l'a laisse pourrir sans bruit pendant tout un
 * lot (il importait une classe supprimee, et le cas nominal du POST etait
 * fatal).
 *
 * Meme idiome que `Host\Store\Row` cote base : un point de conversion unique
 * et verifie, plutot qu'un `(int)` sur du `mixed` a chaque site de lecture.
 * Les chaines numeriques sont acceptees - un formulaire HTML n'envoie que des
 * chaines - mais rien d'autre.
 */
final class Input
{
    /** @param array<array-key, mixed> $source */
    public static function int(array $source, string $key, int $default): int
    {
        $value = $source[$key] ?? null;

        return is_numeric($value) ? (int) $value : $default;
    }

    /** @param array<array-key, mixed> $source */
    public static function float(array $source, string $key, float $default): float
    {
        $value = $source[$key] ?? null;

        return is_numeric($value) ? (float) $value : $default;
    }

    /**
     * @param array<array-key, mixed> $source
     * @return array<array-key, mixed>
     */
    public static function map(array $source, string $key): array
    {
        $value = $source[$key] ?? null;

        return is_array($value) ? $value : [];
    }

    /**
     * Le corps JSON de la requete, ou un tableau vide s'il n'en est pas un.
     *
     * @return array<array-key, mixed>
     */
    public static function jsonBody(): array
    {
        $raw = file_get_contents('php://input');
        $decoded = $raw === false ? null : json_decode($raw, associative: true);

        return is_array($decoded) ? $decoded : [];
    }

    /** La methode HTTP, en majuscules. `GET` si le serveur ne la donne pas. */
    public static function method(): string
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? null;

        return is_string($method) ? strtoupper($method) : 'GET';
    }

    /** Une valeur bornee, pour qu'un POST ne puisse pas demander un run de six heures. */
    public static function clamped(int $value, int $min, int $max): int
    {
        return max($min, min($max, $value));
    }
}
