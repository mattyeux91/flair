<?php

declare(strict_types=1);

namespace Flair\Api\Read\Digest;

use Flair\Api\Read\History\ClubMentions;
use Flair\Api\Read\History\ClubRole;
use Flair\Api\Read\LoadedWorld;
use Flair\Api\Read\View\DigestEntryView;
use Flair\Api\Read\View\DigestSummaryView;
use Flair\Api\Read\View\DigestView;
use Flair\Host\Store\EventStore;
use Flair\Host\Store\RecordedEvent;
use Flair\Kernel\Core\Ecs\WorldState;
use Flair\Kernel\Football\Components\Club;
use Flair\Kernel\Football\Events\ClubInvestedInFacilities;
use Flair\Kernel\Football\Events\ContractExpired;
use Flair\Kernel\Football\Events\ContractSigned;
use Flair\Kernel\Football\Events\MatchPlayed;
use Flair\Kernel\Football\Events\PlayerRetired;
use Flair\Kernel\Football\Events\TransferAgreed;
use Flair\Kernel\Football\Events\YouthPlayerPromoted;
use Flair\Kernel\Football\FootballTypes;

/**
 * « Il s'est passe trois mois, qu'est-ce que j'ai rate ? » - docs/14- §9.
 *
 * ## La fenetre ne coute presque rien
 *
 * `Host\Store\EventStore::between()` existe depuis le lot 2 et fait exactement
 * ce qu'il faut : l'histoire d'un club lit `0..tick`, le digest lit
 * `tick-N..tick`. Aucune table, aucun etat, aucune projection - la fenetre est
 * ~40 fois plus courte que l'histoire complete, qui coute deja 58,7 ms.
 *
 * ## Le tri, forme de docs/14- §3
 *
 *     score = amplitude x poids_du_role x fraicheur
 *
 * Une base qui porte le phenomene (`FactAmplitude`), **deux** modificateurs
 * bornes. `poids_du_role` est la « proximite » de docs/14- §9 : elle sort de
 * `History\ClubMentions::roleOf()`, deja seule a savoir a quel titre un club est
 * nomme. La fraicheur ne peut ni annuler ni doubler un Fait - elle departage a
 * amplitude comparable, ce qui est tout ce qu'on lui demande.
 *
 * ## Deux blocs, deux points de vue
 *
 * Les faits marquants du club sont racontes **de son point de vue** (« large
 * victoire a l'exterieur »), ceux du monde sans point de vue (« A 4-0 B »).
 * C'est le meme `FactSentence`, avec ou sans `clubId` - la distinction que
 * docs/14- §9 appelle « Tes clients » / « Ton monde ».
 */
