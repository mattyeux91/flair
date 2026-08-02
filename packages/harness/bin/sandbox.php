#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * REPL interactif : construit une population synthetique (memes options que
 * bin/aggregate.php) et laisse avancer la simulation tick par tick ou par
 * tranche, avec inspection a la demande - la ou aggregate.php ne rend qu'un
 * rapport agrege apres un run complet. Un seul process CLI, WorldState en
 * memoire, aucune persistance : fermer le REPL perd le monde (cf. plan,
 * "persistance ephemere").
 *
 * Usage :
 *   php bin/sandbox.php --players=200 --seed=42 --clubs=8
 *   php bin/sandbox.php --players=200 --seed=42 --clubs=8 \
 *       --set trainingRate=1.5 --set retirementFragilityWeight=0.30
 *
 * Commandes une fois demarre : step [n], standings, player <id>, club <id>,
 * events [n], help, quit.
 */

require __DIR__ . '/../vendor/autoload.php';

use Flair\Harness\Comparison\RulesetOverride;
use Flair\Harness\Population\PopulationFactory;
use Flair\Harness\Population\PopulationSpec;
use Flair\Harness\Simulation\StepRunner;
use Flair\Harness\Support\WorldInspector;
use Flair\Kernel\Core\Ecs\WorldState;
use Flair\Kernel\Core\Messaging\DomainEvent;
use Flair\Kernel\Core\Ruleset\Ruleset;

const EVENT_LOG_CAPACITY = 200;

$options = getopt('', ['players:', 'seed:', 'clubs:', 'facilities-quality:', 'set:']);

// PopulationSpec::$years n'est consomme que par Metrics\Sampler (bornes de sa
// boucle) - PopulationFactory::populate() ne le lit jamais. Sans utilite ici,
// ou la longueur du run est dictee par les commandes `step`, pas par une
// option de depart.
$spec = new PopulationSpec(
    playerCount: (int) ($options['players'] ?? 200),
    years: 1,
    seed: (int) ($options['seed'] ?? 42),
    clubCount: (int) ($options['clubs'] ?? 8),
    facilitiesQuality: (float) ($options['facilities-quality'] ?? 1.0),
);

$baselineRuleset = new Ruleset('sandbox');

$rawSet = $options['set'] ?? [];
if (!\is_array($rawSet)) {
    $rawSet = [$rawSet];
}

/** @var array<string, float> $overrides */
$overrides = [];
foreach ($rawSet as $entry) {
    if (!\is_string($entry) || !str_contains($entry, '=')) {
        fwrite(STDERR, "Format invalide pour --set (attendu champ=valeur) : {$entry}\n");
        exit(1);
    }

    [$field, $value] = explode('=', $entry, 2);
    if (!is_numeric($value)) {
        fwrite(STDERR, "Valeur non numerique pour --set {$field} : {$value}\n");
        exit(1);
    }

    $overrides[$field] = (float) $value;
}

$ruleset = $overrides === [] ? $baselineRuleset : RulesetOverride::withFields($baselineRuleset, $overrides);

$world = new WorldState();
$playerIds = (new PopulationFactory())->populate($world, $spec);
$runner = new StepRunner($world, $ruleset, $spec->seed);

/** @var list<array{tick: int, event: DomainEvent}> $eventLog */
$eventLog = [];

fwrite(STDOUT, sprintf(
    "Monde pret : %d joueurs, %d clubs, graine %d. Tapez 'help' pour la liste des commandes.\n",
    \count($playerIds),
    $spec->clubCount,
    $spec->seed,
));

while (true) {
    fwrite(STDOUT, "\n> ");
    $line = fgets(STDIN);
    if ($line === false) {
        break;
    }

    $parts = preg_split('/\s+/', trim($line)) ?: [];
    $command = strtolower($parts[0] ?? '');
    $argument = $parts[1] ?? null;

    match ($command) {
        '' => null,
        'help' => printHelp(),
        'step' => runStep($runner, $eventLog, $argument !== null ? (int) $argument : 1),
        'standings' => printStandings($world),
        'player' => printPlayer($world, (int) $argument),
        'club' => printClub($world, (int) $argument),
        'events' => printEvents($eventLog, $argument !== null ? (int) $argument : 10),
        'quit', 'exit' => exit(0),
        default => fwrite(STDOUT, "Commande inconnue : {$command}. Tapez 'help'.\n"),
    };
}

