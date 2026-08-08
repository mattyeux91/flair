<?php

declare(strict_types=1);

namespace Flair\Harness\Tests\Regression;

use Flair\Harness\Population\PopulationSpec;
use Flair\Harness\Simulation\StepRunner;
use Flair\Harness\Support\WorldHasher;
use Flair\Kernel\Core\Ecs\WorldState;
use Flair\Kernel\Core\Messaging\DomainEvent;
use Flair\Kernel\Core\Ruleset\Ruleset;
use Flair\Kernel\Core\Snapshot\JsonSnapshotFormat;
use Flair\Kernel\Core\Snapshot\SnapshotCodec;
use Flair\Kernel\Core\Snapshot\WorldSnapshot;
use Flair\Kernel\Football\Components\Negotiation;
use Flair\Kernel\Football\FootballTypes;
use Flair\Worldgen\WorldFactory;
use PHPUnit\Framework\TestCase;

/**
 * Le critere de sortie de la Phase 3 (docs/15- §4), mecanise **sans base de
 * donnees** : « tuer le processus au hasard, le relancer, et le monde reprend
 * sans incoherence ».
 *
 * Tuer un processus, ici, c'est jeter le WorldState en memoire et n'en garder
 * que la chaine JSON de son snapshot. Si la reprise est fidele, la suite du
 * monde est **indiscernable** de celle d'un monde jamais interrompu - meme
 * etat final, et surtout meme sequence d'evenements. Les deux empreintes sont
 * necessaires : un ScheduledEntry perdu donne souvent le meme etat final avec
 * une histoire differente, et c'est le mode de panne realiste.
 *
 * ## Les points d'interruption sont choisis, pas tires au hasard
 *
 * Un snapshot pris a un tick tranquille ne prouve presque rien : les files
 * sont vides, aucun etat multi-tick n'est en vol, et le test resterait vert en
 * ayant tout perdu. Le run s'interrompt donc au **premier tick ou chaque
 * structure fragile est effectivement occupee** :
 *
 * - l'OutQueue non vide - ce que le tick N a emis doit etre traite au N+1 ;
 * - le Scheduler non vide - un evenement seulement planifie n'a emis aucun
 *   Fait, donc aucun event log ne le rattraperait ;
 * - une `Negotiation` en cours - le seul etat multi-tick du domaine
 *   (docs/17- point 2), donc le composant dont la perte serait la plus
 *   discrete.
 *
 * Le test **echoue si l'une des trois n'a jamais ete couverte** : c'est le
 * meme garde-fou que celui de MonetaryConservationTest, et pour la meme
 * raison - un test vert qui ne prouve rien est pire que pas de test.
 */
final class SnapshotContinuityTest extends TestCase
{
    private const int SEED = 42;

    public function testAWorldRestoredFromASnapshotIsIndistinguishableFromAnUninterruptedOne(): void
    {
        $reference = $this->runUninterrupted();
        $interrupted = $this->runWithRestarts();

        // La propriete d'abord, le garde-fou ensuite : une divergence reelle
        // doit se lire comme une divergence, pas comme un defaut de
        // couverture - un snapshot casse eloigne les deux mondes au point que
        // la structure suivante ne s'observe jamais, et le message porterait
        // alors sur la mauvaise cause.
        self::assertSame($reference['stateHash'], $interrupted['stateHash'], 'Le monde repris a divergé.');
        self::assertSame($reference['eventsHash'], $interrupted['eventsHash'], 'L\'histoire du monde repris a divergé.');

        foreach (['outQueue', 'scheduler', 'negotiation'] as $structure) {
            self::assertArrayHasKey(
                $structure,
                $interrupted['covered'],
                "Aucune interruption pendant que \"{$structure}\" etait occupee : le test ne prouverait rien.",
            );
        }
    }

    /** @return array{stateHash: string, eventsHash: string} */
    private function runUninterrupted(): array
    {
        $world = $this->genesis();
        $runner = new StepRunner($world, self::ruleset(), self::SEED);
        $events = $runner->advance(self::TICKS);

        return [
            'stateHash' => WorldHasher::hashWorld($world),
            'eventsHash' => WorldHasher::hashEventSequence($events),
        ];
    }

    /**
     * Meme run, mais le monde est serialise, jete, et relu depuis sa seule
     * representation textuelle a trois moments choisis.
     *
     * @return array{stateHash: string, eventsHash: string, covered: array<string, int>}
     */
    private function runWithRestarts(): array
    {
        $codec = new SnapshotCodec(FootballTypes::registry());
        $world = $this->genesis();
        $runner = new StepRunner($world, self::ruleset(), self::SEED);

        /** @var list<DomainEvent> $events */
        $events = [];
        /** @var array<string, int> $covered */
        $covered = [];

        for ($tick = 1; $tick <= self::TICKS; $tick++) {
            foreach ($runner->advance(1) as $event) {
                $events[] = $event;
            }

            $reason = $this->fragileStructureAt($world, $covered);
            if ($reason === null) {
                continue;
            }

            $covered[$reason] = $tick;

            $json = JsonSnapshotFormat::toJson(WorldSnapshot::capture(
                $codec,
                $world,
                worldId: 'continuity',
                tick: $tick,
                seed: self::SEED,
                rulesetVersion: self::ruleset()->version,
            ));

            $snapshot = JsonSnapshotFormat::fromJson($json);
            self::assertSame($tick, $snapshot->tick);

            $world = $snapshot->restore($codec);
            $runner = new StepRunner($world, self::ruleset(), $snapshot->seed, $snapshot->tick);
        }

        return [
            'stateHash' => WorldHasher::hashWorld($world),
            'eventsHash' => WorldHasher::hashEventSequence($events),
            'covered' => $covered,
        ];
    }

    /** @param array<string, int> $covered */
    private function fragileStructureAt(WorldState $world, array $covered): ?string
    {
        if (!isset($covered['outQueue']) && $world->outQueue()->count() > 0) {
            return 'outQueue';
        }

        if (!isset($covered['scheduler']) && $world->scheduler()->count() > 0) {
            return 'scheduler';
        }

        if (!isset($covered['negotiation']) && $world->components(Negotiation::class)->entities() !== []) {
            return 'negotiation';
        }

        return null;
    }

    private function genesis(): WorldState
    {
        $world = new WorldState();
        (new WorldFactory())->populate($world, self::spec()->world());

        return $world;
    }

    private const int TICKS = 3 * 365;

    private static function spec(): PopulationSpec
    {
        return new PopulationSpec(playerCount: 80, years: 3, seed: self::SEED, clubCount: 6);
    }

    private static function ruleset(): Ruleset
    {
        return new Ruleset('snapshot-continuity');
    }
}
