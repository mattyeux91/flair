#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Demo manuelle du noyau : monte un WorldState avec quelques joueurs
 * fictifs, fait tourner Simulation::step() sur plusieurs annees simulees,
 * affiche un instantane par annee. Pas un harness (packages/harness/,
 * Phase 1) - juste un point d'entree rapide pour observer le comportement
 * reel des systemes, sans repasser par la suite de tests.
 *
 * A completer au meme rythme que les systemes du domaine football : chaque
 * nouveau System rejoint le Pipeline construit plus bas, sans rien changer
 * au reste du script.
 *
 * Usage : php bin/demo.php
 */

require __DIR__ . '/../vendor/autoload.php';

use Flair\Kernel\Core\Ecs\WorldState;
use Flair\Kernel\Core\Pipeline\Pipeline;
use Flair\Kernel\Core\Ruleset\Balance;
use Flair\Kernel\Core\Ruleset\Ruleset;
use Flair\Kernel\Core\Simulation\Simulation;
use Flair\Kernel\Core\Simulation\TickContext;
use Flair\Kernel\Core\Support\SimDate;
use Flair\Kernel\Football\Components\Club;
use Flair\Kernel\Football\Components\Facilities;
use Flair\Kernel\Football\Components\Person;
use Flair\Kernel\Football\Components\PlayerMentalSkills;
use Flair\Kernel\Football\Components\PlayerPhysicalSkills;
use Flair\Kernel\Football\Components\PlayerPotentials;
use Flair\Kernel\Football\Components\PlayerTechnicalSkills;
use Flair\Kernel\Football\Components\SquadMembership;
use Flair\Kernel\Football\Events\PlayerRetired;
use Flair\Kernel\Football\Events\YouthPlayerPromoted;
use Flair\Kernel\Football\Systems\PlayerDevelopmentSystem;
use Flair\Kernel\Football\Systems\RetirementSystem;
use Flair\Kernel\Football\Systems\TrainingSystem;
use Flair\Kernel\Football\Systems\YouthIntakeSystem;

/** @return array<string, int> nom -> entityId */
function demoCreateClubs(WorldState $world): array
{
    $definitions = [
        'Centre Elite' => 1.8,
        'Centre Modeste' => 0.6,
    ];

    $clubs = [];

    foreach ($definitions as $name => $quality) {
        $club = $world->createEntity();
        $world->components(Club::class)->set($club, new Club($name));
        $world->components(Facilities::class)->set($club, new Facilities($quality));
        $clubs[$name] = $club;
    }

    return $clubs;
}

/**
 * @param array<string, int> $clubs
 * @return array<string, int> nom -> entityId
 */
function demoCreatePlayers(WorldState $world, int $atTick, array $clubs): array
{
    $definitions = [
        'Wonderkid' => ['age' => 17.0, 'skill' => 55, 'ceiling' => 88, 'peakAge' => 27, 'fragility' => 0.2, 'club' => 'Centre Elite'],
        'Prime2' => ['age' => 25.0, 'skill' => 75, 'ceiling' => 80, 'peakAge' => 27, 'fragility' => 0.4, 'club' => 'Centre Modeste'],
        'Veteran' => ['age' => 34.0, 'skill' => 70, 'ceiling' => 75, 'peakAge' => 27, 'fragility' => 0.9, 'club' => 'Centre Elite'],
        'Veteran2' => ['age' => 34.0, 'skill' => 70, 'ceiling' => 75, 'peakAge' => 27, 'fragility' => 0.9, 'club' => 'Centre Modeste'],
    ];

    $players = [];

    foreach ($definitions as $name => $definition) {
        $entity = $world->createEntity();
        $birthDay = (int) round($atTick - $definition['age'] * 365);

        $world->components(Person::class)->set($entity, new Person($name, new SimDate($birthDay)));
        $world->components(PlayerPhysicalSkills::class)->set($entity, new PlayerPhysicalSkills(
            pace: $definition['skill'],
            stamina: $definition['skill'],
            strength: $definition['skill'],
            reflexes: $definition['skill'],
        ));
        $world->components(PlayerTechnicalSkills::class)->set($entity, new PlayerTechnicalSkills(
            technique: $definition['skill'],
            passing: $definition['skill'],
            finishing: $definition['skill'],
            defending: $definition['skill'],
            positioning: $definition['skill'],
            handling: $definition['skill'],
            distribution: $definition['skill'],
        ));
        $world->components(PlayerMentalSkills::class)->set($entity, new PlayerMentalSkills(
            vision: $definition['skill'],
            composure: $definition['skill'],
            leadership: $definition['skill'],
            discipline: $definition['skill'],
            command: $definition['skill'],
        ));
        $world->components(PlayerPotentials::class)->set($entity, new PlayerPotentials(
            ceiling: $definition['ceiling'],
            physicalPeakAge: $definition['peakAge'],
            technicalPeakAge: $definition['peakAge'] + 1,
            mentalPeakAge: $definition['peakAge'] + 5,
            growthRate: 0.4,
            fragility: $definition['fragility'],
        ));
        $world->components(SquadMembership::class)->set($entity, new SquadMembership($clubs[$definition['club']]));

        $players[$name] = $entity;
    }

    return $players;
}

