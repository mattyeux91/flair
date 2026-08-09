<?php

declare(strict_types=1);

namespace Flair\Api\Tests\Read;

use Flair\Api\Read\History\ClubHistoryReader;
use Flair\Api\Read\LoadedWorld;
use Flair\Api\Read\StandingsReader;
use Flair\Api\Read\View\ClubHistoryView;
use Flair\Api\Read\View\SeasonBlockView;
use Flair\Api\Tests\ReadTestCase;
use Flair\Kernel\Football\Components\Club;
use Flair\Kernel\Football\Components\Person;
use Flair\Kernel\Football\Components\StandingsEntry;
use Flair\Kernel\Football\Events\SeasonConcluded;

/**
 * L'histoire d'un club, contre un monde reellement joue.
 *
 * Un an et demi de simulation par test, ce qui est le prix a payer : le marche
 * des transferts n'ouvre qu'au jour 200 de l'**annee 2**, et sans lui il n'y
 * aurait ni indemnite ni depart a verifier.
 *
 * ## ⚠️ Le seau 0 n'est pas la premiere saison de competition
 *
 * Le decoupage est `intdiv($tick, 365)`, et une saison est generee quand
 * `tick % 365 === 0` - donc **au tick 365, qui tombe dans le seau 1**. Le seau
 * 0 couvre la premiere annee du monde : il porte le mercato du jour 180 et
 * l'intake des jeunes, mais **aucun match**, puisqu'aucune competition n'a
 * encore ete generee. C'est contre-intuitif et ca a fait echouer la premiere
 * version de ce test ; le decoupage, lui, est juste - tout ce qui concerne une
 * saison de competition (sa generation, ses journees, sa cloture, son mercato
 * et ses transferts) tombe bien dans un seul seau.
 *
 * ## ⚠️ Sur `ReadTestCase`, donc **sans booter Laravel**
 *
 * La couche de lecture n'a besoin d'aucun framework, et ce test est la moitie
 * qui le prouve - l'autre etant
 * `Tests\Architecture\ReadLayerStaysFrameworkFreeTest`.
 */
final class ClubHistoryReaderTest extends ReadTestCase
{
    private const int TICKS = 575;

    /** La premiere saison de competition : generee au tick 365, conclue au 415. */
    private const int FIRST_COMPETITION_SEASON = 1;

    public function testTheHistoryIsGroupedBySeasonNewestFirst(): void
    {
        [$world, $history] = $this->historyOfFirstClub(self::TICKS);

        self::assertNotNull($history);
        self::assertNotSame([], $history->seasons);
        self::assertSame(self::TICKS, $history->tick);

        $seasons = array_map(static fn (SeasonBlockView $s): int => $s->season, $history->seasons);
        self::assertSame([1, 0], $seasons, 'Les saisons doivent aller de la plus recente a la plus ancienne.');

        // Le seau 0 est l'annee du genesis : du mercato, jamais de match.
        self::assertSame(0, $this->season($history->seasons, 0)->played);
        self::assertGreaterThan(0, $this->season($history->seasons, 1)->played);

        // Le filtre a bien filtre : le club ne peut pas etre nomme par tous
        // les Faits du monde.
        self::assertGreaterThan(0, $history->factsKept);
        self::assertLessThan($history->factsRead, $history->factsKept);
        self::assertSame($world->tick, $history->tick);
    }

    public function testTheConcludedSeasonCarriesAnAuthoritativeRankAndACountedRecord(): void
    {
        [, $history] = $this->historyOfFirstClub(self::TICKS);
        self::assertNotNull($history);

        $season = $this->season($history->seasons, self::FIRST_COMPETITION_SEASON);

        // Le rang vient de `SeasonConcluded.finalTable` : c'est un Fait, il
        // fait autorite. Il n'est jamais recalcule.
        self::assertNotNull($season->rank, 'La saison de competition est conclue, elle doit porter un rang.');
        self::assertSame(4, $season->clubsRanked);
        self::assertGreaterThanOrEqual(1, $season->rank);
        self::assertLessThanOrEqual($season->clubsRanked, $season->rank);

        // Le bilan est compte sur les `MatchPlayed` : six journees a quatre
        // clubs, donc six matchs pour chacun.
        self::assertSame(6, $season->played);
        self::assertSame($season->played, $season->won + $season->drawn + $season->lost);
        self::assertSame($season->goalsFor - $season->goalsAgainst, $season->goalDifference());
        self::assertSame(365, $season->fromTick);
        self::assertSame(729, $season->toTick);
    }

