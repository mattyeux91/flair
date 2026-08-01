#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Simule une population synthetique de joueurs et affiche des metriques
 * agregees (courbes de competence par age, distribution des ages de
 * retraite) - reponse au constat que bin/demo.php (4 joueurs) est trop petit
 * pour juger si AgingSystem/AgingBalance sont plausibles : le bruit
 * stochastique y noie l'effet de chaque parametre.
 *
 * Usage :
 *   php bin/aggregate.php --players=500 --years=40 --seed=42
 *   php bin/aggregate.php --players=500 --years=40 --seed=42 \
 *       --compare-field=retirementFragilityWeight --compare-value=0.30
 */

require __DIR__ . '/../vendor/autoload.php';

use Flair\Harness\Comparison\PairedSeedComparison;
use Flair\Harness\Comparison\RulesetOverride;
use Flair\Harness\Metrics\Sampler;
use Flair\Harness\Population\PopulationFactory;
use Flair\Harness\Report\TextReport;
use Flair\Kernel\Core\Ecs\WorldState;
use Flair\Kernel\Core\Ruleset\Ruleset;

$options = getopt('', ['players:', 'years:', 'seed:', 'compare-field:', 'compare-value:']);

$players = (int) ($options['players'] ?? 500);
$years = (int) ($options['years'] ?? 40);
$seed = (int) ($options['seed'] ?? 42);

$baselineRuleset = new Ruleset('harness');
$report = new TextReport();

if (isset($options['compare-field'], $options['compare-value'])) {
    $modifiedRuleset = RulesetOverride::agingField($baselineRuleset, (string) $options['compare-field'], (float) $options['compare-value']);

    $comparison = new PairedSeedComparison();
    $results = $comparison->compare($players, $years, $seed, $baselineRuleset, $modifiedRuleset);

    echo $report->renderComparison($results['baseline'], $results['modified']);
    exit(0);
}

$world = new WorldState();
$playerIds = (new PopulationFactory())->populate($world, $players, $seed);
$result = (new Sampler())->run($world, $playerIds, $years, $seed, $baselineRuleset);

echo $report->render($result);
