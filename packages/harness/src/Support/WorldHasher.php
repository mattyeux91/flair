<?php

declare(strict_types=1);

namespace Flair\Harness\Support;

use Flair\Kernel\Core\Ecs\WorldState;
use Flair\Kernel\Core\Messaging\DomainEvent;
use Flair\Kernel\Football\Components\Club;
use Flair\Kernel\Football\Components\Competition;
use Flair\Kernel\Football\Components\Contract;
use Flair\Kernel\Football\Components\Facilities;
use Flair\Kernel\Football\Components\Finances;
use Flair\Kernel\Football\Components\Fixture;
use Flair\Kernel\Football\Components\MatchResult;
use Flair\Kernel\Football\Components\Person;
use Flair\Kernel\Football\Components\PlayerMentalSkills;
use Flair\Kernel\Football\Components\PlayerPhysicalSkills;
use Flair\Kernel\Football\Components\PlayerPotentials;
use Flair\Kernel\Football\Components\PlayerTechnicalSkills;
use Flair\Kernel\Football\Components\SeasonIncome;
use Flair\Kernel\Football\Components\SquadMembership;
use Flair\Kernel\Football\Components\Standings;
use Flair\Kernel\Football\Components\TrainingEffect;
use Flair\Kernel\Football\Singletons\MonetaryMass;

/**
 * Hash deterministe (meme machine, meme version de PHP - docs/13- §4.8, pas
 * une forme canonique cross-machine) d'un WorldState football et d'une
 * sequence d'evenements, pour le test de determinisme d'un run complet exige
 * par le critere de sortie Phase 1 (docs/15- §4).
 *
 * WorldState n'expose volontairement aucune methode "toutes les entites du
 * monde" (cf. son docblock) : ce hasher enumere donc explicitement les types
 * de composants football connus, meme esprit que WorldInspector (verite
 * listee, pas generique). `StandingsEntry` n'y figure pas : elle n'est
 * jamais stockee comme composant a part entiere, seulement imbriquee dans
 * Standings::$entries.
 *
 * `MonetaryMass` (Phase 2) est le premier singleton du domaine football :
 * hashe separement de `KNOWN_COMPONENT_TYPES`, qui n'enumere que des types
 * portes par un `ComponentStore` (`$world->singleton()`, pas
 * `$world->components()`). Sans cet ajout explicite, une regression de
 * determinisme dans le bookkeeping du singleton passerait ce test sans
 * etre detectee.
 *
 * Chaque composant liste est un DTO `readonly` a proprietes publiques
 * (verifie classe par classe) - json_encode() serialise ses proprietes
 * publiques dans l'ordre declare, donc "meme run -> meme serialisation".
 * L'ordre d'iteration des entites (ComponentStore::entities(), deja trie par
 * EntityId croissant) garantit que la meme sequence de composants produit
 * toujours la meme chaine, jamais l'ordre d'insertion.
 */
final class WorldHasher
{
    /** @var list<class-string> */
    private const array KNOWN_COMPONENT_TYPES = [
        Person::class,
        PlayerPhysicalSkills::class,
        PlayerTechnicalSkills::class,
        PlayerMentalSkills::class,
        PlayerPotentials::class,
        TrainingEffect::class,
        SquadMembership::class,
        Club::class,
        Facilities::class,
        Finances::class,
        SeasonIncome::class,
        Contract::class,
        Competition::class,
        Standings::class,
        Fixture::class,
        MatchResult::class,
    ];

    public static function hashWorld(WorldState $world): string
    {
        $lines = [];
        foreach (self::KNOWN_COMPONENT_TYPES as $type) {
            $store = $world->components($type);
            foreach ($store->entities() as $entityId) {
                $lines[] = $type . '#' . $entityId . '=' . json_encode($store->get($entityId));
            }
        }

        $mass = $world->singleton(MonetaryMass::class);
        if ($mass !== null) {
            $lines[] = MonetaryMass::class . '=' . json_encode($mass);
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
