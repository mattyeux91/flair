<?php

declare(strict_types=1);

namespace Flair\Kernel\Core\Snapshot;

use BackedEnum;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * Encode et decode une valeur du monde (composant, singleton, evenement, DTO
 * imbrique) vers des donnees plates, par reflexion sur les proprietes promues.
 *
 * Generique plutot que 38 paires `toArray()`/`fromArray()` ecrites a la main :
 * tous les types du domaine ont deja la meme forme - `readonly`, proprietes
 * publiques promues, donnees plates (docs/12- §2) - donc un seul codec les
 * couvre. Ce que cette generalite coute, c'est qu'un type non conforme
 * passerait inapercu ; c'est exactement ce que SnapshotContract empeche, ici a
 * l'execution et au build dans le test de conformite.
 *
 * **L'encodage n'a besoin d'aucune information de type** : chaque valeur est
 * encodee d'apres sa classe reelle. C'est le **decodage** qui a besoin du
 * type declare, lu sur les parametres du constructeur - d'ou l'asymetrie des
 * deux methodes publiques, et d'ou #[SnapshotArrayOf] pour la seule
 * information que la reflexion PHP ne porte pas.
 *
 * Aucune etiquette de type dans le payload d'un objet imbrique : le type du
 * parametre suffit a le reconstruire. Seuls les types de premier niveau
 * portent une cle de registre (cf. SnapshotCodec).
 *
 * Les caches sont des proprietes d'instance, pas des statiques : ils vivent le
 * temps d'un encodage et ne sont un etat global de rien.
 */
final class ValueCodec
{
    /** @var array<class-string, list<ReflectionParameter>> */
    private array $constructors = [];

    /** @var array<class-string, true> */
    private array $validated = [];

    /**
     * @return array<string, mixed>|int|string La forme plate d'un objet, ou
     *         la valeur d'une enum backed.
     */
    public function encode(object $value): array|int|string
    {
        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        $class = $value::class;
        $this->validate($class);

        // Les champs sont enumeres depuis les parametres du constructeur, la
        // meme source que le decodage : encoder et decoder ne peuvent pas
        // diverger sur la liste des champs, quoi qu'il arrive au type.
        $properties = get_object_vars($value);
        $encoded = [];
        foreach ($this->parameters($class) as $parameter) {
            $name = $parameter->getName();
            $encoded[$name] = $this->encodeValue($properties[$name] ?? null, "{$class}::\${$name}");
        }

        return $encoded;
    }

    /**
     * @param class-string $class
     */
    public function decode(string $class, mixed $raw): object
    {
        if (SnapshotContract::isBackedEnum($class)) {
            if (!is_int($raw) && !is_string($raw)) {
                throw SnapshotFormatException::malformed("{$class} attend une valeur d'enum backed");
            }

            /** @var class-string<BackedEnum> $class */
            return $class::from($raw);
        }

        if (!is_array($raw)) {
            throw SnapshotFormatException::malformed("{$class} attend un objet, " . get_debug_type($raw) . ' recu');
        }

        $this->validate($class);

        $arguments = [];
        foreach ($this->parameters($class) as $parameter) {
            $name = $parameter->getName();

            if (!array_key_exists($name, $raw)) {
                throw SnapshotFormatException::malformed("{$class}::\${$name} absent du snapshot");
            }

            $arguments[$name] = $this->decodeValue($parameter, $raw[$name], "{$class}::\${$name}");
            unset($raw[$name]);
        }

        if ($raw !== []) {
            throw SnapshotFormatException::malformed(
                "{$class} : champs inconnus " . implode(', ', array_keys($raw)),
            );
        }

        return new $class(...$arguments);
    }

    private function encodeValue(mixed $value, string $where): mixed
    {
        if ($value === null || is_int($value) || is_string($value) || is_bool($value)) {
            return $value;
        }

        if (is_float($value)) {
            if (is_nan($value) || is_infinite($value)) {
                throw SnapshotFormatException::unsupportedValue($where, 'flottant NAN ou infini');
            }

            return $value;
        }

        if (is_array($value)) {
            $encoded = [];
            foreach ($value as $key => $element) {
                $encoded[$key] = $this->encodeValue($element, "{$where}[{$key}]");
            }

            return $encoded;
        }

        if (is_object($value)) {
            return $this->encode($value);
        }

        throw SnapshotFormatException::unsupportedValue($where, get_debug_type($value));
    }

    private function decodeValue(ReflectionParameter $parameter, mixed $raw, string $where): mixed
    {
        $type = $parameter->getType();

        if (!$type instanceof ReflectionNamedType) {
            throw SnapshotFormatException::unsupportedValue($where, 'type absent, union ou intersection');
        }

        if ($raw === null) {
            if (!$type->allowsNull()) {
                throw SnapshotFormatException::malformed("{$where} est null alors que le type ne l'admet pas");
            }

            return null;
        }

        $name = $type->getName();

        if ($name === 'array') {
            if (!is_array($raw)) {
                throw SnapshotFormatException::malformed("{$where} attend un tableau");
            }

            $element = SnapshotContract::elementType($parameter);
            if ($element === null) {
                throw SnapshotFormatException::unsupportedValue($where, 'propriete array sans #[SnapshotArrayOf]');
            }

            $decoded = [];
            foreach ($raw as $key => $value) {
                $decoded[$key] = $this->decodeElement($element, $value, "{$where}[{$key}]");
            }

            return $decoded;
        }

        return $this->decodeElement($name, $raw, $where);
    }

    /** @param class-string|string $type */
    private function decodeElement(string $type, mixed $raw, string $where): mixed
    {
        return match ($type) {
            'int' => is_int($raw)
                ? $raw
                : throw SnapshotFormatException::malformed("{$where} attend un entier, " . get_debug_type($raw) . ' recu'),
            // Un flottant de valeur entiere peut revenir en `int` d'un JSON
            // ecrit sans JSON_PRESERVE_ZERO_FRACTION : on le recadre plutot
            // que de laisser strict_types lever un TypeError obscur au
            // constructeur.
            'float' => is_float($raw) ? $raw : (is_int($raw)
                ? (float) $raw
                : throw SnapshotFormatException::malformed("{$where} attend un flottant, " . get_debug_type($raw) . ' recu')),
            'string' => is_string($raw)
                ? $raw
                : throw SnapshotFormatException::malformed("{$where} attend une chaine, " . get_debug_type($raw) . ' recu'),
            'bool' => is_bool($raw)
                ? $raw
                : throw SnapshotFormatException::malformed("{$where} attend un booleen, " . get_debug_type($raw) . ' recu'),
            default => class_exists($type) || enum_exists($type)
                ? $this->decode($type, $raw)
                : throw SnapshotFormatException::unsupportedValue($where, "type \"{$type}\""),
        };
    }

    /**
     * @param class-string $class
     * @return list<ReflectionParameter>
     */
    private function parameters(string $class): array
    {
        if (isset($this->constructors[$class])) {
            return $this->constructors[$class];
        }

        $constructor = (new ReflectionClass($class))->getConstructor();

        return $this->constructors[$class] = $constructor === null ? [] : $constructor->getParameters();
    }

    /** @param class-string $class */
    private function validate(string $class): void
    {
        if (isset($this->validated[$class])) {
            return;
        }

        $violations = SnapshotContract::violations($class);
        if ($violations !== []) {
            throw SnapshotFormatException::unsupportedValue($class, implode(' ; ', $violations));
        }

        $this->validated[$class] = true;
    }
}
