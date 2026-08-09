<?php

declare(strict_types=1);

namespace Flair\Host\Tests;

use Flair\Host\AdvanceOutcome;
use Flair\Host\AdvanceWorld;
use Flair\Host\CreateWorld;
use Flair\Host\Database\Database;
use Flair\Host\Store\Row;
use Flair\Host\Store\SnapshotStore;
use Flair\Host\WorldLock;
use Flair\Worldgen\WorldSpec;

final class AdvanceWorldTest extends DatabaseTestCase
{
    public function testANewWorldStartsAtTickZeroWithASnapshotAndNoEvents(): void
    {
        $worldId = $this->newWorldId('genesis');
        $record = $this->create($worldId);

        self::assertSame(0, $record->tick);
        self::assertSame(0, $this->events->countFor($worldId));

        $snapshot = $this->snapshots->latest($worldId);
        self::assertNotNull($snapshot);
        self::assertSame(0, $snapshot->tick);
        self::assertSame(42, $snapshot->seed);
    }

    public function testAdvancingMovesTheWorldForwardAndKeepsTheTickInSync(): void
    {
        $worldId = $this->newWorldId('advance');
        $this->create($worldId);
        $advance = $this->advance();

        for ($i = 1; $i <= 3; $i++) {
            $result = $advance($worldId);

            self::assertSame(AdvanceOutcome::Advanced, $result->outcome);
            self::assertSame($i, $result->tick);
        }

        // Le tick de `worlds` est une commodite, celui du snapshot est la
        // verite : ecrits dans la meme transaction, ils ne peuvent pas
        // diverger. Si ce test casse un jour, c'est que quelqu'un a sorti une
        // des deux ecritures de la transaction.
        self::assertSame(3, $this->worlds->find($worldId)?->tick);
        self::assertSame(3, $this->snapshots->latest($worldId)?->tick);
    }

    /**
     * **Le compteur qui ne doit plus mentir.**
     *
     * `simulationSeconds` et `persistenceSeconds` totalisaient 34,7 ms sur
     * 48,5 mesurees : le chargement du snapshot etait pris avant le premier
     * `microtime()`, et le `COMMIT` tombe apres le retour de la closure de
     * transaction, hors de portee de tout compteur interne. Un chiffre de perf
     * qui manque 29 % de son sujet sert a decider de travers.
     *
     * Ce test n'affirme aucune duree - elles dependent de la machine - mais la
     * **relation** entre les trois, qui est structurelle : le total encadre la
     * somme des deux autres, et l'ecart porte un nom.
     */
    public function testTheTotalCoversTheTwoInnerCountersAndTheCommitTheyCannotSee(): void
    {
        $worldId = $this->newWorldId('compteurs');
        $this->create($worldId);

        $result = ($this->advance())($worldId);

        self::assertSame(AdvanceOutcome::Advanced, $result->outcome);
        self::assertGreaterThan(0.0, $result->simulationSeconds);
        self::assertGreaterThan(0.0, $result->persistenceSeconds);

        self::assertGreaterThanOrEqual(
            $result->simulationSeconds + $result->persistenceSeconds,
            $result->totalSeconds,
            'Le total doit encadrer les deux compteurs internes : il entoure la transaction entiere.',
        );

        self::assertSame(
            $result->totalSeconds - $result->simulationSeconds - $result->persistenceSeconds,
            $result->overheadSeconds(),
            "L'ecart doit etre nomme, pas subi : c'est le verrou et le COMMIT.",
        );
    }

    public function testAnUnknownWorldIsReportedRatherThanCrashing(): void
    {
        $result = ($this->advance())('monde-qui-n-existe-pas');

        self::assertSame(AdvanceOutcome::Unknown, $result->outcome);
    }

    /**
     * Le coeur du verrou mono-writer (docs/13- §8). Une **seconde connexion**
     * tient le verrou dans une transaction ouverte ; le processus principal
     * doit repartir immediatement, sans rien ecrire.
     *
     * Deux connexions reelles et non un double : un verrou advisory est
     * precisement ce qu'aucun test en memoire ne peut reproduire.
     */
    public function testASecondWriterIsTurnedAwayWithoutWritingAnything(): void
    {
        $worldId = $this->newWorldId('lock');
        $this->create($worldId);

        $rival = Database::fromEnvironment();
        $rival->connection()->beginTransaction();

        try {
            self::assertTrue((new WorldLock($rival))->tryAcquire($worldId));

            $result = ($this->advance())($worldId);

            self::assertSame(AdvanceOutcome::Busy, $result->outcome);
            self::assertSame(0, $this->worlds->find($worldId)?->tick);
            self::assertSame(0, $this->snapshots->latest($worldId)?->tick);
            self::assertSame(0, $this->events->countFor($worldId));
        } finally {
            $rival->connection()->rollBack();
        }

        // Le verrou etant lie a la transaction, il tombe avec elle : le monde
        // repart aussitot.
        self::assertSame(AdvanceOutcome::Advanced, ($this->advance())($worldId)->outcome);
    }

    public function testSnapshotsArePrunedToTheConfiguredRetention(): void
    {
        $worldId = $this->newWorldId('prune');
        $this->create($worldId);
        $advance = $this->advance(retention: 2);

        for ($i = 0; $i < 5; $i++) {
            $advance($worldId);
        }

        $kept = $this->database->connection()->table('snapshots')
            ->where('world_id', $worldId)
            ->orderBy('tick')
            ->pluck('tick')
            ->all();

        self::assertSame([4, 5], array_map(Row::toInt(...), $kept));
    }

    /**
     * Les Faits sont journalises avec la **cle stable** du registre, jamais un
     * FQCN : c'est ce qui permet de renommer une classe d'evenement sans
     * rendre illisible l'histoire deja ecrite (docs/13- §5).
     */
    public function testEventsAreLoggedUnderTheirStableRegistryKey(): void
    {
        $worldId = $this->newWorldId('events');
        $this->create($worldId);
        $advance = $this->advance();

        // On avance **jusqu'au premier Fait**, pas jusqu'a un tick devine :
        // le premier evenement d'un monde depend de sa population et de sa
        // graine, et un nombre en dur ici se serait revele faux au premier
        // changement de calibrage. La borne evite qu'un monde muet fasse
        // tourner le test indefiniment.
        for ($i = 0; $i < 250 && $this->events->countFor($worldId) === 0; $i++) {
            $advance($worldId);
        }

        $types = $this->database->connection()->table('events')
            ->where('world_id', $worldId)
            ->distinct()
            ->pluck('type')
            ->all();

        self::assertNotEmpty($types, 'Aucun Fait journalise : le test ne prouverait rien.');

        foreach ($types as $type) {
            self::assertIsString($type);
            self::assertMatchesRegularExpression('/^football\.event\./', $type);
            self::assertStringNotContainsString('\\', $type);
        }
    }

    private function create(string $worldId): \Flair\Host\Store\WorldRecord
    {
        return (new CreateWorld($this->database, $this->worlds, $this->snapshots))(
            $worldId,
            new WorldSpec(playerCount: 40, seed: 42, clubCount: 4),
        );
    }

    private function advance(int $retention = SnapshotStore::DEFAULT_RETENTION): AdvanceWorld
    {
        return new AdvanceWorld(
            $this->database,
            $this->worlds,
            $this->events,
            $this->snapshots,
            new WorldLock($this->database),
            $retention,
        );
    }
}
