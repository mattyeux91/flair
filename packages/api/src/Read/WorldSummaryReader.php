<?php

declare(strict_types=1);

namespace Flair\Api\Read;

use Flair\Api\Read\View\ClubListItemView;
use Flair\Api\Read\View\WorldSummaryView;
use Flair\Kernel\Core\Ecs\WorldState;
use Flair\Kernel\Football\Components\Club;
use Flair\Kernel\Football\Components\Competition;
use Flair\Kernel\Football\Components\Contract;
use Flair\Kernel\Football\Components\Finances;
use Flair\Kernel\Football\Components\PlayerPotentials;
use Flair\Kernel\Football\Components\SquadMembership;
use Flair\Kernel\Football\Singletons\MarketInflation;
use Flair\Kernel\Football\Singletons\MonetaryMass;

/**
 * Un monde vu de haut : ou il en est, son classement, ses clubs, et l'etat de
 * sa monnaie.
 *
 * L'effectif total se compte sur `PlayerPotentials` et non sur `Person` : un
 * retraite garde son `Person` (`RetirementSystem::removes()` ne retire que les
 * competences et le potentiel), donc compter les `Person` compterait les morts
 * avec les vivants. Mesure du lot 0 sur le monde de reference a dix ans : 732
 * `Person` pour 355 joueurs.
 */
final readonly class WorldSummaryReader
{
    public function __construct(private StandingsReader $standings = new StandingsReader())
    {
    }

    public function read(LoadedWorld $world): WorldSummaryView
    {
        $state = $world->state;
        $mass = $state->singleton(MonetaryMass::class) ?? new MonetaryMass();
        $inflation = $state->singleton(MarketInflation::class) ?? new MarketInflation();

        return new WorldSummaryView(
            id: $world->record->id,
            tick: $world->tick,
            season: $world->season(),
            dayOfYear: $world->dayOfYear(),
            seed: $world->record->seed,
            kernelVersion: $world->record->kernelVersion,
            rulesetVersion: $world->record->rulesetVersion,
            competitionName: $this->competitionName($state),
            standings: $this->standings->read($world),
            clubs: $this->clubs($state),
            playerCount: count($state->components(PlayerPotentials::class)->entities()),
            contractedPlayerCount: count($state->components(Contract::class)->entities()),
            monetaryInjectionsCents: $mass->totalInjectionsCents,
            monetarySinksCents: $mass->totalSinksCents,
            inflationIndex: $inflation->index,
            inflationAnnualRate: $inflation->annualRate,
        );
    }

    /** @return list<ClubListItemView> */
    private function clubs(WorldState $state): array
    {
        $sizes = [];
        foreach ($state->components(SquadMembership::class)->entities() as $playerId) {
            $clubId = $state->components(SquadMembership::class)->get($playerId)?->clubId;

            if ($clubId !== null) {
                $sizes[$clubId] = ($sizes[$clubId] ?? 0) + 1;
            }
        }

        $clubs = [];
        foreach ($state->components(Club::class)->entities() as $clubId) {
            $club = $state->components(Club::class)->get($clubId);

            if ($club !== null) {
                $clubs[] = new ClubListItemView(
                    id: $clubId,
                    name: $club->name,
                    squadSize: $sizes[$clubId] ?? 0,
                    balanceCents: $state->components(Finances::class)->get($clubId)->balanceCents ?? 0,
                );
            }
        }

        return $clubs;
    }

    /**
     * Le monde n'a qu'une competition aujourd'hui - meme limite deja
     * documentee par `Football\CalendarSystem` et `Football\FinanceSystem`, a
     * revisiter quand `CompetitionMembership` existera. On prend donc la
     * premiere, sans pretendre qu'il n'y en aura jamais d'autre.
     */
    private function competitionName(WorldState $state): ?string
    {
        foreach ($state->components(Competition::class)->entities() as $competitionId) {
            return $state->components(Competition::class)->get($competitionId)?->name;
        }

        return null;
    }
}
