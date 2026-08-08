<?php

declare(strict_types=1);

namespace Flair\Api\Tests\Read;

use Flair\Api\Read\Digest\DigestReader;
use Flair\Api\Read\History\ClubHistoryReader;
use Flair\Api\Read\LoadedWorld;
use Flair\Api\Read\View\DigestEntryView;
use Flair\Api\Read\View\DigestView;
use Flair\Api\Tests\ReadTestCase;
use Flair\Kernel\Football\Components\Club;

/**
 * Le digest de retour d'absence, contre un monde reellement joue.
 *
 * Meme doctrine que `ClubHistoryReaderTest` : un vrai monde en base, et
 * **sans booter Laravel** (`ReadTestCase`), ce qui prouve au passage que le
 * digest n'a besoin d'aucun framework.
 *
 * ## ⚠️ La fenetre par defaut peut etre legitimement vide
 *
 * La densite des Faits varie d'un facteur ~30 selon l'endroit de la saison ou
 * la fenetre tombe : mesure sur le monde de reference, le mois du mercato
 * (jours 180-210) porte ~180 Faits par an quand le dernier mois de
 * l'intersaison en porte moins de deux. Un digest vide n'est donc pas un bug,
 * et ces tests choisissent leurs fenetres en connaissance de cause.
 */
final class DigestReaderTest extends ReadTestCase
{
    private const int TICKS = 575;

    public function testTheWindowStopsAtTheWorldTickAndCoversTheRequestedDays(): void
    {
        [$world, $digest] = $this->digestOfFirstClub(self::TICKS, days: 90);

        self::assertNotNull($digest);
        self::assertSame(self::TICKS, $digest->tick);
        self::assertSame(self::TICKS, $digest->toTick);
        self::assertSame(self::TICKS - 89, $digest->fromTick);
        self::assertSame(90, $digest->days);
        self::assertSame($world->record->id, $digest->worldId);
    }

    /**
     * **Le test qui donne son sens a la fenetre.** Sans lui, un digest qui
     * lirait tout l'historique passerait toutes les autres assertions.
     */
    public function testAShorterWindowSeesStrictlyLessThanALongerOne(): void
    {
        $worldId = $this->world->create('digest-fenetre');
        $this->world->advance($worldId, self::TICKS);
        $world = $this->read($worldId);
        $clubId = $this->firstClub($world);

        $reader = new DigestReader($this->world->events);
        // `TICKS + 1` et non `TICKS` : une fenetre de N jours finissant au tick
        // T couvre `T-N+1..T`, donc exactement N ticks. Il en faut 576 pour
        // englober le tick 0 d'un monde arrive au 575.
        $wide = $reader->read($world, $clubId, days: self::TICKS + 1);
        $narrow = $reader->read($world, $clubId, days: 30);

        self::assertNotNull($wide);
        self::assertNotNull($narrow);

        self::assertGreaterThan(0, $wide->factsRead, 'Le monde doit avoir produit des Faits.');
        self::assertLessThan($wide->factsRead, $narrow->factsRead);
        self::assertSame(0, $wide->fromTick, 'Une fenetre qui couvre tout le monde part du tick 0.');
        self::assertSame(self::TICKS - 29, $narrow->fromTick);

        foreach ($narrow->highlights as $entry) {
            self::assertGreaterThanOrEqual($narrow->fromTick, $entry->tick);
            self::assertLessThanOrEqual($narrow->toTick, $entry->tick);
        }
    }

    /**
     * Le bandeau est un **bilan**, pas une selection : il doit dire de la
     * periode exactement ce que l'histoire du club en dit, sinon deux lectures
     * du meme monde se contrediraient. C'est le pendant de
     * `PagesMatchJsonTest` entre deux lecteurs plutot qu'entre deux
     * presentations.
     */
    public function testTheSummaryAgreesWithTheClubHistoryOverTheSameSpan(): void
    {
        $worldId = $this->world->create('digest-bilan');
        $this->world->advance($worldId, self::TICKS);
        $world = $this->read($worldId);
        $clubId = $this->firstClub($world);

        // Toute la vie du monde, pour que les deux lectures portent sur
        // exactement la meme periode.
        $digest = (new DigestReader($this->world->events))->read($world, $clubId, days: self::TICKS + 1);
        $history = (new ClubHistoryReader($this->world->events))->read($world, $clubId);

        self::assertNotNull($digest);
        self::assertNotNull($history);

        $played = $won = $drawn = $lost = $goalsFor = $goalsAgainst = 0;
        $arrivals = $departures = $renewals = $youth = $retirements = 0;

        foreach ($history->seasons as $season) {
            $played += $season->played;
            $won += $season->won;
            $drawn += $season->drawn;
            $lost += $season->lost;
            $goalsFor += $season->goalsFor;
            $goalsAgainst += $season->goalsAgainst;
            $arrivals += count($season->arrivals);
            $departures += count($season->departures);
            $renewals += count($season->renewals);
            $youth += count($season->youthPromoted);
            $retirements += count($season->retirements);
        }

        self::assertSame($played, $digest->summary->played, 'Le nombre de matchs diverge entre digest et histoire.');
        self::assertSame($won, $digest->summary->won);
        self::assertSame($drawn, $digest->summary->drawn);
        self::assertSame($lost, $digest->summary->lost);
        self::assertSame($goalsFor, $digest->summary->goalsFor);
        self::assertSame($goalsAgainst, $digest->summary->goalsAgainst);
        self::assertSame($arrivals, $digest->summary->arrivals);
        self::assertSame($departures, $digest->summary->departures);
        self::assertSame($renewals, $digest->summary->renewals);
        self::assertSame($youth, $digest->summary->youthPromoted);
        self::assertSame($retirements, $digest->summary->retirements);
    }