/** @param array<string, int> $players */
function demoPrintSnapshot(WorldState $world, array $players): void
{
    foreach ($players as $name => $entity) {
        $technical = $world->components(PlayerTechnicalSkills::class)->get($entity);
        echo $technical === null
            ? sprintf("  %-10s retraite\n", $name)
            : sprintf("  %-10s technique=%d\n", $name, $technical->technique);
    }
}

/**
 * Le nom des joueurs suivis nominativement est fixe au demarrage ; les
 * promotions de YouthIntakeSystem, elles, sont anonymes et nombreuses. On
 * les agrege donc par club plutot que de les nommer, et on affiche la
 * taille de la population active - c'est cette derniere qui dira si la
 * boucle demographique (intake vs retraite) tient sur 40 ans.
 *
 * @param array<string, int> $clubs        nom -> entityId
 * @param array<int, int>    $promotions   entityId du club -> promus cette annee
 */
function demoPrintPopulation(WorldState $world, array $clubs, array $promotions): void
{
    $active = count($world->components(PlayerPotentials::class)->entities());
    $detail = [];

    foreach ($clubs as $name => $clubId) {
        $detail[] = sprintf('%s +%d', $name, $promotions[$clubId] ?? 0);
    }

    echo sprintf("  population active=%d | promus: %s\n", $active, implode(', ', $detail));
}

const DEMO_YEARS = 40;
const DEMO_TICKS_PER_YEAR = 365;
const DEMO_WORLD_SEED = 42;

$world = new WorldState();
$clubs = demoCreateClubs($world);
$players = demoCreatePlayers($world, atTick: 1, clubs: $clubs);

$simulation = new Simulation(new Pipeline([new YouthIntakeSystem(), new TrainingSystem(), new RetirementSystem(), new PlayerDevelopmentSystem()]));
$ruleset = new Ruleset('demo', new Balance(developmentRate: 1.0));

/** @var array<int, int> $promotions entityId du club -> promus cette annee */
$promotions = [];

echo "Tick 0 (depart) :\n";
demoPrintSnapshot($world, $players);

for ($year = 1; $year <= DEMO_YEARS; $year++) {
    for ($i = 1; $i <= DEMO_TICKS_PER_YEAR; $i++) {
        $tick = ($year - 1) * DEMO_TICKS_PER_YEAR + $i;
        $result = $simulation->step($world, new TickContext(
            tick: $tick,
            seed: DEMO_WORLD_SEED,
            intents: [],
            ruleset: $ruleset,
        ));

        foreach ($result->events as $event) {
            if ($event instanceof PlayerRetired) {
                // Les joueurs promus par YouthIntakeSystem ne sont pas dans
                // $players (fixe au demarrage) : on retombe sur leur EntityId.
                $known = array_search($event->playerId, $players, true);
                $name = $known === false ? "jeune #{$event->playerId}" : $known;
                echo "  >> Fait: {$name} prend sa retraite a {$event->ageYears} ans (tick {$tick})\n";
            }

            if ($event instanceof YouthPlayerPromoted) {
                $promotions[$event->clubId] = ($promotions[$event->clubId] ?? 0) + 1;
            }
        }
    }

    echo "Apres {$year} an(s) :\n";
    demoPrintSnapshot($world, $players);
    demoPrintPopulation($world, $clubs, $promotions);
    $promotions = [];
}
