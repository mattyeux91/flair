#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Le Host, en ligne de commande (docs/13- §8).
 *
 * Un script PHP nu plutot qu'une application console : c'est l'idiome deja
 * etabli du repo (`harness/bin/aggregate.php`, `harness/bin/sandbox.php`), et
 * un framework console n'aurait ici aucun second consommateur. Laravel est
 * present ou il travaille - `illuminate/database` pour la connexion, le schema
 * et les transactions.
 *
 * Usage :
 *   php bin/host.php install                        cree les tables
 *   php bin/host.php create <monde> [--players=500] [--clubs=18] [--seed=42]
 *   php bin/host.php advance <monde> [--ticks=1]    avance, un tick par transaction
 *   php bin/host.php destroy <monde> --force        efface un monde, sans retour
 *   php bin/host.php status [<monde>]               ou en sont les mondes
 *   php bin/host.php events <monde> [--limit=20]    les derniers Faits
 *
 * `advance --ticks=N` reste **N transactions successives**, jamais une grosse.
 * Le cron n'en demandera qu'une a la fois ; l'option ne sert qu'au rattrapage
 * et au developpement, et elle ne doit pas changer les proprietes de reprise.
 */

require __DIR__ . '/../vendor/autoload.php';

use Flair\Host\AdvanceOutcome;
use Flair\Host\AdvanceWorld;
use Flair\Host\CreateWorld;
use Flair\Host\Database\Database;
use Flair\Host\Database\Schema;
use Flair\Host\DestroyWorld;
use Flair\Host\Store\EventStore;
use Flair\Host\Store\SnapshotStore;
use Flair\Host\Store\WorldRepository;
use Flair\Host\WorldLock;
use Flair\Kernel\Football\FootballTypes;
use Flair\Worldgen\WorldSpec;

/** @var list<string> $arguments */
$arguments = array_values(array_filter(
    is_array($_SERVER['argv'] ?? null) ? $_SERVER['argv'] : [],
    is_string(...),
));

$command = $arguments[1] ?? 'help';
$target = isset($arguments[2]) && !str_starts_with($arguments[2], '--') ? $arguments[2] : null;

// `--cle=valeur` et `--drapeau` nu, ce dernier valant '1' : `--force` n'a pas
// de valeur a porter, et l'exiger sous la forme `--force=1` serait une
// bizarrerie a retenir pour rien.
$options = [];
foreach (array_slice($arguments, 2) as $argument) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $argument, $matches) === 1) {
        $options[$matches[1]] = $matches[2] ?? '1';
    }
}

$database = Database::fromEnvironment();
$worlds = new WorldRepository($database);
$snapshots = new SnapshotStore($database);
$events = new EventStore($database, FootballTypes::registry());

try {
    exit(run($command, $target, $options, $database, $worlds, $snapshots, $events));
} catch (Throwable $error) {
    fwrite(STDERR, 'Erreur : ' . $error->getMessage() . PHP_EOL);
    exit(1);
}

