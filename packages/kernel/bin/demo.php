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
 * Monte le pipeline canonique (`Football\FootballPipeline`) : un systeme
 * ajoute au domaine arrive ici sans que personne y pense. Ce script en a
 * justement eu besoin - il a tourne un temps sur neuf systemes sur onze,
 * `SquadSystem` et `ContractSystem` n'ayant jamais rejoint sa copie de la
 * liste, et affichait donc une economie sans renouvellement de contrat ni
 * gestion d'effectif, que la simulation reelle n'a pas.
 *
 * Usage : php bin/demo.php
 */

require __DIR__ . '/../vendor/autoload.php';

use Flair\Kernel\Football\Support\PositionModel;
use Flair\Kernel\Core\Ecs\WorldState;
use Flair\Kernel\Core\Ruleset\Balance;
use Flair\Kernel\Core\Ruleset\CalendarBalance;
use Flair\Kernel\Core\Ruleset\Ruleset;
use Flair\Kernel\Core\Simulation\Simulation;
use Flair\Kernel\Core\Simulation\TickContext;
use Flair\Kernel\Core\Support\SimDate;
use Flair\Kernel\Football\Components\Club;
use Flair\Kernel\Football\Components\Competition;
use Flair\Kernel\Football\Components\Contract;
use Flair\Kernel\Football\Components\Employment;
use Flair\Kernel\Football\Components\Facilities;
use Flair\Kernel\Football\Components\Finances;
use Flair\Kernel\Football\Components\Scout;
use Flair\Kernel\Football\Components\SeasonIncome;
use Flair\Kernel\Core\Ruleset\PositionBalance;
use Flair\Kernel\Football\Components\Person;
use Flair\Kernel\Football\Components\PlayerMentalSkills;
use Flair\Kernel\Football\Components\PlayerPhysicalSkills;
use Flair\Kernel\Football\Components\PlayerPotentials;
use Flair\Kernel\Football\Components\Position;
use Flair\Kernel\Football\Components\PlayerTechnicalSkills;
use Flair\Kernel\Football\Components\SquadMembership;
use Flair\Kernel\Football\Components\Standings;
use Flair\Kernel\Football\Events\PlayerRetired;
use Flair\Kernel\Football\Events\YouthPlayerPromoted;
use Flair\Kernel\Football\FootballPipeline;

function demoCreateCompetition(WorldState $world): int
{
    $competition = $world->createEntity();
    $world->components(Competition::class)->set($competition, new Competition('Ligue Demo'));

    return $competition;
}

const DEMO_STARTING_BALANCE_CENTS = 10_000_000;
const DEMO_WAGE_PER_WEEK_CENTS = 50_000;
// Assez loin pour que la demo montre le vieillissement et l'entrainement sans
// que ses quelques joueurs partent au premier mercato (Football\ContractSystem).
const DEMO_CONTRACT_EXPIRY_TICK = 10_000;

/**
 * Deux clubs contrastes, installations **et** recrutement : un club sans scout
 * du tout serait le pire observateur du monde (`PerceptionBalance::
 * $unstaffedJudgement`), ce qui ferait de la demo un monde d'aveugles plutot
 * qu'un monde inegal.
 *
 * @return array<string, int> nom -> entityId
 */
function demoCreateClubs(WorldState $world): array
{
    $definitions = [
        'Centre Elite' => ['facilities' => 1.8, 'judgement' => 85],
        'Centre Modeste' => ['facilities' => 0.6, 'judgement' => 25],
    ];

    $clubs = [];

    foreach ($definitions as $name => $definition) {
        $club = $world->createEntity();
        $world->components(Club::class)->set($club, new Club($name));
        $world->components(Facilities::class)->set($club, new Facilities($definition['facilities']));
        $world->components(Finances::class)->set($club, new Finances(DEMO_STARTING_BALANCE_CENTS));
        $clubs[$name] = $club;

        $scout = $world->createEntity();
        $world->components(Person::class)->set($scout, new Person("Recruteur - {$name}", new SimDate(0)));
        $world->components(Employment::class)->set($scout, new Employment($club));
        $world->components(Scout::class)->set($scout, new Scout($definition['judgement']));
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
        $clubId = $clubs[$definition['club']];

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
            archetype: Position::Midfielder,
            ceilings: PositionModel::ceilings($definition['ceiling'], Position::Midfielder, [], new PositionBalance()),
            physicalPeakAge: $definition['peakAge'],
            technicalPeakAge: $definition['peakAge'] + 1,
            mentalPeakAge: $definition['peakAge'] + 5,
            growthRate: 0.4,
            fragility: $definition['fragility'],
        ));
        $world->components(SquadMembership::class)->set($entity, new SquadMembership($clubId));
        $world->components(Contract::class)->set($entity, new Contract($clubId, DEMO_WAGE_PER_WEEK_CENTS, new SimDate(DEMO_CONTRACT_EXPIRY_TICK), new SimDate(1)));

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

/** @param array<string, int> $clubs nom -> entityId */
function demoPrintStandings(WorldState $world, int $competitionId, array $clubs): void
{
    $standings = $world->components(Standings::class)->get($competitionId);

    if ($standings === null) {
        return;
    }

    $namesById = array_flip($clubs);
    $rows = $standings->entries;
    usort($rows, static fn ($a, $b): int => $b->points <=> $a->points ?: ($b->goalsFor - $b->goalsAgainst) <=> ($a->goalsFor - $a->goalsAgainst));

    echo "  classement :\n";
    foreach ($rows as $entry) {
        $name = $namesById[$entry->clubId] ?? "club #{$entry->clubId}";
        echo sprintf(
            "    %-14s J=%-2d Pts=%-2d (%d-%d)\n",
            $name,
            $entry->played,
            $entry->points,
            $entry->goalsFor,
            $entry->goalsAgainst,
        );
    }
}

/** @param array<string, int> $clubs nom -> entityId */
function demoPrintFinances(WorldState $world, array $clubs): void
{
    echo "  finances :\n";
    foreach ($clubs as $name => $clubId) {
        $finances = $world->components(Finances::class)->get($clubId);
        $balance = $finances === null ? 'n/a' : number_format($finances->balanceCents / 100, 2, ',', ' ') . ' EUR';
        $income = $world->components(SeasonIncome::class)->get($clubId);
        $earned = $income === null ? 'n/a' : number_format($income->cents / 100, 2, ',', ' ') . ' EUR';
        echo sprintf("    %-14s solde=%s  revenu de saison=%s\n", $name, $balance, $earned);
    }
}

const DEMO_YEARS = 40;
const DEMO_TICKS_PER_YEAR = 365;
const DEMO_WORLD_SEED = 42;

$world = new WorldState();
$competition = demoCreateCompetition($world);
$clubs = demoCreateClubs($world);
$players = demoCreatePlayers($world, atTick: 1, clubs: $clubs);

$simulation = new Simulation(FootballPipeline::build());
// Saison generee des le premier tick simule (pas au tick 0, jamais atteint
// par la boucle ci-dessous) et journees rapprochees : seulement 2 clubs en
// demo, pas besoin de l'espacement realiste d'une vraie saison.
$ruleset = new Ruleset('demo', new Balance(
    developmentRate: 1.0,
    calendar: new CalendarBalance(seasonStartDayOfYear: 1, firstMatchdayOffsetDays: 5, matchdayIntervalDays: 5),
));

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
    demoPrintStandings($world, $competition, $clubs);
    demoPrintFinances($world, $clubs);
    $promotions = [];
}
