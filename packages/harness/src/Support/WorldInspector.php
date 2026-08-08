<?php

declare(strict_types=1);

namespace Flair\Harness\Support;

use Flair\Kernel\Core\Ecs\WorldState;
use Flair\Kernel\Football\Components\Club;
use Flair\Kernel\Football\Components\Competition;
use Flair\Kernel\Football\Components\Contract;
use Flair\Kernel\Football\Components\Facilities;
use Flair\Kernel\Football\Components\Finances;
use Flair\Kernel\Football\Components\SeasonIncome;
use Flair\Kernel\Football\Components\Person;
use Flair\Kernel\Football\Components\PlayerMentalSkills;
use Flair\Kernel\Football\Components\PlayerPhysicalSkills;
use Flair\Kernel\Football\Components\PlayerPotentials;
use Flair\Kernel\Football\Components\PlayerTechnicalSkills;
use Flair\Kernel\Football\Components\Position;
use Flair\Kernel\Football\Components\Standings;
use Flair\Kernel\Football\Components\SquadMembership;
use Flair\Kernel\Football\Support\PositionModel;

/**
 * Lecture a la demande d'un WorldState - vue vraie, pas percue (12- §4 ne
 * protege que le client de jeu, pas un outil de debug interne). `clubNames()`
 * et `standingsSnapshot()` sont extraites de Metrics\Sampler (comportement
 * inchange, mais partage : Sampler les utilise pour l'historique de saison,
 * Simulation\StepRunner/bin/sandbox.php pour l'inspection a la demande) -
 * dupliquer cette lecture aurait ete une deuxieme source de verite pour le
 * meme instantane.
 */
final class WorldInspector
{
    /** @return array<int, string> clubId -> nom */
    public static function clubNames(WorldState $world): array
    {
        $names = [];
        foreach ($world->components(Club::class)->entities() as $clubId) {
            $names[$clubId] = $world->components(Club::class)->get($clubId)->name ?? "Club #{$clubId}";
        }

        return $names;
    }

    /**
     * L'effectif de chaque club, total et ventile par poste **derive**
     * (`PositionModel::bestPosition()`, jamais un poste stocke - docs/12- §4).
     * Un club sans le moindre joueur figure avec des compteurs a zero, sinon un
     * club vide echapperait silencieusement a qui compte sur ce tableau.
     *
     * Deux consommateurs reels : `Metrics\Sampler`, qui en tire les club-annees
     * sans gardien annee par annee, et `Tests\Regression\FieldableSquadTest`,
     * qui portait ce calcul en propre avant que la mesure ait besoin d'exister
     * ailleurs que dans une assertion.
     *
     * @return array<int, array<string, int>> clubId -> ['total' => n, 'GK' => n, 'DEF' => n, ...]
     */
    public static function squadsByPosition(WorldState $world): array
    {
        $empty = ['total' => 0];

        foreach (Position::cases() as $position) {
            $empty[$position->value] = 0;
        }

        $squads = [];

        foreach ($world->components(Club::class)->entities() as $clubId) {
            $squads[$clubId] = $empty;
        }

        foreach ($world->components(SquadMembership::class)->entities() as $playerId) {
            $membership = $world->components(SquadMembership::class)->get($playerId);

            if ($membership === null || !isset($squads[$membership->clubId])) {
                continue;
            }

            $squads[$membership->clubId]['total']++;
            $position = self::positionOf($world, $playerId);

            if ($position !== null) {
                $squads[$membership->clubId][$position->value]++;
            }
        }

        return $squads;
    }

    /**
     * Le poste ou ce joueur note le mieux, ou `null` s'il n'a pas de
     * competences (donc n'est pas un joueur, ou est deja retraite).
     */
    public static function positionOf(WorldState $world, int $playerId): ?Position
    {
        $physical = $world->components(PlayerPhysicalSkills::class)->get($playerId);
        $technical = $world->components(PlayerTechnicalSkills::class)->get($playerId);
        $mental = $world->components(PlayerMentalSkills::class)->get($playerId);

        if ($physical === null || $technical === null || $mental === null) {
            return null;
        }

        return PositionModel::bestPosition($physical, $technical, $mental);
    }

    /**
     * @param array<int, string> $clubNames
     * @return list<array{clubId: int, clubName: string, played: int, won: int, drawn: int, lost: int, goalsFor: int, goalsAgainst: int, points: int}>
     */
    public static function standingsSnapshot(WorldState $world, int $competitionId, array $clubNames): array
    {
        $standings = $world->components(Standings::class)->get($competitionId);
        if ($standings === null) {
            return [];
        }

        $rows = [];
        foreach ($standings->entries as $entry) {
            $rows[] = [
                'clubId' => $entry->clubId,
                'clubName' => $clubNames[$entry->clubId] ?? "Club #{$entry->clubId}",
                'played' => $entry->played,
                'won' => $entry->won,
                'drawn' => $entry->drawn,
                'lost' => $entry->lost,
                'goalsFor' => $entry->goalsFor,
                'goalsAgainst' => $entry->goalsAgainst,
                'points' => $entry->points,
            ];
        }

        usort($rows, static fn (array $a, array $b): int => $b['points'] <=> $a['points']
            ?: ($b['goalsFor'] - $b['goalsAgainst']) <=> ($a['goalsFor'] - $a['goalsAgainst']));

        return $rows;
    }

