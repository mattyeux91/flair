<?php

declare(strict_types=1);

namespace Flair\Api\Read\History;

use Flair\Kernel\Core\Messaging\DomainEvent;
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

        // ⚠️ Dette, pas un choix de conception. `PlayerRetired` ne porte que
        // `playerId` et `ageYears` : impossible de savoir quel club perd ce
        // joueur. Reconstruire le club depuis les `ContractSigned` serait
        // **silencieusement faux**, les contrats du genesis n'etant pas dans
        // l'event log - un joueur au meme club depuis l'origine n'a aucun
        // evenement de signature. Correction : un champ de plus sur le Fait, ou
        // un genesis qui emet ses contrats. C'est ce qui prive le digest des
        // retraites (docs/14- §9).
        PlayerRetired::class => 'ne porte pas le club du joueur (dette connue)',

        // Ne porte que `negotiationId` et `playerId`. Joignable en theorie a
        // l'ouverture qui nomme les clubs, mais ce serait un etat a
        // reconstruire pour un evenement de faible valeur narrative - les
        // tours d'une negociation interessent le marche, pas l'histoire d'un
        // club, qui retient l'ouverture, l'accord et la rupture.
        TransferCounterDemanded::class => 'ne porte que la negociation (dette connue)',
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

            $event instanceof TransferAgreed,
            $event instanceof TransferNegotiationOpened,
            $event instanceof TransferNegotiationBroken => [
                new ClubMention($event->buyerClubId, ClubRole::Buyer),
                new ClubMention($event->sellerClubId, ClubRole::Seller),
            ],

            // Tous les clubs du classement final, d'un coup. Le **rang** ne se
            // lit que par la position : c'est la seule donnee du monde encodee
            // positionnellement, et la seule raison pour laquelle ce cas ne
            // ressemble a aucun autre.
            $event instanceof SeasonConcluded => array_map(
                static fn (int $clubId): ClubMention => new ClubMention($clubId, ClubRole::Ranked),
                $event->finalRanking,
            ),

            default => [],
        };
    }

    /** Le rang d'un club dans une saison conclue, 1-indexe. `null` s'il n'y figure pas. */
    public function rankIn(SeasonConcluded $event, int $clubId): ?int
    {
        $position = array_search($clubId, $event->finalRanking, strict: true);

        return $position === false ? null : $position + 1;
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
