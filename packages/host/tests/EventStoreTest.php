<?php

declare(strict_types=1);

namespace Flair\Host\Tests;

use Flair\Host\AdvanceWorld;
use Flair\Host\CreateWorld;
use Flair\Host\Store\RecordedEvent;
use Flair\Host\WorldLock;
use Flair\Kernel\Core\Messaging\DomainEvent;
use Flair\Kernel\Football\Events\ContractSigned;
use Flair\Kernel\Football\Events\PlayerRetired;
use Flair\Worldgen\WorldSpec;

/**
 * La relecture de l'event log : `between()`, le miroir de `append()`.
 *
 * Ce qui compte n'est pas qu'on retrouve des lignes, c'est qu'on retrouve des
 * **objets** - un `ContractSigned` avec son `previousClubId` typé, pas un
 * tableau dont il faut deviner les clefs. C'est ce qui permet a la couche de
 * lecture de `flair/api` d'ecrire un `match` sur classe que PHPStan verifie,
 * au lieu de fouiller des chaines.
 */
final class EventStoreTest extends DatabaseTestCase
{
    public function testEventsComeBackAsTypedObjectsInHistoricalOrder(): void
    {
        $worldId = $this->playedWorld('rehydrate', 200);

        $recorded = $this->events->between($worldId, 0, 200);

        self::assertNotSame([], $recorded, 'Deux cents ticks doivent avoir produit des Faits.');

        $previous = null;
        foreach ($recorded as $entry) {
            self::assertInstanceOf(RecordedEvent::class, $entry);
            self::assertInstanceOf(DomainEvent::class, $entry->event);

            // L'ordre total (tick, seq) de docs/13- §4.5, celui de la cle
            // primaire : c'est lui que lisent la narration et les projections,
            // et il ne doit jamais dependre de l'ordre d'insertion.
            $position = [$entry->tick, $entry->seq];
            if ($previous !== null) {
                self::assertGreaterThan($previous, $position, 'Les Faits ne reviennent pas dans l\'ordre de l\'histoire.');
            }
            $previous = $position;
        }
    }

    public function testTheRehydratedObjectCarriesItsFieldsTyped(): void
    {
        // Le jour 180 est celui du mercato : des `ContractSigned` y sont emis
        // avec un `previousClubId` qui vaut `null` pour un joueur sans club.
        $worldId = $this->playedWorld('champs', 200);

        $signings = array_values(array_filter(
            $this->events->between($worldId, 0, 200),
            static fn (RecordedEvent $e): bool => $e->event instanceof ContractSigned,
        ));

        self::assertNotSame([], $signings, 'Le mercato du jour 180 doit avoir signe des contrats.');

        $first = $signings[0]->event;
        self::assertInstanceOf(ContractSigned::class, $first);
        self::assertGreaterThan(0, $first->clubId);
        self::assertGreaterThan(0, $first->playerId);
        self::assertGreaterThan(0, $first->wagePerWeekCents);

        // `?int` : le codec doit rendre le `null` tel quel, pas un zero. Un
        // joueur sans club precedent n'est pas un joueur qui vient du club 0.
        foreach ($signings as $signing) {
            $event = $signing->event;
            self::assertInstanceOf(ContractSigned::class, $event);
            self::assertTrue($event->previousClubId === null || $event->previousClubId > 0);
        }
    }

    public function testTheIntervalIsInclusiveAndExcludesWhatFallsOutside(): void
    {
        $worldId = $this->playedWorld('bornes', 200);

        $all = $this->events->between($worldId, 0, 200);
        self::assertNotSame([], $all);

        $ticks = array_map(static fn (RecordedEvent $e): int => $e->tick, $all);

        if ($ticks === []) {
            self::fail('Deux cents ticks doivent avoir produit des Faits.');
        }

        $first = min($ticks);
        $last = max($ticks);

        self::assertSame(
            count(array_filter($ticks, static fn (int $t): bool => $t === $first)),
            count($this->events->between($worldId, $first, $first)),
            'Une borne repliee sur un seul tick doit rendre exactement les Faits de ce tick.',
        );

        self::assertSame([], $this->events->between($worldId, $last + 1, $last + 100));
        self::assertSame([], $this->events->between('monde-qui-n-existe-pas', 0, 10_000));
    }

    public function testAWorldWithoutFactsInTheWindowComesBackEmptyRatherThanFailing(): void
    {
        $worldId = $this->newWorldId('vide');
        (new CreateWorld($this->database, $this->worlds, $this->snapshots))(
            $worldId,
            new WorldSpec(playerCount: 20, seed: 42, clubCount: 2),
        );

        self::assertSame([], $this->events->between($worldId, 0, 0));
    }

    /**
     * **L'inverse exact de ce que ce test verifiait.** Il exigeait que
     * `PlayerRetired` **n'ait pas** de `clubId`, pour que la limite soit
     * mesuree plutot que supposee : c'est elle qui privait le digest des
     * retraites. Le Fait porte desormais son club, et ce test devient le
     * garde-fou de la propriete qui compte a la relecture - **un employeur
     * nomme survit a l'aller-retour en base**, ce qu'aucune lecture de l'etat
     * courant ne pourrait rattraper une fois le contrat retire.
     */
    public function testARetirementNamesTheClubThatLosesThePlayer(): void
    {
        $worldId = $this->playedWorld('retraites', 200);

        $retirements = array_filter(
            $this->events->between($worldId, 0, 200),
            static fn (RecordedEvent $e): bool => $e->event instanceof PlayerRetired,
        );

        self::assertNotSame([], $retirements, 'Deux cents ticks doivent avoir vu partir des joueurs.');

        $employed = 0;

        foreach ($retirements as $entry) {
            $event = $entry->event;
            self::assertInstanceOf(PlayerRetired::class, $event);
            $employed += $event->clubId === null ? 0 : 1;
        }

        // `null` reste licite - un joueur sans club raccroche comme un autre -
        // mais dans un monde ou tout le monde est employe au genesis, la
        // colonne ne peut pas etre vide de bout en bout.
        self::assertGreaterThan(0, $employed, 'Aucune retraite ne nomme de club : le champ ne remonte pas de la base.');
    }

    private function playedWorld(string $hint, int $ticks): string
    {
        $worldId = $this->newWorldId($hint);
        (new CreateWorld($this->database, $this->worlds, $this->snapshots))(
            $worldId,
            new WorldSpec(playerCount: 60, seed: 42, clubCount: 4),
        );

        $advance = new AdvanceWorld(
            $this->database,
            $this->worlds,
            $this->events,
            $this->snapshots,
            new WorldLock($this->database),
        );

        for ($i = 0; $i < $ticks; $i++) {
            $advance($worldId);
        }

        return $worldId;
    }
}
