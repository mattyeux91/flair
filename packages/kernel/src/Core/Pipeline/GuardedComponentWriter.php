<?php

declare(strict_types=1);

namespace Flair\Kernel\Core\Pipeline;

use Flair\Kernel\Core\Ecs\ComponentStore;

/**
 * Handle d'ecriture d'une colonne de composants, borne par les declarations
 * du systeme qui l'a demande (docs/13- §2).
 *
 * N'expose **ni `get()` ni `entities()`**, volontairement : lire passe
 * obligatoirement par `SystemContext::read()`, sinon un systeme pourrait lire
 * a travers son handle d'ecriture et `reads()` redeviendrait decoratif. Or
 * `reads()` n'est pas cosmetique - c'est de lui que se deduisent les aretes
 * du graphe de dependances entre systemes.
 *
 * **N'est pas le pendant de `Ecs\ComponentReader`**, malgre la symetrie des
 * appels `read()`/`write()` qui l'obtiennent. Les deux objets n'ont pas la
 * meme nature, et c'est ce qui explique qu'ils ne vivent pas au meme endroit :
 *
 * - `ComponentReader` est une **capacite de l'ECS** - "ce qu'une colonne sait
 *   faire en lecture". Elle existerait sans pipeline, et `ComponentStore`
 *   l'implemente.
 * - Cette classe-ci est un **garde** - "ce que *ce systeme-la* a le droit de
 *   faire dans *ce tick*". Elle n'a de sens que pendant un tick, et depend de
 *   `SystemAccess`, `CreatedEntities` et `UndeclaredAccessException`, trois
 *   concepts du pipeline. La placer dans `Ecs` y ferait importer `Pipeline`
 *   et inverserait la seule stratification du noyau (`Pipeline` -> `Ecs`,
 *   jamais l'inverse).
 *
 * D'ou le prefixe : il dit que l'objet est un garde, pas la moitie ecriture
 * d'une paire.
 *
 * @template T
 */
final readonly class GuardedComponentWriter
{
    /**
     * @param ComponentStore<T> $store
     * @param class-string<T> $componentType
     */
    public function __construct(
        private ComponentStore $store,
        private SystemAccess $access,
        private CreatedEntities $created,
        private string $componentType,
    ) {
    }

    /** @param T $component */
    public function set(int $entity, mixed $component): void
    {
        if (!$this->access->maySet($this->componentType)) {
            throw UndeclaredAccessException::set($this->access->systemId, $this->componentType);
        }

        if ($this->access->requiresCreatedEntity($this->componentType) && !$this->created->contains($entity)) {
            throw UndeclaredAccessException::setOnForeignEntity(
                $this->access->systemId,
                $this->componentType,
                $entity,
            );
        }

        $this->store->set($entity, $component);
    }

    public function remove(int $entity): void
    {
        if (!$this->access->mayRemove($this->componentType)) {
            throw UndeclaredAccessException::remove($this->access->systemId, $this->componentType);
        }

        $this->store->remove($entity);
    }
}
