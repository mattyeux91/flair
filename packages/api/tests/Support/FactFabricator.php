<?php

declare(strict_types=1);

namespace Flair\Api\Tests\Support;

use Flair\Kernel\Core\Messaging\DomainEvent;
use Flair\Kernel\Core\Snapshot\SnapshotArrayOf;
use PHPUnit\Framework\Assert;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * Un exemplaire de n'importe quel Fait du domaine, fabrique **par reflexion**.
 *
 * Extrait de `Tests\Architecture\EveryFactIsPlacedOrExcludedTest` quand le
 * digest lui a donne un second consommateur reel - le critere d'extraction du
 * projet, satisfait sans etre force.
 *
 * Par reflexion et non par une liste d'exemplaires ecrite a la main : une telle
 * liste aurait exactement le defaut que les tests qui s'en servent existent pour
 * corriger, elle vieillirait sans bruit. Les entiers valent 1, 2, 3... pour
 * qu'un code qui confondrait deux champs (acheteur et vendeur, par exemple) ne
 * passe pas inapercu.
 */
final class FactFabricator
{
    /** @param class-string $class */
    public static function make(string $class): DomainEvent
    {
        $reflection = new ReflectionClass($class);
        $constructor = $reflection->getConstructor();
        $arguments = [];
        $counter = 1;

        foreach ($constructor?->getParameters() ?? [] as $parameter) {
            $type = $parameter->getType();
            $name = $type instanceof ReflectionNamedType ? $type->getName() : 'int';

            $arguments[] = match ($name) {
                'int' => $counter++,
                'float' => (float) $counter++,
                'string' => 'x' . $counter++,
                'bool' => true,
                'array' => self::listFor($parameter, $counter),
                default => null,
            };
        }

        $event = $reflection->newInstanceArgs($arguments);

        return $event instanceof DomainEvent
            ? $event
            : Assert::fail("{$class} est enregistre comme Fait mais n'implemente pas DomainEvent.");
    }

    /**
     * Trois elements du type que le parametre **declare deja** via
     * `SnapshotArrayOf` - l'attribut dont le codec se sert pour serialiser ce
     * meme champ.
     *
     * Suivre cette declaration plutot qu'ecrire `[10, 11, 12]` en dur est ce
     * qui empeche ce code de vieillir : le jour ou un Fait porte une liste
     * d'objets (`SeasonConcluded::$finalTable` est le premier), une constante
     * d'entiers ferait tomber les lecteurs sur une erreur de type, et le test
     * accuserait le code au lieu de lui-meme.
     *
     * @return list<mixed>
     */
    private static function listFor(ReflectionParameter $parameter, int &$counter): array
    {
        $attributes = $parameter->getAttributes(SnapshotArrayOf::class);
        $of = $attributes === [] ? 'int' : $attributes[0]->newInstance()->type;

        if ($of === 'int') {
            return [10, 11, 12];
        }

        Assert::assertTrue(class_exists($of), "SnapshotArrayOf({$of}) ne designe ni 'int' ni une classe.");

        // Le premier parametre porte l'identifiant dans tous les types de
        // valeur du domaine (`StandingsEntry::$clubId`), et les suivants ont
        // un defaut : trois lignes distinctes suffisent.
        return [
            new $of($counter++),
            new $of($counter++),
            new $of($counter++),
        ];
    }
}