    /**
     * Classement de l'unique competition du monde (Phase 0 : une seule,
     * cf. docblock Football\Components\Competition). Renvoie [] si aucune
     * competition n'existe (monde sans clubs, cf. Worldgen\WorldFactory).
     *
     * @return list<array{clubId: int, clubName: string, played: int, won: int, drawn: int, lost: int, goalsFor: int, goalsAgainst: int, points: int}>
     */
    public static function currentStandings(WorldState $world): array
    {
        $competitionIds = $world->components(Competition::class)->entities();
        if ($competitionIds === []) {
            return [];
        }

        return self::standingsSnapshot($world, $competitionIds[0], self::clubNames($world));
    }

    /**
     * Dump brut (verite, pas perception) des composants d'un joueur.
     * `null` si aucun composant `Person` n'existe pour cet id (entite
     * inconnue, retraitee - RetirementSystem retire ses composants - ou
     * id appartenant a une autre categorie d'entite comme un club).
     *
     * @return array{id: int, name: string, birthDayTick: int, club: string|null, wagePerWeekCents: int|null, physical: array<string, int>, technical: array<string, int>, mental: array<string, int>, potentials: array<string, int|float>}|null
     */
    public static function player(WorldState $world, int $playerId): ?array
    {
        $person = $world->components(Person::class)->get($playerId);
        if ($person === null) {
            return null;
        }

        $physical = $world->components(PlayerPhysicalSkills::class)->get($playerId);
        $technical = $world->components(PlayerTechnicalSkills::class)->get($playerId);
        $mental = $world->components(PlayerMentalSkills::class)->get($playerId);
        $potentials = $world->components(PlayerPotentials::class)->get($playerId);
        $membership = $world->components(SquadMembership::class)->get($playerId);
        $contract = $world->components(Contract::class)->get($playerId);
        $clubName = $membership !== null
            ? (self::clubNames($world)[$membership->clubId] ?? "Club #{$membership->clubId}")
            : null;

        return [
            'id' => $playerId,
            'name' => $person->name,
            'birthDayTick' => $person->birthDate->epochDay,
            'club' => $clubName,
            'wagePerWeekCents' => $contract?->wagePerWeekCents,
            'physical' => $physical === null ? [] : [
                'pace' => $physical->pace,
                'stamina' => $physical->stamina,
                'strength' => $physical->strength,
                'reflexes' => $physical->reflexes,
            ],
            'technical' => $technical === null ? [] : [
                'technique' => $technical->technique,
                'passing' => $technical->passing,
                'finishing' => $technical->finishing,
                'defending' => $technical->defending,
                'positioning' => $technical->positioning,
                'handling' => $technical->handling,
                'distribution' => $technical->distribution,
            ],
            'mental' => $mental === null ? [] : [
                'vision' => $mental->vision,
                'composure' => $mental->composure,
                'leadership' => $mental->leadership,
                'discipline' => $mental->discipline,
                'command' => $mental->command,
            ],
            'potentials' => $potentials === null ? [] : [
                'ceiling' => $potentials->ceiling,
                'physicalPeakAge' => $potentials->physicalPeakAge,
                'technicalPeakAge' => $potentials->technicalPeakAge,
                'mentalPeakAge' => $potentials->mentalPeakAge,
                'growthRate' => $potentials->growthRate,
                'fragility' => $potentials->fragility,
            ],
        ];
    }

    /**
     * Dump brut d'un club : identite, facilites, effectif (balaye
     * SquadMembership - pas d'index inverse cote club, cf. son docblock).
     * `null` si aucun composant `Club` n'existe pour cet id.
     *
     * @return array{id: int, name: string, facilitiesQuality: float|null, balanceCents: int|null, seasonIncomeCents: int|null, roster: list<int>}|null
     */
    public static function club(WorldState $world, int $clubId): ?array
    {
        $club = $world->components(Club::class)->get($clubId);
        if ($club === null) {
            return null;
        }

        $facilities = $world->components(Facilities::class)->get($clubId);
        $finances = $world->components(Finances::class)->get($clubId);
        $seasonIncome = $world->components(SeasonIncome::class)->get($clubId);

        $roster = [];
        foreach ($world->components(SquadMembership::class)->entities() as $playerId) {
            if ($world->components(SquadMembership::class)->get($playerId)?->clubId === $clubId) {
                $roster[] = $playerId;
            }
        }

        return [
            'id' => $clubId,
            'name' => $club->name,
            'facilitiesQuality' => $facilities?->quality,
            'balanceCents' => $finances?->balanceCents,
            'seasonIncomeCents' => $seasonIncome?->cents,
            'roster' => $roster,
        ];
    }
}
