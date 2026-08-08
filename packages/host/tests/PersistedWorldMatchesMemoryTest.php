<?php

declare(strict_types=1);

namespace Flair\Host\Tests;

use Flair\Host\AdvanceWorld;
use Flair\Host\CreateWorld;
use Flair\Host\WorldLock;
use Flair\Kernel\Core\Ecs\WorldState;
use Flair\Kernel\Core\Ruleset\Ruleset;
use Flair\Kernel\Core\Simulation\Simulation;
use Flair\Kernel\Core\Simulation\TickContext;
use Flair\Kernel\Core\Snapshot\SnapshotCodec;
use Flair\Kernel\Football\FootballPipeline;
use Flair\Kernel\Football\FootballTypes;
use Flair\Worldgen\WorldFactory;
use Flair\Worldgen\WorldSpec;

/**
 * Persister ne doit **rien** changer au monde.
 *
 * Le meme monde avance de N ticks par le Host, avec un aller-retour complet en
 * base a chaque tick, doit etre identique a celui d'un processus qui n'a
 * jamais rien ecrit. Sans cette garantie, tout ce qui a ete mesure jusqu'ici
 * dans le harness cesse de valoir pour un monde de production, et on
 * n'aurait aucun moyen de s'en apercevoir.
 *
 * La comparaison porte sur l'**etat serialise complet**, pas sur un hash :
 * `Harness\Support\WorldHasher` vit dans un package que `host` n'a pas le
 * droit d'importer (docs/11- §7), et de toute facon un diff de JSON dit *ou*
 * ca diverge la ou un hash dit seulement *que* ca diverge.
 */
final class PersistedWorldMatchesMemoryTest extends DatabaseTestCase
{
    private const int TICKS = 120;
    private const int SEED = 42;

    public function testAWorldAdvancedThroughTheDatabaseIsIdenticalToOneAdvancedInMemory(): void
    {
        $worldId = $this->newWorldId('parity');

        $spec = new WorldSpec(playerCount: 40, seed: self::SEED, clubCount: 4);
        (new CreateWorld($this->database, $this->worlds, $this->snapshots))($worldId, $spec);

        $advance = new AdvanceWorld(
            $this->database,
            $this->worlds,
            $this->events,
            $this->snapshots,
            new WorldLock($this->database),
        );

        for ($i = 0; $i < self::TICKS; $i++) {
            $advance($worldId);
        }

        $persisted = $this->snapshots->latest($worldId);
        self::assertNotNull($persisted);
        self::assertSame(self::TICKS, $persisted->tick);

        self::assertSame(
            $this->inMemoryState($spec),
            $persisted->state,
            'Le monde persiste a divergé du monde en memoire.',
        );
    }

    /** @return array<string, mixed> */
    private function inMemoryState(WorldSpec $spec): array
    {
        $world = new WorldState();
        (new WorldFactory())->populate($world, $spec, atTick: 1);

        $simulation = new Simulation(FootballPipeline::build());
        $ruleset = new Ruleset('default');

        for ($tick = 1; $tick <= self::TICKS; $tick++) {
            $simulation->step($world, new TickContext(
                tick: $tick,
                seed: self::SEED,
                intents: [],
                ruleset: $ruleset,
            ));
        }

        return (new SnapshotCodec(FootballTypes::registry()))->encode($world);
    }
}
