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
use Flair\Kernel\Football\Events\SeasonConcluded;
use Flair\Kernel\Football\Events\SeasonEnded;
use Flair\Kernel\Football\Events\SeasonStarted;

/**
 * Le classement (docs/15- §4) : purement reactif, aucune logique
 * periodique. Seul writer de `Standings` (docs/12- §3 : porte par l'entite
 * competition elle-meme).
 *
 * ## Trois evenements
 *
 * - `SeasonEnded` (programme par `Football\CalendarSystem` au lendemain de
 *   la derniere journee) : le classement est complet et definitif, ce
 *   systeme le trie et emet `Football\Events\SeasonConcluded` avec le
 *   classement final. Ne touche pas `Standings` - la table doit survivre
 *   jusqu'a la saison suivante, `Harness\Metrics\Sampler` va l'y lire.
 *   Voir le docblock de `SeasonConcluded` pour la raison pour laquelle le
 *   classement voyage dans le payload plutot que d'etre relu depuis
 *   `Standings` par son consommateur.
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
 *
 * ## L'ordre du classement appartient a ce systeme
 *
 * `rank()` est le seul endroit du noyau qui sait ce que "premier" veut dire :
 * points, puis difference de buts, puis buts marques, puis `clubId`
 * croissant. Ce dernier depart n'est pas cosmetique - `Standings::$entries`
 * est keye par `clubId` et peuple paresseusement, donc son ordre d'iteration
 * est un ordre d'insertion, interdit comme source d'ordre (docs/12- §2). En
 * terminant sur `clubId`, le comparateur devient un ordre **total** : deux
 * clubs ne peuvent jamais etre a egalite parfaite, et le resultat ne depend
 * plus du tout de l'ordre d'entree.
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
            SeasonEnded::class,
            SeasonStarted::class,
        ];
    }

    public function handle(DomainEvent $event, SystemContext $ctx): void
    {
        if ($event instanceof SeasonEnded) {
            $final = $ctx->read(Standings::class)->get($event->competitionId) ?? new Standings();

            $ctx->emit(
                new SeasonConcluded($event->competitionId, self::rank($final)),
                entityId: $event->competitionId,
            );

            return;
        }

        if ($event instanceof SeasonStarted) {
            $ctx->write(Standings::class)->set($event->competitionId, new Standings());

            return;
        }

        if ($event instanceof FixtureKickoff) {
            $result = $ctx->read(MatchResult::class)->get($event->fixtureId);

            if ($result !== null) {
                $this->applyResult($ctx, $result);
            }
        }
    }

    public function update(SystemContext $ctx): void
    {
    }

    /**
     * La table classee, du premier au dernier. Vide si aucun match n'a ete
     * joue.
     *
     * Les lignes sont rendues **entieres**, pas reduites a leurs `clubId` : ce
     * tri est la derniere occasion de la saison de fixer ses chiffres, et
     * `Standings` sera vide des le prochain `SeasonStarted` (cf. le docblock de
     * `SeasonConcluded`). Ce qui n'est pas publie ici est perdu pour toujours.
     *
     * @return list<StandingsEntry>
     */
    private static function rank(Standings $standings): array
    {
        $entries = array_values($standings->entries);

        usort($entries, static fn (StandingsEntry $a, StandingsEntry $b): int => $b->points <=> $a->points
            ?: ($b->goalsFor - $b->goalsAgainst) <=> ($a->goalsFor - $a->goalsAgainst)
            ?: $b->goalsFor <=> $a->goalsFor
            ?: $a->clubId <=> $b->clubId);

        return $entries;
    }

    private function applyResult(SystemContext $ctx, MatchResult $result): void
    {
        $balance = $ctx->ruleset()->balance->competition;
        $standings = $ctx->read(Standings::class)->get($result->competitionId) ?? new Standings();

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

        $ctx->write(Standings::class)->set($result->competitionId, new Standings($entries));
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
