<?php

declare(strict_types=1);

namespace Flair\Harness\Tests\Population;

use Flair\Harness\Population\CompetitionFactory;
use Flair\Kernel\Core\Ecs\WorldState;
use Flair\Kernel\Football\Components\Competition;
use PHPUnit\Framework\TestCase;

final class CompetitionFactoryTest extends TestCase
{
    public function testCreatesACompetitionEntityWithTheGivenName(): void
    {
        $world = new WorldState();

        $competitionId = (new CompetitionFactory())->create($world, 'Ligue Test');

        $competition = $world->components(Competition::class)->get($competitionId);
        self::assertNotNull($competition);
        self::assertSame('Ligue Test', $competition->name);
    }

    public function testDefaultsToASyntheticName(): void
    {
        $world = new WorldState();

        $competitionId = (new CompetitionFactory())->create($world);

        self::assertNotNull($world->components(Competition::class)->get($competitionId));
    }
}
