<?php

declare(strict_types=1);

namespace Flair\Kernel\Core\Snapshot;

/**
 * Table de correspondance entre une **cle stable** et un nom de classe, pour
 * les trois familles de types qui traversent la persistance : composants,
 * singletons, evenements.
 *
 * Pourquoi une cle plutot qu'un FQCN dans le payload : renommer une classe ou
 * deplacer un namespace ne doit pas invalider les snapshots deja ecrits ni les
 * lignes deja journalisees. Le FQCN est une donnee de code, la cle est une
 * donnee de format.
 *
 * Deux consommateurs reels, jamais un seul (docs/13- §2, critere d'extraction) :
 * le codec de snapshot (Core\Snapshot\SnapshotCodec) et la colonne `type` de
 * l'event store (docs/13- §5). C'est ce qui justifie de l'ecrire maintenant
 * plutot que par anticipation.
 *
 * Les cles sont uniques **toutes familles confondues** : un evenement ne peut
 * pas porter la meme cle qu'un composant, sans quoi `classFor()` serait
 * ambigu. Les evenements sont prefixes (`football.event.*`) pour que
 * l'apparition future d'un composant homonyme n'oblige jamais a renommer une
 * cle deja ecrite en base.
 *
 * Le registre est **passe en donnee** (DIP, docs/11- §8), jamais lu depuis un
 * singleton statique : le noyau ne connait pas le domaine football, c'est
 * `Football\FootballTypes` qui declare le sien, une seule fois, comme
 * `Football\FootballPipeline` declare le pipeline une seule fois.
 */
final readonly class TypeRegistry
{
    /** @var array<string, class-string> cle -> classe, toutes familles */
    private array $byKey;

    /** @var array<class-string, string> classe -> cle, toutes familles */
    private array $byClass;

    /**
     * @param array<string, class-string> $components
     * @param array<string, class-string> $singletons
     * @param array<string, class-string> $events
     */
    public function __construct(
        public array $components = [],
        public array $singletons = [],
        public array $events = [],
    ) {
        $byKey = [];
        $byClass = [];

        foreach ([$components, $singletons, $events] as $family) {
            foreach ($family as $key => $class) {
                if (isset($byKey[$key])) {
                    throw SnapshotFormatException::duplicateKey($key);
                }

                $byKey[$key] = $class;
                $byClass[$class] = $key;
            }
        }

        $this->byKey = $byKey;
        $this->byClass = $byClass;
    }

    /** @return class-string */
    public function classFor(string $key): string
    {
        return $this->byKey[$key] ?? throw SnapshotFormatException::unknownKey($key);
    }

    /** @param class-string|object $subject */
    public function keyFor(string|object $subject): string
    {
        $class = is_object($subject) ? $subject::class : $subject;

        return $this->byClass[$class] ?? throw SnapshotFormatException::unregisteredClass($class);
    }

    public function knows(string $class): bool
    {
        return isset($this->byClass[$class]);
    }

    /**
     * Classes de composants, triees par cle : c'est ce tri qui rend deux
     * snapshots du meme monde identiques octet pour octet, jamais l'ordre de
     * declaration ni celui d'apparition des stores.
     *
     * @return list<class-string>
     */
    public function componentClasses(): array
    {
        return self::sortedByKey($this->components);
    }

    /** @return list<class-string> */
    public function singletonClasses(): array
    {
        return self::sortedByKey($this->singletons);
    }

    /**
     * @param array<string, class-string> $family
     * @return list<class-string>
     */
    private static function sortedByKey(array $family): array
    {
        ksort($family);

        return array_values($family);
    }
}