    public function testHighlightsAreSortedAndBounded(): void
    {
        [, $digest] = $this->digestOfFirstClub(self::TICKS, days: self::TICKS);
        self::assertNotNull($digest);
        self::assertNotSame([], $digest->highlights, 'Une annee et demie doit produire au moins un fait marquant.');
        self::assertLessThanOrEqual(8, count($digest->highlights));
        self::assertLessThanOrEqual(4, count($digest->world));

        $scores = array_map(static fn (DigestEntryView $e): float => $e->score, $digest->highlights);
        $sorted = $scores;
        rsort($sorted);
        self::assertSame($sorted, $scores, 'Les faits marquants doivent etre tries par score decroissant.');

        foreach ($digest->highlights as $entry) {
            self::assertGreaterThan(0.0, $entry->amplitude, 'Un Fait sans amplitude ne doit jamais etre retenu.');
            self::assertNotSame('', $entry->sentence);
            self::assertNotNull($entry->role, 'Un fait marquant du club doit porter le role sous lequel il le nomme.');
        }
    }

    /**
     * Les deux blocs ne se recouvrent jamais : « ailleurs dans le monde » veut
     * dire ce que le club n'a pas vecu.
     */
    public function testTheWorldBlockNeverNamesTheClub(): void
    {
        [, $digest] = $this->digestOfFirstClub(self::TICKS, days: self::TICKS);
        self::assertNotNull($digest);

        foreach ($digest->world as $entry) {
            self::assertNull($entry->role, 'Le bloc « le monde » ne raconte rien du point de vue du club.');
        }

        $mine = array_map(static fn (DigestEntryView $e): string => "{$e->tick}:{$e->seq}", $digest->highlights);
        $others = array_map(static fn (DigestEntryView $e): string => "{$e->tick}:{$e->seq}", $digest->world);
        self::assertSame([], array_intersect($mine, $others), 'Un meme Fait ne peut pas etre dans les deux blocs.');
    }

    public function testTheTypeBreakdownAccountsForEveryFactInTheWindow(): void
    {
        [, $digest] = $this->digestOfFirstClub(self::TICKS, days: self::TICKS);
        self::assertNotNull($digest);

        self::assertSame($digest->factsRead, array_sum($digest->factsByType));
        self::assertLessThanOrEqual($digest->factsRead, $digest->factsAboutClub);

        // La ventilation est triee : c'est ce qui rend le « 60 % de
        // MatchPlayed » lisible d'un coup d'oeil sur la page.
        $counts = array_values($digest->factsByType);
        $sorted = $counts;
        rsort($sorted);
        self::assertSame($sorted, $counts);
    }

    public function testAnUnknownClubHasNoDigestRatherThanAnEmptyOne(): void
    {
        $worldId = $this->world->create('digest-inconnu');
        $this->world->advance($worldId, 30);

        self::assertNull((new DigestReader($this->world->events))->read($this->read($worldId), 999_999));
    }

    /**
     * Une fenetre sans le moindre Fait doit rendre un digest **vide et
     * valide**, jamais une erreur : c'est la situation normale de
     * l'intersaison, et un exploitant qui l'ouvre doit lire « rien ne s'est
     * passe » plutot qu'une page en erreur.
     */
    public function testAnEmptyWindowIsAValidDigest(): void
    {
        $worldId = $this->world->create('digest-vide');
        $this->world->advance($worldId, 30);

        $world = $this->read($worldId);
        $digest = (new DigestReader($this->world->events))->read($world, $this->firstClub($world), days: 1);

        self::assertNotNull($digest);
        self::assertSame($world->tick, $digest->fromTick);
        self::assertSame([], $digest->highlights);
        self::assertSame([], $digest->world);
        self::assertTrue($digest->summary->isEmpty());
    }

    /** @return array{0: LoadedWorld, 1: ?DigestView} */
    private function digestOfFirstClub(int $ticks, int $days): array
    {
        $worldId = $this->world->create('digest');
        $this->world->advance($worldId, $ticks);

        $world = $this->read($worldId);

        return [$world, (new DigestReader($this->world->events))->read($world, $this->firstClub($world), $days)];
    }

    private function firstClub(LoadedWorld $world): int
    {
        $clubs = $world->state->components(Club::class)->entities();
        self::assertNotSame([], $clubs);

        return $clubs[0];
    }
}
