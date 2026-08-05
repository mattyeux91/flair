<?php

declare(strict_types=1);

namespace Flair\Kernel\Tests\Football\Systems;

use Flair\Kernel\Football\Support\PositionModel;
use Flair\Kernel\Core\Ecs\WorldState;
use Flair\Kernel\Core\Pipeline\Pipeline;
use Flair\Kernel\Core\Ruleset\Balance;
use Flair\Kernel\Core\Ruleset\Ruleset;
use Flair\Kernel\Core\Simulation\Simulation;
use Flair\Kernel\Core\Simulation\TickContext;
use Flair\Kernel\Core\Support\SimDate;
use Flair\Kernel\Core\Ruleset\PositionBalance;
use Flair\Kernel\Football\Components\Person;
use Flair\Kernel\Football\Components\PlayerMentalSkills;
use Flair\Kernel\Football\Components\PlayerPhysicalSkills;
use Flair\Kernel\Football\Components\PlayerPotentials;
use Flair\Kernel\Football\Components\Position;
use Flair\Kernel\Football\Components\PlayerTechnicalSkills;
use Flair\Kernel\Football\Events\PlayerRetired;
use Flair\Kernel\Football\Systems\RetirementSystem;
use PHPUnit\Framework\TestCase;

final class RetirementSystemTest extends TestCase
{
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

        $simulation = new Simulation(new Pipeline([new RetirementSystem()]));
        $retirementEvents = [];

        // 2000 ticks (contre 200 pour l'ancien AgingSystem combine) : le
        // flux RNG de RetirementSystem est desormais isole par son propre
        // systemId ('retirement'), distinct de celui qu'AgingSystem
        // partageait entre tirage de retraite et progression - la sequence
        // de tirages a change, la marge est elargie pour rester fiable au
        // seed fixe utilise ici (retraite observee au tick 512).
        for ($tick = 1; $tick <= 2000; $tick++) {
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
            archetype: Position::Midfielder,
            ceilings: PositionModel::ceilings($ceiling, Position::Midfielder, [], new PositionBalance()),
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
