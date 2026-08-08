<?php

declare(strict_types=1);

namespace Flair\Api\Read;

use Flair\Api\Read\View\ClubSheetView;
use Flair\Api\Read\View\ScoutView;
use Flair\Api\Read\View\SquadPlayerView;
use Flair\Api\Read\View\StandingsRowView;
use Flair\Kernel\Core\Ecs\WorldState;
use Flair\Kernel\Football\Components\BoardPatience;
use Flair\Kernel\Football\Components\Club;
use Flair\Kernel\Football\Components\Contract;
use Flair\Kernel\Football\Components\Employment;
use Flair\Kernel\Football\Components\Facilities;
use Flair\Kernel\Football\Components\Finances;
use Flair\Kernel\Football\Components\Person;
use Flair\Kernel\Football\Components\PlayerMentalSkills;
use Flair\Kernel\Football\Components\PlayerPhysicalSkills;
use Flair\Kernel\Football\Components\PlayerPotentials;
use Flair\Kernel\Football\Components\PlayerTechnicalSkills;
use Flair\Kernel\Football\Components\Position;
use Flair\Kernel\Football\Components\Scout;
use Flair\Kernel\Football\Components\SeasonIncome;
use Flair\Kernel\Football\Components\SquadMembership;
use Flair\Kernel\Football\Support\PositionModel;
use Flair\Kernel\Football\Support\WageModel;

/**
 * Construit la fiche d'un club depuis un monde decode.
 *
 * ## Lire l'ECS sans etre un systeme
 *
 * `WorldState::components()` est public et **generiquement type**
 * (`@template T of object`, `class-string<T>` en entree,
 * `ComponentStore<T>` en sortie) : `->components(Club::class)->get($id)` est
 * vu `?Club` par PHPStan, sans cast ni assertion. Rien a ajouter au noyau, et
 * pas de lecture de `mixed` a verifier comme le fait `Host\Store\Row` sur les
 * lignes du query builder.
 *
 * Cette classe n'est pas un `System` et n'a pas de `SystemContext` : elle ne
 * declare rien, ne peut rien ordonner, et n'ecrit jamais (docs/13- §2). Une
 * lecture n'a pas de place dans le pipeline.
 */
