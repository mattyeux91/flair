<?php

declare(strict_types=1);

namespace Flair\Kernel\Tests\Football\Support;

use Flair\Kernel\Core\Ecs\WorldState;
use Flair\Kernel\Core\Messaging\OutQueue;
use Flair\Kernel\Core\Messaging\Scheduler;
use Flair\Kernel\Core\Pipeline\SeqCounter;
use Flair\Kernel\Core\Pipeline\SystemAccess;
use Flair\Kernel\Core\Pipeline\SystemContext;
use Flair\Kernel\Core\Ruleset\ContractBalance;
use Flair\Kernel\Core\Ruleset\PositionBalance;
use Flair\Kernel\Core\Ruleset\Ruleset;
use Flair\Kernel\Core\Support\SimDate;
use Flair\Kernel\Football\Components\Contract;
use Flair\Kernel\Football\Components\PlayerMentalSkills;
use Flair\Kernel\Football\Components\PlayerPhysicalSkills;
use Flair\Kernel\Football\Components\PlayerTechnicalSkills;
use Flair\Kernel\Football\Support\SquadComposition;
use Flair\Kernel\Tests\Core\Pipeline\Fixtures\DeclaredSystem;
use PHPUnit\Framework\TestCase;

final class SquadCompositionTest extends TestCase
{
    public function testByPositionTalliesEachClubsSquadByBestPosition(): void
    {
        $world = new WorldState();
        $club = 100;
        // Un gardien (reflexes eleve, tout le reste bas) et un attaquant
        // (finishing eleve) au meme club.
        $goalkeeper = $this->createPlayer($world, $club, reflexes: 90, finishing: 10);
        $attacker = $this->createPlayer($world, $club, reflexes: 10, finishing: 90);

        $byPosition = SquadComposition::byPosition($this->makeContext($world));

        self::assertSame(1, $byPosition[$club]['GK'] ?? 0);
        self::assertSame(1, $byPosition[$club]['ATT'] ?? 0);
        self::assertArrayNotHasKey('DEF', $byPosition[$club]);
        self::assertArrayNotHasKey('MID', $byPosition[$club]);
        // Les deux joueurs existent bien.
        self::assertNotSame($goalkeeper, $attacker);
    }

    public function testByPositionIgnoresAPlayerWithoutSkills(): void
    {
        $world = new WorldState();
        $club = 100;
        $world->components(Contract::class)->set(
            $world->createEntity(),
            new Contract($club, 1, new SimDate(1), new SimDate(0)),
        );

        $byPosition = SquadComposition::byPosition($this->makeContext($world));

        self::assertSame([], $byPosition);
    }

    /**
     * Un 4-4-2 (defauts de `PositionBalance`) mis a l'echelle de vingt
     * joueurs donne deux gardiens, huit defenseurs, huit milieux, quatre
     * attaquants - la meme repartition que `ContractSystem` calculait avant
     * l'extraction.
     */
    public function testTargetsScaleTheFormationToTheTargetSquadSize(): void
    {
        $targets = SquadComposition::targets(new PositionBalance(), new ContractBalance(targetSquadSize: 20));

        self::assertSame(2, $targets['GK']);
        self::assertSame(8, $targets['DEF']);
        self::assertSame(8, $targets['MID']);
        self::assertSame(4, $targets['ATT']);
    }

    private function createPlayer(WorldState $world, int $clubId, int $reflexes, int $finishing): int
    {
        $player = $world->createEntity();
        $world->components(Contract::class)->set($player, new Contract($clubId, 1, new SimDate(1), new SimDate(0)));
        $world->components(PlayerPhysicalSkills::class)->set($player, new PlayerPhysicalSkills(50, 50, 50, $reflexes));
        $world->components(PlayerTechnicalSkills::class)->set($player, new PlayerTechnicalSkills(50, 50, $finishing, 50, 50, 50, 50));
        $world->components(PlayerMentalSkills::class)->set($player, new PlayerMentalSkills(50, 50, 50, 50, 50));

        return $player;
    }

    private function makeContext(WorldState $world): SystemContext
    {
        return new SystemContext(
            tick: 1,
            systemIndex: 0,
            access: SystemAccess::of(new DeclaredSystem(
                reads: [Contract::class, PlayerPhysicalSkills::class, PlayerTechnicalSkills::class, PlayerMentalSkills::class],
            )),
            worldSeed: 1,
            ruleset: new Ruleset('test'),
            intents: [],
            world: $world,
            scheduler: new Scheduler(),
            outQueue: new OutQueue(),
            seq: new SeqCounter(),
        );
    }
}
