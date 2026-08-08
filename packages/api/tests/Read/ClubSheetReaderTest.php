<?php

declare(strict_types=1);

namespace Flair\Api\Tests\Read;

use Flair\Api\Read\ClubSheetReader;
use Flair\Api\Read\WorldSummaryReader;
use Flair\Api\Tests\ReadTestCase;
use Flair\Kernel\Core\Ecs\WorldState;
use Flair\Kernel\Football\Components\Club;
use Flair\Kernel\Football\Components\Position;

/**
 * La couche de lecture, contre un monde reellement ecrit en base.
 *
 * Ce qui est verifie ici n'est pas « le DTO a les bons champs » (le typage s'en
 * charge) mais que la lecture **reconstitue le monde tel que le Host l'a
 * ecrit** : les postes derives, les tris, les composants absents, et le
 * classement qui n'existe pas avant qu'une saison ait ete jouee.
 *
 * ⚠️ Etend `ReadTestCase`, donc **aucune application Laravel n'est bootee**.
 * Ce n'est pas une economie de millisecondes, c'est la preuve que
 * `Flair\Api\Read\` n'a besoin d'aucun framework - la moitie de la garantie que
 * la frontiere `src/` vs `app/` vaut son prix, l'autre moitie etant
 * `Tests\Architecture\ReadLayerStaysFrameworkFreeTest`. Un test de lecture qui
 * arriverait ici en etendant `Tests\TestCase` annulerait cette propriete sans
 * rien casser de visible.
 */
final class ClubSheetReaderTest extends ReadTestCase
{
    public function testAFreshWorldExposesItsClubsSquadsAndScouts(): void
    {
        $world = $this->read($this->world->create('genesis'));
        $sheet = (new ClubSheetReader())->read($world, $this->firstClubId($world->state));

        self::assertNotNull($sheet);
        self::assertNotSame('', $sheet->name);
        self::assertGreaterThan(0, $sheet->squadSize);
        self::assertGreaterThan(0, $sheet->wageBillPerWeekCents);

        // Le staff est seme au genesis, jamais ecrit par un systeme : chaque
        // club emploie un recruteur des le tick 0 (lot perception, 2026-08-05).
        self::assertNotNull($sheet->scout);
        self::assertGreaterThan(0, $sheet->scout->judgement);

        // Aucun match joue : pas de classement. Ce n'est pas un trou de
        // lecture, c'est l'etat du monde - la premiere saison n'est generee
        // qu'au tick 365 (`CalendarBalance::$seasonStartDayOfYear`).
        self::assertNull($sheet->standing);
    }

    public function testTheSquadIsGroupedByDerivedPositionAndSortedByQuality(): void
    {
        $world = $this->read($this->world->create('effectif'));
        $sheet = (new ClubSheetReader())->read($world, $this->firstClubId($world->state));

        self::assertNotNull($sheet);

        // Les quatre postes sont toujours presents, meme vides : un club sans
        // gardien doit se voir, et une cle absente le ferait disparaitre.
        foreach (Position::cases() as $position) {
            self::assertArrayHasKey($position->value, $sheet->squadByPosition);
        }

        $seen = 0;
        foreach ($sheet->squadByPosition as $position => $players) {
            $previous = PHP_INT_MAX;

            foreach ($players as $player) {
                self::assertSame($position, $player->position, 'Un joueur est range sous un poste qui n\'est pas le sien.');
                self::assertLessThanOrEqual($previous, $player->quality, 'L\'effectif n\'est pas trie par note decroissante.');
                self::assertGreaterThan(0, $player->quality);
                self::assertGreaterThan(0.0, $player->age);
                $previous = $player->quality;
                $seen++;
            }
        }

        self::assertSame($sheet->squadSize, $seen);
    }

    public function testStandingsAppearOnlyOnceASeasonHasBeenPlayed(): void
    {
        $worldId = $this->world->create('saison');

        // 420 ticks : saison generee au 365, six journees du 379 au 414,
        // cloture au 415. Le plus court chemin vers un classement complet.
        $this->world->advance($worldId, 420);

        $world = $this->read($worldId);
        $summary = (new WorldSummaryReader())->read($world);

        self::assertSame(420, $summary->tick);
        self::assertSame(1, $summary->season);
        self::assertCount(4, $summary->standings, 'Les quatre clubs doivent figurer au classement.');
        self::assertSame(1, $summary->standings[0]->rank);
        self::assertGreaterThan(0, $summary->standings[0]->played);

        // Ordre total : points, difference de buts, buts marques, puis
        // `clubId` croissant. Sans le dernier critere le classement changerait
        // d'un affichage a l'autre entre deux clubs a egalite parfaite.
        $previous = null;
        foreach ($summary->standings as $row) {
            if ($previous !== null) {
                self::assertLessThanOrEqual($previous, $row->points);
            }
            $previous = $row->points;
        }

        // Et le club retrouve sa propre ligne depuis sa fiche.
        $sheet = (new ClubSheetReader())->read($world, $summary->standings[0]->clubId);
        self::assertNotNull($sheet);
        self::assertNotNull($sheet->standing);
        self::assertSame(1, $sheet->standing->rank);
    }

    private function firstClubId(WorldState $state): int
    {
        $clubs = $state->components(Club::class)->entities();
        self::assertNotSame([], $clubs);

        return $clubs[0];
    }
}
