<?php

declare(strict_types=1);

namespace Flair\Harness\Support;

use Flair\Kernel\Core\Ecs\WorldState;
use Flair\Kernel\Core\Messaging\DomainEvent;
use Flair\Kernel\Football\FootballTypes;

/**
 * Hash deterministe (meme machine, meme version de PHP - docs/13- §4.8, pas
 * une forme canonique cross-machine) d'un WorldState football et d'une
 * sequence d'evenements, pour le test de determinisme d'un run complet exige
 * par le critere de sortie Phase 1 (docs/15- §4).
 *
 * **La liste des types hashes est derivee de `Football\FootballTypes`**, le
 * registre de persistance, et non plus tenue a la main ici. Elle l'etait
 * jusqu'au lot snapshot, et il y manquait `BoardPatience`, `Negotiation` et
 * le singleton `MarketInflation` : tout le marche des transferts etait hors
 * du test de determinisme depuis le lot 3, sans que rien ne le signale. Deux
 * listes du meme monde finissent toujours par diverger ; il n'y en a plus
 * qu'une, et le test de conformite du noyau garantit qu'elle est exhaustive.
 *
 * Chaque composant est un DTO `readonly` a proprietes publiques -
 * json_encode() serialise ses proprietes publiques dans l'ordre declare, donc
 * "meme run -> meme serialisation". L'ordre d'iteration des entites
 * (ComponentStore::entities(), deja trie par EntityId croissant) garantit que
 * la meme sequence de composants produit toujours la meme chaine, jamais
 * l'ordre d'insertion.
 *
 * Ce n'est volontairement pas le codec de snapshot : celui-ci valide, leve, et
 * porte une enveloppe. Ici on veut une empreinte, la plus directe possible.
 * Les deux partagent ce qui doit l'etre - la liste des types - et rien de plus.
 */
final class WorldHasher
{
    public static function hashWorld(WorldState $world): string
    {
        $registry = FootballTypes::registry();
        $lines = [];

        foreach ($registry->componentClasses() as $type) {
            $store = $world->components($type);
            foreach ($store->entities() as $entityId) {
                $lines[] = $type . '#' . $entityId . '=' . json_encode($store->get($entityId));
            }
        }

        foreach ($registry->singletonClasses() as $type) {
            $singleton = $world->singleton($type);
            if ($singleton !== null) {
                $lines[] = $type . '=' . json_encode($singleton);
            }
        }

        return hash('sha256', implode("\n", $lines));
    }

    /** @param list<DomainEvent> $events */
    public static function hashEventSequence(array $events): string
    {
        $lines = [];
        foreach ($events as $event) {
            $lines[] = $event::class . '=' . json_encode($event);
        }

        return hash('sha256', implode("\n", $lines));
    }
}
