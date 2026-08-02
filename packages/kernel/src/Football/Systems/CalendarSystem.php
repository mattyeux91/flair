<?php

declare(strict_types=1);

namespace Flair\Kernel\Football\Systems;

use Flair\Kernel\Core\Messaging\DomainEvent;
use Flair\Kernel\Core\Pipeline\System;
use Flair\Kernel\Core\Pipeline\SystemContext;
use Flair\Kernel\Core\Ruleset\CalendarBalance;
use Flair\Kernel\Football\Components\Club;
use Flair\Kernel\Football\Components\Competition;
use Flair\Kernel\Football\Components\Fixture;
use Flair\Kernel\Football\Events\FixtureKickoff;
use Flair\Kernel\Football\Events\SeasonEnded;
use Flair\Kernel\Football\Events\SeasonStarted;

/**
 * Le calendrier (docs/15- §4) : purement periodique, aucun evenement
 * ecoute. Un jour precis de l'annee simulee
 * (`CalendarBalance::$seasonStartDayOfYear`), genere le calendrier complet
 * de la saison a venir pour chaque `Competition` du monde, et programme un
 * `FixtureKickoff` par match via le `Scheduler` (docs/13- §3, "calendrier =
 * entites `Fixture` programmees dans le Scheduler existant" - pas de
 * `TimeSystem`, le `Pipeline` draine deja le Scheduler chaque tick).
 *
 * ## Une seule competition, tous les clubs
 *
 * Aucun composant `CompetitionMembership` n'existe cote club (docs/15- §4 :
 * "1 pays, 1 division, 18 clubs", une seule competition en Phase 0). Ce
 * systeme associe donc tous les `Club::entities()` du monde a **chaque**
 * `Competition` qu'il trouve - correct tant qu'il n'en existe qu'une, a
 * corriger le jour ou une deuxieme division apparait.
 *
 * ## Methode du cercle, double manche
 *
 * Round-robin deterministe (aucun RNG necessaire) : la manche aller fixe
 * `clubIds[0]` et fait tourner les autres positions a chaque journee ; la
 * manche retour rejoue exactement les memes paires, domicile et exterieur
 * inverses. Consequence testable independamment du detail d'alternance de
 * la methode du cercle : chaque club joue exactement `N-1` fois a domicile
 * et `N-1` fois a l'exterieur sur la saison entiere, un match contre chaque
 * autre club dans chaque sens.
 *
 * ## Position dans le pipeline
 *
 * En fin de pipeline, apres les quatre systemes de vieillissement/formation
 * deja declares - un jour ou un joueur est a la fois promu/retraite et
 * calendrier genere n'a besoin d'aucun ordre particulier entre les deux
 * groupes, la generation du calendrier ne lit aucun composant joueur.
 * `MatchSystem` doit etre declare juste apres (il reagit a
 * `FixtureKickoff`), `CompetitionSystem` juste apres lui (il lit
 * `MatchResult`, ecrit par `MatchSystem` plus tot dans le meme tick -
 * canal 1, docs/13- §2).
 *
 * Seul createur de `Fixture` (`creates()`) : les entites qu'il cree
 * n'existent pas encore quand un autre systeme itere ce tick, meme
 * raisonnement que `YouthIntakeSystem` pour les joueurs promus.
 */
final class CalendarSystem implements System
{
    public function id(): string
    {
        return 'calendar';
    }

    /** @return list<class-string> */
    public function reads(): array
    {
        return [
            Competition::class,
            Club::class,
        ];
    }

    /** @return list<class-string> */
    public function writes(): array
    {
        return [];
    }

    /** @return list<class-string> */
    public function removes(): array
    {
        return [];
    }

    /** @return list<class-string> */
    public function creates(): array
    {
        return [
            Fixture::class,
        ];
    }

    /** @return list<class-string> */
    public function subscribesTo(): array
    {
        return [];
    }

    public function handle(DomainEvent $event, SystemContext $ctx): void
    {
    }

