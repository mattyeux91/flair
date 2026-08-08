<?php

declare(strict_types=1);

namespace Flair\Api\Tests\Http;

use Flair\Api\Format\Money;
use Flair\Api\Tests\TestCase;

/**
 * **Le test qui justifie la fusion de l'admin et de l'API dans un seul paquet.**
 *
 * Le graphe de docs/11- §7 prevoyait `admin` et `api` separes. On a assume
 * l'ecart : l'admin est un outil interne mono-utilisateur, et un SPA n'y achete
 * rien. Mais l'argument tenait a une condition - que ce ne soit pas la
 * separation en paquets qui empeche les deux presentations de diverger, mais le
 * fait qu'elles n'aient **qu'une source**.
 *
 * Ce test est ce qui rend cette condition verifiable au lieu d'etre une
 * intention. Il prend les chiffres de la reponse JSON, les met sous la forme
 * que la page emploie, et exige de les y retrouver. Si un jour quelqu'un lit
 * la base directement depuis une vue ou un controleur, ou renumerote un champ
 * dans un seul des deux chemins, c'est ici que ca rougit.
 */
final class PagesMatchJsonTest extends TestCase
{
    public function testTheClubSheetPageCarriesTheSameFiguresAsItsJsonRoute(): void
    {
        $worldId = $this->world->create('pages');
        $clubId = $this->firstClubIdFromJson($worldId);

        $json = $this->getJson("/api/worlds/{$worldId}/clubs/{$clubId}");
        $json->assertOk();

        /** @var array<string, mixed> $sheet */
        $sheet = $json->json();

        $html = $this->get("/worlds/{$worldId}/clubs/{$clubId}");
        $html->assertOk();

        $html->assertSee(self::text($sheet, 'name'), escape: false);
        $html->assertSee(Money::roundEuros(self::int($sheet, 'balanceCents')), escape: false);
        $html->assertSee(Money::roundEuros(self::int($sheet, 'seasonIncomeCents')), escape: false);
        $html->assertSee(Money::roundEuros(self::int($sheet, 'wageBillPerWeekCents')), escape: false);
        $html->assertSee((string) self::int($sheet, 'boardPatience'), escape: false);
        $html->assertSee(number_format(self::float($sheet, 'facilitiesQuality'), 2, ',', ' '), escape: false);

        // Chaque joueur du DTO doit se retrouver sur la page, avec sa note et
        // son salaire. C'est ce qui interdit qu'une vue affiche un autre
        // effectif que celui que l'API annonce.
        $players = 0;
        /** @var array<string, mixed> $byPosition */
        $byPosition = self::array($sheet, 'squadByPosition');

        foreach ($byPosition as $group) {
            self::assertIsArray($group);

            foreach ($group as $player) {
                self::assertIsArray($player);
                /** @var array<string, mixed> $player */
                $html->assertSee(self::text($player, 'name'), escape: false);
                $html->assertSee(Money::euros(self::int($player, 'wagePerWeekCents')), escape: false);
                $players++;
            }
        }

        self::assertSame(self::int($sheet, 'squadSize'), $players);
        self::assertGreaterThan(10, $players, 'Un effectif de moins de onze ne testerait pas une vraie fiche.');
    }

    public function testTheWorldPageCarriesTheSameFiguresAsItsJsonRoute(): void
    {
        $worldId = $this->world->create('pages');

        $json = $this->getJson("/api/worlds/{$worldId}");
        $json->assertOk();

        /** @var array<string, mixed> $summary */
        $summary = $json->json();

        $html = $this->get("/worlds/{$worldId}");
        $html->assertOk();

        $html->assertSee($worldId, escape: false);
        $html->assertSee((string) self::int($summary, 'playerCount'), escape: false);
        $html->assertSee((string) self::int($summary, 'contractedPlayerCount'), escape: false);
        $html->assertSee(Money::roundEuros(
            self::int($summary, 'monetaryInjectionsCents') - self::int($summary, 'monetarySinksCents'),
        ), escape: false);

        /** @var array<int, mixed> $clubs */
        $clubs = self::array($summary, 'clubs');
        self::assertCount(4, $clubs);

        foreach ($clubs as $club) {
            self::assertIsArray($club);
            /** @var array<string, mixed> $club */
            $html->assertSee(self::text($club, 'name'), escape: false);
        }
    }