    /**
     * **Le garde-fou du chemin « compte ».** Une saison en cours n'a pas encore
     * de `SeasonConcluded` : son bilan est compte sur les `MatchPlayed` et ses
     * points appliques depuis les baremes du `Ruleset`. Rien n'empecherait ce
     * calcul de diverger de ce que le monde fait reellement - sauf ceci.
     *
     * La comparaison porte donc sur la saison **en cours**, la seule dont le
     * `Standings` du snapshot soit encore le reflet : une fois la saison
     * suivante generee, le classement repart de zero. C'est precisement
     * pourquoi une saison conclue est **citee** et non comptee - voir
     * `testAConcludedSeasonQuotesTheFactRatherThanRecountingIt`.
     */
    public function testRecomputedPointsMatchTheStandingsOfTheSnapshot(): void
    {
        $worldId = $this->world->create('points');
        // 400 ticks : la competition est en cours (journees du 379 au 400),
        // pas encore conclue au 415. `Standings` est donc encore le sien.
        $this->world->advance($worldId, 400);

        $world = $this->read($worldId);
        $standings = (new StandingsReader())->read($world);
        self::assertNotSame([], $standings, 'La saison doit avoir commence pour qu\'un classement existe.');

        $reader = new ClubHistoryReader($this->world->events);
        $compared = 0;

        foreach ($standings as $row) {
            $history = $reader->read($world, $row->clubId);
            self::assertNotNull($history);

            $season = $this->season($history->seasons, self::FIRST_COMPETITION_SEASON);

            self::assertSame($row->points, $season->points, sprintf(
                'Club %d : points recalcules %d, `Standings` du snapshot %d. '
                . 'Le recalcul de l\'histoire a diverge de ce que le monde a fait.',
                $row->clubId,
                $season->points,
                $row->points,
            ));
            self::assertSame($row->played, $season->played);
            self::assertSame($row->won, $season->won);
            self::assertSame($row->drawn, $season->drawn);
            self::assertSame($row->lost, $season->lost);
            self::assertSame($row->goalsFor, $season->goalsFor);
            self::assertSame($row->goalsAgainst, $season->goalsAgainst);
            $compared++;
        }

        self::assertSame(4, $compared, 'Les quatre clubs doivent avoir ete compares.');
    }

    /**
     * **Une saison conclue est citee, pas recomptee.**
     *
     * Chaque chiffre du bloc doit etre celui de la ligne du club dans
     * `SeasonConcluded.finalTable`, le proces-verbal publie par le monde. Ce
     * que ce test peut prouver : que la lecture prend bien sa source dans le
     * Fait. Ce qu'il ne peut pas encore prouver : que ca **change** quelque
     * chose - aucune regle n'attribue aujourd'hui de points hors d'un resultat
     * de match, donc les deux chemins donnent le meme resultat. Le jour ou un
     * retrait de points existera, ce test sera le seul a distinguer les deux,
     * et c'est pour ce jour-la qu'il est ecrit.
     */
    public function testAConcludedSeasonQuotesTheFactRatherThanRecountingIt(): void
    {
        $worldId = $this->world->create('proces-verbal');
        $this->world->advance($worldId, self::TICKS);
        $world = $this->read($worldId);

        $clubs = $world->state->components(Club::class)->entities();
        $history = (new ClubHistoryReader($this->world->events))->read($world, $clubs[0]);
        self::assertNotNull($history);

        $block = $this->season($history->seasons, self::FIRST_COMPETITION_SEASON);
        $line = $this->officialLine($worldId, $clubs[0]);

        self::assertSame($line->played, $block->played);
        self::assertSame($line->won, $block->won);
        self::assertSame($line->drawn, $block->drawn);
        self::assertSame($line->lost, $block->lost);
        self::assertSame($line->goalsFor, $block->goalsFor);
        self::assertSame($line->goalsAgainst, $block->goalsAgainst);
        self::assertSame($line->points, $block->points);
    }

    /**
     * **La dette que ce lot solde.** `PlayerRetired` ne portait que `playerId`
     * et `ageYears` : les retraites d'un club etaient invisibles dans son
     * histoire, et le reconstruire depuis les `ContractSigned` aurait ete
     * silencieusement faux (les contrats du genesis ne sont pas dans l'event
     * log). Le Fait porte desormais son club.
     *
     * Compte sur les quatre clubs : le monde produit des retraites, la question
     * est que la lecture les place - pas qu'un club en particulier en subisse.
     */
    public function testRetirementsAreAttributedToTheClubThatLosesThePlayer(): void
    {
        $worldId = $this->world->create('retraites');
        $this->world->advance($worldId, self::TICKS);
        $world = $this->read($worldId);

        $reader = new ClubHistoryReader($this->world->events);
        $retirements = 0;

        foreach ($world->state->components(Club::class)->entities() as $clubId) {
            $history = $reader->read($world, $clubId);
            self::assertNotNull($history);

            foreach ($history->seasons as $season) {
                foreach ($season->retirements as $movement) {
                    self::assertNotSame('', $movement->playerName);
                    self::assertNotNull($movement->ageYears, 'Une retraite doit dire a quel age.');
                    self::assertGreaterThanOrEqual(30, $movement->ageYears);
                    // Une retraite ne fait ni gagner ni perdre d'argent, et
                    // n'a pas de club d'en face : personne ne recupere le
                    // joueur.
                    self::assertNull($movement->feeCents);
                    self::assertNull($movement->otherClubId);
                    $retirements++;
                }
            }
        }

        self::assertGreaterThan(0, $retirements, 'Dix-neuf mois de monde doivent produire des retraites.');
    }

