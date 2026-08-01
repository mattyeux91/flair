<?php

declare(strict_types=1);

namespace Flair\Harness\Tests\Metrics;

use Flair\Harness\Metrics\Sampler;
use Flair\Harness\Population\PopulationFactory;
use Flair\Harness\Population\PopulationSpec;
use Flair\Kernel\Core\Ecs\WorldState;
use Flair\Kernel\Core\Ruleset\Ruleset;
use PHPUnit\Framework\TestCase;

final class SamplerTest extends TestCase
{
    /**
     * Sans clubs, YouthIntakeSystem n'a personne a promouvoir (cf. docblock
     * ClubFactory) : la population ne peut que decliner par retraite. Avec
     * des clubs, elle doit croitre au-dela de ce plancher - la preuve que
     * Sampler suit bien les promotions en cours de run (cf. son docblock),
     * pas seulement que YouthIntakeSystem tourne quelque part dans le
     * kernel.
     */
    public function testPopulationGrowsThroughYouthIntakeWhenClubsExist(): void
    {
        $ruleset = new Ruleset('test');
        $years = 15;

        $withClubs = $this->finalPopulation(new PopulationSpec(playerCount: 40, years: $years, seed: 99, clubCount: 6), $ruleset);
        $withoutClubs = $this->finalPopulation(new PopulationSpec(playerCount: 40, years: $years, seed: 99, clubCount: 0), $ruleset);

        self::assertGreaterThan($withoutClubs, $withClubs);
    }

    public function testFinalAgeHistogramCountsMatchTheFinalYearPopulation(): void
    {
        $ruleset = new Ruleset('test');
        $spec = new PopulationSpec(playerCount: 30, years: 10, seed: 7, clubCount: 4);

        $world = new WorldState();
        $playerIds = (new PopulationFactory())->populate($world, $spec);
        $result = (new Sampler())->run($world, $playerIds, $spec->years, $spec->seed, $ruleset);

        self::assertSame($result->populationByYear[$spec->years], array_sum($result->finalAgeHistogram));
    }

    private function finalPopulation(PopulationSpec $spec, Ruleset $ruleset): int
    {
        $world = new WorldState();
        $playerIds = (new PopulationFactory())->populate($world, $spec);
        $result = (new Sampler())->run($world, $playerIds, $spec->years, $spec->seed, $ruleset);

        return $result->populationByYear[$spec->years] ?? 0;
    }
}
