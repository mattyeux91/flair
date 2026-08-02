<?php

declare(strict_types=1);

namespace Flair\Kernel\Football\Systems;

use Flair\Kernel\Core\Messaging\DomainEvent;
use Flair\Kernel\Core\Pipeline\System;
use Flair\Kernel\Core\Pipeline\SystemContext;
use Flair\Kernel\Core\Ruleset\CompetitionBalance;
use Flair\Kernel\Football\Components\MatchResult;
use Flair\Kernel\Football\Components\Standings;
use Flair\Kernel\Football\Components\StandingsEntry;
use Flair\Kernel\Football\Events\FixtureKickoff;
use Flair\Kernel\Football\Events\SeasonStarted;

/**
 * Le classement (docs/15- §4) : purement reactif, aucune logique
 * periodique. Seul writer de `Standings` (docs/12- §3 : porte par l'entite
 * competition elle-meme).
 *
 * ## Deux evenements, deux canaux
 *
 * - `SeasonStarted` (emis par `Football\CalendarSystem` a la generation du
 *   calendrier, canal 2) remet `Standings` a vide pour la competition
 *   concernee - pas besoin d'une resolution le jour meme, le premier match
 *   de la saison arrive de toute facon plusieurs jours plus tard.
 * - `FixtureKickoff` (le meme evenement que `Football\MatchSystem`, canal 1
 *   au sens de docs/13- §2 : "un match joue doit alimenter le classement du
 *   jour") : lit `MatchResult`, deja ecrit par `MatchSystem` plus tot dans
 *   **le meme tick**, puisque `MatchSystem` est declare avant ce systeme
 *   dans le pipeline - le meme `$incoming` (Scheduler+OutQueue draines en
 *   debut de tick) est rejoue pour chaque systeme qui s'y abonne, dans
 *   l'ordre du pipeline (docs/13- §4.2). Si `MatchResult` est absent
 *   (ordre du pipeline rompu par erreur), l'evenement est ignore plutot que
 *   de lever une exception - un classement qui rate une mise a jour reste
 *   diagnosticable, un noyau qui plante ne l'est pas.
 *
 * `entries` de `Standings` est peuple paresseusement : une entree de club
 * n'existe qu'a partir de son premier match joue.
 */
final class CompetitionSystem implements System
{
    public function id(): string
    {
        return 'competition';
    }

    /** @return list<class-string> */
    public function reads(): array
    {
        return [
            MatchResult::class,
            Standings::class,
        ];
    }

    /** @return list<class-string> */
    public function writes(): array
    {
        return [
            Standings::class,
        ];
    }

    /** @return list<class-string> */
    public function removes(): array
    {
        return [];
    }

    /** @return list<class-string> */
    public function creates(): array
    {
        return [];
    }

    /** @return list<class-string> */
    public function subscribesTo(): array
    {
        return [
            FixtureKickoff::class,
            SeasonStarted::class,
        ];
    }

    public function handle(DomainEvent $event, SystemContext $ctx): void
    {
        if ($event instanceof SeasonStarted) {
            $ctx->components(Standings::class)->set($event->competitionId, new Standings());

            return;
        }

        if ($event instanceof FixtureKickoff) {
            $result = $ctx->components(MatchResult::class)->get($event->fixtureId);

            if ($result !== null) {
                $this->applyResult($ctx, $result);
            }
        }
    }

    public function update(SystemContext $ctx): void
    {
    }

    private function applyResult(SystemContext $ctx, MatchResult $result): void
    {
        $balance = $ctx->ruleset()->balance->competition;
        $standings = $ctx->components(Standings::class)->get($result->competitionId) ?? new Standings();

        $entries = $standings->entries;
        $entries[$result->homeClubId] = $this->updateEntry(
            $entries[$result->homeClubId] ?? new StandingsEntry($result->homeClubId),
            $result->homeGoals,
            $result->awayGoals,
            $balance,
        );
        $entries[$result->awayClubId] = $this->updateEntry(
            $entries[$result->awayClubId] ?? new StandingsEntry($result->awayClubId),
            $result->awayGoals,
            $result->homeGoals,
            $balance,
        );

        $ctx->components(Standings::class)->set($result->competitionId, new Standings($entries));
    }

    private function updateEntry(StandingsEntry $entry, int $goalsFor, int $goalsAgainst, CompetitionBalance $balance): StandingsEntry
    {
        $won = $goalsFor > $goalsAgainst;
        $drawn = $goalsFor === $goalsAgainst;

        $points = match (true) {
            $won => $balance->pointsForWin,
            $drawn => $balance->pointsForDraw,
            default => 0,
        };

        return new StandingsEntry(
            clubId: $entry->clubId,
            played: $entry->played + 1,
            won: $entry->won + ($won ? 1 : 0),
            drawn: $entry->drawn + ($drawn ? 1 : 0),
            lost: $entry->lost + (!$won && !$drawn ? 1 : 0),
            goalsFor: $entry->goalsFor + $goalsFor,
            goalsAgainst: $entry->goalsAgainst + $goalsAgainst,
            points: $entry->points + $points,
        );
    }
}
