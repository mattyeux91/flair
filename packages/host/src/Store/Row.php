<?php

declare(strict_types=1);

namespace Flair\Host\Store;

/**
 * Lecture typee d'une ligne rendue par le query builder.
 *
 * `illuminate/database` rend des `stdClass` dont chaque propriete est `mixed` :
 * le pilote ne sait pas ce que la colonne contient. Un `(int) $row->tick`
 * suffirait a faire tourner le code, mais PHPStan au niveau max le refuse a
 * juste titre - un cast de `mixed` transforme silencieusement `null` en `0` et
 * `"abc"` en `0`, ce qui est exactement comme perdre un tick sans le savoir.
 *
 * Ces trois methodes verifient au lieu de caster, et **levent** quand la
 * colonne n'a pas la forme attendue : une base dont le schema a derive doit
 * s'annoncer bruyamment, pas produire un monde au tick 0.
 *
 * `int` accepte aussi une chaine de chiffres : PostgreSQL rend les `bigint`
 * en texte via PDO des que la valeur peut depasser un entier de plateforme,
 * et un tick comme une graine sont des `bigint`.
 */
final class Row
{
    public static function string(object $row, string $field): string
    {
        $value = $row->{$field} ?? null;

        return is_string($value)
            ? $value
            : throw new UnexpectedColumn($field, 'chaine', get_debug_type($value));
    }

    public static function int(object $row, string $field): int
    {
        $value = $row->{$field} ?? null;

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        throw new UnexpectedColumn($field, 'entier', get_debug_type($value));
    }

    /**
     * Un entier depuis une valeur deja extraite (agregat, `pluck()`), qui n'a
     * pas de ligne ni de nom de colonne autour d'elle.
     */
    public static function toInt(mixed $value, int $default = 0): int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && preg_match('/^-?\d+$/', $value) === 1 ? (int) $value : $default;
    }
}
