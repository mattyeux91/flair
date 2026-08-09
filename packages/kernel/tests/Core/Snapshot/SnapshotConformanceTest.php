<?php

declare(strict_types=1);

namespace Flair\Kernel\Tests\Core\Snapshot;

use Flair\Kernel\Core\Snapshot\SnapshotContract;
use Flair\Kernel\Football\FootballTypes;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

/**
 * Le test qui rend l'oubli impossible.
 *
 * Un type du domaine absent du registre, ou dont la forme sort du contrat
 * d'encodage, n'est pas une gene theorique : c'est de l'etat de monde **perdu
 * au redemarrage, en silence**. Le seul moment acceptable pour s'en
 * apercevoir est la CI, pas la reprise d'un monde vivant.
 *
 * D'ou un test qui balaye le disque plutot qu'une liste ecrite a la main -
 * une liste a la main aurait exactement le defaut qu'on cherche a corriger.
 * Lire un repertoire est interdit *dans le noyau* (docs/11- §1), pas dans un
 * test du noyau.
 *
 * La regle « enregistre **ou atteignable** » est ce qui laisse leur place aux
 * types de valeur : `Position` n'est jamais un composant, `StandingsEntry`
 * n'existe qu'imbriquee dans `Standings::$entries`. Ni l'une ni l'autre ne
 * merite une cle de registre - mais toutes deux doivent etre serialisables,
 * et le sont via le type qui les porte.
 */
final class SnapshotConformanceTest extends TestCase
{
    private const array DOMAIN_DIRECTORIES = [
        'Components' => 'Flair\\Kernel\\Football\\Components\\',
        'Singletons' => 'Flair\\Kernel\\Football\\Singletons\\',
        'Events' => 'Flair\\Kernel\\Football\\Events\\',
    ];

    /**
     * Ce qui doit rester **hors** du registre. Le balayage ci-dessus ne peut
     * rien dire d'un repertoire qu'il ne lit pas : sans cette seconde liste,
     * `Requests/` serait un angle mort, et un `DecisionRequest` enregistre par
     * reflexe passerait sans bruit.
     */
    private const array TRANSIENT_DIRECTORIES = [
        'Requests' => 'Flair\\Kernel\\Football\\Requests\\',
    ];

    public function testEveryDomainTypeIsRegisteredOrReachableFromARegisteredOne(): void
    {
        $reachable = $this->reachableTypes();

        foreach ($this->domainTypes() as $class) {
            self::assertArrayHasKey(
                $class,
                $reachable,
                "{$class} n'est ni enregistre dans FootballTypes ni atteignable depuis un type enregistre : "
                . 'son etat serait perdu au redemarrage.',
            );
        }
    }

    /**
     * **La garde inverse**, et elle vaut autant que la premiere. Une cle de
     * registre est une promesse de relecture a dix ans ; un `DecisionRequest`
     * n'est jamais ecrit nulle part (docs/16- §1). L'enregistrer ne casserait
     * rien tout de suite - c'est bien le probleme : ca installerait dans le
     * registre un type que personne ne relira jamais, et le prochain lecteur
     * en deduirait que les questions sont journalisees.
     */
    public function testNoTransientMessageIsRegistered(): void
    {
        $registry = FootballTypes::registry();
        $registered = [
            ...array_values($registry->components),
            ...array_values($registry->singletons),
            ...array_values($registry->events),
        ];

        $transient = $this->typesIn(self::TRANSIENT_DIRECTORIES);
        self::assertNotSame([], $transient, 'Aucun type transitoire trouve : le balayage ne prouverait rien.');

        foreach ($transient as $class) {
            self::assertNotContains(
                $class,
                $registered,
                "{$class} est un DecisionRequest : il ne doit pas avoir de cle de registre, "
                . 'rien ne l\'ecrit ni sur disque ni dans l\'event log.',
            );
        }
    }

    public function testEveryReachableTypeRespectsTheEncodingContract(): void
    {
        foreach (array_keys($this->reachableTypes()) as $class) {
            self::assertSame(
                [],
                SnapshotContract::violations($class),
                "{$class} sort du contrat d'encodage : " . implode(' ; ', SnapshotContract::violations($class)),
            );
        }
    }

    public function testRegistryKeysAreStableLookingAndUnique(): void
    {
        $registry = FootballTypes::registry();
        $keys = [
            ...array_keys($registry->components),
            ...array_keys($registry->singletons),
            ...array_keys($registry->events),
        ];

        self::assertSame(count($keys), count(array_unique($keys)));

        foreach ($keys as $key) {
            self::assertMatchesRegularExpression(
                '/^[a-z][a-z0-9_.]*$/',
                $key,
                "La cle \"{$key}\" doit rester ecrivable telle quelle dans une colonne d'event store.",
            );
        }

        foreach (array_keys($registry->events) as $key) {
            self::assertStringStartsWith('football.event.', $key);
        }
    }

    public function testRegistryResolvesBothWays(): void
    {
        $registry = FootballTypes::registry();

        foreach ($registry->components as $key => $class) {
            self::assertSame($class, $registry->classFor($key));
            self::assertSame($key, $registry->keyFor($class));
        }
    }

    /**
     * Tout ce que le registre nomme, plus tout ce qu'on atteint depuis lui en
     * suivant les types des parametres de constructeur et les
     * #[SnapshotArrayOf].
     *
     * @return array<class-string, true>
     */
    private function reachableTypes(): array
    {
        $registry = FootballTypes::registry();
        $queue = [...$registry->componentClasses(), ...$registry->singletonClasses(), ...array_values($registry->events)];
        $seen = [];

        while ($queue !== []) {
            $class = array_pop($queue);
            if (isset($seen[$class])) {
                continue;
            }

            $seen[$class] = true;

            $constructor = (new ReflectionClass($class))->getConstructor();
            if ($constructor === null) {
                continue;
            }

            foreach ($constructor->getParameters() as $parameter) {
                $type = $parameter->getType();
                if (!$type instanceof ReflectionNamedType) {
                    continue;
                }

                $candidate = $type->getName() === 'array'
                    ? SnapshotContract::elementType($parameter)
                    : $type->getName();

                if ($candidate !== null && (class_exists($candidate) || enum_exists($candidate))) {
                    $queue[] = $candidate;
                }
            }
        }

        return $seen;
    }

    /** @return list<class-string> */
    private function domainTypes(): array
    {
        return $this->typesIn(self::DOMAIN_DIRECTORIES);
    }

    /**
     * @param array<string, string> $directories repertoire -> namespace
     * @return list<class-string>
     */
    private function typesIn(array $directories): array
    {
        $types = [];

        foreach ($directories as $directory => $namespace) {
            $files = glob(__DIR__ . "/../../../src/Football/{$directory}/*.php");
            self::assertIsArray($files);
            self::assertNotEmpty($files, "Aucun type trouve dans src/Football/{$directory}.");

            foreach ($files as $file) {
                $class = $namespace . basename($file, '.php');
                self::assertTrue(
                    class_exists($class) || enum_exists($class) || interface_exists($class),
                    "{$class} introuvable depuis son fichier.",
                );

                if (interface_exists($class)) {
                    continue;
                }

                /** @var class-string $class */
                $types[] = $class;
            }
        }

        return $types;
    }
}
