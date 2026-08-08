<?php

declare(strict_types=1);

namespace Flair\Kernel\Tests\Football\Support;

use Flair\Kernel\Core\Ruleset\PerceptionBalance;
use Flair\Kernel\Core\Support\Hash;
use Flair\Kernel\Football\Support\PerceptionModel;
use PHPUnit\Framework\TestCase;

final class PerceptionModelTest extends TestCase
{
    private const SAMPLE_SIZE = 4000;
    private const TRUE_VALUE = 50;

    /**
     * L'interrupteur du lot : a erreur de base nulle, tout observateur lit la
     * verite, exactement. C'est ce qui rend la desactivation de la perception
     * une reduction stricte au comportement d'avant le lot, et pas une
     * approximation a l'arrondi pres.
     */
    public function testZeroBaseErrorReturnsTheTruthExactly(): void
    {
        $balance = new PerceptionBalance(baseErrorPoints: 0.0);

        foreach ([1, 37, 50, 99, 100] as $trueValue) {
            for ($i = 0; $i < 100; $i++) {
                self::assertSame(
                    $trueValue,
                    PerceptionModel::estimate($trueValue, $this->noise($i), observationYears: 0, judgement: 20, balance: $balance),
                );
            }
        }

        self::assertSame(0.0, PerceptionModel::sigma(observationYears: 0, judgement: 50, balance: $balance));
    }

    public function testSameInputsAlwaysProduceTheSameEstimate(): void
    {
        $balance = new PerceptionBalance();
        $noise = $this->noise(7);

        self::assertSame(
            PerceptionModel::estimate(self::TRUE_VALUE, $noise, observationYears: 2, judgement: 55, balance: $balance),
            PerceptionModel::estimate(self::TRUE_VALUE, $noise, observationYears: 2, judgement: 55, balance: $balance),
        );
    }

    /**
     * La propriete que le lot achete : un meilleur staff se trompe moins - y
     * compris sur un joueur jamais observe (`observationYears: 0`), ce qui est
     * l'ecart assume avec l'esquisse de docs/12- §4.
     */
    public function testBetterJudgementLowersTheErrorEvenWithoutAnyObservation(): void
    {
        $balance = new PerceptionBalance();

        $poor = $this->meanAbsoluteError(observationYears: 0, judgement: 20, balance: $balance);
        $median = $this->meanAbsoluteError(observationYears: 0, judgement: 50, balance: $balance);
        $excellent = $this->meanAbsoluteError(observationYears: 0, judgement: 85, balance: $balance);

        self::assertGreaterThan($median, $poor);
        self::assertGreaterThan($excellent, $median);
    }

    public function testMoreObservationLowersTheError(): void
    {
        $balance = new PerceptionBalance();

        $unknown = $this->meanAbsoluteError(observationYears: 0, judgement: 50, balance: $balance);
        $twoYears = $this->meanAbsoluteError(observationYears: 2, judgement: 50, balance: $balance);
        $tenYears = $this->meanAbsoluteError(observationYears: 10, judgement: 50, balance: $balance);

        self::assertGreaterThan($twoYears, $unknown);
        self::assertGreaterThan($tenYears, $twoYears);
    }

    /**
     * Un mauvais staff sur un joueur maison ne doit pas voir mieux qu'un bon
     * staff sur une recrue : sans quoi le recrutement exterieur serait toujours
     * le parent pauvre de la decision, quel que soit le staff.
     */
    public function testJudgementAndObservationCompose(): void
    {
        $balance = new PerceptionBalance();

        self::assertGreaterThan(
            PerceptionModel::sigma(observationYears: 0, judgement: 85, balance: $balance),
            PerceptionModel::sigma(observationYears: 1, judgement: 20, balance: $balance),
        );
    }

    public function testTheErrorIsCenteredOnTheTruth(): void
    {
        $balance = new PerceptionBalance();
        $sum = 0;

        for ($i = 0; $i < self::SAMPLE_SIZE; $i++) {
            $sum += PerceptionModel::estimate(self::TRUE_VALUE, $this->noise($i), observationYears: 0, judgement: 50, balance: $balance);
        }

        // Aucun biais systematique : un scout se trompe, il ne surestime pas.
        self::assertEqualsWithDelta(self::TRUE_VALUE, $sum / self::SAMPLE_SIZE, 0.5);
    }

    public function testEstimatesStayOnTheAbsoluteOneToHundredScale(): void
    {
        $balance = new PerceptionBalance(baseErrorPoints: 40.0);

        for ($i = 0; $i < self::SAMPLE_SIZE; $i++) {
            foreach ([1, 100] as $trueValue) {
                $estimate = PerceptionModel::estimate($trueValue, $this->noise($i), observationYears: 0, judgement: 1, balance: $balance);
                self::assertGreaterThanOrEqual(PerceptionModel::MIN_ESTIMATE, $estimate);
                self::assertLessThanOrEqual(PerceptionModel::MAX_ESTIMATE, $estimate);
            }
        }
    }

    /**
     * Un `Ruleset` aberrant ne doit ni diviser par zero ni faire exploser
     * sigma : clamp defensif au consommateur, jamais d'exception dans le noyau.
     */
    public function testAbsurdRulesetValuesStayBounded(): void
    {
        $balance = new PerceptionBalance(baseErrorPoints: 10.0, judgementReference: 0, unstaffedJudgement: 0);

        self::assertGreaterThan(0.0, PerceptionModel::sigma(observationYears: -5, judgement: -50, balance: $balance));
        self::assertLessThanOrEqual(100.0, PerceptionModel::sigma(observationYears: 0, judgement: 0, balance: $balance));
    }

    private function meanAbsoluteError(int $observationYears, int $judgement, PerceptionBalance $balance): float
    {
        $total = 0;

        for ($i = 0; $i < self::SAMPLE_SIZE; $i++) {
            $total += abs(PerceptionModel::estimate(
                self::TRUE_VALUE,
                $this->noise($i),
                $observationYears,
                $judgement,
                $balance,
            ) - self::TRUE_VALUE);
        }

        return $total / self::SAMPLE_SIZE;
    }

    /** Un bruit de la meme forme que celui produit par `SystemContext::stableHash()`. */
    private function noise(int $subjectId): int
    {
        return Hash::mixAll(777, 3, $subjectId, 0);
    }
}
