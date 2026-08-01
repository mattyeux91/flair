<?php

declare(strict_types=1);

namespace Flair\Harness\Tests\Metrics;

use Flair\Harness\Metrics\DeltaCurveBuilder;
use Flair\Harness\Metrics\SkillSample;
use PHPUnit\Framework\TestCase;

final class DeltaCurveBuilderTest extends TestCase
{
    public function testChainedCurveIsNotFooledByEarlyDropoutUnlikeARawCrossSectionalMean(): void
    {
        // Joueur 1 : decline regulier (-2/an) et reste jusqu'a 22 ans.
        // Joueur 2 : decline vite (-10/an) puis "part en retraite" (plus
        // aucun releve) apres 21 ans - un decrochage precoce qui, en coupe
        // transversale brute, ferait remonter la moyenne a 22 ans (46, contre
        // 44 a 21 ans) alors qu'aucun des deux joueurs n'a jamais regagne de
        // competence individuellement.
        $samples = [
            new SkillSample(1, 20.0, 'physical', 50.0),
            new SkillSample(1, 21.0, 'physical', 48.0),
            new SkillSample(1, 22.0, 'physical', 46.0),
            new SkillSample(2, 20.0, 'physical', 50.0),
            new SkillSample(2, 21.0, 'physical', 40.0),
        ];

        $result = DeltaCurveBuilder::build($samples);

        self::assertEqualsWithDelta(50.0, $result['chainedCurves']['physical'][20], 0.0001);
        self::assertEqualsWithDelta(44.0, $result['chainedCurves']['physical'][21], 0.0001);
        self::assertEqualsWithDelta(42.0, $result['chainedCurves']['physical'][22], 0.0001);

        // La courbe corrigee decline de facon monotone - pas de remontee
        // artificielle contrairement a ce qu'une moyenne brute produirait ici.
        self::assertLessThan($result['chainedCurves']['physical'][20], $result['chainedCurves']['physical'][21]);
        self::assertLessThan($result['chainedCurves']['physical'][21], $result['chainedCurves']['physical'][22]);
    }

    public function testDeltaBucketsAggregateMeanAndCountPerStartingAge(): void
    {
        $samples = [
            new SkillSample(1, 20.0, 'physical', 50.0),
            new SkillSample(1, 21.0, 'physical', 48.0),
            new SkillSample(2, 20.0, 'physical', 50.0),
            new SkillSample(2, 21.0, 'physical', 40.0),
        ];

        $result = DeltaCurveBuilder::build($samples);

        self::assertSame(['meanDelta' => -6.0, 'count' => 2], $result['deltaCurves']['physical'][20]);
    }

    public function testChainStopsWhereTransitionsRunOutRatherThanExtrapolating(): void
    {
        $samples = [
            new SkillSample(1, 20.0, 'physical', 50.0),
            new SkillSample(1, 21.0, 'physical', 48.0),
            new SkillSample(1, 22.0, 'physical', 46.0),
        ];

        $result = DeltaCurveBuilder::build($samples);

        self::assertSame([20, 21, 22], array_keys($result['chainedCurves']['physical']));
    }

    public function testPlayerWithASingleSampleContributesNoDelta(): void
    {
        $samples = [
            new SkillSample(1, 20.0, 'physical', 50.0),
        ];

        $result = DeltaCurveBuilder::build($samples);

        self::assertSame([], $result['deltaCurves']);
    }

    public function testCategoriesAreKeptIndependent(): void
    {
        $samples = [
            new SkillSample(1, 20.0, 'physical', 50.0),
            new SkillSample(1, 21.0, 'physical', 45.0),
            new SkillSample(1, 20.0, 'mental', 50.0),
            new SkillSample(1, 21.0, 'mental', 52.0),
        ];

        $result = DeltaCurveBuilder::build($samples);

        self::assertEqualsWithDelta(-5.0, $result['deltaCurves']['physical'][20]['meanDelta'], 0.0001);
        self::assertEqualsWithDelta(2.0, $result['deltaCurves']['mental'][20]['meanDelta'], 0.0001);
    }
}
