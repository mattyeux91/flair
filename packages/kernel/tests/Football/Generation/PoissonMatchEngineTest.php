<?php

declare(strict_types=1);

namespace Flair\Kernel\Tests\Football\Generation;

use Flair\Kernel\Core\Ruleset\MatchBalance;
use Flair\Kernel\Core\Support\Rng;
use Flair\Kernel\Football\Generation\PoissonMatchEngine;
use PHPUnit\Framework\TestCase;

final class PoissonMatchEngineTest extends TestCase
{
    private const RATING = 50.0;

    public function testIsDeterministicForAGivenSeed(): void
    {
        $engine = new PoissonMatchEngine();
        $balance = new MatchBalance();

        $play = static fn (): array => (function () use ($engine, $balance): array {
            $score = $engine->play(new Rng(42), 60.0, 40.0, 45.0, 55.0, $balance);

            return [$score->homeGoals, $score->awayGoals];
        })();

        self::assertSame($play(), $play());
    }

    public function testMeanGoalsApproximatesLambdaForEvenlyMatchedTeamsWithoutHomeAdvantage(): void
    {
        $engine = new PoissonMatchEngine();
        $balance = new MatchBalance(homeAdvantage: 0.0);

        $totalHome = 0;
        $totalAway = 0;
        $draws = 3000;

        for ($i = 0; $i < $draws; $i++) {
            $score = $engine->play(Rng::forStream(1, 0, 'test', $i), self::RATING, self::RATING, self::RATING, self::RATING, $balance);
            $totalHome += $score->homeGoals;
            $totalAway += $score->awayGoals;
        }

        // λ_home = λ_away = exp(0) = 1.0 quand les deux equipes sont identiques et sans avantage du terrain.
        self::assertEqualsWithDelta(1.0, $totalHome / $draws, 0.1);
        self::assertEqualsWithDelta(1.0, $totalAway / $draws, 0.1);
    }

    public function testHomeAdvantageIncreasesExpectedHomeGoals(): void
    {
        $engine = new PoissonMatchEngine();
        $balance = new MatchBalance(homeAdvantage: 0.4);

        $totalHome = 0;
        $totalAway = 0;
        $draws = 3000;

        for ($i = 0; $i < $draws; $i++) {
            $score = $engine->play(Rng::forStream(1, 0, 'test', $i), self::RATING, self::RATING, self::RATING, self::RATING, $balance);
            $totalHome += $score->homeGoals;
            $totalAway += $score->awayGoals;
        }

        self::assertGreaterThan($totalAway / $draws, $totalHome / $draws);
    }

    public function testAStrongerAttackAgainstAWeakerDefenseScoresMoreOnAverage(): void
    {
        $engine = new PoissonMatchEngine();
        $balance = new MatchBalance(homeAdvantage: 0.0);

        $strongTotal = 0;
        $weakTotal = 0;
        $draws = 3000;

        for ($i = 0; $i < $draws; $i++) {
            $rng = Rng::forStream(1, 0, 'test', $i);
            $score = $engine->play($rng, 80.0, self::RATING, 30.0, self::RATING, $balance);
            $strongTotal += $score->homeGoals;
            $weakTotal += $score->awayGoals;
        }

        self::assertGreaterThan($weakTotal / $draws, $strongTotal / $draws);
    }

    /**
     * La correction de Dixon-Coles avec `ρ < 0` (docs/14- §1) doit augmenter
     * la masse sur le 1-1 par rapport a un Poisson independant (`ρ = 0`) -
     * c'est exactement le defaut qu'elle corrige. Graines appariees
     * (memes flux RNG pour les deux reglages) pour isoler l'effet du bruit,
     * meme technique que le harness (docs/13- §4.0).
     */
    public function testNegativeLowScoreCorrelationIncreasesDrawsOnOneOne(): void
    {
        $engine = new PoissonMatchEngine();
        $independent = new MatchBalance(homeAdvantage: 0.0, lowScoreCorrelation: 0.0);
        $corrected = new MatchBalance(homeAdvantage: 0.0, lowScoreCorrelation: -0.3);

        $independentOneOne = 0;
        $correctedOneOne = 0;
        $draws = 4000;

        for ($i = 0; $i < $draws; $i++) {
            $independentScore = $engine->play(Rng::forStream(1, 0, 'test', $i), self::RATING, self::RATING, self::RATING, self::RATING, $independent);
            $correctedScore = $engine->play(Rng::forStream(1, 0, 'test', $i), self::RATING, self::RATING, self::RATING, self::RATING, $corrected);

            if ($independentScore->homeGoals === 1 && $independentScore->awayGoals === 1) {
                $independentOneOne++;
            }

            if ($correctedScore->homeGoals === 1 && $correctedScore->awayGoals === 1) {
                $correctedOneOne++;
            }
        }

        self::assertGreaterThan($independentOneOne, $correctedOneOne);
    }
}
