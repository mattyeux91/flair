<?php

declare(strict_types=1);

namespace Flair\Host\Tests;

use Flair\Host\AdvanceWorld;
use Flair\Host\CreateWorld;
use Flair\Host\DestroyWorld;
use Flair\Host\WorldLock;
use Flair\Worldgen\WorldSpec;

/**
 * Effacer un monde, et n'effacer que lui.
 *
 * Aucune cle etrangere ni `ON DELETE CASCADE` dans `Database\Schema` : rien au
 * niveau du moteur n'empeche de laisser des Faits orphelins derriere une ligne
 * de monde disparue. Ce test est donc la seule chose qui tient l'invariant.
 */
final class DestroyWorldTest extends DatabaseTestCase
{
    public function testDestroyingAWorldLeavesNothingOfItAndNothingOfTheOthers(): void
    {
        $doomed = $this->playedWorld('condamne');
        $spared = $this->playedWorld('epargne');

        $factsSpared = $this->events->countFor($spared);
        self::assertGreaterThan(0, $factsSpared);

        $deleted = (new DestroyWorld($this->database))($doomed);

        self::assertGreaterThan(0, $deleted['events'], 'Le monde efface avait des Faits.');
        self::assertGreaterThan(0, $deleted['snapshots']);
        self::assertSame(1, $deleted['worlds']);

        self::assertNull($this->worlds->find($doomed));
        self::assertSame(0, $this->events->countFor($doomed));
        self::assertNull($this->snapshots->latest($doomed));

        // Le voisin n'a pas bouge : la clause `where` porte bien sur le monde,
        // pas sur la table.
        self::assertNotNull($this->worlds->find($spared));
        self::assertSame($factsSpared, $this->events->countFor($spared));
        self::assertNotNull($this->snapshots->latest($spared));
    }

    public function testDestroyingAWorldThatDoesNotExistDeletesNothing(): void
    {
        self::assertSame(
            ['events' => 0, 'snapshots' => 0, 'worlds' => 0],
            (new DestroyWorld($this->database))('monde-qui-n-existe-pas'),
        );
    }

    private function playedWorld(string $hint): string
    {
        $worldId = $this->newWorldId($hint);
        (new CreateWorld($this->database, $this->worlds, $this->snapshots))(
            $worldId,
            new WorldSpec(playerCount: 20, seed: 42, clubCount: 2),
        );

        $advance = new AdvanceWorld($this->database, $this->worlds, $this->events, $this->snapshots, new WorldLock($this->database));

        for ($tick = 1; $tick <= 200; $tick++) {
            $advance($worldId);
        }

        return $worldId;
    }
}
