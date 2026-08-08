<?php

declare(strict_types=1);

namespace Flair\Api\Read\History;

use Flair\Api\Read\LoadedWorld;
use Flair\Api\Read\View\ClubHistoryView;
use Flair\Api\Read\View\LoggedFactView;
use Flair\Api\Read\View\MovementView;
use Flair\Api\Read\View\SeasonBlockView;
use Flair\Host\Rules\RulesetForWorld;
use Flair\Host\Store\EventStore;
use Flair\Host\Store\RecordedEvent;
use Flair\Kernel\Core\Ecs\WorldState;
use Flair\Kernel\Core\Ruleset\CompetitionBalance;
use Flair\Kernel\Core\Snapshot\ValueCodec;
use Flair\Kernel\Football\Components\Club;
use Flair\Kernel\Football\Components\Person;
use Flair\Kernel\Football\Events\ClubInvestedInFacilities;
use Flair\Kernel\Football\Events\ContractExpired;
use Flair\Kernel\Football\Events\ContractSigned;
use Flair\Kernel\Football\Events\MatchPlayed;
use Flair\Kernel\Football\Events\SeasonConcluded;
use Flair\Kernel\Football\Events\TransferAgreed;
use Flair\Kernel\Football\Events\YouthPlayerPromoted;
use Flair\Kernel\Football\FootballTypes;

/**
 * L'histoire d'un club, groupee par saison.
 *
 * ## Pourquoi on filtre en PHP et pas en SQL
 *
 * On charge l'intervalle complet (`EventStore::between()`) et on filtre ici.
 * Le filtre SQL serait ~8x plus rapide - 2,17 ms en `Seq Scan` avec des
 * `payload @> '{"homeClubId": X}'` contre 40,8 ms pour tout charger et
 * rehydrater sur dix ans - mais il **dupliquerait la correspondance
 * club <-> cle** en deux endroits, l'un en PHP typé et l'autre en chaines SQL.
 * C'est exactement la divergence que `ClubMentions` existe pour empecher.
 *
 * 40,8 ms est du meme ordre que la fiche de club deja livree (28,7 ms).
 * L'echappatoire, si un monde vieillit assez : **deriver les predicats SQL de
 * la meme declaration**, une source et la vitesse du SQL. Le declencheur est
 * mesurable - `ClubHistoryView::$factsRead`.
 *
 * ## Le decoupage en saisons
 *
 * `intdiv($tick, 365)`, la seule notion de date du noyau (docs/13- §1). Une
 * saison de competition demarre au jour 0 de l'annee et se conclut au jour
 * ~250 : tout ce qui la concerne, mercato du jour 180 compris, tombe donc dans
 * le meme seau. Ce n'est pas une coincidence heureuse, c'est
 * `CalendarBalance::$seasonStartDayOfYear = 0` qui aligne les deux.
 */