/** @param array<string, string> $options */
function run(
    string $command,
    ?string $target,
    array $options,
    Database $database,
    WorldRepository $worlds,
    SnapshotStore $snapshots,
    EventStore $events,
): int {
    switch ($command) {
        case 'install':
            (new Schema($database))->install();
            echo "Schema installe (worlds, events, snapshots)." . PHP_EOL;

            return 0;

        case 'create':
            if ($target === null) {
                fwrite(STDERR, 'Usage : host.php create <monde> [--players=500] [--clubs=18] [--seed=42]' . PHP_EOL);

                return 1;
            }

            $spec = new WorldSpec(
                playerCount: (int) ($options['players'] ?? 500),
                seed: (int) ($options['seed'] ?? 42),
                clubCount: (int) ($options['clubs'] ?? 18),
            );

            $record = (new CreateWorld($database, $worlds, $snapshots))($target, $spec);
            printf(
                "Monde \"%s\" cree au tick 0 : %d joueurs, %d clubs, graine %d, noyau %s.%s",
                $record->id,
                $spec->playerCount,
                $spec->clubCount,
                $record->seed,
                $record->kernelVersion,
                PHP_EOL,
            );

            return 0;

        case 'advance':
            if ($target === null) {
                fwrite(STDERR, 'Usage : host.php advance <monde> [--ticks=1]' . PHP_EOL);

                return 1;
            }

            $advance = new AdvanceWorld($database, $worlds, $events, $snapshots, new WorldLock($database));
            $ticks = max(1, (int) ($options['ticks'] ?? 1));
            $simulation = 0.0;
            $persistence = 0.0;
            $total = 0.0;
            $written = 0;
            $last = $advance($target);

            for ($i = 1; $i < $ticks && $last->outcome === AdvanceOutcome::Advanced; $i++) {
                $simulation += $last->simulationSeconds;
                $persistence += $last->persistenceSeconds;
                $total += $last->totalSeconds;
                $written += $last->events;
                $last = $advance($target);
            }

            if ($last->outcome === AdvanceOutcome::Advanced) {
                $simulation += $last->simulationSeconds;
                $persistence += $last->persistenceSeconds;
                $total += $last->totalSeconds;
                $written += $last->events;
            }

            if ($last->outcome === AdvanceOutcome::Unknown) {
                fwrite(STDERR, "Monde \"{$target}\" inconnu, ou sans snapshot pour le reprendre." . PHP_EOL);

                return 1;
            }

            if ($last->outcome === AdvanceOutcome::Busy) {
                echo "Monde \"{$target}\" deja en cours d'avancement par un autre processus, rien ecrit." . PHP_EOL;

                return 0;
            }

            // Le total et l'ecart sont affiches, pas seulement les deux
            // compteurs internes : ceux-la totalisaient 34,7 ms sur 48,5
            // reelles, et un chiffre de perf qui manque 29 % de son sujet
            // sert a decider de travers.
            printf(
                "Monde \"%s\" au tick %d. %d tick(s), %d Fait(s).%s"
                . "  %.1f ms/tick au total : simulation %.1f, persistance %.1f, verrou+commit %.1f.%s",
                $target,
                $last->tick,
                $ticks,
                $written,
                PHP_EOL,
                $total / $ticks * 1000,
                $simulation / $ticks * 1000,
                $persistence / $ticks * 1000,
                max(0.0, $total - $simulation - $persistence) / $ticks * 1000,
                PHP_EOL,
            );

            return 0;

        case 'destroy':
            if ($target === null) {
                fwrite(STDERR, 'Usage : host.php destroy <monde> --force' . PHP_EOL);

                return 1;
            }

            if (!$worlds->exists($target)) {
                fwrite(STDERR, "Monde \"{$target}\" inconnu." . PHP_EOL);

                return 1;
            }

            // `--force` obligatoire : c'est irreversible, et un script nu n'a
            // pas de quoi poser une question a un humain sans se rendre
            // inutilisable dans un pipe.
            if (!isset($options['force'])) {
                fwrite(STDERR, sprintf(
                    "Refus : effacer \"%s\" detruit %d Fait(s) et tous ses snapshots, sans retour.%s"
                    . 'Relancer avec --force si c\'est bien l\'intention.%s',
                    $target,
                    $events->countFor($target),
                    PHP_EOL,
                    PHP_EOL,
                ));

                return 1;
            }

            $deleted = new DestroyWorld($database)($target);
            printf(
                "Monde \"%s\" efface : %d Fait(s), %d snapshot(s).%s",
                $target,
                $deleted['events'],
                $deleted['snapshots'],
                PHP_EOL,
            );

            return 0;

        case 'status':
            $records = $target === null
                ? $worlds->all()
                : array_filter([$worlds->find($target)]);

            if ($records === []) {
                echo 'Aucun monde.' . PHP_EOL;

                return 0;
            }

            printf("%-20s %10s %8s %10s  %s%s", 'monde', 'tick', 'graine', 'faits', 'noyau/ruleset', PHP_EOL);
            foreach ($records as $record) {
                printf(
                    "%-20s %10d %8d %10d  %s / %s%s",
                    $record->id,
                    $record->tick,
                    $record->seed,
                    $events->countFor($record->id),
                    $record->kernelVersion,
                    $record->rulesetVersion,
                    PHP_EOL,
                );
            }

            return 0;

        case 'events':
            if ($target === null) {
                fwrite(STDERR, 'Usage : host.php events <monde> [--limit=20]' . PHP_EOL);

                return 1;
            }

            foreach ($events->tail($target, (int) ($options['limit'] ?? 20)) as $event) {
                printf('tick %6d #%-3d %-45s %s%s', $event['tick'], $event['seq'], $event['type'], $event['payload'], PHP_EOL);
            }

            return 0;

        default:
            echo <<<TXT
            Host Flair - fait vivre un monde en base.

              install                                       cree les tables
              create <monde> [--players=] [--clubs=] [--seed=]
              advance <monde> [--ticks=1]
              destroy <monde> --force                       efface un monde, sans retour
              status [<monde>]
              events <monde> [--limit=20]

            Connexion par variables d'environnement (defauts = docker-compose.yml) :
              FLAIR_DB_HOST, FLAIR_DB_PORT, FLAIR_DB_NAME, FLAIR_DB_USER, FLAIR_DB_PASSWORD

            TXT;

            return 0;
    }
}
