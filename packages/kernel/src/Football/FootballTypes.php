<?php

declare(strict_types=1);

namespace Flair\Kernel\Football;

use Flair\Kernel\Core\Snapshot\TypeRegistry;
use Flair\Kernel\Football\Components\BoardPatience;
use Flair\Kernel\Football\Components\Club;
use Flair\Kernel\Football\Components\Competition;
use Flair\Kernel\Football\Components\Contract;
use Flair\Kernel\Football\Components\Employment;
use Flair\Kernel\Football\Components\Facilities;
use Flair\Kernel\Football\Components\Finances;
use Flair\Kernel\Football\Components\Fixture;
use Flair\Kernel\Football\Components\MatchResult;
use Flair\Kernel\Football\Components\Negotiation;
use Flair\Kernel\Football\Components\Person;
use Flair\Kernel\Football\Components\PlayerMentalSkills;
use Flair\Kernel\Football\Components\PlayerPhysicalSkills;
use Flair\Kernel\Football\Components\PlayerPotentials;
use Flair\Kernel\Football\Components\PlayerTechnicalSkills;
use Flair\Kernel\Football\Components\Scout;
use Flair\Kernel\Football\Components\SeasonIncome;
use Flair\Kernel\Football\Components\SquadMembership;
use Flair\Kernel\Football\Components\Standings;
use Flair\Kernel\Football\Components\TrainingEffect;
use Flair\Kernel\Football\Events\ClubInvestedInFacilities;
use Flair\Kernel\Football\Events\ContractExpired;
use Flair\Kernel\Football\Events\ContractSigned;
use Flair\Kernel\Football\Events\FixtureKickoff;
use Flair\Kernel\Football\Events\MatchPlayed;
use Flair\Kernel\Football\Events\PlayerRetired;
use Flair\Kernel\Football\Events\SeasonConcluded;
use Flair\Kernel\Football\Events\SeasonEnded;
use Flair\Kernel\Football\Events\SeasonStarted;
use Flair\Kernel\Football\Events\TransferAgreed;
use Flair\Kernel\Football\Events\TransferCounterDemanded;
use Flair\Kernel\Football\Events\TransferNegotiationBroken;
use Flair\Kernel\Football\Events\TransferNegotiationOpened;
use Flair\Kernel\Football\Events\YouthPlayerPromoted;

/**
 * Le registre des types persistables du domaine football, declare **une seule
 * fois** - meme role que FootballPipeline pour l'ordre des systemes : le
 * noyau generique ne connait pas le football, c'est le domaine qui se
 * declare.
 *
 * Deux consommateurs, aujourd'hui et demain : le codec de snapshot
 * (Core\Snapshot\SnapshotCodec) et la colonne `type` de l'event store
 * (docs/13- §5).
 *
 * ## Regles a tenir en ajoutant un type
 *
 * - **Une cle ne se renomme jamais.** Elle est ecrite dans des snapshots et
 *   des lignes d'event log deja sur disque ; le FQCN, lui, est libre de
 *   bouger. C'est tout l'interet de l'indirection.
 * - **Les evenements sont prefixes `football.event.`** pour qu'un futur
 *   composant homonyme n'oblige jamais a renommer une cle deja ecrite.
 * - **N'y figurent que les types de premier niveau** : ce qui vit dans un
 *   ComponentStore, ce qui est un singleton, ce qui est un Fait. `Position`
 *   et `StandingsEntry` n'y sont pas - la premiere est un type de valeur
 *   (`PlayerPotentials::$archetype`), la seconde n'existe qu'imbriquee dans
 *   `Standings::$entries`. Les deux sont neanmoins couvertes : le test de
 *   conformite exige que tout type du domaine soit enregistre **ou**
 *   atteignable depuis un type enregistre.
 * - Oublier un type ici est impossible sans casser
 *   Tests\Core\Snapshot\SnapshotConformanceTest.
 */
final class FootballTypes
{
    public static function registry(): TypeRegistry
    {
        return new TypeRegistry(
            components: [
                'football.board_patience' => BoardPatience::class,
                'football.club' => Club::class,
                'football.competition' => Competition::class,
                'football.contract' => Contract::class,
                'football.employment' => Employment::class,
                'football.facilities' => Facilities::class,
                'football.finances' => Finances::class,
                'football.fixture' => Fixture::class,
                'football.match_result' => MatchResult::class,
                'football.negotiation' => Negotiation::class,
                'football.person' => Person::class,
                'football.player_mental_skills' => PlayerMentalSkills::class,
                'football.player_physical_skills' => PlayerPhysicalSkills::class,
                'football.player_potentials' => PlayerPotentials::class,
                'football.player_technical_skills' => PlayerTechnicalSkills::class,
                'football.scout' => Scout::class,
                'football.season_income' => SeasonIncome::class,
                'football.squad_membership' => SquadMembership::class,
                'football.standings' => Standings::class,
                'football.training_effect' => TrainingEffect::class,
            ],
            singletons: [
                'football.market_inflation' => Singletons\MarketInflation::class,
                'football.monetary_mass' => Singletons\MonetaryMass::class,
            ],
            events: [
                'football.event.club_invested_in_facilities' => ClubInvestedInFacilities::class,
                'football.event.contract_expired' => ContractExpired::class,
                'football.event.contract_signed' => ContractSigned::class,
                'football.event.fixture_kickoff' => FixtureKickoff::class,
                'football.event.match_played' => MatchPlayed::class,
                'football.event.player_retired' => PlayerRetired::class,
                'football.event.season_concluded' => SeasonConcluded::class,
                'football.event.season_ended' => SeasonEnded::class,
                'football.event.season_started' => SeasonStarted::class,
                'football.event.transfer_agreed' => TransferAgreed::class,
                'football.event.transfer_counter_demanded' => TransferCounterDemanded::class,
                'football.event.transfer_negotiation_broken' => TransferNegotiationBroken::class,
                'football.event.transfer_negotiation_opened' => TransferNegotiationOpened::class,
                'football.event.youth_player_promoted' => YouthPlayerPromoted::class,
            ],
        );
    }
}
