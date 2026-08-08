<?php

declare(strict_types=1);

namespace Flair\Kernel\Tests\Football\Systems;

use Flair\Kernel\Core\Ecs\WorldState;
use Flair\Kernel\Core\Messaging\DomainEvent;
use Flair\Kernel\Core\Pipeline\Pipeline;
use Flair\Kernel\Core\Ruleset\Ruleset;
use Flair\Kernel\Core\Support\SimDate;
use Flair\Kernel\Football\Components\Contract;
use Flair\Kernel\Football\Components\SquadMembership;
use Flair\Kernel\Football\Events\ContractExpired;
use Flair\Kernel\Football\Events\ContractSigned;
use Flair\Kernel\Football\Events\PlayerRetired;
use Flair\Kernel\Football\Systems\SquadSystem;
use PHPUnit\Framework\TestCase;

/**
 * `SquadSystem` ne decide rien : chaque Fait qu'il recoit porte deja la
 * decision. Ces tests verifient donc uniquement qu'il applique fidelement, et
 * qu'il ne defait pas un engagement plus recent qu'un Fait perime.
 *
 * Les Faits sont poses dans l'OutQueue avant le tick plutot qu'emis pendant :
 * le Pipeline calcule son lot d'evenements entrants avant qu'aucun systeme ne
 * tourne, ce qui reproduit exactement l'ecart d'un tick qui separe
 * `ContractSystem` de ce systeme.
 */
final class SquadSystemTest extends TestCase
{
    public function testAContractSignedCreatesTheEmploymentRelation(): void
    {
        $world = new WorldState();
        $player = $world->createEntity();

        $this->applyEvents($world, new ContractSigned($player, clubId: 7, previousClubId: null, wagePerWeekCents: 61_000, expiresOnEpochDay: 900));

        $contract = $world->components(Contract::class)->get($player);

        self::assertNotNull($contract);
        self::assertSame(7, $contract->clubId);
        self::assertSame(61_000, $contract->wagePerWeekCents);
        self::assertSame(900, $contract->expiresOn->epochDay);
        self::assertSame(7, $world->components(SquadMembership::class)->get($player)?->clubId);
    }

    public function testAContractSignedElsewhereMovesThePlayer(): void
    {
        $world = new WorldState();
        $player = $this->employ($world, clubId: 1);

        $this->applyEvents($world, new ContractSigned($player, clubId: 2, previousClubId: 1, wagePerWeekCents: 50_000, expiresOnEpochDay: 900));

        self::assertSame(2, $world->components(Contract::class)->get($player)?->clubId);
        self::assertSame(2, $world->components(SquadMembership::class)->get($player)?->clubId);
    }

    public function testAContractExpiredLeavesThePlayerWithoutAClub(): void
    {
        $world = new WorldState();
        $player = $this->employ($world, clubId: 1);

        $this->applyEvents($world, new ContractExpired($player, clubId: 1));

        self::assertNull($world->components(Contract::class)->get($player));
        self::assertNull($world->components(SquadMembership::class)->get($player));
    }

    /**
     * Le nettoyage de la retraite vit ici depuis que ce systeme possede la
     * relation d'emploi - `RetirementSystem` ne retire plus que l'archetype
     * "joueur". Il corrige au passage la limite qui trainait : un retraite
     * conservait indefiniment son `SquadMembership`.
     */
    public function testARetiredPlayerLosesBothContractAndSquadMembership(): void
    {
        $world = new WorldState();
        $player = $this->employ($world, clubId: 3);

        $this->applyEvents($world, new PlayerRetired($player, ageYears: 36, clubId: 3));

        self::assertNull($world->components(Contract::class)->get($player));
        self::assertNull($world->components(SquadMembership::class)->get($player));
    }

    /**
     * Une retraite delie de **qui que ce soit**, contrairement a une expiration
     * de contrat, qui est propre a un employeur (cf.
     * `testAStaleExpiryDoesNotUndoAMoreRecentEngagement` juste en dessous).
     * Le club que porte le Fait sert a la lecture, jamais a filtrer ici : un
     * retraite qui garderait un contrat parce qu'il a change de club entre
     * l'emission et le traitement serait une fuite, pas une prudence.
     */
    public function testARetirementReleasesEvenFromAClubItDoesNotName(): void
    {
        $world = new WorldState();
        $player = $this->employ($world, clubId: 2);

        $this->applyEvents($world, new PlayerRetired($player, ageYears: 36, clubId: 1));

        self::assertNull($world->components(Contract::class)->get($player));
        self::assertNull($world->components(SquadMembership::class)->get($player));
    }

    public function testAStaleExpiryDoesNotUndoAMoreRecentEngagement(): void
    {
        $world = new WorldState();
        $player = $this->employ($world, clubId: 2);

        $this->applyEvents($world, new ContractExpired($player, clubId: 1));

        self::assertSame(2, $world->components(Contract::class)->get($player)?->clubId);
        self::assertSame(2, $world->components(SquadMembership::class)->get($player)?->clubId);
    }

    private function employ(WorldState $world, int $clubId): int
    {
        $player = $world->createEntity();
        $world->components(Contract::class)->set($player, new Contract($clubId, 50_000, new SimDate(900), new SimDate(1)));
        $world->components(SquadMembership::class)->set($player, new SquadMembership($clubId));

        return $player;
    }

    private function applyEvents(WorldState $world, DomainEvent ...$events): void
    {
        $seq = 0;
        foreach ($events as $event) {
            $world->outQueue()->emit($event, 0, $seq, $seq);
            $seq++;
        }

        (new Pipeline([new SquadSystem()]))->tick($world, tick: 1, worldSeed: 1, ruleset: new Ruleset('test'), intents: []);
    }
}
