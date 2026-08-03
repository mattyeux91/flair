<?php

declare(strict_types=1);

namespace Flair\Kernel\Core\Pipeline;

/**
 * Les declarations d'un `System` (docs/13- §2), indexees pour un test O(1).
 *
 * Porte aussi `systemId` : le contexte n'a plus besoin de le recevoir a part,
 * puisque toute reponse a "ce systeme a-t-il le droit de ..." et le flux RNG
 * derive (docs/13- §4.1) partent du meme systeme.
 *
 * Construit une fois par systeme dans le constructeur de `Pipeline`, jamais a
 * chaque tick : indexer quatre listes 365 fois par an et par systeme serait
 * du gaspillage pur, les declarations ne changent pas.
 */
final readonly class SystemAccess
{
    /**
     * @param array<class-string, true> $reads
     * @param array<class-string, true> $writes
     * @param array<class-string, true> $creates
     * @param array<class-string, true> $removes
     */
    private function __construct(
        public string $systemId,
        private array $reads,
        private array $writes,
        private array $creates,
        private array $removes,
    ) {
    }

    public static function of(System $system): self
    {
        return new self(
            $system->id(),
            self::index($system->reads()),
            self::index($system->writes()),
            self::index($system->creates()),
            self::index($system->removes()),
        );
    }

    public function mayRead(string $componentType): bool
    {
        return isset($this->reads[$componentType]);
    }

    /** writes() ∪ creates() : les deux autorisent `set()`, sur des entites differentes. */
    public function maySet(string $componentType): bool
    {
        return isset($this->writes[$componentType]) || isset($this->creates[$componentType]);
    }

    public function mayRemove(string $componentType): bool
    {
        return isset($this->removes[$componentType]);
    }

    /**
     * Declare en creates() mais pas en writes() : `set()` n'est alors permis
     * que sur une entite creee par ce systeme dans ce tick (CreatedEntities).
     * C'est exactement la condition qui rend l'exclusion de creates() du
     * controle de dependance inversee legitime (Football\PipelineInvariantsTest).
     */
    public function requiresCreatedEntity(string $componentType): bool
    {
        return isset($this->creates[$componentType]) && !isset($this->writes[$componentType]);
    }

    /**
     * @param list<class-string> $componentTypes
     * @return array<class-string, true>
     */
    private static function index(array $componentTypes): array
    {
        $indexed = [];

        foreach ($componentTypes as $componentType) {
            $indexed[$componentType] = true;
        }

        return $indexed;
    }
}
