<?php

declare(strict_types=1);

namespace Flair\Kernel\Tests\Football;

use Flair\Kernel\Core\Ecs\WorldState;
use Flair\Kernel\Core\Pipeline\Pipeline;
use Flair\Kernel\Core\Ruleset\Balance;
use Flair\Kernel\Core\Ruleset\Ruleset;
use Flair\Kernel\Core\Simulation\Simulation;
use Flair\Kernel\Core\Simulation\TickContext;
use Flair\Kernel\Core\Support\SimDate;
use Flair\Kernel\Football\AgingSystem;
use Flair\Kernel\Football\Person;
use Flair\Kernel\Football\PlayerRetired;
use Flair\Kernel\Football\PlayerSkills;
use Flair\Kernel\Football\Potential;
use PHPUnit\Framework\TestCase;

final class AgingSystemTest extends TestCase
{
    public function testAYoungPlayerWithRoomToGrowImprovesOverSeveralTicks(): void
    {
        $world = new WorldState();
        $entity = $this->createPlayer($world, ageYears: 18.0, ceiling: 90, currentSkill: 40, peakAge: 27);

        $pipeline = new Pipeline([new AgingSystem()]);
        for ($tick = 1; $tick <= 200; $tick++) {
            $pipeline->tick($world, tick: $tick, worldSeed: 1, ruleset: $this->ruleset(), intents: []);
        }

        $skills = $world->components(PlayerSkills::class)->get($entity);
        self::assertNotNull($skills);
        self::assertGreaterThan(40, $skills->technique);
    }

    public function testAPlayerFarPastPeakAgeDeclinesOnAverage(): void
    {
        // peakAge volontairement bas (15) pour amplifier g(age) et obtenir
        // un signal de declin net en peu de ticks, tout en restant sous
        // l'age d'eligibilite a la retraite (33) pour isoler ce comportement.
        $world = new WorldState();
        $entity = $this->createPlayer(
            $world,
            ageYears: 30.0,
            ceiling: 90,
            currentSkill: 80,
            peakAge: 15,
            fragility: 1.0,
        );

        $pipeline = new Pipeline([new AgingSystem()]);
        for ($tick = 1; $tick <= 900; $tick++) {
            $pipeline->tick($world, tick: $tick, worldSeed: 1, ruleset: $this->ruleset(), intents: []);
        }

        $skills = $world->components(PlayerSkills::class)->get($entity);
        self::assertNotNull($skills);
        self::assertLessThan(80, $skills->technique);
    }

    public function testSameWorldSeedProducesTheSameOutcome(): void
    {
        $worldA = new WorldState();
        $this->createPlayer($worldA, ageYears: 18.0, ceiling: 90, currentSkill: 40, peakAge: 27);
        $worldB = new WorldState();
        $this->createPlayer($worldB, ageYears: 18.0, ceiling: 90, currentSkill: 40, peakAge: 27);

        $pipelineA = new Pipeline([new AgingSystem()]);
        $pipelineB = new Pipeline([new AgingSystem()]);

        for ($tick = 1; $tick <= 50; $tick++) {
            $pipelineA->tick($worldA, tick: $tick, worldSeed: 777, ruleset: $this->ruleset(), intents: []);
            $pipelineB->tick($worldB, tick: $tick, worldSeed: 777, ruleset: $this->ruleset(), intents: []);
        }

        self::assertEquals(
            $worldA->components(PlayerSkills::class)->get(1),
            $worldB->components(PlayerSkills::class)->get(1),
        );
    }

    public function testDevelopmentRateHasAMeasurableEffectOnGrowth(): void
    {
        $normalWorld = new WorldState();
        $this->createPlayer($normalWorld, ageYears: 18.0, ceiling: 90, currentSkill: 40, peakAge: 27);
        $fastWorld = new WorldState();
        $this->createPlayer($fastWorld, ageYears: 18.0, ceiling: 90, currentSkill: 40, peakAge: 27);

        $normalPipeline = new Pipeline([new AgingSystem()]);
        $fastPipeline = new Pipeline([new AgingSystem()]);

        for ($tick = 1; $tick <= 200; $tick++) {
            $normalPipeline->tick($normalWorld, tick: $tick, worldSeed: 1, ruleset: $this->ruleset(1.0), intents: []);
            $fastPipeline->tick($fastWorld, tick: $tick, worldSeed: 1, ruleset: $this->ruleset(5.0), intents: []);
        }

        $normalSkills = $normalWorld->components(PlayerSkills::class)->get(1);
        $fastSkills = $fastWorld->components(PlayerSkills::class)->get(1);

        self::assertNotNull($normalSkills);
        self::assertNotNull($fastSkills);
        self::assertGreaterThan($normalSkills->technique, $fastSkills->technique);
    }

    public function testARetiredPlayerLosesPlayerSkillsAndPotentialAndEmitsAFact(): void
    {
        $world = new WorldState();
        $entity = $this->createPlayer(
            $world,
            ageYears: 36.0,
            ceiling: 90,
            currentSkill: 60,
            peakAge: 27,
            fragility: 0.8,
        );

        $simulation = new Simulation(new Pipeline([new AgingSystem()]));
        $retirementEvents = [];

        for ($tick = 1; $tick <= 200; $tick++) {
            $result = $simulation->step($world, new TickContext(
                tick: $tick,
                seed: 1,
                intents: [],
                ruleset: $this->ruleset(),
            ));

            foreach ($result->events as $event) {
                if ($event instanceof PlayerRetired) {
                    $retirementEvents[] = $event;
                }
            }

            if ($world->components(PlayerSkills::class)->get($entity) === null) {
                break;
            }
        }

        self::assertNull($world->components(PlayerSkills::class)->get($entity));
        self::assertNull($world->components(Potential::class)->get($entity));
        self::assertCount(1, $retirementEvents);
        self::assertSame($entity, $retirementEvents[0]->playerId);
    }

    private function createPlayer(
        WorldState $world,
        float $ageYears,
        int $ceiling,
        int $currentSkill,
        int $peakAge,
        float $growthRate = 0.3,
        float $fragility = 0.5,
    ): int {
        $entity = $world->createEntity();
        $birthDay = (int) round(1 - $ageYears * 365);

        $world->components(Person::class)->set($entity, new Person('Joueur Test', new SimDate($birthDay)));
        $world->components(PlayerSkills::class)->set($entity, new PlayerSkills(
            technique: $currentSkill,
            passing: $currentSkill,
            finishing: $currentSkill,
            pace: $currentSkill,
            stamina: $currentSkill,
            strength: $currentSkill,
            defending: $currentSkill,
            positioning: $currentSkill,
            vision: $currentSkill,
            composure: $currentSkill,
            leadership: $currentSkill,
            discipline: $currentSkill,
        ));
        $world->components(Potential::class)->set($entity, new Potential(
            ceiling: $ceiling,
            peakAge: $peakAge,
            growthRate: $growthRate,
            fragility: $fragility,
        ));

        return $entity;
    }

    private function ruleset(float $developmentRate = 1.0): Ruleset
    {
        return new Ruleset('test', new Balance($developmentRate));
    }
}
