#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Prototype papier/CLI de la boucle d'agent (docs/14-algorithmes.md §5,
 * docs/15-roadmap.md Phase 5) : U(agent) = commission + satisfaction_client
 * + gain_de_reputation - cout_temps. Incarne un seul client sur plusieurs
 * fenetres de mercato successives - voir README.md pour ce qu'il faut
 * observer en jouant.
 *
 * Volontairement hors du monorepo Composer (pas de namespace Flair\, pas de
 * dependance au kernel) : jetable, pour trancher une question de game
 * design avant d'ecrire la moindre ligne du vrai client (cf. CLAUDE.md).
 */

require __DIR__ . '/src/Client.php';
require __DIR__ . '/src/GameState.php';
require __DIR__ . '/src/ClubOffer.php';
require __DIR__ . '/src/Outcome.php';
require __DIR__ . '/src/NegotiationWindow.php';

const NB_WINDOWS = 4;
const ACTIONS_PER_WINDOW = 2;
const FIRE_THRESHOLD = 0.2;

$options = getopt('', ['seed:']);
if (isset($options['seed']) && is_numeric($options['seed'])) {
    mt_srand((int) $options['seed']);
}

$client = new Client(name: 'M. Diallo', position: 'Milieu offensif', skill: 68, age: 21);
$state = new GameState();

echo "=== Agent de joueurs - prototype ===\n";
echo "Client : {$client->name} ({$client->position}, {$client->age} ans, niveau {$client->skill}/100)\n";

for ($window = 1; $window <= NB_WINDOWS; $window++) {
    if ($state->clientSatisfaction < FIRE_THRESHOLD) {
        break;
    }

    echo "\n--- Fenetre de mercato {$window}/" . NB_WINDOWS . " ---\n";
    printState($state);

    $offers = NegotiationWindow::generate($state);
    $actionsLeft = ACTIONS_PER_WINDOW;
    $accepted = null;

    while (true) {
        printOffers($offers);
        echo "Actions restantes : {$actionsLeft}\n";
        echo "Commandes : [numero] accepter | n<numero> negocier | s<numero> se renseigner | p passer\n> ";

        $raw = fgets(STDIN);
        if ($raw === false) {
            echo "\nFin des entrees, arret de la partie.\n";
            exit(0);
        }

        $line = trim($raw);

        if ($line === 'p') {
            break;
        }

        if (preg_match('/^n(\d+)$/', $line, $matches) === 1) {
            $index = ((int) $matches[1]) - 1;
            if (!isset($offers[$index])) {
                echo "Offre inconnue.\n";
                continue;
            }
            if ($actionsLeft <= 0) {
                echo "Plus d'actions disponibles cette fenetre.\n";
                continue;
            }
            $actionsLeft--;
            if (NegotiationWindow::negotiate($offers[$index])) {
                echo "Negociation reussie : {$offers[$index]->clubName} ameliore son offre.\n";
            } else {
                echo "Negociation refusee par {$offers[$index]->clubName}.\n";
            }
            continue;
        }

        if (preg_match('/^s(\d+)$/', $line, $matches) === 1) {
            $index = ((int) $matches[1]) - 1;
            if (!isset($offers[$index])) {
                echo "Offre inconnue.\n";
                continue;
            }
            if ($actionsLeft <= 0) {
                echo "Plus d'actions disponibles cette fenetre.\n";
                continue;
            }
            $actionsLeft--;
            $offers[$index]->scouted = true;
            echo "Temps de jeu reel chez {$offers[$index]->clubName} : {$offers[$index]->displayPlayingTime()}\n";
            continue;
        }

        if (ctype_digit($line) && $line !== '') {
            $index = ((int) $line) - 1;
            if (!isset($offers[$index])) {
                echo "Offre inconnue.\n";
                continue;
            }
            $accepted = $offers[$index];
            break;
        }

        echo "Commande non reconnue.\n";
    }

    if ($accepted !== null) {
        $outcome = resolveOutcome($client, $accepted);
        applyOutcome($state, $accepted, $outcome);
        narrate($client, $state, $accepted, $outcome);
    } else {
        $state->clientSatisfaction -= 0.05;
        $state->addLog("Fenetre {$window} : aucun placement (passee).");
        echo "Fenetre passee sans placement. {$client->name} s'impatiente un peu.\n";
    }

    $state->clampMeters();

    if ($state->clientSatisfaction < FIRE_THRESHOLD) {
        echo "\n{$client->name} n'a plus confiance en vous et change d'agent.\n";
    }
}

