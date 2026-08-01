<?php

declare(strict_types=1);

namespace Flair\Kernel\Tests\Football\Systems;

use Flair\Kernel\Core\Ecs\WorldState;
use Flair\Kernel\Core\Pipeline\Pipeline;
use Flair\Kernel\Core\Ruleset\AgingBalance;
use Flair\Kernel\Core\Ruleset\Balance;
use Flair\Kernel\Core\Ruleset\Ruleset;
use Flair\Kernel\Core\Simulation\Simulation;
use Flair\Kernel\Core\Simulation\TickContext;
use Flair\Kernel\Core\Support\SimDate;
use Flair\Kernel\Football\Components\Person;
use Flair\Kernel\Football\Components\PlayerMentalSkills;
use Flair\Kernel\Football\Components\PlayerPhysicalSkills;
use Flair\Kernel\Football\Components\PlayerPotentials;
use Flair\Kernel\Football\Components\PlayerTechnicalSkills;
use Flair\Kernel\Football\Events\PlayerRetired;
use Flair\Kernel\Football\Systems\AgingSystem;
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

        $technical = $world->components(PlayerTechnicalSkills::class)->get($entity);
        self::assertNotNull($technical);
        self::assertGreaterThan(40, $technical->technique);
    }

    public function testAPlayerFarPastPeakAgeDeclinesOnAverage(): void
    {
        // peakAge volontairement bas (15) pour amplifier g(age) et obtenir
        // un signal de declin net en peu de ticks, tout en restant sous un
        // age d'eligibilite a la retraite releve explicitement (33) pour
        // isoler ce comportement du risque de retraite.
        $world = new WorldState();
        $entity = $this->createPlayer(
            $world,
            ageYears: 30.0,
            ceiling: 90,
            currentSkill: 80,
            peakAge: 15,
            fragility: 1.0,
        );

        $ruleset = new Ruleset('test', new Balance(aging: new AgingBalance(retirementEligibleAge: 33.0)));

        $pipeline = new Pipeline([new AgingSystem()]);
        for ($tick = 1; $tick <= 900; $tick++) {
            $pipeline->tick($world, tick: $tick, worldSeed: 1, ruleset: $ruleset, intents: []);
        }

        $technical = $world->components(PlayerTechnicalSkills::class)->get($entity);
        self::assertNotNull($technical);
        self::assertLessThan(80, $technical->technique);
    }

    public function testEachCategoryDeclinesFromItsOwnPeakAge(): void
    {
        // Pic physique bas (16), pic mental haut (40) : a 30 ans, le
        // physique est loin dans son declin tandis que le mental est
        // encore en plateau de progression - la preuve que les trois
        // categories ne partagent plus un seul age de pic.
        $world = new WorldState();
        $entity = $world->createEntity();
        $birthDay = (int) round(1 - 30.0 * 365);

        $world->components(Person::class)->set($entity, new Person('Joueur Test', new SimDate($birthDay)));
        $world->components(PlayerPhysicalSkills::class)->set($entity, new PlayerPhysicalSkills(
            pace: 70,
            stamina: 70,
            strength: 70,
            reflexes: 70,
        ));
        $world->components(PlayerMentalSkills::class)->set($entity, new PlayerMentalSkills(
            vision: 70,
            composure: 70,
            leadership: 70,
            discipline: 70,
            command: 70,
        ));
        $world->components(PlayerPotentials::class)->set($entity, new PlayerPotentials(
            ceiling: 90,
            physicalPeakAge: 16,
            technicalPeakAge: 16,
            mentalPeakAge: 40,
            growthRate: 0.5,
            fragility: 1.0,
        ));

        $ruleset = new Ruleset('test', new Balance(aging: new AgingBalance(retirementEligibleAge: 33.0)));
        $pipeline = new Pipeline([new AgingSystem()]);
        for ($tick = 1; $tick <= 900; $tick++) {
            $pipeline->tick($world, tick: $tick, worldSeed: 1, ruleset: $ruleset, intents: []);
        }

        $physical = $world->components(PlayerPhysicalSkills::class)->get($entity);
        $mental = $world->components(PlayerMentalSkills::class)->get($entity);
        self::assertNotNull($physical);
        self::assertNotNull($mental);
        self::assertLessThan(70, $physical->pace);
        self::assertGreaterThanOrEqual(70, $mental->vision);
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
            $worldA->components(PlayerTechnicalSkills::class)->get(1),
            $worldB->components(PlayerTechnicalSkills::class)->get(1),
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

        $normalSkills = $normalWorld->components(PlayerTechnicalSkills::class)->get(1);
        $fastSkills = $fastWorld->components(PlayerTechnicalSkills::class)->get(1);

        self::assertNotNull($normalSkills);
        self::assertNotNull($fastSkills);
        self::assertGreaterThan($normalSkills->technique, $fastSkills->technique);
    }

    public function testARetiredPlayerLosesAllSkillComponentsAndPotentialsAndEmitsAFact(): void
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

            if ($world->components(PlayerPotentials::class)->get($entity) === null) {
                break;
            }
        }

        self::assertNull($world->components(PlayerPotentials::class)->get($entity));
        self::assertNull($world->components(PlayerPhysicalSkills::class)->get($entity));
        self::assertNull($world->components(PlayerTechnicalSkills::class)->get($entity));
        self::assertNull($world->components(PlayerMentalSkills::class)->get($entity));
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
        $world->components(PlayerPhysicalSkills::class)->set($entity, new PlayerPhysicalSkills(
            pace: $currentSkill,
            stamina: $currentSkill,
            strength: $currentSkill,
            reflexes: $currentSkill,
        ));
        $world->components(PlayerTechnicalSkills::class)->set($entity, new PlayerTechnicalSkills(
            technique: $currentSkill,
            passing: $currentSkill,
            finishing: $currentSkill,
            defending: $currentSkill,
            positioning: $currentSkill,
            handling: $currentSkill,
            distribution: $currentSkill,
        ));
        $world->components(PlayerMentalSkills::class)->set($entity, new PlayerMentalSkills(
            vision: $currentSkill,
            composure: $currentSkill,
            leadership: $currentSkill,
            discipline: $currentSkill,
            command: $currentSkill,
        ));
        $world->components(PlayerPotentials::class)->set($entity, new PlayerPotentials(
            ceiling: $ceiling,
            physicalPeakAge: $peakAge,
            technicalPeakAge: $peakAge,
            mentalPeakAge: $peakAge,
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
