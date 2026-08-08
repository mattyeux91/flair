<?php

declare(strict_types=1);

namespace Flair\Kernel\Core\Snapshot;

use BackedEnum;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * Le contrat qu'un type doit respecter pour etre serialisable par
 * ValueCodec, et l'unique endroit ou il est ecrit.
 *
 * Deux consommateurs, et c'est voulu :
 *
 * - **ValueCodec**, a l'execution, memoise par classe : le codec refuse
 *   d'encoder un objet dont il perdrait une partie de l'etat, plutot que de
 *   l'ecrire amoindri sans bruit.
 * - **Tests\Core\Snapshot\SnapshotConformanceTest**, au build, sur tout le
 *   domaine : un composant qui viole le contrat casse la CI, pas un
 *   redemarrage de monde a 3 h du matin.
 *
 * Le contrat lui-meme :
 *
 * - une enum **backed** (`int`/`string`) ;
 * - ou une classe dont **toutes** les proprietes declarees sont publiques et
 *   promues au constructeur - c'est deja le cas des 22 composants, des 2
 *   singletons et des 14 evenements du domaine, verifie par reflexion ;
 * - dont chaque parametre de constructeur porte un type nomme parmi
 *   `int`, `float`, `string`, `bool`, `array`, une enum backed, ou une autre
 *   classe conforme (recursivement, cf. `SimDate` dans `Contract`) ;
 * - toute propriete `array` portant `#[SnapshotArrayOf]` (la reflexion PHP ne
 *   donne pas le type des elements).
 *
 * Ce qui est volontairement **hors** contrat : les types union et
 * intersection, `mixed`, `object`, les ressources, les closures. Aucun n'existe
 * dans l'etat du monde aujourd'hui, et chacun demanderait une etiquette de
 * type dans le payload - donc un FQCN, donc un format qui casse au premier
 * renommage.
 */
final class SnapshotContract
{
    private const array SCALARS = ['int', 'float', 'string', 'bool'];

    /**
     * @param class-string $class
     * @param array<class-string, true> $visited garde-fou de recursion
     * @return list<string> les violations, [] si le type est conforme
     */
    public static function violations(string $class, array $visited = []): array
    {
        if (isset($visited[$class])) {
            return [];
        }

        $visited[$class] = true;

        if (!class_exists($class) && !enum_exists($class)) {
            return ["{$class} : type inconnu"];
        }

        $reflection = new ReflectionClass($class);

        if ($reflection->isEnum()) {
            return self::isBackedEnum($class)
                ? []
                : ["{$class} : enum non backed, aucune valeur a serialiser"];
        }

        $violations = [];

        foreach ($reflection->getProperties() as $property) {
            if ($property->isStatic()) {
                continue;
            }

            if (!$property->isPublic() || !$property->isPromoted()) {
                $violations[] = "{$class}::\${$property->getName()} : propriete non publique ou non promue";
            }
        }

        $constructor = $reflection->getConstructor();
        if ($constructor === null) {
            return $violations;
        }

        foreach ($constructor->getParameters() as $parameter) {
            foreach (self::parameterViolations($class, $parameter, $visited) as $violation) {
                $violations[] = $violation;
            }
        }

        return $violations;
    }

    /**
     * @param class-string $class
     * @param array<class-string, true> $visited
     * @return list<string>
     */
    private static function parameterViolations(string $class, ReflectionParameter $parameter, array $visited): array
    {
        $where = "{$class}::\${$parameter->getName()}";
        $type = $parameter->getType();

        if (!$type instanceof ReflectionNamedType) {
            return ["{$where} : type absent, union ou intersection"];
        }

        $name = $type->getName();

        if (in_array($name, self::SCALARS, true)) {
            return [];
        }

        if ($name === 'array') {
            $element = self::elementType($parameter);

            if ($element === null) {
                return ["{$where} : propriete array sans #[SnapshotArrayOf]"];
            }

            if (in_array($element, self::SCALARS, true)) {
                return [];
            }

            if (!class_exists($element) && !enum_exists($element)) {
                return ["{$where} : #[SnapshotArrayOf(\"{$element}\")] ne designe ni un scalaire ni une classe"];
            }

            return self::violations($element, $visited);
        }

        if (class_exists($name) || enum_exists($name)) {
            return self::violations($name, $visited);
        }

        return ["{$where} : type \"{$name}\" hors contrat"];
    }

    /**
     * Le type d'element declare par #[SnapshotArrayOf], ou null s'il manque.
     *
     * @return class-string|string|null
     */
    public static function elementType(ReflectionParameter $parameter): ?string
    {
        $attributes = $parameter->getAttributes(SnapshotArrayOf::class);

        return $attributes === [] ? null : $attributes[0]->newInstance()->type;
    }

    /** @param class-string $class */
    public static function isBackedEnum(string $class): bool
    {
        return enum_exists($class) && is_a($class, BackedEnum::class, true);
    }
}
