<?php

declare(strict_types=1);

namespace Flair\Kernel\Tests\Football\Systems;

use Flair\Kernel\Core\Ecs\WorldState;
use Flair\Kernel\Core\Pipeline\Pipeline;
use Flair\Kernel\Core\Ruleset\RetirementBalance;
use Flair\Kernel\Core\Ruleset\Balance;
use Flair\Kernel\Core\Ruleset\Ruleset;
use Flair\Kernel\Core\Support\SimDate;
use Flair\Kernel\Core\Ruleset\PositionBalance;
use Flair\Kernel\Football\Components\Person;
use Flair\Kernel\Football\Components\PlayerMentalSkills;
use Flair\Kernel\Football\Components\PlayerPhysicalSkills;
use Flair\Kernel\Football\Components\PlayerPotentials;
use Flair\Kernel\Football\Components\Position;
use Flair\Kernel\Football\Components\PlayerTechnicalSkills;
use Flair\Kernel\Football\Support\PositionModel;
use Flair\Kernel\Football\Systems\PlayerDevelopmentSystem;
use Flair\Kernel\Football\Systems\RetirementSystem;
use PHPUnit\Framework\TestCase;

final class PlayerDevelopmentSystemTest extends TestCase
{
    public function testAYoungPlayerWithRoomToGrowImprovesOverSeveralTicks(): void
    {
        $world = new WorldState();
        $entity = $this->createPlayer($world, ageYears: 18.0, ceiling: 90, currentSkill: 40, peakAge: 27);

        $pipeline = new Pipeline([new PlayerDevelopmentSystem()]);
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
            archetype: Position::Midfielder,
            currentSkill: 80,
            peakAge: 15,
            fragility: 1.0,
        );

        $ruleset = new Ruleset('test', new Balance(retirement: new RetirementBalance(retirementEligibleAge: 33.0)));

        $pipeline = new Pipeline([new PlayerDevelopmentSystem()]);
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
            archetype: Position::Midfielder,
            ceilings: PositionModel::ceilings(90, Position::Midfielder, [], new PositionBalance()),
            physicalPeakAge: 16,
            technicalPeakAge: 16,
            mentalPeakAge: 40,
            growthRate: 0.5,
            fragility: 1.0,
        ));

        $ruleset = new Ruleset('test', new Balance(retirement: new RetirementBalance(retirementEligibleAge: 33.0)));
        $pipeline = new Pipeline([new PlayerDevelopmentSystem()]);
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

        $pipelineA = new Pipeline([new PlayerDevelopmentSystem()]);
        $pipelineB = new Pipeline([new PlayerDevelopmentSystem()]);

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

        $normalPipeline = new Pipeline([new PlayerDevelopmentSystem()]);
        $fastPipeline = new Pipeline([new PlayerDevelopmentSystem()]);

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

    public function testRetirementAndDevelopmentSystemsCoexistInDeclaredOrder(): void
    {
        // Preuve executable que l'ordre RetirementSystem -> PlayerDevelopmentSystem
        // est sur : un joueur age/fragile part a la retraite (ses composants
        // disparaissent) pendant qu'un jeune joueur, dans le meme monde,
        // continue de progresser normalement.
        $world = new WorldState();
        $veteran = $this->createPlayer(
            $world,
            ageYears: 36.0,
            ceiling: 90,
            archetype: Position::Midfielder,
            currentSkill: 60,
            peakAge: 27,
            fragility: 0.8,
        );
        $rookie = $this->createPlayer($world, ageYears: 18.0, ceiling: 90, currentSkill: 40, peakAge: 27);

        $pipeline = new Pipeline([new RetirementSystem(), new PlayerDevelopmentSystem()]);
        for ($tick = 1; $tick <= 900; $tick++) {
            $pipeline->tick($world, tick: $tick, worldSeed: 1, ruleset: $this->ruleset(), intents: []);
        }

        self::assertNull($world->components(PlayerPotentials::class)->get($veteran));

        $rookieSkills = $world->components(PlayerTechnicalSkills::class)->get($rookie);
        self::assertNotNull($rookieSkills);
        self::assertGreaterThan(40, $rookieSkills->technique);
    }

    /**
     * Archetype `Midfielder` par defaut : c'est celui dont le profil contient
     * `technique` et `vision`, les deux attributs sur lesquels ces tests
     * assertent une progression. Un archetype hors profil leur donnerait un
     * plafond rabaisse (`PositionModel::ceilings()`) et les ferait decliner -
     * ce qui serait le comportement correct, mais ne testerait plus la meme
     * chose.
     */
    private function createPlayer(
        WorldState $world,
        float $ageYears,
        int $ceiling,
        int $currentSkill,
        int $peakAge,
        Position $archetype = Position::Midfielder,
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
            archetype: $archetype,
            ceilings: PositionModel::ceilings($ceiling, $archetype, [], new PositionBalance()),
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