    public function update(SystemContext $ctx): void
    {
        $calendar = $ctx->ruleset()->balance->calendar;

        if ($ctx->tick % 365 !== $calendar->seasonStartDayOfYear) {
            return;
        }

        $clubIds = $ctx->components(Club::class)->entities();

        foreach ($ctx->components(Competition::class)->entities() as $competitionId) {
            $this->scheduleSeason($ctx, $competitionId, $clubIds, $calendar);
        }
    }

    /** @param list<int> $clubIds */
    private function scheduleSeason(SystemContext $ctx, int $competitionId, array $clubIds, CalendarBalance $calendar): void
    {
        $leg = $this->roundRobin($clubIds);
        $matchday = 0;

        foreach ($leg as $round) {
            $this->scheduleMatchday($ctx, $competitionId, $round, $matchday, $calendar);
            $matchday++;
        }

        foreach ($leg as $round) {
            $reversed = array_map(
                static fn (array $pair): array => ['home' => $pair['away'], 'away' => $pair['home']],
                $round,
            );
            $this->scheduleMatchday($ctx, $competitionId, $reversed, $matchday, $calendar);
            $matchday++;
        }

        $ctx->emit(new SeasonStarted($competitionId), entityId: $competitionId);
        $ctx->schedule(
            new SeasonEnded($competitionId),
            atTick: $this->seasonEndTick($ctx->tick, $matchday, $calendar),
            entityId: $competitionId,
        );
    }

    /**
     * Le lendemain de la derniere journee : `$matchdayCount` journees ont ete
     * programmees, la derniere porte l'indice `$matchdayCount - 1`. Le "+1"
     * n'est pas cosmetique - au tick de la derniere journee,
     * `Football\CompetitionSystem` traite les `FixtureKickoff` du jour et le
     * classement n'est complet qu'a la fin de ce tick.
     *
     * Une competition sans aucune journee (moins de deux clubs, cf.
     * `roundRobin()`) se termine des le lendemain de sa generation, plutot
     * que de ne jamais se terminer : le seul consommateur en aval est le
     * versement des revenus, et un monde degenere ne doit pas priver ses
     * clubs de recettes en silence.
     */
    private function seasonEndTick(int $tick, int $matchdayCount, CalendarBalance $calendar): int
    {
        if ($matchdayCount === 0) {
            return $tick + 1;
        }

        return $tick + $calendar->firstMatchdayOffsetDays + ($matchdayCount - 1) * $calendar->matchdayIntervalDays + 1;
    }

    /** @param list<array{home:int, away:int}> $pairs */
    private function scheduleMatchday(SystemContext $ctx, int $competitionId, array $pairs, int $matchday, CalendarBalance $calendar): void
    {
        $atTick = $ctx->tick + $calendar->firstMatchdayOffsetDays + $matchday * $calendar->matchdayIntervalDays;

        foreach ($pairs as $pair) {
            $fixtureId = $ctx->createEntity();
            $ctx->components(Fixture::class)->set($fixtureId, new Fixture($competitionId, $pair['home'], $pair['away'], $matchday));
            $ctx->schedule(
                new FixtureKickoff($fixtureId, $competitionId, $pair['home'], $pair['away'], $matchday),
                atTick: $atTick,
                entityId: $fixtureId,
            );
        }
    }

    /**
     * @param list<int> $clubIds triee par id croissant (ComponentStore::entities())
     * @return list<list<array{home:int, away:int}>> une manche simple, indexee par journee
     */
    private function roundRobin(array $clubIds): array
    {
        $clubCount = count($clubIds);

        if ($clubCount < 2) {
            return [];
        }

        $positions = $clubIds;
        $rounds = [];

        for ($round = 0; $round < $clubCount - 1; $round++) {
            $matchday = [];

            for ($i = 0; $i < intdiv($clubCount, 2); $i++) {
                $home = $positions[$i];
                $away = $positions[$clubCount - 1 - $i];

                if ($round % 2 === 1) {
                    [$home, $away] = [$away, $home];
                }

                $matchday[] = ['home' => $home, 'away' => $away];
            }

            $rounds[] = $matchday;

            $last = $positions[$clubCount - 1];
            for ($i = $clubCount - 1; $i > 1; $i--) {
                $positions[$i] = $positions[$i - 1];
            }
            $positions[1] = $last;
        }

        return $rounds;
    }
}