    public function testTheHistoryPageCarriesTheSameFiguresAsItsJsonRoute(): void
    {
        $worldId = $this->world->create('pages');
        // Assez pour une saison de competition conclue (generee au 365,
        // journees du 379 au 414, cloture au 415) : sans elle il n'y aurait ni
        // rang ni bilan a comparer.
        $this->world->advance($worldId, 430);

        $clubId = $this->firstClubIdFromJson($worldId);

        $json = $this->getJson("/api/worlds/{$worldId}/clubs/{$clubId}/history");
        $json->assertOk();

        /** @var array<string, mixed> $history */
        $history = $json->json();

        $html = $this->get("/worlds/{$worldId}/clubs/{$clubId}/history");
        $html->assertOk();

        $html->assertSee(self::text($history, 'clubName'), escape: false);

        /** @var array<int, mixed> $seasons */
        $seasons = self::array($history, 'seasons');
        self::assertNotSame([], $seasons, 'Un club doit avoir au moins une saison apres 430 ticks.');

        $ranked = $retired = 0;
        foreach ($seasons as $season) {
            self::assertIsArray($season);
            /** @var array<string, mixed> $season */

            // Une saison sans match n'affiche ni points ni bilan : seules les
            // saisons jouees ont des chiffres a comparer.
            if (self::int($season, 'played') > 0) {
                $html->assertSee((string) self::int($season, 'points'), escape: false);
                $html->assertSee(sprintf(
                    '%d&nbsp;/&nbsp;%d&nbsp;/&nbsp;%d',
                    self::int($season, 'won'),
                    self::int($season, 'drawn'),
                    self::int($season, 'lost'),
                ), escape: false);
            }

            if (($season['rank'] ?? null) !== null) {
                $html->assertSee((string) self::int($season, 'rank'), escape: false);
                $ranked++;
            }

            // Les retraites ont leur propre tableau, aux colonnes distinctes :
            // le nom du joueur et son age doivent s'y retrouver tels que le
            // JSON les donne.
            foreach (self::array($season, 'retirements') as $retirement) {
                self::assertIsArray($retirement);
                /** @var array<string, mixed> $retirement */
                $html->assertSee(self::text($retirement, 'playerName'), escape: false);
                $html->assertSee((string) self::int($retirement, 'ageYears'), escape: false);
                $retired++;
            }
        }

        self::assertGreaterThan(0, $ranked, 'La saison conclue doit porter un rang, sur la page comme dans le JSON.');
        self::assertGreaterThan(0, $retired, 'Le monde doit avoir vu partir des joueurs, page et JSON compris.');
    }

    public function testTheIndexListsTheWorldWithoutDecodingIt(): void
    {
        $worldId = $this->world->create('pages');

        $this->get('/')->assertOk()->assertSee($worldId, escape: false);
        $this->getJson('/api/worlds')->assertOk()->assertJsonFragment(['id' => $worldId]);
    }

    public function testAnUnknownWorldOrClubIsNotFound(): void
    {
        $worldId = $this->world->create('pages');

        $this->get('/worlds/monde-qui-n-existe-pas')->assertNotFound();
        $this->getJson('/api/worlds/monde-qui-n-existe-pas')->assertNotFound();
        $this->get("/worlds/{$worldId}/clubs/999999")->assertNotFound();
    }

    private function firstClubIdFromJson(string $worldId): int
    {
        $response = $this->getJson("/api/worlds/{$worldId}");
        $response->assertOk();

        /** @var array<string, mixed> $summary */
        $summary = $response->json();
        /** @var array<int, mixed> $clubs */
        $clubs = self::array($summary, 'clubs');
        self::assertNotSame([], $clubs);
        self::assertIsArray($clubs[0]);

        /** @var array<string, mixed> $first */
        $first = $clubs[0];

        return self::int($first, 'id');
    }

    /** @param array<string, mixed> $data */
    private static function text(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        self::assertIsString($value, "Champ \"{$key}\" absent ou non textuel dans la reponse JSON.");

        return $value;
    }

    /** @param array<string, mixed> $data */
    private static function int(array $data, string $key): int
    {
        $value = $data[$key] ?? null;
        self::assertIsInt($value, "Champ \"{$key}\" absent ou non entier dans la reponse JSON.");

        return $value;
    }

    /** @param array<string, mixed> $data */
    private static function float(array $data, string $key): float
    {
        $value = $data[$key] ?? null;
        self::assertTrue(is_float($value) || is_int($value), "Champ \"{$key}\" absent ou non numerique.");

        return (float) $value;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<array-key, mixed>
     */
    private static function array(array $data, string $key): array
    {
        $value = $data[$key] ?? null;
        self::assertIsArray($value, "Champ \"{$key}\" absent ou non tableau dans la reponse JSON.");

        return $value;
    }
}