recap($client, $state);

function printState(GameState $state): void
{
    printf(
        "Reputation agent : %d%% | Satisfaction client : %d%% | Commission cumulee : %d EUR\n",
        (int) round($state->agentReputation * 100),
        (int) round($state->clientSatisfaction * 100),
        $state->totalCommission,
    );
}

/** @param list<ClubOffer> $offers */
function printOffers(array $offers): void
{
    foreach ($offers as $i => $offer) {
        printf(
            "%d. %-20s [%-32s] salaire %6d EUR/an, temps de jeu %-24s, prestige %3d%%, commission %6d EUR%s\n",
            $i + 1,
            $offer->clubName,
            $offer->profile,
            $offer->annualSalary,
            $offer->displayPlayingTime(),
            (int) round($offer->prestige * 100),
            $offer->commissionOffered,
            $offer->negotiated ? ' (negocie)' : '',
        );
    }
}

function resolveOutcome(Client $client, ClubOffer $offer): Outcome
{
    $roll = static fn (): float => mt_rand() / mt_getrandmax();

    $gotPlayingTime = $roll() < $offer->realPlayingTime;
    $performanceChance = ($client->skill / 100) * ($gotPlayingTime ? 1.0 : 0.4);
    $performedWell = $roll() < $performanceChance;

    return new Outcome($gotPlayingTime, $performedWell);
}

function applyOutcome(GameState $state, ClubOffer $offer, Outcome $outcome): void
{
    $state->totalCommission += $offer->commissionOffered;

    if ($outcome->gotPromisedPlayingTime && $outcome->performedWell) {
        $state->clientSatisfaction += 0.20;
        $state->agentReputation += 0.10 * $offer->prestige + 0.05;
    } elseif ($outcome->gotPromisedPlayingTime) {
        $state->clientSatisfaction += 0.05;
        $state->agentReputation += 0.03;
    } else {
        $brokenPromiseGap = $offer->announcedPlayingTime - $offer->realPlayingTime;
        $state->clientSatisfaction -= 0.20 + $brokenPromiseGap * 0.30;
        $state->agentReputation -= 0.05;
    }
}

function narrate(Client $client, GameState $state, ClubOffer $offer, Outcome $outcome): void
{
    if ($outcome->gotPromisedPlayingTime && $outcome->performedWell) {
        $line = "{$client->name} a rejoint {$offer->clubName}, a eu du temps de jeu et a brille. Satisfaction et reputation en hausse.";
    } elseif ($outcome->gotPromisedPlayingTime) {
        $line = "{$client->name} a rejoint {$offer->clubName} et a joue, sans se distinguer particulierement.";
    } else {
        $line = "{$client->name} a rejoint {$offer->clubName} mais est reste sur le banc bien plus que promis. La relation en souffre.";
    }

    echo $line . "\n";
    $state->addLog("Place a {$offer->clubName} ({$offer->profile}) : {$line}");
}

function recap(Client $client, GameState $state): void
{
    echo "\n=== Fin de partie ===\n";
    printf("Commission totale : %d EUR\n", $state->totalCommission);
    printf("Reputation finale : %d%%\n", (int) round($state->agentReputation * 100));
    printf("Satisfaction finale de {$client->name} : %d%%\n", (int) round($state->clientSatisfaction * 100));
    echo "\nJournal :\n";
    foreach ($state->log as $entry) {
        echo "- {$entry}\n";
    }
    echo "\nRelis README.md pour savoir quoi observer en rejouant differemment.\n";
}
