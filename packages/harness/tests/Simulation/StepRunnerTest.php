<?php

declare(strict_types=1);

namespace Flair\Harness\Tests\Simulation;

use Flair\Harness\Metrics\Sampler;
use Flair\Harness\Population\PopulationFactory;
use Flair\Harness\Population\PopulationSpec;
use Flair\Harness\Simulation\StepRunner;
use Flair\Harness\Support\WorldInspector;
use Flair\Kernel\Core\Ecs\WorldState;
use Flair\Kernel\Core\Ruleset\Ruleset;
use Flair\Kernel\Football\Components\PlayerPhysicalSkills;
use PHPUnit\Framework\TestCase;

final class StepRunnerTest extends TestCase
{
    /**
     * Garde-fou de non-divergence : StepRunner (pas-a-pas, tick par tick)
     * et Sampler::run() (boucle interne d'un bloc) partagent PipelineFactory
     * et doivent donc produire exactement le meme etat pour la meme graine
     * et le meme nombre de ticks - sinon le REPL raconterait une histoire
     * differente de l'outil batch existant, silencieusement.
     */
    public function testAdvancingTickByTickMatchesSamplerForTheSameSeed(): void
    {
        $ruleset = new Ruleset('test');
        $years = 5;
        $spec = new PopulationSpec(playerCount: 100, years: $years, seed: 123, clubCount: 6);

        $worldA = new WorldState();
        $playerIdsA = (new PopulationFactory())->populate($worldA, $spec);
        (new Sampler())->run($worldA, $playerIdsA, $years, $spec->seed, $ruleset);

        $worldB = new WorldState();
        (new PopulationFactory())->populate($worldB, $spec);
        $runner = new StepRunner($worldB, $ruleset, $spec->seed);
        $runner->advance($years * 365);

        self::assertSame(WorldInspector::currentStandings($worldA), WorldInspector::currentStandings($worldB));
        self::assertSame(
            \count($worldA->components(PlayerPhysicalSkills::class)->entities()),
            \count($worldB->components(PlayerPhysicalSkills::class)->entities()),
        );
    }

    public function testAdvancingInSeveralSmallerCallsMatchesOneLargeCall(): void
    {
        $ruleset = new Ruleset('test');
        $spec = new PopulationSpec(playerCount: 60, years: 2, seed: 7, clubCount: 4);

        $worldA = new WorldState();
        (new PopulationFactory())->populate($worldA, $spec);
        $runnerA = new StepRunner($worldA, $ruleset, $spec->seed);
        $runnerA->advance(730);

        $worldB = new WorldState();
        (new PopulationFactory())->populate($worldB, $spec);
        $runnerB = new StepRunner($worldB, $ruleset, $spec->seed);
        for ($i = 0; $i < 730; $i++) {
            $runnerB->advance(1);
        }

        self::assertSame($runnerA->currentTick(), $runnerB->currentTick());
        self::assertSame(WorldInspector::currentStandings($worldA), WorldInspector::currentStandings($worldB));
    }
}
