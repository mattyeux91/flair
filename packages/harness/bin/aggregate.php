#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Simule une population synthetique de joueurs (repartie sur des clubs
 * synthetiques) et affiche des metriques agregees (courbes de competence
 * par age, distribution des ages de retraite, effectif actif par annee,
 * pyramide des ages) - reponse au constat que bin/demo.php (4 joueurs) est
 * trop petit pour juger si PlayerDevelopmentSystem/RetirementSystem/
 * YouthIntakeSystem/TrainingSystem et leurs Balance sont plausibles : le
 * bruit stochastique y noie l'effet de chaque parametre.
 *
 * `--set champ=valeur` est repetable et couvre tous les champs de
 * `Ruleset::$balance` (cf. Comparison\RulesetOverride::ALL_FIELDS) - pas
 * seulement les 9 champs de vieillissement de la premiere version de cet
 * outil. Des que `--set` est fourni au moins une fois, la commande bascule
 * en comparaison a graines appariees (baseline vs modifie) ; sans `--set`,
 * simple rapport baseline.
 *
 * Usage :
 *   php bin/aggregate.php --players=500 --years=40 --seed=42
 *   php bin/aggregate.php --players=500 --years=40 --seed=42 --clubs=18 \
 *       --set retirementFragilityWeight=0.30 --set trainingRate=1.5
 *
 * Deux experiences distinctes autour de la perception, a ne pas confondre :
 *   --set baseErrorPoints=0            perception contre omniscience, meme
 *                                      population : c'est bien une comparaison
 *                                      a graines appariees.
 *   --scout-judgement-spread=0         tous les scouts egaux : change la
 *                                      population, donc deux runs separes.
 *
 * Pas de plafond de taille ici (contrairement a public/index.php) : le CLI
 * n'a pas de contrainte requete/reponse HTTP. Pour un run plus rapide sur de
 * gros volumes, `opcache.jit` (desactive par defaut en CLI) donne ~2,5x sans
 * rien changer au resultat - verifie bit a bit identique interprete vs JIT
 * sur le meme seed :
 *   php -d opcache.enable_cli=1 -d opcache.jit_buffer_size=64M \
 *       -d opcache.jit=tracing bin/aggregate.php --players=2000 --years=50
 */

require __DIR__ . '/../vendor/autoload.php';

use Flair\Harness\Comparison\PairedSeedComparison;
use Flair\Harness\Comparison\RulesetOverride;
use Flair\Harness\Metrics\EventGraphCollector;
use Flair\Harness\Metrics\Sampler;
use Flair\Harness\Population\PopulationFactory;
use Flair\Harness\Population\PopulationSpec;
use Flair\Harness\Report\TextReport;
use Flair\Kernel\Core\Ecs\WorldState;
use Flair\Kernel\Core\Ruleset\Ruleset;

$options = getopt('', ['players:', 'years:', 'seed:', 'clubs:', 'facilities-quality:', 'scout-judgement-spread:', 'set:', 'event-graph']);

// `--scout-judgement-spread` n'est **pas** un `--set` : il change la population
// (le jugement des scouts est une donnee du monde, pas un levier de Ruleset -
// cf. PopulationSpec), donc il ne peut pas etre compare a graines appariees.
// L'experience "tous les scouts egaux" est deux runs, a lire cote a cote.
$spec = new PopulationSpec(
    playerCount: (int) ($options['players'] ?? 500),
    years: (int) ($options['years'] ?? 40),
    seed: (int) ($options['seed'] ?? 42),
    clubCount: (int) ($options['clubs'] ?? 18),
    facilitiesQuality: (float) ($options['facilities-quality'] ?? 1.0),
    scoutJudgementSpread: (int) ($options['scout-judgement-spread'] ?? 25),
);

$baselineRuleset = new Ruleset('harness');
$report = new TextReport();

// getopt() rend une chaine pour une seule occurrence de --set et un tableau des la deuxieme - on normalise toujours en tableau.
$rawSet = $options['set'] ?? [];
if (!\is_array($rawSet)) {
    $rawSet = [$rawSet];
}

/** @var array<string, float> $overrides */
$overrides = [];
foreach ($rawSet as $entry) {
    if (!\is_string($entry) || !str_contains($entry, '=')) {
        $entryLabel = \is_scalar($entry) ? (string) $entry : json_encode($entry);
        fwrite(STDERR, "Format invalide pour --set (attendu champ=valeur) : {$entryLabel}\n");
        exit(1);
    }

    [$field, $value] = explode('=', $entry, 2);
    if (!is_numeric($value)) {
        fwrite(STDERR, "Valeur non numerique pour --set {$field} : {$value}\n");
        exit(1);
    }

    $overrides[$field] = (float) $value;
}

if ($overrides !== []) {
    try {
        $modifiedRuleset = RulesetOverride::withFields($baselineRuleset, $overrides);
    } catch (\InvalidArgumentException $e) {
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(1);
    }

    $comparison = new PairedSeedComparison();
    $results = $comparison->compare($spec, $baselineRuleset, $modifiedRuleset);

    echo $report->renderComparison($results['baseline'], $results['modified']);
    exit(0);
}

$eventGraph = array_key_exists('event-graph', $options) ? new EventGraphCollector() : null;

$world = new WorldState();
$playerIds = (new PopulationFactory())->populate($world, $spec);
$result = (new Sampler())->run($world, $playerIds, $spec->years, $spec->seed, $baselineRuleset, $eventGraph);

echo $report->render($result);
if ($eventGraph !== null) {
    echo $report->renderEventGraph($eventGraph->snapshot());
}