    /**
     * Sur les quatre clubs, pas sur un seul : dans un monde de soixante
     * joueurs, un club donne peut tres bien ne perdre personne en dix-neuf
     * mois. Ce qui doit etre vrai est que le monde produit des departs et que
     * la lecture les place - pas qu'un club en particulier en subisse.
     */
    public function testMovementsResolveNamesEvenForPlayersWhoHaveLeft(): void
    {
        $worldId = $this->world->create('mouvements');
        $this->world->advance($worldId, self::TICKS);
        $world = $this->read($worldId);

        $reader = new ClubHistoryReader($this->world->events);
        $arrivals = $departures = $youth = $renewals = 0;

        foreach ($world->state->components(Club::class)->entities() as $clubId) {
            $history = $reader->read($world, $clubId);
            self::assertNotNull($history);

            foreach ($history->seasons as $season) {
                $this->assertMovementsAreReadable($world, $season);

                $arrivals += count($season->arrivals);
                $departures += count($season->departures);
                $youth += count($season->youthPromoted);
                $renewals += count($season->renewals);
            }
        }

        self::assertGreaterThan(0, $arrivals, 'Le mercato du jour 180 doit avoir fait signer des joueurs.');
        self::assertGreaterThan(0, $departures, 'Un contrat expire ou un transfert doit avoir fait partir quelqu\'un.');
        self::assertGreaterThan(0, $youth, 'L\'intake du jour 180 doit avoir promu des jeunes.');

        // **Le cas dominant, et le piege de ce lot.** Un `ContractSigned` est
        // le plus souvent une prolongation au meme club : 753 sur 819 dans le
        // monde de reference a dix ans. La premiere version de ce lecteur les
        // comptait comme des arrivees, et la page annoncait « sept arrivees »
        // pour un club qui n'avait recrute personne. Vu en ouvrant la vraie
        // page, pas par un test - d'ou celui-ci.
        self::assertGreaterThan(
            $arrivals,
            $renewals,
            'Les prolongations doivent dominer les arrivees : c\'est ce que fait un mercato de PNJ.',
        );
    }

    private function assertMovementsAreReadable(LoadedWorld $world, SeasonBlockView $season): void
    {
        foreach ([...$season->arrivals, ...$season->departures, ...$season->youthPromoted, ...$season->renewals] as $movement) {
            self::assertGreaterThan(0, $movement->playerId);
            self::assertNotSame('', $movement->playerName);

            // La vraie propriete : le nom d'un joueur **parti** reste lisible,
            // parce que `RetirementSystem::removes()` ne retire pas `Person`.
            // On verifie donc que le composant existe encore dans le snapshot,
            // et pas la forme de la chaine - `Person` nomme les joueurs
            // « Joueur {id} », exactement comme la valeur de repli, ce qui rend
            // les deux indistinguables a la lecture.
            self::assertNotNull(
                $world->state->components(Person::class)->get($movement->playerId),
                "Le joueur {$movement->playerId} n'a plus de Person : son nom serait perdu pour l'histoire.",
            );

            // `null` et non zero quand rien n'a change de mains : une fin de
            // contrat n'est pas un transfert a titre gratuit.
            self::assertTrue($movement->feeCents === null || $movement->feeCents > 0);
        }
    }

    public function testAnUnknownClubHasNoHistoryRatherThanAnEmptyOne(): void
    {
        $world = $this->read($this->world->create('inconnu'));

        self::assertNull((new ClubHistoryReader($this->world->events))->read($world, 999_999));
    }

    /** @return array{LoadedWorld, ?ClubHistoryView} */
    private function historyOfFirstClub(int $ticks): array
    {
        $worldId = $this->world->create('histoire');
        $this->world->advance($worldId, $ticks);

        $world = $this->read($worldId);
        $clubs = $world->state->components(Club::class)->entities();
        self::assertNotSame([], $clubs);

        return [$world, (new ClubHistoryReader($this->world->events))->read($world, $clubs[0])];
    }

    /**
     * La ligne d'un club dans le classement final publie par le monde, lue
     * directement dans l'event store - sans passer par la couche qu'on teste.
     */
    private function officialLine(string $worldId, int $clubId): StandingsEntry
    {
        foreach ($this->world->events->between($worldId, 0, self::TICKS) as $recorded) {
            if (!$recorded->event instanceof SeasonConcluded) {
                continue;
            }

            foreach ($recorded->event->finalTable as $entry) {
                if ($entry->clubId === $clubId) {
                    return $entry;
                }
            }
        }

        self::fail("Aucun SeasonConcluded ne classe le club {$clubId}.");
    }

    /** @param list<SeasonBlockView> $seasons */
    private function season(array $seasons, int $wanted): SeasonBlockView
    {
        foreach ($seasons as $season) {
            if ($season->season === $wanted) {
                return $season;
            }
        }

        self::fail("Aucun bloc pour la saison {$wanted}.");
    }
}
