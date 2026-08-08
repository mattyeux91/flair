<?php

declare(strict_types=1);

namespace Flair\Api\Read\History;

use Flair\Kernel\Core\Messaging\DomainEvent;
use Flair\Kernel\Football\Components\StandingsEntry;
use Flair\Kernel\Football\Events\ClubInvestedInFacilities;
use Flair\Kernel\Football\Events\ContractExpired;
use Flair\Kernel\Football\Events\ContractSigned;
use Flair\Kernel\Football\Events\MatchPlayed;
use Flair\Kernel\Football\Events\PlayerRetired;
use Flair\Kernel\Football\Events\SeasonConcluded;
use Flair\Kernel\Football\Events\SeasonEnded;
use Flair\Kernel\Football\Events\SeasonStarted;
use Flair\Kernel\Football\Events\TransferAgreed;
use Flair\Kernel\Football\Events\TransferCounterDemanded;
use Flair\Kernel\Football\Events\TransferNegotiationBroken;
use Flair\Kernel\Football\Events\TransferNegotiationOpened;
use Flair\Kernel\Football\Events\FixtureKickoff;
use Flair\Kernel\Football\Events\YouthPlayerPromoted;

/**
 * Quels clubs un Fait concerne, et a quel titre. **Le seul endroit du projet
 * qui le sait.**
 *
 * ## Pourquoi ca doit etre declare quelque part
 *
 * Un club n'a pas de cle unique dans les payloads : il apparait sous six noms
 * differents selon le type de Fait - `clubId`, `previousClubId`,
 * `homeClubId`/`awayClubId`, `buyerClubId`/`sellerClubId` - et `SeasonConcluded`
 * l'encode carrement par sa **position** dans un tableau. « L'histoire du club
 * X » n'est donc pas une requete, c'est une union de cas.
 *
 * On travaille sur des objets **rehydrates** (`Host\Store\EventStore::between()`),
 * pas sur des tableaux : un `match` sur classe avec acces typé, que PHPStan
 * verifie au niveau max. Un `$payload['homeClubId'] ?? null` compilerait aussi
 * bien avec une faute de frappe.
 *
 * ## Ce qui rend l'oubli impossible
 *
 * `Tests\Architecture\EveryFactIsPlacedOrExcludedTest` parcourt
 * `Football\FootballTypes::registry()->events` et exige que **chaque** type
 * soit traite ici ou inscrit dans `self::NOT_ABOUT_A_CLUB`. Sans lui, le
 * prochain Fait ajoute au noyau disparaitrait en silence de l'histoire de tous
 * les clubs - exactement le mode de panne que `SnapshotConformanceTest` exclut
 * cote serialisation, et dont ce projet a deja paye deux fois le prix.
 */
final class ClubMentions
{
    /**
     * Les Faits qui ne nomment aucun club, avec leur raison. Y figurer est une
     * **decision**, pas un oubli : le test d'exhaustivite ne fait pas la
     * difference entre les deux, mais la relecture si.
     *
     * @var array<class-string, string>
     */
    public const array NOT_ABOUT_A_CLUB = [
        // Ne porte que `competitionId`. Une saison qui demarre appartient a la
        // competition, pas a un club en particulier.
        SeasonStarted::class => 'ne nomme qu\'une competition',

        // Programmes par le Scheduler, jamais emis : ils n'existent pas dans
        // l'event log. Enregistres au TypeRegistry parce que le Scheduler les
        // serialise dans le snapshot, ce qui est un autre besoin.
        SeasonEnded::class => 'passe par le Scheduler, jamais journalise',
        FixtureKickoff::class => 'passe par le Scheduler, jamais journalise',
    ];

    /** @return list<ClubMention> */
    public function of(DomainEvent $event): array
    {
        return match (true) {
            $event instanceof MatchPlayed => [
                new ClubMention($event->homeClubId, ClubRole::Home),
                new ClubMention($event->awayClubId, ClubRole::Away),
            ],

            // Un seul Fait, deux histoires : le club qui signe gagne un joueur,
            // celui qu'il quitte en perd un. C'est tout l'interet du role.
            $event instanceof ContractSigned => $event->previousClubId === null
                ? [new ClubMention($event->clubId, ClubRole::Subject)]
                : [
                    new ClubMention($event->clubId, ClubRole::Subject),
                    new ClubMention($event->previousClubId, ClubRole::Previous),
                ],

            $event instanceof ContractExpired,
            $event instanceof YouthPlayerPromoted,
            $event instanceof ClubInvestedInFacilities => [
                new ClubMention($event->clubId, ClubRole::Subject),
            ],

            // `null` pour un joueur qui raccroche sans club : ce n'est pas une
            // donnee manquante, c'est qu'aucun club ne le perd. Une liste vide
            // est donc la bonne reponse ici, pas une exclusion.
            $event instanceof PlayerRetired => $event->clubId === null
                ? []
                : [new ClubMention($event->clubId, ClubRole::Subject)],

            $event instanceof TransferAgreed,
            $event instanceof TransferNegotiationOpened,
            $event instanceof TransferNegotiationBroken,
            $event instanceof TransferCounterDemanded => [
                new ClubMention($event->buyerClubId, ClubRole::Buyer),
                new ClubMention($event->sellerClubId, ClubRole::Seller),
            ],

            // Tous les clubs du classement final, d'un coup. Le **rang** ne se
            // lit que par la position : c'est la seule donnee du monde encodee
            // positionnellement, et la seule raison pour laquelle ce cas ne
            // ressemble a aucun autre.
            $event instanceof SeasonConcluded => array_map(
                static fn (int $clubId): ClubMention => new ClubMention($clubId, ClubRole::Ranked),
                $event->ranking(),
            ),

            default => [],
        };
    }

    /** Le rang d'un club dans une saison conclue, 1-indexe. `null` s'il n'y figure pas. */
    public function rankIn(SeasonConcluded $event, int $clubId): ?int
    {
        $position = array_search($clubId, $event->ranking(), strict: true);

        return $position === false ? null : $position + 1;
    }

    /** Sa ligne du classement final, avec ses points et son bilan. `null` s'il n'y figure pas. */
    public function lineIn(SeasonConcluded $event, int $clubId): ?StandingsEntry
    {
        foreach ($event->finalTable as $entry) {
            if ($entry->clubId === $clubId) {
                return $entry;
            }
        }

        return null;
    }

    public function concerns(DomainEvent $event, int $clubId): bool
    {
        foreach ($this->of($event) as $mention) {
            if ($mention->clubId === $clubId) {
                return true;
            }
        }

        return false;
    }

    /** Le role sous lequel un club est nomme, `null` s'il ne l'est pas. */
    public function roleOf(DomainEvent $event, int $clubId): ?ClubRole
    {
        foreach ($this->of($event) as $mention) {
            if ($mention->clubId === $clubId) {
                return $mention->role;
            }
        }

        return null;
    }
}