final readonly class ClubHistoryReader
{
    public function __construct(
        private EventStore $events,
        private ClubMentions $mentions = new ClubMentions(),
    ) {
    }

    public function read(LoadedWorld $world, int $clubId): ?ClubHistoryView
    {
        $club = $world->state->components(Club::class)->get($clubId);

        if ($club === null) {
            return null;
        }

        $all = $this->events->between($world->record->id, 0, $world->tick);
        $mine = array_values(array_filter(
            $all,
            fn (RecordedEvent $recorded): bool => $this->mentions->concerns($recorded->event, $clubId),
        ));

        $points = $this->pointsRule($world);
        $seasons = [];

        foreach ($this->groupBySeason($mine) as $season => $entries) {
            $seasons[] = $this->block($world->state, $season, $entries, $clubId, $points);
        }

        // De la plus recente a la plus ancienne : on revient sur un club pour
        // savoir ou il en est, pas pour relire sa fondation.
        usort($seasons, static fn (SeasonBlockView $a, SeasonBlockView $b): int => $b->season <=> $a->season);

        return new ClubHistoryView(
            worldId: $world->record->id,
            clubId: $clubId,
            clubName: $club->name,
            tick: $world->tick,
            seasons: $seasons,
            factsRead: count($all),
            factsKept: count($mine),
        );
    }

    /**
     * @param list<RecordedEvent> $entries
     * @return array<int, list<RecordedEvent>>
     */
    private function groupBySeason(array $entries): array
    {
        $bySeason = [];

        foreach ($entries as $entry) {
            $bySeason[$entry->season()][] = $entry;
        }

        return $bySeason;
    }

    /**
     * @param list<RecordedEvent> $entries
     */
    private function block(
        WorldState $state,
        int $season,
        array $entries,
        int $clubId,
        CompetitionBalance $points,
    ): SeasonBlockView {
        $won = $drawn = $lost = $goalsFor = $goalsAgainst = 0;
        $rank = $clubsRanked = null;
        $facilities = $spend = $income = 0;
        $arrivals = $departures = $youth = $renewals = [];
        $log = [];

        // Construits une fois par saison, pas une fois par Fait : le registre
        // se reconstruit a chaque appel.
        $codec = new ValueCodec();
        $types = FootballTypes::registry();

        foreach ($entries as $entry) {
            $event = $entry->event;

            if ($event instanceof MatchPlayed) {
                $home = $event->homeClubId === $clubId;
                $for = $home ? $event->homeGoals : $event->awayGoals;
                $against = $home ? $event->awayGoals : $event->homeGoals;

                $goalsFor += $for;
                $goalsAgainst += $against;
                $won += $for > $against ? 1 : 0;
                $drawn += $for === $against ? 1 : 0;
                $lost += $for < $against ? 1 : 0;
            }

            if ($event instanceof SeasonConcluded) {
                $rank = $this->mentions->rankIn($event, $clubId);
                $clubsRanked = count($event->finalRanking);
            }

            if ($event instanceof ClubInvestedInFacilities) {
                $facilities += $event->cents;
            }

            if ($event instanceof TransferAgreed) {
                if ($event->buyerClubId === $clubId) {
                    $spend += $event->agreedPriceCents;
                } else {
                    $income += $event->agreedPriceCents;
                }
            }

            if ($event instanceof ContractSigned) {
                $fee = $this->feeFor($entries, $event->playerId, $entry->tick);
                $movement = $this->movement($state, $event->playerId, null, $fee, $event->wagePerWeekCents, $entry->tick);

                // Trois cas, pas deux. **Un `ContractSigned` est le plus
                // souvent un renouvellement** : sur le monde de reference a dix
                // ans, 753 des 819 signatures ont le meme club avant et apres,
                // contre 25 vrais transferts et 41 signatures de joueurs sans
                // club. Les compter comme des arrivees ferait dire a une fiche
                // « sept arrivees » la ou le club n'a fait que prolonger ses
                // joueurs - une information fausse, pas seulement inelegante.
                if ($event->clubId === $clubId && $event->previousClubId === $clubId) {
                    $renewals[] = $movement;
                } elseif ($event->clubId === $clubId) {
                    $arrivals[] = $this->movement($state, $event->playerId, $event->previousClubId, $fee, $event->wagePerWeekCents, $entry->tick);
                } else {
                    $departures[] = $this->movement($state, $event->playerId, $event->clubId, $fee, null, $entry->tick);
                }
            }

            if ($event instanceof ContractExpired) {
                $departures[] = $this->movement($state, $event->playerId, null, null, null, $entry->tick);
            }

            if ($event instanceof YouthPlayerPromoted) {
                $youth[] = $this->movement($state, $event->playerId, null, null, null, $entry->tick);
            }

            $encoded = $codec->encode($event);

            $log[] = new LoggedFactView(
                tick: $entry->tick,
                seq: $entry->seq,
                // La **cle stable** du registre, pas le FQCN : c'est celle que
                // porte `events.type`, et elle ne se renomme jamais. Un FQCN
                // rendrait une page archivee illisible au premier deplacement
                // de classe.
                type: $types->keyFor($event),
                role: $this->mentions->roleOf($event, $clubId)?->value,
                data: is_array($encoded) ? $encoded : ['value' => $encoded],
            );
        }

        return new SeasonBlockView(
            season: $season,
            fromTick: $season * 365,
            toTick: ($season + 1) * 365 - 1,
            rank: $rank,
            clubsRanked: $clubsRanked,
            played: $won + $drawn + $lost,
            won: $won,
            drawn: $drawn,
            lost: $lost,
            goalsFor: $goalsFor,
            goalsAgainst: $goalsAgainst,
            points: $won * $points->pointsForWin + $drawn * $points->pointsForDraw,
            arrivals: $arrivals,
            departures: $departures,
            youthPromoted: $youth,
            renewals: $renewals,
            facilitiesInvestedCents: $facilities,
            transferSpendCents: $spend,
            transferIncomeCents: $income,
            log: $log,
        );
    }

    /**
     * L'indemnite payee pour ce joueur, s'il y en a eu une.
     *
     * `TransferAgreed` et le `ContractSigned` qui l'execute sont emis **au meme
     * tick** par `TransferSystem` (point 4 du lot marche) : on rapproche donc
     * les deux par `(playerId, tick)`, sans etat a maintenir. `null` - et non
     * zero - quand rien n'a change de mains : une fin de contrat n'est pas un
     * transfert a titre gratuit.
     *
     * @param list<RecordedEvent> $entries
     */
    private function feeFor(array $entries, int $playerId, int $tick): ?int
    {
        foreach ($entries as $entry) {
            $event = $entry->event;

            if ($event instanceof TransferAgreed && $event->playerId === $playerId && $entry->tick === $tick) {
                return $event->agreedPriceCents;
            }
        }

        return null;
    }

    private function movement(
        WorldState $state,
        int $playerId,
        ?int $otherClubId,
        ?int $feeCents,
        ?int $wagePerWeekCents,
        int $tick,
    ): MovementView {
        return new MovementView(
            playerId: $playerId,
            // Les noms des partis restent lisibles parce que
            // `RetirementSystem::removes()` ne retire pas `Person` :
            // l'accumulation notee en dette au lot 0 a ici un usage reel.
            playerName: $state->components(Person::class)->get($playerId)->name ?? "Joueur {$playerId}",
            otherClubId: $otherClubId,
            otherClubName: $otherClubId === null
                ? null
                : ($state->components(Club::class)->get($otherClubId)->name ?? "Club {$otherClubId}"),
            feeCents: $feeCents,
            wagePerWeekCents: $wagePerWeekCents,
            tick: $tick,
        );
    }

    /**
     * Les baremes du monde, lus par le site unique qui traduit une version de
     * regles en `Ruleset` - `Host\Rules\RulesetForWorld`, pose au lot 1. Un
     * monde epingle a des regles que ce Host ne sait pas reconstruire **leve**
     * ici plutot que d'afficher des points calcules avec les mauvais baremes.
     */
    private function pointsRule(LoadedWorld $world): CompetitionBalance
    {
        return RulesetForWorld::for($world->record->rulesetVersion)->balance->competition;
    }
}
