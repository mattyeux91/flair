<?php

declare(strict_types=1);

namespace Flair\Harness\Tests\Comparison;

use Flair\Harness\Comparison\PairedSeedComparison;
use Flair\Harness\Comparison\RulesetOverride;
use Flair\Harness\Population\PopulationSpec;
use Flair\Kernel\Core\Ruleset\Ruleset;
use PHPUnit\Framework\TestCase;

final class PairedSeedComparisonTest extends TestCase
{
    /**
     * Meme graine, meme population, meme calendrier -> meme distribution
     * d'ages de retraite et meme effectif par annee quel que soit
     * `homeAdvantage` (qui ne touche aucun composant joueur). C'est ce qui
     * prouve que l'appariement des graines isole bien l'effet du parametre
     * teste, plutot que de simplement constater deux runs differents.
     */
    public function testBaselineAndModifiedShareTheSamePlayerMetrics(): void
    {
        $spec = new PopulationSpec(playerCount: 60, years: 5, seed: 123, clubCount: 6);
        $baseline = new Ruleset('test');
        $modified = RulesetOverride::withFields($baseline, ['homeAdvantage' => 0.9]);

        $results = (new PairedSeedComparison())->compare($spec, $baseline, $modified);

        self::assertSame($results['baseline']->retirementAgeHistogram, $results['modified']->retirementAgeHistogram);
        self::assertSame($results['baseline']->populationByYear, $results['modified']->populationByYear);
    }

    /**
     * L'effet inverse : un `homeAdvantage` nettement plus fort doit se voir
     * dans la repartition des resultats, sans quoi le test precedent ne
     * prouverait que l'absence d'effet, jamais l'isolation d'un effet reel.
     */
    public function testAMatchBalanceOverrideChangesTheResultDistribution(): void
    {
        $spec = new PopulationSpec(playerCount: 200, years: 8, seed: 123, clubCount: 10);
        $baseline = new Ruleset('test');
        $modified = RulesetOverride::withFields($baseline, ['homeAdvantage' => 0.9]);

        $results = (new PairedSeedComparison())->compare($spec, $baseline, $modified);

        $baselineHomeWins = $results['baseline']->matchResultDistribution['homeWin'];
        $modifiedHomeWins = $results['modified']->matchResultDistribution['homeWin'];

        self::assertGreaterThan($baselineHomeWins, $modifiedHomeWins);
    }
}
