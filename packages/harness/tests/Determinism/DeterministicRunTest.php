<?php

declare(strict_types=1);

namespace Flair\Harness\Tests\Determinism;

use Flair\Harness\Population\PopulationSpec;
use Flair\Harness\Simulation\StepRunner;
use Flair\Harness\Support\WorldHasher;
use Flair\Kernel\Core\Ecs\WorldState;
use Flair\Kernel\Core\Ruleset\Ruleset;
use Flair\Worldgen\WorldFactory;
use PHPUnit\Framework\TestCase;

/**
 * Critere de sortie Phase 1 (docs/15- §4) : meme graine -> meme hash d'etat
 * ET meme hash de sequence d'evenements, sur le pipeline football complet
 * (pas seulement les vecteurs figes de Rng/Hash isolement, deja couverts
 * cote kernel). Population volontairement petite pour rester rapide et
 * faire partie de la suite par defaut, pas d'une suite "lente".
 */
final class DeterministicRunTest extends TestCase
{
    private const int SEED = 42;

    public function testSameSeedProducesTheSameStateHashAndEventSequence(): void
    {
        $a = $this->runOnce(self::SEED);
        $b = $this->runOnce(self::SEED);

        self::assertNotEmpty($a['eventsHash']);
        self::assertSame($a['stateHash'], $b['stateHash']);
        self::assertSame($a['eventsHash'], $b['eventsHash']);
    }

    /** @return array{stateHash: string, eventsHash: string} */
    private function runOnce(int $seed): array
    {
        $spec = new PopulationSpec(playerCount: 40, years: 3, seed: $seed, clubCount: 4);
        $ruleset = new Ruleset('determinism-test');

        $world = new WorldState();
        (new WorldFactory())->populate($world, $spec->world());

        $runner = new StepRunner($world, $ruleset, $seed);
        $events = $runner->advance($spec->years * 365);

        return [
            'stateHash' => WorldHasher::hashWorld($world),
            'eventsHash' => WorldHasher::hashEventSequence($events),
        ];
    }
}
