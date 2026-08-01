<?php

declare(strict_types=1);

namespace Flair\Kernel\Tests\Football\Systems;

use Flair\Kernel\Core\Ecs\WorldState;
use Flair\Kernel\Core\Pipeline\Pipeline;
use Flair\Kernel\Core\Ruleset\Balance;
use Flair\Kernel\Core\Ruleset\Ruleset;
use Flair\Kernel\Core\Support\SimDate;
use Flair\Kernel\Football\Components\Club;
use Flair\Kernel\Football\Components\Facilities;
use Flair\Kernel\Football\Components\Person;
use Flair\Kernel\Football\Components\PlayerMentalSkills;
use Flair\Kernel\Football\Components\PlayerPhysicalSkills;
use Flair\Kernel\Football\Components\PlayerPotentials;
use Flair\Kernel\Football\Components\PlayerTechnicalSkills;
use Flair\Kernel\Football\Components\SquadMembership;
use Flair\Kernel\Football\Components\TrainingEffect;
use Flair\Kernel\Football\Systems\PlayerDevelopmentSystem;
use Flair\Kernel\Football\Systems\TrainingSystem;
use PHPUnit\Framework\TestCase;

final class TrainingSystemTest extends TestCase
{
    public function testAPlayerAtAClubReceivesTheFacilitiesQualityAsModifier(): void
    {
        $world = new WorldState();
        $club = $this->createClub($world, quality: 1.5);
        $player = $this->createPlayer($world, $club);

        $this->tick($world, new TrainingSystem(), $this->ruleset());

        $effect = $world->components(TrainingEffect::class)->get($player);
        self::assertNotNull($effect);
        self::assertSame(1.5, $effect->quality);
    }

    public function testTheModifierIsClampedAtTheUpperBound(): void
    {
        $world = new WorldState();
        $club = $this->createClub($world, quality: 3.0);
        $player = $this->createPlayer($world, $club);

        $this->tick($world, new TrainingSystem(), $this->ruleset());

        $effect = $world->components(TrainingEffect::class)->get($player);
        self::assertNotNull($effect);
        self::assertSame(2.0, $effect->quality);
    }

    public function testTheModifierIsClampedAtTheLowerBound(): void
    {
        $world = new WorldState();
        $club = $this->createClub($world, quality: 0.1);
        $player = $this->createPlayer($world, $club);

        $this->tick($world, new TrainingSystem(), $this->ruleset());

        $effect = $world->components(TrainingEffect::class)->get($player);
        self::assertNotNull($effect);
        self::assertSame(0.5, $effect->quality);
    }

    public function testTrainingRateHasAMeasurableEffectOnTheModifier(): void
    {
        $world = new WorldState();
        $club = $this->createClub($world, quality: 1.2);
        $player = $this->createPlayer($world, $club);

        $this->tick($world, new TrainingSystem(), $this->ruleset(trainingRate: 1.5));

        $effect = $world->components(TrainingEffect::class)->get($player);
        self::assertNotNull($effect);
        self::assertEqualsWithDelta(1.8, $effect->quality, 1e-9);
    }

    public function testAPlayerWithoutASquadMembershipReceivesNoTrainingEffect(): void
    {
        $world = new WorldState();
        $player = $world->createEntity();
        $world->components(Person::class)->set($player, new Person('Sans club', new SimDate(0)));

        $this->tick($world, new TrainingSystem(), $this->ruleset());

        self::assertNull($world->components(TrainingEffect::class)->get($player));
    }

    public function testAPlayerAtAHighQualityClubProgressesFasterThanAPlayerWithoutAClub(): void
    {
        $world = new WorldState();
        $club = $this->createClub($world, quality: 2.0);

        $withClub = $this->createDevelopingPlayer($world);
        $world->components(SquadMembership::class)->set($withClub, new SquadMembership($club));

        $withoutClub = $this->createDevelopingPlayer($world);

        $pipeline = new Pipeline([new TrainingSystem(), new PlayerDevelopmentSystem()]);
        for ($tick = 1; $tick <= 200; $tick++) {
            $pipeline->tick($world, tick: $tick, worldSeed: 1, ruleset: $this->ruleset(), intents: []);
        }

        $withClubSkills = $world->components(PlayerTechnicalSkills::class)->get($withClub);
        $withoutClubSkills = $world->components(PlayerTechnicalSkills::class)->get($withoutClub);

        self::assertNotNull($withClubSkills);
        self::assertNotNull($withoutClubSkills);
        self::assertGreaterThan($withoutClubSkills->technique, $withClubSkills->technique);
    }

    private function tick(WorldState $world, TrainingSystem $system, Ruleset $ruleset): void
    {
        $pipeline = new Pipeline([$system]);
        $pipeline->tick($world, tick: 1, worldSeed: 1, ruleset: $ruleset, intents: []);
    }

    private function createClub(WorldState $world, float $quality): int
    {
        $club = $world->createEntity();
        $world->components(Club::class)->set($club, new Club('Club Test'));
        $world->components(Facilities::class)->set($club, new Facilities($quality));

        return $club;
    }

    private function createPlayer(WorldState $world, int $clubId): int
    {
        $player = $world->createEntity();
        $world->components(Person::class)->set($player, new Person('Joueur Test', new SimDate(0)));
        $world->components(SquadMembership::class)->set($player, new SquadMembership($clubId));

        return $player;
    }

    private function createDevelopingPlayer(WorldState $world): int
    {
        $entity = $world->createEntity();
        $birthDay = (int) round(1 - 18.0 * 365);

        $world->components(Person::class)->set($entity, new Person('Joueur Test', new SimDate($birthDay)));
        $world->components(PlayerPhysicalSkills::class)->set($entity, new PlayerPhysicalSkills(40, 40, 40, 40));
        $world->components(PlayerTechnicalSkills::class)->set($entity, new PlayerTechnicalSkills(40, 40, 40, 40, 40, 40, 40));
        $world->components(PlayerMentalSkills::class)->set($entity, new PlayerMentalSkills(40, 40, 40, 40, 40));
        $world->components(PlayerPotentials::class)->set($entity, new PlayerPotentials(
            ceiling: 90,
            physicalPeakAge: 27,
            technicalPeakAge: 27,
            mentalPeakAge: 27,
            growthRate: 0.3,
            fragility: 0.5,
        ));

        return $entity;
    }

    private function ruleset(float $trainingRate = 1.0): Ruleset
    {
        return new Ruleset('test', new Balance(trainingRate: $trainingRate));
    }
}
