<?php

declare(strict_types=1);

namespace Flair\Harness\Metrics;

use Flair\Kernel\Core\Ecs\WorldState;
use Flair\Kernel\Core\Pipeline\Pipeline;
use Flair\Kernel\Core\Ruleset\Ruleset;
use Flair\Kernel\Core\Simulation\Simulation;
use Flair\Kernel\Core\Simulation\TickContext;
use Flair\Kernel\Core\Support\SimDate;
use Flair\Kernel\Football\Components\Person;
use Flair\Kernel\Football\Components\PlayerMentalSkills;
use Flair\Kernel\Football\Components\PlayerPhysicalSkills;
use Flair\Kernel\Football\Components\PlayerTechnicalSkills;
use Flair\Kernel\Football\Events\PlayerRetired;
use Flair\Kernel\Football\Events\YouthPlayerPromoted;
use Flair\Kernel\Football\Systems\PlayerDevelopmentSystem;
use Flair\Kernel\Football\Systems\RetirementSystem;
use Flair\Kernel\Football\Systems\TrainingSystem;
use Flair\Kernel\Football\Systems\YouthIntakeSystem;

/**
 * Fait tourner la simulation du noyau sur une population deja construite,
 * et releve un SkillSample par joueur actif et par categorie a chaque fin
 * d'annee simulee (pas a chaque tick - inutile pour des courbes annuelles,
 * couteux en memoire). Ne reimplemente aucune logique de vieillissement :
 * observe seulement ce que le pipeline football ecrit
 * (Flair\Kernel\Football\Systems\YouthIntakeSystem,
 * Flair\Kernel\Football\Systems\TrainingSystem,
 * Flair\Kernel\Football\Systems\RetirementSystem et
 * Flair\Kernel\Football\Systems\PlayerDevelopmentSystem).
 *
 * **La population echantillonnee n'est pas figee.** `$playerIds` n'est que
 * le point de depart : `YouthIntakeSystem` cree de nouveaux joueurs en
 * cours de run (si le monde contient des clubs - cf.
 * Population\ClubFactory/PopulationFactory, qui les cree desormais), et ce
 * Sampler les suit des leur promotion via `YouthPlayerPromoted`, symetrique
 * du suivi deja en place pour `PlayerRetired`. Sans ce suivi, les joueurs
 * promus en cours de route seraient invisibles des courbes - mesurer une
 * cohorte fermee alors que le monde n'en est plus une.
 */
final class Sampler
{
    private const int TICKS_PER_YEAR = 365;

    /** @param list<int> $playerIds */
    public function run(WorldState $world, array $playerIds, int $years, int $worldSeed, Ruleset $ruleset): AggregateResult
    {
        $simulation = new Simulation(new Pipeline([new YouthIntakeSystem(), new TrainingSystem(), new RetirementSystem(), new PlayerDevelopmentSystem()]));

        /** @var list<SkillSample> $samples */
        $samples = [];
        /** @var list<int> $retirementAges */
        $retirementAges = [];
        /** @var array<int, true> $retired */
        $retired = [];
        /** @var array<int, true> $known joueurs initiaux + promus, jamais purge (la soustraction de $retired se fait a la lecture) */
        $known = array_fill_keys($playerIds, true);
        /** @var array<int, int> $populationByYear annee -> effectif actif en fin d'annee */
        $populationByYear = [];
        /** @var list<int> $finalAges ages (arrondis) des actifs a la derniere annee simulee, pour l'histogramme de pyramide */
        $finalAges = [];

        for ($year = 1; $year <= $years; $year++) {
            for ($day = 1; $day <= self::TICKS_PER_YEAR; $day++) {
                $tick = ($year - 1) * self::TICKS_PER_YEAR + $day;
                $result = $simulation->step($world, new TickContext(
                    tick: $tick,
                    seed: $worldSeed,
                    intents: [],
                    ruleset: $ruleset,
                ));

                foreach ($result->events as $event) {
                    if ($event instanceof PlayerRetired && !isset($retired[$event->playerId])) {
                        $retired[$event->playerId] = true;
                        $retirementAges[] = $event->ageYears;
                    }

                    if ($event instanceof YouthPlayerPromoted) {
                        $known[$event->playerId] = true;
                    }
                }
            }

            /** @var list<int> $activePlayerIds */
            $activePlayerIds = array_keys(array_diff_key($known, $retired));
            $populationByYear[$year] = \count($activePlayerIds);

            $ages = $this->sampleYearEnd($world, $activePlayerIds, $year, $samples);
            if ($year === $years) {
                $finalAges = $ages;
            }
        }

        return AggregateResult::fromSamples($samples, $retirementAges, $populationByYear, Stats::histogram($finalAges, bucketWidth: 1));
    }

    /**
     * @param list<int> $activePlayerIds joueurs actifs (retraites deja exclus par l'appelant)
     * @param list<SkillSample> $samples
     * @return list<int> ages (annees, arrondis) des joueurs effectivement echantillonnes
     */
    private function sampleYearEnd(WorldState $world, array $activePlayerIds, int $year, array &$samples): array
    {
        $now = new SimDate($year * self::TICKS_PER_YEAR);
        $ages = [];

        foreach ($activePlayerIds as $playerId) {
            $person = $world->components(Person::class)->get($playerId);
            if ($person === null) {
                continue;
            }

            $ageYears = $now->yearsSince($person->birthDate);
            $ages[] = (int) round($ageYears);

            $physical = $world->components(PlayerPhysicalSkills::class)->get($playerId);
            if ($physical !== null) {
                $samples[] = new SkillSample($playerId, $ageYears, 'physical', $this->average([
                    $physical->pace, $physical->stamina, $physical->strength, $physical->reflexes,
                ]));
            }

            $technical = $world->components(PlayerTechnicalSkills::class)->get($playerId);
            if ($technical !== null) {
                $samples[] = new SkillSample($playerId, $ageYears, 'technical', $this->average([
                    $technical->technique, $technical->passing, $technical->finishing,
                    $technical->defending, $technical->positioning, $technical->handling, $technical->distribution,
                ]));
            }

            $mental = $world->components(PlayerMentalSkills::class)->get($playerId);
            if ($mental !== null) {
                $samples[] = new SkillSample($playerId, $ageYears, 'mental', $this->average([
                    $mental->vision, $mental->composure, $mental->leadership, $mental->discipline, $mental->command,
                ]));
            }
        }

        return $ages;
    }

    /** @param list<int> $values */
    private function average(array $values): float
    {
        return array_sum($values) / \count($values);
    }
}
