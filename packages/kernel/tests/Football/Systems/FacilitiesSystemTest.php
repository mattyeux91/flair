<?php

declare(strict_types=1);

namespace Flair\Kernel\Tests\Football\Systems;

use Flair\Kernel\Core\Ecs\WorldState;
use Flair\Kernel\Core\Messaging\DomainEvent;
use Flair\Kernel\Core\Pipeline\Pipeline;
use Flair\Kernel\Core\Ruleset\Balance;
use Flair\Kernel\Core\Ruleset\FacilitiesBalance;
use Flair\Kernel\Core\Ruleset\Ruleset;
use Flair\Kernel\Football\Components\Club;
use Flair\Kernel\Football\Components\Facilities;
use Flair\Kernel\Football\Events\ClubInvestedInFacilities;
use Flair\Kernel\Football\Events\SeasonConcluded;
use Flair\Kernel\Football\Systems\FacilitiesSystem;
use PHPUnit\Framework\TestCase;

final class FacilitiesSystemTest extends TestCase
{
    public function testQualityDecaysOnceAtTheEndOfEachSeason(): void
    {
        $world = new WorldState();
        $club = $this->createClub($world, quality: 1.0);

        $this->deliver($world, new SeasonConcluded(1, [$club]), atTick: 5);

        self::assertEqualsWithDelta(0.95, $this->quality($world, $club), 0.0001);
    }

    public function testInvestmentBuysQualityAtTheConfiguredRate(): void
    {
        $world = new WorldState();
        $club = $this->createClub($world, quality: 1.0);

        // 200M centimes le point entier -> 10M achetent 0,05.
        $this->deliver($world, new ClubInvestedInFacilities($club, 10_000_000, 10_000_000), atTick: 5);

        self::assertEqualsWithDelta(1.05, $this->quality($world, $club), 0.0001);
    }

    /**
     * La degradation et l'investissement arrivent a un tick d'ecart (c'est
     * `FinanceSystem`, en traitant le meme `SeasonConcluded`, qui emet le Fait
     * d'investissement). Un club qui investit exactement de quoi compenser
     * doit retrouver son niveau exact.
     */
    public function testInvestingExactlyTheDecayHoldsTheQualitySteady(): void
    {
        $world = new WorldState();
        $club = $this->createClub($world, quality: 1.4);

        $this->deliver($world, new SeasonConcluded(1, [$club]), atTick: 5);
        $this->deliver($world, new ClubInvestedInFacilities($club, 10_000_000, 10_000_000), atTick: 6);

        self::assertEqualsWithDelta(1.4, $this->quality($world, $club), 0.0001);
    }

    public function testQualityNeverExceedsTheComponentCeiling(): void
    {
        $world = new WorldState();
        $club = $this->createClub($world, quality: 1.95);

        $this->deliver($world, new ClubInvestedInFacilities($club, 10_000_000_000, 10_000_000_000), atTick: 5);

        self::assertSame(Facilities::MAX_QUALITY, $this->quality($world, $club));
    }

    public function testQualityNeverFallsBelowTheComponentFloor(): void
    {
        $world = new WorldState();
        $club = $this->createClub($world, quality: Facilities::MIN_QUALITY);

        for ($tick = 5; $tick <= 10; $tick++) {
            $this->deliver($world, new SeasonConcluded(1, [$club]), atTick: $tick);
        }

        self::assertSame(Facilities::MIN_QUALITY, $this->quality($world, $club));
    }

    /**
     * Ce systeme fait evoluer des installations existantes, il n'en cree pas
     * (`creates()` est vide) : un club sans `Facilities` doit traverser une
     * fin de saison et un investissement sans que le composant apparaisse.
     */
    public function testAClubWithoutFacilitiesIsLeftAlone(): void
    {
        $world = new WorldState();
        $club = $world->createEntity();
        $world->components(Club::class)->set($club, new Club('Sans installations'));

        $this->deliver($world, new SeasonConcluded(1, [$club]), atTick: 5);
        $this->deliver($world, new ClubInvestedInFacilities($club, 10_000_000, 10_000_000), atTick: 6);

        self::assertNull($world->components(Facilities::class)->get($club));
    }

    private function deliver(WorldState $world, DomainEvent $event, int $atTick): void
    {
        $world->scheduler()->schedule($event, atTick: $atTick, systemIndex: 0, entityId: 1, seq: 0);

        (new Pipeline([new FacilitiesSystem()]))->tick(
            $world,
            tick: $atTick,
            worldSeed: 1,
            ruleset: new Ruleset('test', new Balance(facilities: new FacilitiesBalance())),
            intents: [],
        );
    }

    private function createClub(WorldState $world, float $quality): int
    {
        $club = $world->createEntity();
        $world->components(Club::class)->set($club, new Club('Club Test'));
        $world->components(Facilities::class)->set($club, new Facilities($quality));

        return $club;
    }

    private function quality(WorldState $world, int $clubId): float
    {
        $facilities = $world->components(Facilities::class)->get($clubId);
        self::assertNotNull($facilities);

        return $facilities->quality;
    }
}