final readonly class ClubSheetReader
{
    public function __construct(private StandingsReader $standings = new StandingsReader())
    {
    }

    public function read(LoadedWorld $world, int $clubId): ?ClubSheetView
    {
        $state = $world->state;
        $club = $state->components(Club::class)->get($clubId);

        if ($club === null) {
            return null;
        }

        $squad = $this->squad($state, $world->tick, $clubId);
        $wageBill = 0;
        $squadSize = 0;

        foreach ($squad as $players) {
            foreach ($players as $player) {
                $wageBill += $player->wagePerWeekCents;
                $squadSize++;
            }
        }

        return new ClubSheetView(
            id: $clubId,
            name: $club->name,
            balanceCents: $state->components(Finances::class)->get($clubId)->balanceCents ?? 0,
            facilitiesQuality: $state->components(Facilities::class)->get($clubId)->quality ?? 0.0,
            seasonIncomeCents: $state->components(SeasonIncome::class)->get($clubId)->cents ?? 0,
            boardPatience: $state->components(BoardPatience::class)->get($clubId)->level ?? 0,
            scout: $this->scout($state, $clubId),
            standing: $this->standing($world, $clubId),
            squadByPosition: $squad,
            squadSize: $squadSize,
            wageBillPerWeekCents: $wageBill,
        );
    }

    /**
     * L'effectif groupe par poste derive, dans l'ordre du terrain, chaque
     * groupe trie par note decroissante.
     *
     * Les postes vides restent presents avec une liste vide : un club sans
     * gardien est l'information la plus interessante qu'une fiche puisse
     * porter, et une cle absente la ferait disparaitre.
     *
     * @return array<string, list<SquadPlayerView>>
     */
    private function squad(WorldState $state, int $tick, int $clubId): array
    {
        $byPosition = [];
        foreach (Position::cases() as $position) {
            $byPosition[$position->value] = [];
        }

        foreach ($state->components(SquadMembership::class)->entities() as $playerId) {
            if ($state->components(SquadMembership::class)->get($playerId)?->clubId !== $clubId) {
                continue;
            }

            $player = $this->player($state, $tick, $playerId);

            if ($player !== null) {
                $byPosition[$player->position][] = $player;
            }
        }

        foreach ($byPosition as $position => $players) {
            usort($players, static fn (SquadPlayerView $a, SquadPlayerView $b): int => $b->quality <=> $a->quality);
            $byPosition[$position] = $players;
        }

        return $byPosition;
    }

    private function player(WorldState $state, int $tick, int $playerId): ?SquadPlayerView
    {
        $physical = $state->components(PlayerPhysicalSkills::class)->get($playerId);
        $technical = $state->components(PlayerTechnicalSkills::class)->get($playerId);
        $mental = $state->components(PlayerMentalSkills::class)->get($playerId);
        $person = $state->components(Person::class)->get($playerId);
        $contract = $state->components(Contract::class)->get($playerId);

        // Un retraite garde son `Person` mais perd ses competences
        // (`RetirementSystem::removes()`) : sans elles il n'y a pas de joueur a
        // montrer, seulement un nom dans l'histoire du monde.
        if ($physical === null || $technical === null || $mental === null || $person === null) {
            return null;
        }

        $potentials = $state->components(PlayerPotentials::class)->get($playerId);

        return new SquadPlayerView(
            id: $playerId,
            name: $person->name,
            age: round(($tick - $person->birthDate->epochDay) / 365.0, 1),
            position: PositionModel::bestPosition($physical, $technical, $mental)->value,
            archetype: $potentials?->archetype->value ?? '?',
            quality: $this->qualityOf($physical, $technical, $mental),
            ceiling: $potentials->ceiling ?? 0,
            wagePerWeekCents: $contract->wagePerWeekCents ?? 0,
            contractExpiresOnDay: $contract?->expiresOn->epochDay ?? 0,
        );
    }

    /**
     * **Le point de bascule vers la perception, et le seul.**
     *
     * Cette methode rend aujourd'hui la **verite** : la note au meilleur poste,
     * telle que `WageModel::quality()` la calcule pour fixer les salaires. C'est
     * legitime parce que le seul lecteur de cette couche est la surface
     * d'administration, qui est une surface d'**exploitation** - un exploitant
     * voit son monde, sinon il ne peut pas l'inspecter (docs/15- §4 Phase 4 :
     * « explorer et editer »).
     *
     * Le jour ou un client de **jeu** lira ces fiches, c'est ici, et nulle part
     * ailleurs, que `PerceptionModel::estimate()` prendra la place - avec
     * l'observateur, son `judgement` et son nombre d'observations. Rien d'autre
     * ne bougera, et ce n'est pas un espoir : `estimate()` rend un `int` sur la
     * meme echelle 1-100 que `quality()`, donc `SquadPlayerView` ne change pas
     * de forme.
     *
     * Ce qui n'est deliberement **pas** fait ici : ni interface a une seule
     * implementation, ni parametre de point de vue sans second appelant, ni
     * drapeau `truthVisible` sans client de jeu pour le lire. Le site est
     * nomme ; le mecanisme arrivera avec son consommateur.
     */
    private function qualityOf(
        PlayerPhysicalSkills $physical,
        PlayerTechnicalSkills $technical,
        PlayerMentalSkills $mental,
    ): int {
        return WageModel::quality($physical, $technical, $mental);
    }

    private function scout(WorldState $state, int $clubId): ?ScoutView
    {
        foreach ($state->components(Scout::class)->entities() as $personId) {
            if ($state->components(Employment::class)->get($personId)?->clubId !== $clubId) {
                continue;
            }

            $scout = $state->components(Scout::class)->get($personId);
            $person = $state->components(Person::class)->get($personId);

            if ($scout !== null && $person !== null) {
                return new ScoutView($personId, $person->name, $scout->judgement);
            }
        }

        return null;
    }

    private function standing(LoadedWorld $world, int $clubId): ?StandingsRowView
    {
        foreach ($this->standings->read($world) as $row) {
            if ($row->clubId === $clubId) {
                return $row;
            }
        }

        return null;
    }
}