function printHelp(): void
{
    fwrite(STDOUT, <<<TEXT
        step [n]      avance de n ticks (defaut 1)
        standings     classement courant
        player <id>   dump brut des composants du joueur <id>
        club <id>     dump brut du club <id> (identite, facilites, effectif)
        events [n]    reaffiche les n derniers evenements vus (defaut 10)
        quit          quitte le REPL
        TEXT . "\n");
}

/** @param list<array{tick: int, event: DomainEvent}> $eventLog */
function runStep(StepRunner $runner, array &$eventLog, int $ticks): void
{
    if ($ticks < 1) {
        fwrite(STDOUT, "n doit etre >= 1.\n");

        return;
    }

    $tallies = [];
    $events = $runner->advance($ticks);
    foreach ($events as $event) {
        $type = (new ReflectionClass($event))->getShortName();
        $tallies[$type] = ($tallies[$type] ?? 0) + 1;

        $eventLog[] = ['tick' => $runner->currentTick(), 'event' => $event];
        if (\count($eventLog) > EVENT_LOG_CAPACITY) {
            array_shift($eventLog);
        }
    }

    fwrite(STDOUT, sprintf("Tick courant : %d.\n", $runner->currentTick()));
    if ($tallies === []) {
        fwrite(STDOUT, "Aucun evenement emis sur cette tranche.\n");

        return;
    }

    ksort($tallies);
    foreach ($tallies as $type => $count) {
        fwrite(STDOUT, sprintf("  %-24s %d\n", $type, $count));
    }
}

function printStandings(WorldState $world): void
{
    $rows = WorldInspector::currentStandings($world);
    if ($rows === []) {
        fwrite(STDOUT, "Aucun classement disponible (pas de club, ou aucun match joue).\n");

        return;
    }

    fwrite(STDOUT, sprintf("%-24s  %3s  %3s  %3s  %3s  %5s  %5s  %4s\n", 'club', 'J', 'G', 'N', 'P', 'BP', 'BC', 'Pts'));
    foreach ($rows as $row) {
        fwrite(STDOUT, sprintf(
            "%-24s  %3d  %3d  %3d  %3d  %5d  %5d  %4d\n",
            $row['clubName'],
            $row['played'],
            $row['won'],
            $row['drawn'],
            $row['lost'],
            $row['goalsFor'],
            $row['goalsAgainst'],
            $row['points'],
        ));
    }
}

function printPlayer(WorldState $world, int $playerId): void
{
    $player = WorldInspector::player($world, $playerId);
    if ($player === null) {
        fwrite(STDOUT, "Aucun joueur avec l'id {$playerId} (retraite, jamais existe, ou id de club).\n");

        return;
    }

    fwrite(STDOUT, var_export($player, true) . "\n");
}

function printClub(WorldState $world, int $clubId): void
{
    $club = WorldInspector::club($world, $clubId);
    if ($club === null) {
        fwrite(STDOUT, "Aucun club avec l'id {$clubId}.\n");

        return;
    }

    fwrite(STDOUT, var_export($club, true) . "\n");
}

/** @param list<array{tick: int, event: DomainEvent}> $eventLog */
function printEvents(array $eventLog, int $count): void
{
    if ($eventLog === []) {
        fwrite(STDOUT, "Aucun evenement observe pour l'instant - avancez d'abord avec 'step'.\n");

        return;
    }

    $slice = \array_slice($eventLog, -max(1, $count));
    foreach ($slice as $entry) {
        $type = (new ReflectionClass($entry['event']))->getShortName();
        $properties = get_object_vars($entry['event']);
        fwrite(STDOUT, sprintf("[tick %d] %s %s\n", $entry['tick'], $type, json_encode($properties)));
    }
}