final readonly class DigestReader
{
    /** La fenetre par defaut : trois mois, l'enonce meme du critere de sortie. */
    public const int DEFAULT_DAYS = 90;

    private const int MAX_HIGHLIGHTS = 8;
    private const int MAX_WORLD = 4;

    /**
     * Combien pese chaque role dans le tri. `Subject` est le club dont le Fait
     * parle directement ; `Ranked` ne dit que « il figurait au classement », ce
     * qui est vrai de tous les clubs a la fois.
     *
     * ⚠️ **Exhaustive sur `History\ClubRole`, et sans repli.** Un `?? 1.0` a
     * ete retire ici : PHPStan niveau max le signale comme mort tant que la
     * table couvre l'enum, ce qui fait de lui le garde-fou du couple - ajouter
     * un role sans lui donner de poids ne compilera plus. Un repli silencieux
     * aurait donne a un role inconnu le poids maximal, sans rien dire.
     */
    private const array ROLE_WEIGHTS = [
        ClubRole::Subject->value => 1.0,
        ClubRole::Buyer->value => 0.95,
        ClubRole::Seller->value => 0.95,
        ClubRole::Home->value => 0.8,
        ClubRole::Away->value => 0.8,
        ClubRole::Ranked->value => 0.7,
        ClubRole::Previous->value => 0.6,
    ];

    public function __construct(
        private EventStore $events,
        private ClubMentions $mentions = new ClubMentions(),
        private FactAmplitude $amplitude = new FactAmplitude(),
        private FactSentence $sentence = new FactSentence(),
    ) {
    }

    public function read(LoadedWorld $world, int $clubId, int $days = self::DEFAULT_DAYS): ?DigestView
    {
        $club = $world->state->components(Club::class)->get($clubId);

        if ($club === null) {
            return null;
        }

        $days = max(1, $days);
        $from = max(0, $world->tick - $days + 1);
        $window = $this->events->between($world->record->id, $from, $world->tick);

        $mine = [];
        $others = [];

        foreach ($window as $recorded) {
            if ($this->mentions->concerns($recorded->event, $clubId)) {
                $mine[] = $recorded;
            } else {
                $others[] = $recorded;
            }
        }

        return new DigestView(
            worldId: $world->record->id,
            clubId: $clubId,
            clubName: $club->name,
            tick: $world->tick,
            fromTick: $from,
            toTick: $world->tick,
            days: $days,
            summary: $this->summarise($mine, $clubId),
            highlights: $this->rank($world->state, $mine, $clubId, $from, $world->tick, self::MAX_HIGHLIGHTS),
            world: $this->rank($world->state, $others, null, $from, $world->tick, self::MAX_WORLD),
            factsRead: count($window),
            factsAboutClub: count($mine),
            factsByType: $this->countByType($window),
        );
    }

    /**
     * Le bilan non trie de la fenetre. Reprend, en plus court, le comptage que
     * `History\ClubHistoryReader::block()` fait par saison - les deux doivent
     * dire la meme chose d'une meme periode, et un test l'exige.
     *
     * @param list<RecordedEvent> $entries
     */
    private function summarise(array $entries, int $clubId): DigestSummaryView
    {
        $won = $drawn = $lost = $goalsFor = $goalsAgainst = 0;
        $arrivals = $departures = $renewals = $youth = $retirements = 0;
        $spend = $income = $facilities = 0;

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

            if ($event instanceof ContractSigned) {
                // Les trois cas du lot 2, pour la meme raison : une prolongation
                // n'est ni une arrivee ni un depart, et les confondre a deja
                // fait annoncer « sept arrivees » a un club qui n'avait recrute
                // personne.
                if ($event->clubId === $clubId && $event->previousClubId === $clubId) {
                    $renewals++;
                } elseif ($event->clubId === $clubId) {
                    $arrivals++;
                } else {
                    $departures++;
                }
            }

            $departures += $event instanceof ContractExpired ? 1 : 0;
            $youth += $event instanceof YouthPlayerPromoted ? 1 : 0;
            $retirements += $event instanceof PlayerRetired ? 1 : 0;
            $facilities += $event instanceof ClubInvestedInFacilities ? $event->cents : 0;

            if ($event instanceof TransferAgreed) {
                if ($event->buyerClubId === $clubId) {
                    $spend += $event->agreedPriceCents;
                } else {
                    $income += $event->agreedPriceCents;
                }
            }
        }

        return new DigestSummaryView(
            played: $won + $drawn + $lost,
            won: $won,
            drawn: $drawn,
            lost: $lost,
            goalsFor: $goalsFor,
            goalsAgainst: $goalsAgainst,
            arrivals: $arrivals,
            departures: $departures,
            renewals: $renewals,
            youthPromoted: $youth,
            retirements: $retirements,
            transferSpendCents: $spend,
            transferIncomeCents: $income,
            facilitiesInvestedCents: $facilities,
        );
    }

    /**
     * @param list<RecordedEvent> $entries
     * @return list<DigestEntryView>
     */
    private function rank(
        WorldState $state,
        array $entries,
        ?int $clubId,
        int $from,
        int $to,
        int $limit,
    ): array {
        $types = FootballTypes::registry();
        $scored = [];

        foreach ($entries as $entry) {
            $amplitude = $this->amplitude->of($entry->event);

            // Un Fait sans amplitude n'entre jamais, quel que soit son role ou
            // sa fraicheur : les modificateurs nuancent une nouvelle, ils n'en
            // fabriquent pas. `null` (type non note) et `0.0` (instance
            // ordinaire) se filtrent pareil ici - la distinction sert au test
            // d'exhaustivite, pas a la lecture.
            if ($amplitude === null || $amplitude <= 0.0) {
                continue;
            }

            $role = $clubId === null ? null : $this->mentions->roleOf($entry->event, $clubId);
            $weight = $role === null ? 1.0 : self::ROLE_WEIGHTS[$role->value];
            $freshness = $this->freshness($entry->tick, $from, $to);

            $scored[] = new DigestEntryView(
                tick: $entry->tick,
                seq: $entry->seq,
                type: $types->keyFor($entry->event),
                sentence: $this->sentence->of($state, $entry->event, $clubId),
                role: $role?->value,
                score: $amplitude * $weight * $freshness,
                amplitude: $amplitude,
                roleWeight: $weight,
                freshness: $freshness,
            );
        }

        // Ordre total et stable : a score egal, le Fait le plus recent d'abord,
        // puis `(tick, seq)` qui est l'ordre total des Faits d'un monde
        // (docs/13- §4.5). Jamais l'ordre de lecture.
        usort($scored, static fn (DigestEntryView $a, DigestEntryView $b): int
            => [$b->score, $b->tick, $b->seq] <=> [$a->score, $a->tick, $a->seq]);

        return array_slice($scored, 0, $limit);
    }

    /**
     * La position dans la fenetre, bornee a `[0.6, 1.0]` : un Fait d'hier passe
     * devant un Fait de trois mois a amplitude egale, sans jamais l'ecraser.
     * Une fenetre d'un seul jour rend `1.0` plutot qu'une division par zero.
     */
    private function freshness(int $tick, int $from, int $to): float
    {
        $span = $to - $from;

        if ($span <= 0) {
            return 1.0;
        }

        return 0.6 + 0.4 * (($tick - $from) / $span);
    }

    /**
     * @param list<RecordedEvent> $entries
     * @return array<string, int>
     */
    private function countByType(array $entries): array
    {
        $types = FootballTypes::registry();
        $counts = [];

        foreach ($entries as $entry) {
            $key = $types->keyFor($entry->event);
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        arsort($counts);

        return $counts;
    }
}
